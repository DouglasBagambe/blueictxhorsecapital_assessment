<?php
declare(strict_types=1);

final class ReportRepository
{
    private const TRANSITIONS = [
        'Submitted' => ['Under Review'],
        'Under Review' => ['Resolved', 'Rejected'],
        'Resolved' => [],
        'Rejected' => [],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $description, ?string $locationText): array
    {
        $reference = 'CRM-' . strtoupper(bin2hex(random_bytes(3)));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ussd_reports (reference, description, location_text, status)
             VALUES (:reference, :description, :location_text, :status)
             RETURNING reference, status, created_at'
        );

        $stmt->execute([
            ':reference' => $reference,
            ':description' => $description,
            ':location_text' => $locationText,
            ':status' => 'Submitted',
        ]);

        return $stmt->fetch();
    }

    public function findByReference(string $reference): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT reference, description, location_text, status, created_at
             FROM ussd_reports
             WHERE reference = :reference'
        );

        $stmt->execute([
            ':reference' => strtoupper(trim($reference)),
        ]);

        $report = $stmt->fetch();

        return $report ?: null;
    }

    public function updateStatus(
        string $reference,
        string $newStatus,
        string $updatedBy
    ): array {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, reference, status
                 FROM ussd_reports
                 WHERE reference = :reference
                 FOR UPDATE'
            );

            $stmt->execute([
                ':reference' => strtoupper(trim($reference)),
            ]);

            $report = $stmt->fetch();

            if (!$report) {
                throw new RuntimeException('Report not found.');
            }

            $currentStatus = $report['status'];

            if (!array_key_exists($currentStatus, self::TRANSITIONS)) {
                throw new RuntimeException('Current report status is invalid.');
            }

            if (!in_array($newStatus, self::TRANSITIONS[$currentStatus], true)) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid transition: %s -> %s',
                        $currentStatus,
                        $newStatus
                    )
                );
            }

            $update = $this->pdo->prepare(
                'UPDATE ussd_reports
                 SET status = :status,
                     updated_at = NOW()
                 WHERE id = :id'
            );

            $update->execute([
                ':status' => $newStatus,
                ':id' => $report['id'],
            ]);

            $history = $this->pdo->prepare(
                'INSERT INTO report_status_history
                 (report_id, old_status, new_status, updated_by)
                 VALUES (:report_id, :old_status, :new_status, :updated_by)'
            );

            $history->execute([
                ':report_id' => $report['id'],
                ':old_status' => $currentStatus,
                ':new_status' => $newStatus,
                ':updated_by' => $updatedBy,
            ]);

            $this->pdo->commit();

            return [
                'reference' => $report['reference'],
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
