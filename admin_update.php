<?php
declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/ReportRepository.php';

function fail(string $message, int $exitCode = 2): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit($exitCode);
}

$options = getopt('', ['ref:', 'status:', 'by:']);

if ($options === false || count($options) !== 3 || count(array_diff(array_keys($options), ['ref', 'status', 'by'])) > 0) {
    fail('Usage: php admin_update.php --ref=CRM-ABC123 --status="Under Review" --by="AdminUser"');
}

$reference = strtoupper(trim((string)($options['ref'] ?? '')));
$status = trim((string)($options['status'] ?? ''));
$updatedBy = trim((string)($options['by'] ?? ''));

if (!preg_match('/^CRM-[A-F0-9]{6}$/', $reference)) {
    fail('Reference must use the format CRM-ABC123.');
}

if (!in_array($status, ['Submitted', 'Under Review', 'Resolved', 'Rejected'], true)) {
    fail('Status must be Submitted, Under Review, Resolved, or Rejected.');
}

if ($updatedBy === '' || mb_strlen($updatedBy) > 120 || preg_match('/[\r\n\0]/', $updatedBy)) {
    fail('Updated-by value is required, must be at most 120 characters, and cannot contain line breaks.');
}

try {
    $config = require __DIR__ . '/config.php';
    $repository = new ReportRepository(Database::connect($config['db']));
    $result = $repository->updateStatus($reference, $status, $updatedBy);

    fwrite(
        STDOUT,
        sprintf(
            "Success: %s changed from %s to %s by %s.\n",
            $result['reference'],
            $result['old_status'],
            $result['new_status'],
            $updatedBy
        )
    );
} catch (RuntimeException $e) {
    fail($e->getMessage(), 3);
} catch (Throwable $e) {
    fail('Database operation failed.', 4);
}
