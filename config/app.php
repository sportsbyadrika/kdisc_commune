<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Commune'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8000'),
    'timezone' => 'Asia/Kolkata',
    'locale' => 'en_IN',
    'log_level' => env('LOG_LEVEL', 'info'),

    // Encryption key for sensitive data (Aadhaar). Generate: php bin/console key:generate
    'key' => env('APP_KEY', ''),

    // Private upload root (KYC documents). Never inside public/. Tests point this at a temp dir.
    'uploads_path' => env('UPLOADS_PATH', dirname(__DIR__) . '/storage/uploads'),

    // Loaded in order by the router. Earlier files win on identical paths.
    'route_files' => ['site.php', 'portal.php', 'staff.php'],

    // Organisation details used in headers / footers / PDFs.
    'org' => [
        'name' => 'Kerala Development and Innovation Strategic Council (K-DISC)',
        'short' => 'K-DISC',
        'brand' => 'Commune',
        'tagline' => 'Work near home',
        'centre' => 'Kottarakara',
        'address' => 'Commune Workspace, Kottarakara, Kollam, Kerala 691506',
        'phone' => '+91 474 000 0000',
        'email' => 'commune.ktr@kdisc.kerala.gov.in',
        'hours' => 'Mon–Sat · 8:00 am – 8:00 pm',
        'map_url' => 'https://maps.google.com/?q=Kottarakara,Kerala',
    ],
];
