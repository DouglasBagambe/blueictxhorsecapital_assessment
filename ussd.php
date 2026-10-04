<?php
declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/ReportRepository.php';
require __DIR__ . '/src/AuditLogger.php';
require __DIR__ . '/src/UssdEngine.php';

$config = require __DIR__ . '/config.php';

$startedAt = hrtime(true);

$sessionId = (string)($_POST['sessionId'] ?? $_GET['sessionId'] ?? 'anonymous');
$text = (string)($_POST['text'] ?? $_GET['text'] ?? '');

$logger = new AuditLogger($config['log_path']);

try {
    $pdo = Database::connect($config['db']);
    $reports = new ReportRepository($pdo);
    $engine = new UssdEngine($reports);

    $result = $engine->handle($text);
    $response = $result['response'];

    header('Content-Type: text/plain; charset=utf-8');
    echo $response;

    $logger->log(
        $sessionId,
        $text,
        $response,
        $result['action'],
        (hrtime(true) - $startedAt) / 1_000_000
    );
} catch (Throwable $e) {
    http_response_code(500);

    $response = 'END Service temporarily unavailable.';

    header('Content-Type: text/plain; charset=utf-8');
    echo $response;

    $logger->log(
        $sessionId,
        $text,
        $response,
        'internal_error',
        (hrtime(true) - $startedAt) / 1_000_000
    );
}
