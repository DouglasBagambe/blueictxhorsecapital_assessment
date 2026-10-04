<?php
declare(strict_types=1);

final class UssdEngine
{
    private array $menu;

    public function __construct(private ReportRepository $reports)
    {
        $this->menu = [
            'prompt' => "Welcome to Crime Reporter\n1. Submit report\n2. Check report status",
            'children' => [
                '1' => [
                    'prompt' => 'Enter a short description of the incident:',
                    'capture' => 'description',
                    'next' => [
                        'prompt' => 'Enter location or landmark:',
                        'capture' => 'location_text',
                        'handler' => 'submitReport',
                    ],
                ],
                '2' => [
                    'prompt' => 'Enter your report reference:',
                    'capture' => 'reference',
                    'handler' => 'checkStatus',
                ],
            ],
        ];
    }

    public function handle(string $text): array
    {
        $tokens = $this->tokenize($text);

        if (in_array('0', $tokens, true)) {
            return [
                'response' => "CON " . $this->menu['prompt'],
                'action' => 'reset_to_main_menu',
            ];
        }

        if ($tokens === []) {
            return [
                'response' => "CON " . $this->menu['prompt'],
                'action' => 'show_main_menu',
            ];
        }

        $node = $this->menu;
        $captured = [];
        $index = 0;

        $choice = $tokens[$index++] ?? null;

        if (!isset($node['children'][$choice])) {
            return [
                'response' => "CON Invalid selection.\n" . $this->menu['prompt'],
                'action' => 'invalid_menu_selection',
            ];
        }

        $node = $node['children'][$choice];

        while (true) {
            if (isset($node['capture'])) {
                if (!array_key_exists($index, $tokens)) {
                    return [
                        'response' => 'CON ' . $node['prompt'],
                        'action' => 'await_' . $node['capture'],
                    ];
                }

                $captured[$node['capture']] = $this->cleanInput($tokens[$index]);
                $index++;
            }

            if (isset($node['handler'])) {
                return $this->{$node['handler']}($captured);
            }

            if (isset($node['next'])) {
                $node = $node['next'];
                continue;
            }

            return [
                'response' => 'END Unable to continue.',
                'action' => 'invalid_menu_definition',
            ];
        }
    }

    private function submitReport(array $captured): array
    {
        $description = $captured['description'] ?? '';
        $location = $captured['location_text'] ?? '';

        if ($description === '' || $location === '') {
            return [
                'response' => 'END Description and location are required.',
                'action' => 'submission_validation_failed',
            ];
        }

        $report = $this->reports->create($description, $location);

        return [
            'response' => sprintf(
                'END Report submitted successfully. Reference: %s',
                $report['reference']
            ),
            'action' => 'report_submitted',
        ];
    }

    private function checkStatus(array $captured): array
    {
        $reference = strtoupper($captured['reference'] ?? '');

        if (!preg_match('/^CRM-[A-F0-9]{6}$/', $reference)) {
            return [
                'response' => 'END Invalid report reference format.',
                'action' => 'invalid_reference_format',
            ];
        }

        $report = $this->reports->findByReference($reference);

        if (!$report) {
            return [
                'response' => 'END Report not found.',
                'action' => 'report_not_found',
            ];
        }

        $template = 'Dear Citizen, your report [REFERENCE] is currently [STATUS]. Submitted on: [DATE].';

        $message = strtr($template, [
            '[REFERENCE]' => $this->safeTemplateValue($report['reference']),
            '[STATUS]' => $this->safeTemplateValue($report['status']),
            '[DATE]' => $this->safeTemplateValue(
                (new DateTimeImmutable($report['created_at']))->format('Y-m-d H:i:s')
            ),
        ]);

        return [
            'response' => 'END ' . $message,
            'action' => 'status_checked',
        ];
    }

    private function tokenize(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return array_values(array_map('trim', explode('*', $text)));
    }

    private function cleanInput(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);

        return trim(mb_substr($value, 0, 500));
    }

    private function safeTemplateValue(string $value): string
    {
        return str_replace(
            ["\r", "\n", '[', ']'],
            [' ', ' ', '(', ')'],
            $value
        );
    }
}
