<?php

declare(strict_types=1);

namespace Diary\Storage;

use JsonException;

/**
 * Maps a sensitive payload array to and from the `key_id`, `nonce` and
 * `payload_ciphertext` columns of its table (Requirements 4.1, 4.2).
 *
 * This is the only place that knows the JSON documents described in the design:
 * diary entries, CBT recommendations and milestones. Repositories hand it an
 * array and a record id and get back columns to bind, or hand it a fetched row
 * and get back a validated array. Nothing outside this class calls json_encode
 * on health data or decides what `schema_version` means.
 *
 * Two guarantees are worth stating plainly:
 *
 *   - Nothing altered is ever returned. Decryption fails closed as a
 *     CryptoException, so a ciphertext that was edited in the database, moved to
 *     another row, or sealed under a key that no longer unwraps stops the read
 *     instead of yielding plausible-looking content. A payload that authenticates
 *     but does not match its schema is rejected the same way, as a
 *     PayloadException. Both cases prevent rendering rather than degrade it.
 *   - Encoding validates first. A payload is checked field by field before it is
 *     encrypted, so an out-of-range mood rating or an unknown key cannot reach
 *     the database in a form nothing can read back.
 *
 * Decoding returns a canonical array: every field the shape defines, present, in
 * schema order, with `schema_version` last. Callers therefore never need to test
 * for missing keys.
 */
final class PayloadCodec
{
    /**
     * The version written into every payload this build produces.
     * Version 2 adds optional `food_meals` on diary entries; CBT and milestone
     * documents keep the same fields and simply stamp the current version.
     */
    public const SCHEMA_VERSION = 2;

    /**
     * Versions this build can read. Version 1 diary rows decode with an empty
     * food diary; version 2 carries `food_meals`.
     *
     * @var list<int>
     */
    private const READABLE_SCHEMA_VERSIONS = [1, 2];

    private const MOOD_MIN = 1;
    private const MOOD_MAX = 10;
    private const SLEEP_MIN = 1;
    private const SLEEP_MAX = 5;

    public function __construct(private readonly Crypto $crypto)
    {
    }

    /**
     * Validate, serialise and encrypt a payload for one record.
     *
     * @param array<string, mixed> $payload
     *
     * @throws PayloadException when the payload does not match the shape
     */
    public function encode(PayloadShape $shape, string $recordId, array $payload): Envelope
    {
        $canonical = $this->canonicalise($shape, $payload);

        try {
            $json = json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw PayloadException::notUtf8($shape);
        }

        return $this->crypto->encryptFor($shape->table(), $recordId, $json);
    }

    /**
     * The column values to bind on a write.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{key_id: string, nonce: string, payload_ciphertext: string}
     */
    public function encodeRow(PayloadShape $shape, string $recordId, array $payload): array
    {
        return $this->encode($shape, $recordId, $payload)->toRow();
    }

    /**
     * Read the encrypted columns of a fetched row back into a payload array.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> canonical: every field of the shape, in order
     *
     * @throws CryptoException  when the row does not authenticate, so nothing is rendered
     * @throws PayloadException when the decrypted document does not match the schema
     */
    public function decode(PayloadShape $shape, string $recordId, array $row): array
    {
        return $this->decodeEnvelope($shape, $recordId, Envelope::fromRow($row));
    }

    /**
     * Like decode(), but for tables where the payload columns are nullable -
     * a `cbt_recommendations` row with status `failed` carries no ciphertext.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null null when the row holds no payload
     */
    public function decodeOptional(PayloadShape $shape, string $recordId, array $row): ?array
    {
        $present = 0;

        foreach (['key_id', 'nonce', 'payload_ciphertext'] as $column) {
            if (($row[$column] ?? null) !== null) {
                $present++;
            }
        }

        if ($present === 0) {
            return null;
        }

        if ($present < 3) {
            throw PayloadException::partiallyEncrypted($shape);
        }

        return $this->decode($shape, $recordId, $row);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CryptoException  when the envelope does not authenticate
     * @throws PayloadException when the decrypted document does not match the schema
     */
    public function decodeEnvelope(PayloadShape $shape, string $recordId, Envelope $envelope): array
    {
        // A failure here is a CryptoException, deliberately left to propagate:
        // an altered or misbound payload must stop the read, not shape one.
        $json = $this->crypto->decryptFor($shape->table(), $recordId, $envelope);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PayloadException::malformedStoredPayload($shape);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw PayloadException::malformedStoredPayload($shape);
        }

        /** @var array<string, mixed> $decoded */
        $this->assertReadableVersion($shape, $decoded);

        try {
            // Unknown keys are dropped rather than rejected on the way out, so a
            // row written by a newer build of the same schema version still reads.
            return $this->canonicalise($shape, array_intersect_key(
                $decoded,
                array_fill_keys($shape->fields(), true)
            ));
        } catch (PayloadException $cause) {
            throw PayloadException::storedPayloadRejected($shape, $cause);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function canonicalise(PayloadShape $shape, array $payload): array
    {
        $unknown = array_values(array_diff(array_keys($payload), $shape->fields()));

        if ($unknown !== []) {
            throw PayloadException::unknownFields($shape, $unknown);
        }

        if (array_key_exists('schema_version', $payload) && $payload['schema_version'] !== null) {
            $this->assertReadableVersion($shape, $payload);
        }

        return match ($shape) {
            PayloadShape::DiaryEntry => [
                'mood_rating' => self::requiredInt($shape, $payload, 'mood_rating', self::MOOD_MIN, self::MOOD_MAX),
                'sleep_quality' => self::optionalInt($shape, $payload, 'sleep_quality', self::SLEEP_MIN, self::SLEEP_MAX),
                'events' => self::optionalText($shape, $payload, 'events'),
                'thoughts' => self::optionalText($shape, $payload, 'thoughts'),
                'emotions' => self::optionalText($shape, $payload, 'emotions'),
                'food_meals' => self::optionalFoodMeals($shape, $payload),
                'schema_version' => self::SCHEMA_VERSION,
            ],
            PayloadShape::CbtRecommendation => [
                'positive_focus' => self::requiredText($shape, $payload, 'positive_focus'),
                'suggested_change' => self::requiredText($shape, $payload, 'suggested_change'),
                'schema_version' => self::SCHEMA_VERSION,
            ],
            PayloadShape::Milestone => [
                'description' => self::requiredText($shape, $payload, 'description'),
                'category' => self::requiredChoice($shape, $payload, 'category', PayloadShape::MILESTONE_CATEGORIES),
                'schema_version' => self::SCHEMA_VERSION,
            ],
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertReadableVersion(PayloadShape $shape, array $payload): void
    {
        $version = $payload['schema_version'] ?? null;

        if (!is_int($version)) {
            throw PayloadException::unsupportedSchemaVersion(
                $shape,
                $version === null ? 'nothing' : get_debug_type($version),
                self::SCHEMA_VERSION
            );
        }

        if (!in_array($version, self::READABLE_SCHEMA_VERSIONS, true)) {
            throw PayloadException::unsupportedSchemaVersion($shape, (string) $version, self::SCHEMA_VERSION);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredInt(PayloadShape $shape, array $payload, string $field, int $min, int $max): int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            throw PayloadException::missingField($shape, $field);
        }

        return self::intInRange($shape, $payload[$field], $field, $min, $max);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalInt(PayloadShape $shape, array $payload, string $field, int $min, int $max): ?int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        return self::intInRange($shape, $payload[$field], $field, $min, $max);
    }

    private static function intInRange(PayloadShape $shape, mixed $value, string $field, int $min, int $max): int
    {
        if (!is_int($value)) {
            throw PayloadException::invalidField(
                $shape,
                $field,
                sprintf('must be a whole number between %d and %d, not a %s', $min, $max, get_debug_type($value))
            );
        }

        if ($value < $min || $value > $max) {
            throw PayloadException::invalidField(
                $shape,
                $field,
                sprintf('must be between %d and %d', $min, $max)
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requiredText(PayloadShape $shape, array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            throw PayloadException::missingField($shape, $field);
        }

        $value = self::text($shape, $payload[$field], $field);

        if (trim($value) === '') {
            throw PayloadException::invalidField($shape, $field, 'must not be blank');
        }

        return $value;
    }

    /**
     * Absent or null free text is stored as an empty string, so a decoded payload
     * always has the same keys whatever the writer left out.
     *
     * @param array<string, mixed> $payload
     */
    private static function optionalText(PayloadShape $shape, array $payload, string $field): string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return '';
        }

        return self::text($shape, $payload[$field], $field);
    }

    private static function text(PayloadShape $shape, mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw PayloadException::invalidField(
                $shape,
                $field,
                sprintf('must be text, not a %s', get_debug_type($value))
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowed
     */
    private static function requiredChoice(PayloadShape $shape, array $payload, string $field, array $allowed): string
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            throw PayloadException::missingField($shape, $field);
        }

        $value = self::text($shape, $payload[$field], $field);

        if (!in_array($value, $allowed, true)) {
            throw PayloadException::invalidField(
                $shape,
                $field,
                sprintf('must be one of %s', implode(', ', $allowed))
            );
        }

        return $value;
    }

    /**
     * Absent or null food_meals becomes an empty list. Each retained meal needs
     * a closed type, a non-blank description, and optional notes text.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{type: string, description: string, notes: string}>
     */
    private static function optionalFoodMeals(PayloadShape $shape, array $payload): array
    {
        if (!array_key_exists('food_meals', $payload) || $payload['food_meals'] === null) {
            return [];
        }

        $rows = $payload['food_meals'];

        if (!is_array($rows) || ($rows !== [] && !array_is_list($rows))) {
            throw PayloadException::invalidField($shape, 'food_meals', 'must be a list of meals');
        }

        if (count($rows) > 12) {
            throw PayloadException::invalidField($shape, 'food_meals', 'must hold at most 12 meals');
        }

        $meals = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw PayloadException::invalidField(
                    $shape,
                    'food_meals',
                    sprintf('entry %d must be an object with type, description and notes', $index)
                );
            }

            /** @var array<string, mixed> $row */
            $unknown = array_values(array_diff(array_keys($row), ['type', 'description', 'notes']));
            if ($unknown !== []) {
                throw PayloadException::invalidField(
                    $shape,
                    'food_meals',
                    sprintf('entry %d carries unknown fields: %s', $index, implode(', ', $unknown))
                );
            }

            if (!array_key_exists('type', $row) || $row['type'] === null) {
                throw PayloadException::invalidField($shape, 'food_meals', sprintf('entry %d requires type', $index));
            }

            $type = self::text($shape, $row['type'], 'food_meals');
            if (!in_array($type, PayloadShape::FOOD_MEAL_TYPES, true)) {
                throw PayloadException::invalidField(
                    $shape,
                    'food_meals',
                    sprintf('entry %d type must be one of %s', $index, implode(', ', PayloadShape::FOOD_MEAL_TYPES))
                );
            }

            if (!array_key_exists('description', $row) || $row['description'] === null) {
                throw PayloadException::invalidField(
                    $shape,
                    'food_meals',
                    sprintf('entry %d requires description', $index)
                );
            }

            $description = self::text($shape, $row['description'], 'food_meals');
            if (trim($description) === '') {
                throw PayloadException::invalidField(
                    $shape,
                    'food_meals',
                    sprintf('entry %d description must not be blank', $index)
                );
            }

            $notes = '';
            if (array_key_exists('notes', $row) && $row['notes'] !== null) {
                $notes = self::text($shape, $row['notes'], 'food_meals');
            }

            $meals[] = [
                'type' => $type,
                'description' => $description,
                'notes' => $notes,
            ];
        }

        return $meals;
    }
}
