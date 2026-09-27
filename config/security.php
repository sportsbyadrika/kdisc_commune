<?php

declare(strict_types=1);

/*
 * Response headers added to every response by App\Core\App (a response may set its own value first — PDFs and
 * KYC files send a stricter CSP).
 *
 * CSP: all scripts/styles are self-hosted. Alpine.js (standard build) needs 'unsafe-eval' — see
 * docs/DEPLOYMENT.md "Content Security Policy" for why the CSP build is not used yet. Seat maps position elements
 * with inline style attributes, hence 'unsafe-inline' for styles only (never for scripts). Google Fonts is the
 * only third party.
 */
return [
    // Strict-Transport-Security on HTTPS responses (keep on in production; the vhost should redirect :80 → :443).
    'hsts' => true,
    'hsts_max_age' => 31536000,

    // Only honour X-Forwarded-Proto / X-Forwarded-For from a reverse proxy you control (TRUSTED_PROXIES = comma
    // separated IPs, or "*" behind a load balancer that always overwrites these headers).
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    // Route rate limits (throttle:… middleware). Never disable in production.
    'rate_limits' => (bool) env('RATE_LIMITS', true),

    // Pages that may use the camera (document capture with the phone/webcam, QR check-in). Everything else gets
    // camera=() in Permissions-Policy.
    'camera_paths' => ['/my/documents', '/my/profile/wizard*', '/staff/visitors*', '/staff/checkin*'],

    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Content-Security-Policy' => implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-src 'self'", // inline PDF viewer for KYC documents / payment proofs
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]),
    ],
];
