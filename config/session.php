<?php

declare(strict_types=1);

/*
 * Staff and visitors use separate session cookies. The first context whose
 * prefix matches the request path is used; 'site' is the fallback.
 */
return [
    'defaults' => [
        'lifetime' => 0,                                  // browser-session cookie
        'idle_timeout' => (int) env('SESSION_IDLE_MINUTES', 120) * 60,
        // hard cap on one session's age, however active (re-sign-in after this)
        'absolute_timeout' => (int) env('SESSION_ABSOLUTE_HOURS', 12) * 3600,
        'secure' => (bool) env('SESSION_SECURE_COOKIE', false), // forced on when request is HTTPS
        'samesite' => 'Lax',
        'domain' => env('SESSION_DOMAIN', ''),
        'save_path' => env('SESSION_PATH', dirname(__DIR__) . '/storage/sessions'),
    ],
    'contexts' => [
        'staff' => ['prefix' => '/staff', 'name' => 'commune_staff', 'path' => '/staff', 'idle_timeout' => 60 * 60],
        'site' => ['prefix' => '', 'name' => 'commune_session', 'path' => '/'],
    ],
];
