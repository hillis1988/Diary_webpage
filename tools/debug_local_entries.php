<?php

declare(strict_types=1);

use Diary\Access\OwnerId;
use Diary\Auth\UserRepository;
use Diary\Diary\DiaryService;
use Diary\Diary\DiaryEntryRepository;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\SystemClock;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = ConnectionFactory::fromConfig($config);
$clock = new SystemClock();
$masterKey = base64_decode((string) $config['encryption']['master_key_base64'], true);
$keyRing = new KeyRing($pdo, $masterKey, $clock);
$codec = new PayloadCodec(new Crypto($keyRing));

$user = (new UserRepository($pdo))->findByEmail('local@preview.test');
if ($user === null) {
    fwrite(STDERR, "no user\n");
    exit(1);
}

$owner = OwnerId::fromUserId($user->id);
$diary = new DiaryService(new DiaryEntryRepository($pdo, $codec));
$today = LocalDate::today($clock);
$defaultRange = DateRange::of($today->minusDays(29), $today);

$entries = $diary->entriesInRange($owner, $defaultRange);
fwrite(STDOUT, 'today=' . $today->toIso() . "\n");
fwrite(STDOUT, 'defaultRange=' . $defaultRange->start()->toIso() . ' .. ' . $defaultRange->end()->toIso() . "\n");
fwrite(STDOUT, 'entriesInDefaultRange=' . count($entries) . "\n");

$wide = DateRange::of($today->minusDays(90), $today);
$all = $diary->entriesInRange($owner, $wide);
fwrite(STDOUT, 'entriesIn90Days=' . count($all) . "\n");
foreach ($all as $entry) {
    fwrite(STDOUT, '  ' . $entry->date()->toIso() . ' mood=' . $entry->input()->moodRating() . "\n");
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM diary_entries')->fetchColumn();
fwrite(STDOUT, "rawDiaryRows={$count}\n");
