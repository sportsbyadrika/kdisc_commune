<?php

declare(strict_types=1);

namespace App\Enums;

/** customer_documents.doc_type (spec 4.3 "Documents"). */
enum DocumentType: string
{
    use EnumHelpers;

    case Aadhaar = 'aadhaar';
    case Passport = 'passport';
    case Pan = 'pan';
    case GstCertificate = 'gst_certificate';
    case Tan = 'tan';
    case Photo = 'photo';
    case SignatoryAadhaar = 'signatory_aadhaar';
    case Registration = 'registration';
    case AuthorisationLetter = 'authorisation_letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Aadhaar => 'Aadhaar (masked preferred)',
            self::Passport => 'Passport',
            self::Pan => 'PAN card',
            self::GstCertificate => 'GST certificate',
            self::Tan => 'TAN proof',
            self::Photo => 'Photograph',
            self::SignatoryAadhaar => "Signatory's Aadhaar",
            self::Registration => 'Registration / incorporation certificate',
            self::AuthorisationLetter => 'Authorisation letter',
            self::Other => 'Other',
        };
    }
}
