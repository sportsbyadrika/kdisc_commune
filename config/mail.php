<?php

declare(strict_types=1);

// Symfony Mailer DSN, e.g. smtp://user:pass@smtp.example.com:587 — "null://null" discards mail.
return [
    'dsn' => env('MAIL_DSN', 'null://null'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@commune.test'),
        'name' => env('MAIL_FROM_NAME', 'Commune Kottarakara'),
    ],
];
