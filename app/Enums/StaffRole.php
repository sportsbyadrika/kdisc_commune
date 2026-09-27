<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Staff console roles (spec 1.1). Permissions are expressed as abilities so
 * routes can use either `role:` or `can:` middleware:
 *
 *   ->middleware('role:centre_manager')          // exact roles
 *   ->middleware('can:bookings.approve')          // ability (preferred for features)
 *   StaffRole::from($user['role'])->can('layout.design')
 */
enum StaffRole: string
{
    use EnumHelpers;

    case Receptionist = 'receptionist';
    case CentreManager = 'centre_manager';
    case FinanceAdmin = 'finance_admin';
    case StateAdmin = 'state_admin';

    public function label(): string
    {
        return match ($this) {
            self::Receptionist => 'Receptionist',
            self::CentreManager => 'Centre Manager',
            self::FinanceAdmin => 'Finance Admin',
            self::StateAdmin => 'State Admin',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Receptionist => 'info',
            self::CentreManager => 'brand',
            self::FinanceAdmin => 'success',
            self::StateAdmin => 'warning',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Receptionist => 'Front desk: registrations, bookings, check-in/out, payments',
            self::CentreManager => 'Runs the centre: approvals, KYC, layout & pricing',
            self::FinanceAdmin => 'Payments verification, GST invoices, receipts, reports',
            self::StateAdmin => 'Read-only dashboards and reports across the network',
        };
    }

    /** @return list<string> */
    public function abilities(): array
    {
        $reception = [
            'dashboard.view', 'visitors.view', 'visitors.register', 'documents.upload', 'documents.view',
            'bookings.view', 'bookings.create', 'bookings.cancel', 'bookings.extend', 'checkins.manage', 'payments.log', 'space.explore', 'invoices.view',
        ];
        return match ($this) {
            self::Receptionist => $reception,
            self::CentreManager => [
                ...$reception,
                'bookings.approve', 'space.override', 'kyc.verify', 'seats.handover', 'renewals.view', 'dues.view', 'payments.void', 'payments.view', 'finance.settings',
                'layout.design', 'pricing.manage', 'facilities.manage', 'staff.manage', 'imports.manage', 'reports.view', 'audit.view',
            ],
            self::FinanceAdmin => [
                'dashboard.view', 'visitors.view', 'bookings.view', 'payments.view', 'payments.verify',
                'invoices.manage', 'invoices.view', 'receipts.manage', 'credit_notes.manage', 'deposits.refund', 'reports.view', 'reports.finance', 'dues.view',
                'finance.settings',
            ],
            self::StateAdmin => [
                'dashboard.view', 'visitors.view', 'bookings.view', 'payments.view', 'invoices.view', 'reports.view', 'reports.finance', 'space.explore',
                'dues.view', 'renewals.view', 'audit.view',
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** Role-specific dashboard partial under resources/views/staff/dashboard/. */
    public function dashboardView(): string
    {
        return 'staff/dashboard/' . $this->value;
    }
}
