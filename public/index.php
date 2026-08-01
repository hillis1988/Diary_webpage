<?php

declare(strict_types=1);

use Diary\Access\AccessControlService;
use Diary\Ai\AiConfig;
use Diary\Ai\AiFeedbackService;
use Diary\Ai\AiSummaryService;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Ai\CurlHttpTransport;
use Diary\Ai\HttpsFeedbackProvider;
use Diary\Ai\HttpsSummaryProvider;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserRepository;
use Diary\Diary\CalendarService;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\DiaryService;
use Diary\Http\AuthorisationMiddleware;
use Diary\Http\CalendarController;
use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\DiaryEntryController;
use Diary\Http\HomePageController;
use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\MilestoneController;
use Diary\Http\Pipeline;
use Diary\Http\Request;
use Diary\Http\Router;
use Diary\Http\SecurityHeadersMiddleware;
use Diary\Http\SessionResolverMiddleware;
use Diary\Http\SummaryController;
use Diary\Milestone\MilestoneInputValidator;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Storage\StorageException;
use Diary\Support\SystemClock;

/**
 * Front controller. The only PHP file inside the document root.
 *
 * Responsibilities:
 *   1. Establish the application paths (src/, templates/, config/ all live above the document root).
 *   2. Load the Composer autoloader (vendor/ is committed, so deployment is a file copy).
 *   3. Load config/config.php and fail closed with a generic message if it is missing or malformed.
 *   4. Assemble the middleware pipeline in its fixed order and hand it the request.
 *
 * The order in Pipeline::fixedOrder() is the design's request pipeline: HTTPS redirect,
 * security headers, CSRF check, session resolution, authorisation, handler.
 */

const DIARY_ROOT = __DIR__ . '/..';
const DIARY_SRC = DIARY_ROOT . '/src';
const DIARY_TEMPLATES = DIARY_ROOT . '/templates';
const DIARY_CONFIG = DIARY_ROOT . '/config';

/**
 * Render a generic failure page. Never includes exception text, SQL or configuration values:
 * detail belongs in the server log, not in the response.
 */
$diaryFail = static function (string $message): never {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Something went wrong</title></head><body><h1>Something went wrong</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</p></body></html>';
    exit;
};

$autoload = DIARY_ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('Bootstrap failed: vendor/autoload.php is missing. Run "composer install" and deploy vendor/.');
    $diaryFail('Please try again shortly.');
}
require $autoload;

$configFile = DIARY_CONFIG . '/config.php';
if (!is_file($configFile)) {
    error_log('Bootstrap failed: config/config.php is missing. Copy config/config.example.php and fill it in.');
    $diaryFail('Please try again shortly.');
}

/** @var mixed $config */
$config = require $configFile;
if (!is_array($config)) {
    error_log('Bootstrap failed: config/config.php did not return an array.');
    $diaryFail('Please try again shortly.');
}

// Detailed errors are only ever shown outside production.
$isProduction = ($config['app']['env'] ?? 'production') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Dates are handled as explicit UTC values; the default keeps any implicit call predictable.
date_default_timezone_set('UTC');

$appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
$baseUrl = is_string($appConfig['base_url'] ?? null) ? $appConfig['base_url'] : 'https://royhillis.co.uk';
$forceHttps = (bool) ($appConfig['force_https'] ?? true);
$trustForwardedProto = (bool) ($appConfig['trust_forwarded_proto'] ?? false);

// The CSRF signing secret is derived from the master key, so there is no second secret to
// deploy and no path where a missing key silently turns the CSRF check into a no-op.
$masterKeyBase64 = $config['encryption']['master_key_base64'] ?? null;
$masterKey = is_string($masterKeyBase64) && $masterKeyBase64 !== ''
    ? base64_decode($masterKeyBase64, true)
    : false;
if (!is_string($masterKey) || strlen($masterKey) < 32) {
    error_log('Bootstrap failed: encryption.master_key_base64 must hold a base64-encoded 32-byte key.');
    $diaryFail('Please try again shortly.');
}

$clock = new SystemClock();

// Session resolution reads the sessions table on every request, so the connection is
// established here rather than inside a middleware. An unreachable database is fatal:
// carrying on would mean every request resolved to anonymous, which reads as "signed out"
// and would invite a retry loop rather than showing that something is wrong.
try {
    $pdo = ConnectionFactory::fromConfig($config);
} catch (StorageException $exception) {
    error_log('Bootstrap failed: ' . $exception->getMessage());
    $diaryFail('Please try again shortly.');
}

$authService = new AuthService(
    users: new UserRepository($pdo),
    passwordPolicy: new DefaultPasswordPolicy(),
    clock: $clock,
    sessions: new SessionRepository($pdo),
    auditLog: new AuditLogRepository($pdo),
    // Derived from the master key with its own HKDF label, as the CSRF secret is: the
    // audit trail's address hashes need a key, not a second value to deploy and lose.
    ipHasher: new IpHasher(hash_hkdf('sha256', $masterKey, 32, 'diary-ip-hash-v1')),
);

$accessControl = new AccessControlService(
    clock: $clock,
    auditLog: new AuditLogRepository($pdo),
    ipHasher: new IpHasher(hash_hkdf('sha256', $masterKey, 32, 'diary-ip-hash-v1')),
);

$request = Request::fromGlobals($_SERVER, $_GET, $_POST, $_COOKIE, $trustForwardedProto);

$csrfGuard = CsrfGuard::withMasterKey($masterKey, $clock);

$keyRing = new KeyRing($pdo, $masterKey, $clock);
$payloadCodec = new PayloadCodec(new Crypto($keyRing));
$diaryEntryRepository = new DiaryEntryRepository($pdo, $payloadCodec);
$diaryService = new DiaryService($diaryEntryRepository);

$aiConfig = AiConfig::fromConfig($config);
$aiFeedbackService = new AiFeedbackService(
    new HttpsFeedbackProvider(new CurlHttpTransport(), $aiConfig),
    new CbtRecommendationRepository($pdo, $payloadCodec),
    $aiConfig,
);

$router = new Router();

// Routes are registered here as controllers arrive.
$homePage = new HomePageController($accessControl);
$router->get('/', static fn (Request $r, array $params) => $homePage->show($r));

$diaryEntryPage = new DiaryEntryController(
    $accessControl,
    $diaryService,
    new DiaryInputValidator(),
    $csrfGuard,
    $clock,
    $aiFeedbackService,
);
$router->get(AccessControlService::DIARY_ENTRY_PATH, static fn (Request $r, array $params) => $diaryEntryPage->show($r));
$router->post(AccessControlService::DIARY_ENTRY_PATH, static fn (Request $r, array $params) => $diaryEntryPage->submit($r));
$router->post(DiaryEntryController::RETRY_FEEDBACK_PATH, static fn (Request $r, array $params) => $diaryEntryPage->retryFeedback($r));

$milestoneRepository = new MilestoneRepository($pdo, $payloadCodec);
$milestoneService = new MilestoneService($milestoneRepository);
$milestonePages = new MilestoneController(
    $accessControl,
    $milestoneService,
    new MilestoneInputValidator(),
    $csrfGuard,
    $clock,
);
$router->get(AccessControlService::MILESTONES_PATH, static fn (Request $r, array $params) => $milestonePages->list($r));
$router->get(MilestoneController::NEW_PATH, static fn (Request $r, array $params) => $milestonePages->showCreateForm($r));
$router->post(MilestoneController::NEW_PATH, static fn (Request $r, array $params) => $milestonePages->submitCreate($r));
$router->get(AccessControlService::MILESTONES_PATH . '/{id}/edit', static fn (Request $r, array $params) => $milestonePages->showEditForm($r, $params));
$router->post(AccessControlService::MILESTONES_PATH . '/{id}/edit', static fn (Request $r, array $params) => $milestonePages->submitEdit($r, $params));
$router->post(AccessControlService::MILESTONES_PATH . '/{id}/delete', static fn (Request $r, array $params) => $milestonePages->delete($r, $params));

$cbtRecommendationRepository = new CbtRecommendationRepository($pdo, $payloadCodec);
$calendarService = new CalendarService($diaryEntryRepository, $milestoneRepository);
$calendarPage = new CalendarController(
    $accessControl,
    $calendarService,
    $diaryService,
    $cbtRecommendationRepository,
    $clock,
);
$router->get(AccessControlService::CALENDAR_PATH, static fn (Request $r, array $params) => $calendarPage->show($r));

$aiSummaryService = new AiSummaryService(
    $diaryService,
    $milestoneService,
    new HttpsSummaryProvider(new CurlHttpTransport(), $aiConfig),
);
$summaryPage = new SummaryController($accessControl, $aiSummaryService, $clock);
$router->get(AccessControlService::SUMMARY_PATH, static fn (Request $r, array $params) => $summaryPage->show($r));

$pipeline = Pipeline::fixedOrder(
    new HttpsRedirectMiddleware($baseUrl, $forceHttps),
    new SecurityHeadersMiddleware(),
    new CsrfMiddleware($csrfGuard),
    new SessionResolverMiddleware($authService, $clock),
    new AuthorisationMiddleware($accessControl),
    $router,
);

$pipeline->handle($request)->send();
