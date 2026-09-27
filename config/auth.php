<?php

declare(strict_types=1);

return [
    'guards' => [
        'staff' => ['table' => 'staff_users', 'active_column' => 'is_active', 'login_route' => 'staff.login', 'home_route' => 'staff.dashboard'],
        'visitor' => ['table' => 'accounts', 'active_column' => 'status', 'login_route' => 'portal.login', 'home_route' => 'home'],
    ],
    // Login throttling (App\Services\Auth\LoginThrottle)
    'throttle' => [
        'max_attempts' => 5,       // per email + IP
        'max_attempts_ip' => 20,   // per IP across all emails
        'decay_minutes' => 15,
    ],
    'password' => [
        'algo' => PASSWORD_ARGON2ID,
        'options' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
    ],
];
