<?php

declare(strict_types=1);

namespace App\Enums;

/** Sub-category of a customer (spec 4.3). The institution list is still "to confirm" with K-DISC. */
enum CustomerSubCategory: string
{
    use EnumHelpers;

    // Individual
    case RemoteWorker = 'remote_worker';
    case SelfEmployed = 'self_employed';
    case Student = 'student';
    case Freelancer = 'freelancer';
    // Institution
    case Company = 'company';
    case Startup = 'startup';
    case Institution = 'institution';
    case Ngo = 'ngo';
    case GovtBody = 'govt_body';

    public function label(): string
    {
        return match ($this) {
            self::RemoteWorker => 'Remote worker',
            self::SelfEmployed => 'Self-employed',
            self::Student => 'Student',
            self::Freelancer => 'Freelancer',
            self::Company => 'Company',
            self::Startup => 'Startup',
            self::Institution => 'Institution',
            self::Ngo => 'NGO',
            self::GovtBody => 'Govt. body',
        };
    }

    public function type(): CustomerType
    {
        return match ($this) {
            self::RemoteWorker, self::SelfEmployed, self::Student, self::Freelancer => CustomerType::Individual,
            default => CustomerType::Institution,
        };
    }

    /** @return array<string, string> */
    public static function optionsFor(CustomerType $type): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if ($case->type() === $type) {
                $out[$case->value] = $case->label();
            }
        }
        return $out;
    }
}
