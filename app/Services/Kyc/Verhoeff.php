<?php

declare(strict_types=1);

namespace App\Services\Kyc;

/**
 * Verhoeff checksum (dihedral group D5), used by UIDAI for the last digit of an Aadhaar number.
 *   Verhoeff::validate('234123412346')  // true when the check digit is correct
 *   Verhoeff::checkDigit('23412341234')  // '6'
 */
final class Verhoeff
{
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 7, 8, 6, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    private const INV = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

    public static function validate(string $number): bool
    {
        if ($number === '' || !ctype_digit($number)) {
            return false;
        }
        $c = 0;
        foreach (array_reverse(str_split($number)) as $i => $digit) {
            $c = self::D[$c][self::P[$i % 8][(int) $digit]];
        }
        return $c === 0;
    }

    /** Check digit to append to $number (digits only). */
    public static function checkDigit(string $number): string
    {
        if ($number === '' || !ctype_digit($number)) {
            throw new \InvalidArgumentException('Digits only.');
        }
        $c = 0;
        foreach (array_reverse(str_split($number)) as $i => $digit) {
            $c = self::D[$c][self::P[($i + 1) % 8][(int) $digit]];
        }
        return (string) self::INV[$c];
    }
}
