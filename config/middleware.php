<?php

declare(strict_types=1);

use App\Middleware;

return [
    // Run on every matched route, before route middleware.
    'global' => ['csrf'],

    // Usage in routes: ->middleware('auth.staff', 'role:centre_manager,state_admin')
    'aliases' => [
        'csrf' => Middleware\VerifyCsrfToken::class,
        'auth.staff' => Middleware\StaffAuth::class,
        'auth.visitor' => Middleware\VisitorAuth::class,
        'guest' => Middleware\Guest::class,          // guest:staff | guest:visitor
        'role' => Middleware\Role::class,            // role:receptionist,centre_manager
        'can' => Middleware\Can::class,              // can:layout.design
    ],
];
