<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\CryptoException;
use Diary\Storage\Envelope;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PayloadException;
use Diary\Storage\PayloadShape;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Encoding and decoding the three encrypted payload documents against the
 * `key_id`, `nonce`, `payload_ciphertext` columns (Requirements 4.1, 4.2).
 *
 * The cases that matter most here are the negative ones: an altered row must
 * stop the read rather than return content, and an invalid payload must be
 * refused before it is written.
 */
final class PayloadCodecTest extends TestCase
{
    private const ENTRY_ID = '01JQ0000000000000000000001';
    private const MILESTONE_ID = '01JQ0000000000000000000002';
    private const RECOMMENDATION_ID = '01JQ0000000000000000000003';

    private PDO $pdo;
    private PayloadCodec $codec;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );

        $keyRing = new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            FixedClock::at('2025-03-01 09:30:00')
        );

        $this->codec = new PayloadCodec(new Crypto($keyRing));
    }

    public function testDiaryEntryPayloadRoundTripsThroughTheStoredColumns(): void
    {
        $payload = [
            'mood_rating' => 7,
            'sleep_quality' => 3,
            'events' => 'Walked to the coast 🌊',
            'thoughts' => 'I coped better than I expected',
            'emotions' => 'relieved',
        ];

        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, $payload);

        self::assertSame(['key_id', 'nonce', 'payload_ciphertext'], array_keys($row));
        self::assertSame(Envelope::NONCE_LENGTH, strlen($row['nonce']));
        self::assertStringNotContainsString('coast', $row['payload_ciphertext']);

        self::assertSame(
            $payload + ['schema_version' => PayloadCodec::SCHEMA_VERSION],
            $this->codec->decode(PayloadShape::DiaryEntry, self::ENTRY_ID, $row)
        );
    }

    public function testMilestoneAndRecommendationPayloadsRoundTrip(): void
    {
        $milestoneRow = $this->codec->encodeRow(PayloadShape::Milestone, self::MILESTONE_ID, [
            'description' => 'Started a new medication',
            'category' => 'medication',
        ]);

        self::assertSame(
            [
                'description' => 'Started a new medication',
                'category' => 'medication',
                'schema_version' => 1,
            ],
            $this->codec->decode(PayloadShape::Milestone, self::MILESTONE_ID, $milestoneRow)
        );

        $recommendationRow = $this->codec->encodeRow(PayloadShape::CbtRecommendation, self::RECOMMENDATION_ID, [
            'positive_focus' => 'You noticed the coping you did well.',
            'suggested_change' => 'Try naming one alternative thought tomorrow.',
        ]);

        self::assertSame(
            [
                'positive_focus' => 'You noticed the coping you did well.',
                'suggested_change' => 'Try naming one alternative thought tomorrow.',
                'schema_version' => 1,
            ],
            $this->codec->decode(PayloadShape::CbtRecommendation, self::RECOMMENDATION_ID, $recommendationRow)
        );
    }

    public function testOmittedOptionalDiaryFieldsDecodeToAStableShape(): void
    {
        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, ['mood_rating' => 1]);

        self::assertSame(
            [
                'mood_rating' => 1,
                'sleep_quality' => null,
                'events' => '',
                'thoughts' => '',
                'emotions' => '',
                'schema_version' => 1,
            ],
            $this->codec->decode(PayloadShape::DiaryEntry, self::ENTRY_ID, $row)
        );
    }

    public function testEveryEncodedPayloadCarriesTheSchemaVersion(): void
    {
        foreach ($this->samples() as $shape => $payload) {
            $decoded = $this->codec->decode(
                PayloadShape::from($shape),
                self::ENTRY_ID,
                $this->codec->encodeRow(PayloadShape::from($shape), self::ENTRY_ID, $payload)
            );

            self::assertSame(PayloadCodec::SCHEMA_VERSION, $decoded['schema_version']);
            self::assertSame('schema_version', array_key_last($decoded));
        }
    }

    public function testAnAlteredCiphertextIsAnIntegrityFailureRatherThanAlteredData(): void
    {
        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, ['mood_rating' => 7]);
        $row['payload_ciphertext'][0] = chr(ord($row['payload_ciphertext'][0]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $this->codec->decode(PayloadShape::DiaryEntry, self::ENTRY_ID, $row);
    }

    public function testAnAlteredAuthenticationTagIsAnIntegrityFailure(): void
    {
        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, ['mood_rating' => 7]);
        $last = strlen($row['payload_ciphertext']) - 1;
        $row['payload_ciphertext'][$last] = chr(ord($row['payload_ciphertext'][$last]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $this->codec->decode(PayloadShape::DiaryEntry, self::ENTRY_ID, $row);
    }

    public function testAPayloadReadUnderAnotherRecordIdOrTableIsAnIntegrityFailure(): void
    {
        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, ['mood_rating' => 7]);

        try {
            $this->codec->decode(PayloadShape::DiaryEntry, self::MILESTONE_ID, $row);
            self::fail('a payload read under another record id must not decode');
        } catch (CryptoException) {
            // expected: the record id is authenticated
        }

        $this->expectException(CryptoException::class);

        $this->codec->decode(PayloadShape::Milestone, self::ENTRY_ID, $row);
    }

    public function testARowMissingAnEncryptedColumnIsRejected(): void
    {
        $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, self::ENTRY_ID, ['mood_rating' => 7]);
        unset($row['nonce']);

        $this->expectException(CryptoException::class);

        $this->codec->decode(PayloadShape::DiaryEntry, self::ENTRY_ID, $row);
    }

    public function testAnUnreadableSchemaVersionIsRejectedOnDecode(): void
    {
        $crypto = new Crypto(new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            FixedClock::at('2025-03-01 09:30:00')
        ));

        $envelope = $crypto->encryptFor(
            PayloadShape::DiaryEntry->table(),
            self::ENTRY_ID,
            json_encode(['mood_rating' => 7, 'schema_version' => 99], JSON_THROW_ON_ERROR)
        );

        $this->expectException(PayloadException::class);
        $this->expectExceptionMessageMatches('/schema version 99/');

        $this->codec->decodeEnvelope(PayloadShape::DiaryEntry, self::ENTRY_ID, $envelope);
    }

    public function testAStoredPayloadOutsideItsSchemaIsNotRendered(): void
    {
        $crypto = new Crypto(new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            FixedClock::at('2025-03-01 09:30:00')
        ));

        $envelope = $crypto->encryptFor(
            PayloadShape::DiaryEntry->table(),
            self::ENTRY_ID,
            json_encode(['mood_rating' => 42, 'schema_version' => 1], JSON_THROW_ON_ERROR)
        );

        $this->expectException(PayloadException::class);
        $this->expectExceptionMessageMatches('/was not rendered/');

        $this->codec->decodeEnvelope(PayloadShape::DiaryEntry, self::ENTRY_ID, $envelope);
    }

    public function testAStoredDocumentThatIsNotAJsonObjectIsNotRendered(): void
    {
        $crypto = new Crypto(new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            FixedClock::at('2025-03-01 09:30:00')
        ));

        $envelope = $crypto->encryptFor(PayloadShape::Milestone->table(), self::MILESTONE_ID, '[1,2,3]');

        $this->expectException(PayloadException::class);

        $this->codec->decodeEnvelope(PayloadShape::Milestone, self::MILESTONE_ID, $envelope);
    }

    public function testFieldsNewerThanThisBuildAreDroppedRatherThanBreakingTheRead(): void
    {
        $crypto = new Crypto(new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            FixedClock::at('2025-03-01 09:30:00')
        ));

        $envelope = $crypto->encryptFor(
            PayloadShape::Milestone->table(),
            self::MILESTONE_ID,
            json_encode([
                'description' => 'Moved house',
                'category' => 'lifestyle',
                'mood_after' => 6,
                'schema_version' => 1,
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(
            ['description' => 'Moved house', 'category' => 'lifestyle', 'schema_version' => 1],
            $this->codec->decodeEnvelope(PayloadShape::Milestone, self::MILESTONE_ID, $envelope)
        );
    }

    /**
     * @return iterable<string, array{0: PayloadShape, 1: array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'mood rating missing' => [PayloadShape::DiaryEntry, ['events' => 'a walk']];
        yield 'mood rating null' => [PayloadShape::DiaryEntry, ['mood_rating' => null]];
        yield 'mood rating below range' => [PayloadShape::DiaryEntry, ['mood_rating' => 0]];
        yield 'mood rating above range' => [PayloadShape::DiaryEntry, ['mood_rating' => 11]];
        yield 'mood rating not a number' => [PayloadShape::DiaryEntry, ['mood_rating' => '7']];
        yield 'sleep quality above range' => [PayloadShape::DiaryEntry, ['mood_rating' => 5, 'sleep_quality' => 6]];
        yield 'sleep quality below range' => [PayloadShape::DiaryEntry, ['mood_rating' => 5, 'sleep_quality' => 0]];
        yield 'free text not text' => [PayloadShape::DiaryEntry, ['mood_rating' => 5, 'thoughts' => ['a']]];
        yield 'unknown diary field' => [PayloadShape::DiaryEntry, ['mood_rating' => 5, 'mood' => 5]];
        yield 'unreadable schema version' => [PayloadShape::DiaryEntry, ['mood_rating' => 5, 'schema_version' => 2]];
        yield 'milestone description missing' => [PayloadShape::Milestone, ['category' => 'other']];
        yield 'milestone description blank' => [PayloadShape::Milestone, ['description' => '  ', 'category' => 'other']];
        yield 'milestone category unknown' => [PayloadShape::Milestone, ['description' => 'x', 'category' => 'work']];
        yield 'milestone category missing' => [PayloadShape::Milestone, ['description' => 'x']];
        yield 'recommendation half filled' => [PayloadShape::CbtRecommendation, ['positive_focus' => 'x']];
        yield 'recommendation blank change' => [
            PayloadShape::CbtRecommendation,
            ['positive_focus' => 'x', 'suggested_change' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testAnInvalidPayloadIsRefusedBeforeItIsEncrypted(PayloadShape $shape, array $payload): void
    {
        $this->expectException(PayloadException::class);

        $this->codec->encode($shape, self::ENTRY_ID, $payload);
    }

    public function testAnExceptionMessageNeverRepeatsTheRejectedContent(): void
    {
        try {
            $this->codec->encode(PayloadShape::Milestone, self::MILESTONE_ID, [
                'description' => 'started sertraline 50mg',
                'category' => 'pharmacology',
            ]);
            self::fail('an unknown category must be rejected');
        } catch (PayloadException $e) {
            self::assertStringNotContainsString('sertraline', $e->getMessage());
            self::assertStringNotContainsString('pharmacology', $e->getMessage());
            self::assertStringContainsString('category', $e->getMessage());
        }
    }

    public function testANullPayloadRowDecodesToNullAndAHalfWrittenOneIsRejected(): void
    {
        $empty = ['key_id' => null, 'nonce' => null, 'payload_ciphertext' => null];

        self::assertNull($this->codec->decodeOptional(
            PayloadShape::CbtRecommendation,
            self::RECOMMENDATION_ID,
            $empty
        ));

        $written = $this->codec->encodeRow(PayloadShape::CbtRecommendation, self::RECOMMENDATION_ID, [
            'positive_focus' => 'x',
            'suggested_change' => 'y',
        ]);

        self::assertNotNull($this->codec->decodeOptional(
            PayloadShape::CbtRecommendation,
            self::RECOMMENDATION_ID,
            $written
        ));

        $this->expectException(PayloadException::class);

        $this->codec->decodeOptional(
            PayloadShape::CbtRecommendation,
            self::RECOMMENDATION_ID,
            ['key_id' => $written['key_id'], 'nonce' => null, 'payload_ciphertext' => null]
        );
    }

    public function testShapesMapToTheirTablesAndUnknownTablesAreRejected(): void
    {
        self::assertSame('diary_entries', PayloadShape::DiaryEntry->table());
        self::assertSame(PayloadShape::Milestone, PayloadShape::forTable('milestones'));
        self::assertSame(PayloadShape::CbtRecommendation, PayloadShape::forTable('cbt_recommendations'));

        $this->expectException(PayloadException::class);

        PayloadShape::forTable('users');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function samples(): array
    {
        return [
            'diary_entries' => ['mood_rating' => 4, 'sleep_quality' => 2],
            'milestones' => ['description' => 'Started therapy', 'category' => 'other'],
            'cbt_recommendations' => ['positive_focus' => 'a', 'suggested_change' => 'b'],
        ];
    }
}
