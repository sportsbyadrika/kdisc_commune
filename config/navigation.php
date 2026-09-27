<?php

declare(strict_types=1);

/*
 * Menus. 'route' is a named route (rendered only when it exists — so later
 * batches can list items before implementing them); 'href' is a literal path.
 * Staff items: 'can' = ability from App\Enums\StaffRole::abilities().
 * Items whose route is not yet defined render as disabled "Soon" entries.
 */
return [
    'site' => [
        ['label' => 'Spaces', 'route' => 'spaces', 'children' => [
            ['label' => 'Flexi / Hot desks', 'href' => '/spaces#flexi', 'icon' => 'armchair', 'text' => 'Drop in by the day or month'],
            ['label' => 'Dedicated seats', 'href' => '/spaces#dedicated', 'icon' => 'monitor', 'text' => 'Your own desk in a quiet room'],
            ['label' => 'Executive cabins', 'href' => '/spaces#cabin', 'icon' => 'door-open', 'text' => 'Private cabins for three'],
            ['label' => 'Conference room', 'href' => '/spaces#conference', 'icon' => 'presentation', 'text' => 'Nine seats, hourly'],
            ['label' => 'Explore the building', 'route' => 'spaces.explore', 'icon' => 'building-2', 'text' => 'Live seat map — pick your seat'],
        ]],
        ['label' => 'Facilities', 'route' => 'facilities', 'children' => [
            ['label' => 'Included with every seat', 'href' => '/facilities#included', 'icon' => 'wifi', 'text' => 'Wi-Fi, AC, power backup, pantry'],
            ['label' => 'Add-ons', 'href' => '/facilities#addons', 'icon' => 'plus', 'text' => 'Lockers, parking, printing & more'],
            ['label' => 'Around the building', 'href' => '/facilities#landmarks', 'icon' => 'map-pin', 'text' => 'Entry, lift, fire exits, access'],
        ]],
        ['label' => 'Pricing', 'route' => 'pricing'],
        ['label' => 'About', 'route' => 'about'],
        ['label' => 'Contact', 'route' => 'contact'],
    ],

    'staff' => [
        ['section' => 'Overview'],
        ['label' => 'Dashboard', 'route' => 'staff.dashboard', 'icon' => 'layout-dashboard', 'can' => 'dashboard.view'],
        ['section' => 'Front desk'],
        ['label' => 'Visitors', 'route' => 'staff.visitors.index', 'icon' => 'users', 'can' => 'visitors.view'],
        ['label' => 'New visitor', 'route' => 'staff.visitors.create', 'icon' => 'user-plus', 'can' => 'visitors.register'],
        ['label' => 'KYC queue', 'route' => 'staff.kyc.index', 'icon' => 'shield-check', 'can' => 'kyc.verify'],
        ['label' => 'Space Explorer', 'route' => 'staff.explorer', 'icon' => 'map', 'can' => 'space.explore'],
        ['label' => 'Bookings', 'route' => 'staff.bookings.index', 'icon' => 'calendar-check', 'can' => 'bookings.view'],
        ['label' => 'Check-in / out', 'route' => 'staff.checkins.index', 'icon' => 'scan-line', 'can' => 'checkins.manage'],
        ['section' => 'Centre'],
        ['label' => 'Layout & pricing', 'route' => 'staff.layout.index', 'icon' => 'pen-tool', 'can' => 'layout.design'],
        ['label' => 'Facilities', 'route' => 'staff.facilities.index', 'icon' => 'sparkles', 'can' => 'facilities.manage'],
        ['label' => 'Bulk upload', 'route' => 'staff.imports.index', 'icon' => 'file-spreadsheet', 'can' => 'bulk.import'],
        ['label' => 'Staff users', 'route' => 'staff.users.index', 'icon' => 'users-round', 'can' => 'staff.manage'],
        ['section' => 'Finance'],
        ['label' => 'Finance overview', 'route' => 'staff.finance.dashboard', 'icon' => 'chart-pie', 'can' => 'reports.finance'],
        ['label' => 'Verify payments', 'route' => 'staff.payments.index', 'icon' => 'wallet', 'can' => 'payments.view'],
        ['label' => 'Invoices & receipts', 'route' => 'staff.invoices.index', 'icon' => 'receipt-indian-rupee', 'can' => 'invoices.view'],
        ['label' => 'Registers', 'route' => 'staff.registers.index', 'icon' => 'book-open-text', 'can' => 'reports.finance'],
        ['label' => 'Finance settings', 'route' => 'staff.finance.settings', 'icon' => 'settings', 'can' => 'finance.settings'],
        ['label' => 'Reports', 'route' => 'staff.reports.index', 'icon' => 'chart-column', 'can' => 'reports.view'],
    ],
];
