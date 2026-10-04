<?php
declare(strict_types=1);

final class AuditLogger
{
    public function __construct(private string $path)
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
    }

    public function log(
        string $sessionId,
        string $userInput,
        string $response,
        string $action,
        float $executionTimeMs
    ): void {
        $entry = [
            'timestamp' => gmdate('c'),
            'session_id' => hash('sha256', $sessionId),
            'user_input' => $this->maskPii($this->sanitize($userInput)),
            'response_sent' => $this->maskPii($this->sanitize($response)),
            'action_taken' => $this->sanitize($action),
            'execution_time_ms' => round($executionTimeMs, 3),
        ];

        $json = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException('Unable to encode audit log entry.');
        }

        file_put_contents(
            $this->path,
            $json . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function sanitize(string $value): string
    {
        $value = str_replace(
            ["\r", "\n", "\0"],
            [' ', ' ', ''],
            $value
        );

        return trim(mb_substr($value, 0, 1000));
    }

    private function maskPii(string $value): string
    {
        $value = preg_replace(
            '/\b(?:house|plot|apartment|flat|room)\s*(?:no\.?\s*)?[A-Za-z0-9\-\/]+\b/i',
            '[REDACTED_ADDRESS]',
            $value
        );

        $value = preg_replace_callback(
            '/(?<!\d)(?:\+?\d[\d\s\-]{7,}\d)(?!\d)/',
            static function (array $matches): string {
                $digits = preg_replace('/\D+/', '', $matches[0]);

                if (strlen($digits) < 4) {
                    return '[REDACTED_PHONE]';
                }

                return '[PHONE_***' . substr($digits, -3) . ']';
            },
            $value
        );

        return $value;
    }
}
