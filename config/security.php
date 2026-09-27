<?php

declare(strict_types=1);

/*
 * Response headers added to every response by App\Core\App.
 * CSP: all scripts/styles are self-hosted. Alpine.js (standard build) needs
 * 'unsafe-eval'; seat maps position elements with inline style attributes,
 * hence 'unsafe-inline' for styles. Google Fonts is the only third party.
 */
return [
    'hsts' => true,
    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
        'Content-Security-Policy' => implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-src https://www.google.com https://maps.google.com",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]),
    ],
];
