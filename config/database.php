<?php

declare(strict_types=1);

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'socket' => env('DB_SOCKET', ''),
            'database' => env('DB_DATABASE', 'commune'),
            'username' => env('DB_USERNAME', 'commune'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'timezone' => '+05:30',
        ],
    ],
    'migrations_path' => 'database/migrations',
];
