<?php

declare(strict_types=1);

/**
 * Seeds bogus diary entries, milestones and CBT notes for the local preview user
 * so charts, calendar and Bright spots have something to show.
 *
 *   php tools/seed_local_demo_data.php
 */

use Diary\Access\OwnerId;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Auth\UserRepository;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\SubmittedAnswers;
use Diary\Diary\DiaryValidation;
use Diary\Diary\DiaryService;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Milestone\MilestoneSubmission;
use Diary\Milestone\MilestoneValidation;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\LocalDate;
use Diary\Support\SystemClock;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = ConnectionFactory::fromConfig($config);
$clock = new SystemClock();

$masterKey = base64_decode((string) $config['encryption']['master_key_base64'], true);
if ($masterKey === false || strlen($masterKey) !== 32) {
    fwrite(STDERR, "Local config master key is invalid.\n");
    exit(1);
}

$user = (new UserRepository($pdo))->findByEmail('local@preview.test');
if ($user === null) {
    fwrite(STDERR, "Local user not found. Run: php tools/seed_local_user.php\n");
    exit(1);
}

$owner = OwnerId::fromUserId($user->id);
$keyRing = new KeyRing($pdo, $masterKey, $clock);
$codec = new PayloadCodec(new Crypto($keyRing));
$diaryRepo = new DiaryEntryRepository($pdo, $codec);
$diary = new DiaryService($diaryRepo);
$milestones = new MilestoneService(new MilestoneRepository($pdo, $codec));
$recommendations = new CbtRecommendationRepository($pdo, $codec);

$today = LocalDate::today($clock);

/** @var list<array{offset:int, mood:int, sleep:int, events:string, thoughts:string, emotions:string, focus:string, change:string}> $days */
$days = [
    ['offset' => 27, 'mood' => 4, 'sleep' => 2, 'events' => 'Stayed in most of the day and cancelled plans.', 'thoughts' => 'Felt behind on everything.', 'emotions' => 'Low, tired.', 'focus' => 'You still named how you felt instead of ignoring it.', 'change' => 'Tomorrow, try one short walk even if it is only around the block.'],
    ['offset' => 25, 'mood' => 5, 'sleep' => 3, 'events' => 'Managed a food shop and a quick call with Mum.', 'thoughts' => 'Doing small things still counts.', 'emotions' => 'A bit brighter.', 'focus' => 'You kept a basic routine going on a hard day.', 'change' => 'Write down one task you finished tonight so tomorrow starts with proof.'],
    ['offset' => 23, 'mood' => 5, 'sleep' => 3, 'events' => 'Went for a short walk after lunch.', 'thoughts' => 'Outside air helped more than expected.', 'emotions' => 'Calmer.', 'focus' => 'You chose movement when staying still would have been easier.', 'change' => 'Schedule the same walk tomorrow at the same time.'],
    ['offset' => 21, 'mood' => 6, 'sleep' => 3, 'events' => 'Caught up with a friend over coffee.', 'thoughts' => 'Talking made the worry feel smaller.', 'emotions' => 'Connected, relieved.', 'focus' => 'You reached out instead of sitting alone with it.', 'change' => 'Send that friend a quick thank-you text tonight.'],
    ['offset' => 19, 'mood' => 6, 'sleep' => 4, 'events' => 'Slept better and finished a bit of admin.', 'thoughts' => 'Clearing one email pile helped.', 'emotions' => 'Capable.', 'focus' => 'Better sleep and one finished task stacked nicely.', 'change' => 'Pick one more small admin item for tomorrow morning.'],
    ['offset' => 17, 'mood' => 5, 'sleep' => 3, 'events' => 'A stressful work message derailed the afternoon.', 'thoughts' => 'I assumed the worst about what they meant.', 'emotions' => 'Anxious, then steadier.', 'focus' => 'You noticed the jump to the worst-case story.', 'change' => 'Before replying next time, write the facts on one line and the guess on another.'],
    ['offset' => 15, 'mood' => 7, 'sleep' => 4, 'events' => 'Cooked a proper meal and watched something light.', 'thoughts' => 'Looking after myself does not have to be dramatic.', 'emotions' => 'Content.', 'focus' => 'You gave yourself an evening that was actually restorative.', 'change' => 'Keep one low-effort comfort ritual on the calendar this week.'],
    ['offset' => 13, 'mood' => 7, 'sleep' => 4, 'events' => 'Morning walk and a focused hour of work.', 'thoughts' => 'Starting early made the day feel workable.', 'emotions' => 'Motivated.', 'focus' => 'A simple morning structure paid off.', 'change' => 'Protect the same morning window twice more this week.'],
    ['offset' => 11, 'mood' => 6, 'sleep' => 3, 'events' => 'Had an honest conversation about how I have been feeling.', 'thoughts' => 'Being open was scary but useful.', 'emotions' => 'Nervous, then lighter.', 'focus' => 'You said the true thing out loud.', 'change' => 'Note what felt safer after saying it, so you remember it worked.'],
    ['offset' => 9, 'mood' => 8, 'sleep' => 4, 'events' => 'Long walk in the park and coffee afterwards.', 'thoughts' => 'Felt calmer than yesterday.', 'emotions' => 'Content, a little tired.', 'focus' => 'You paired movement with something pleasant.', 'change' => 'Repeat a walk-plus-treat pairing once this weekend.'],
    ['offset' => 7, 'mood' => 7, 'sleep' => 5, 'events' => 'Best sleep in a while; kept a gentle pace.', 'thoughts' => 'Rest makes everything else easier.', 'emotions' => 'Rested, hopeful.', 'focus' => 'You protected sleep and felt the difference.', 'change' => 'Keep the same wind-down start time for three nights.'],
    ['offset' => 5, 'mood' => 8, 'sleep' => 4, 'events' => 'Finished a task I had been putting off.', 'thoughts' => 'Avoiding it cost more energy than doing it.', 'emotions' => 'Proud, relieved.', 'focus' => 'You broke the avoidance loop with one concrete action.', 'change' => 'Choose one more postponed task under 20 minutes for tomorrow.'],
    ['offset' => 3, 'mood' => 8, 'sleep' => 5, 'events' => 'Met a friend and planned a weekend outing.', 'thoughts' => 'Having something ahead feels good.', 'emotions' => 'Optimistic.', 'focus' => 'You invested in connection and future plans.', 'change' => 'Put the outing date in your calendar tonight so it stays real.'],
    ['offset' => 1, 'mood' => 9, 'sleep' => 5, 'events' => 'Steady day: walk, work block, early night.', 'thoughts' => 'Ordinary good days matter too.', 'emotions' => 'Settled, grateful.', 'focus' => 'You built a day that supports you without forcing drama.', 'change' => 'Keep collecting ordinary good days — they are the point.'],
];

$entryCount = 0;
foreach ($days as $day) {
    $date = $today->minusDays($day['offset']);
    $input = DiaryEntryInput::of(
        $date,
        $day['mood'],
        $day['sleep'],
        $day['events'],
        $day['thoughts'],
        $day['emotions'],
    );
    $answers = SubmittedAnswers::of([
        'entry_date' => $date->toIso(),
        'mood_rating' => (string) $day['mood'],
        'sleep_quality' => (string) $day['sleep'],
        'events' => $day['events'],
        'thoughts' => $day['thoughts'],
        'emotions' => $day['emotions'],
    ]);
    $validation = DiaryValidation::accepted($input, $answers);
    $result = $diary->submitEntry($owner, $validation, $clock);
    if (!$result->isOk()) {
        fwrite(STDERR, 'Failed entry ' . $date->toIso() . ': ' . $result->message() . "\n");
        exit(1);
    }

    $entry = $result->value();
    $recommendations->recordSuccess(
        $entry->id(),
        new CbtRecommendation($day['focus'], $day['change']),
        'local-seed',
        'demo',
        $clock->now(),
        $clock,
    );
    $entryCount++;
}

$milestoneSpecs = [
    [$today->minusDays(20), 'Started a gentler evening routine before bed.', MilestoneCategory::Lifestyle],
    [$today->minusDays(11), 'Had an honest conversation with someone close.', MilestoneCategory::Relationship],
    [$today->minusDays(4), 'Kept a medication appointment and asked a follow-up question.', MilestoneCategory::Medication],
];

$milestoneCount = 0;
foreach ($milestoneSpecs as [$date, $description, $category]) {
    $input = MilestoneInput::of($date, $description, $category);
    $submission = MilestoneSubmission::of([
        'date' => $date->toIso(),
        'description' => $description,
        'category' => $category->value,
    ]);
    $validation = MilestoneValidation::accepted($input, $submission);
    $result = $milestones->create($owner, $validation, $clock);
    if (!$result->isOk()) {
        fwrite(STDERR, 'Failed milestone: ' . $result->message() . "\n");
        exit(1);
    }
    $milestoneCount++;
}

fwrite(STDOUT, "Seeded {$entryCount} diary entries and {$milestoneCount} milestones for local@preview.test.\n");
fwrite(STDOUT, "Sign in, then try:\n");
fwrite(STDOUT, "  http://127.0.0.1:8080/summary\n");
fwrite(STDOUT, "  http://127.0.0.1:8080/bright-spots\n");
fwrite(STDOUT, "  http://127.0.0.1:8080/calendar\n");
