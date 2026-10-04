<?php
declare(strict_types=1);

return [
    'db' => [
        'dsn' => getenv('DATABASE_DSN') ?: 'pgsql:host=127.0.0.1;port=5433;dbname=crime_assessment',
        'user' => getenv('DATABASE_USER') ?: 'postgres',
        'password' => getenv('DATABASE_PASSWORD') ?: '',
    ],
    'log_path' => __DIR__ . '/logs/ussd.log',
];
