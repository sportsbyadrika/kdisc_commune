<?php

declare(strict_types=1);

/*
 * App\Services\Notify\Mailer. Symfony Mailer DSN:
 *   log://default                          development: storage/logs/mail.log + storage/mail/*.eml|*.html
 *   smtp://user:pass@smtp.example.com:587  production
 *   null://null                            discard everything
 */
return [
    'dsn' => env('MAIL_DSN', 'log://default'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@commune.test'),
        'name' => env('MAIL_FROM_NAME', 'Commune Kottarakara'),
    ],
    'reply_to' => env('MAIL_REPLY_TO', ''),
    'log_file' => dirname(__DIR__) . '/storage/logs/mail.log',
    'mail_dir' => dirname(__DIR__) . '/storage/mail',

    // Email clients ignore external CSS, so the branded layout (resources/views/emails/layout.php) uses inline
    // styles. Keep these in sync with the @theme tokens in resources/css/app.css.
    'theme' => [
        'brand' => '#1d4ed8',      // --color-brand-600
        'brand_dark' => '#0b1b3f', // --color-brand-900
        'accent' => '#e11d74',     // --color-accent-500
        'surface' => '#f7f7f9',    // --color-surface
        'ink' => '#111827',        // --color-ink
        'muted' => '#6b7280',      // --color-muted
        'line' => '#e5e7eb',       // --color-line
        'white' => '#ffffff',
    ],
];
