<?php

declare(strict_types=1);

namespace App\Support;

/** GST state / UT codes (used for customers.state_code and the GSTIN state check). */
final class IndianStates
{
    public const HOME = '32'; // Kerala

    public const ALL = [
        '35' => 'Andaman and Nicobar Islands', '37' => 'Andhra Pradesh', '12' => 'Arunachal Pradesh', '18' => 'Assam',
        '10' => 'Bihar', '04' => 'Chandigarh', '22' => 'Chhattisgarh', '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '07' => 'Delhi', '30' => 'Goa', '24' => 'Gujarat', '06' => 'Haryana', '02' => 'Himachal Pradesh',
        '01' => 'Jammu and Kashmir', '20' => 'Jharkhand', '29' => 'Karnataka', '32' => 'Kerala', '38' => 'Ladakh',
        '31' => 'Lakshadweep', '23' => 'Madhya Pradesh', '27' => 'Maharashtra', '14' => 'Manipur', '17' => 'Meghalaya',
        '15' => 'Mizoram', '13' => 'Nagaland', '21' => 'Odisha', '34' => 'Puducherry', '03' => 'Punjab',
        '08' => 'Rajasthan', '11' => 'Sikkim', '33' => 'Tamil Nadu', '36' => 'Telangana', '16' => 'Tripura',
        '09' => 'Uttar Pradesh', '05' => 'Uttarakhand', '19' => 'West Bengal', '97' => 'Other Territory',
    ];

    /** @return array<string, string> code => name, sorted by name (for <select>) */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => $name) {
            $out[str_pad((string) $code, 2, '0', STR_PAD_LEFT)] = $name;
        }
        asort($out);
        return $out;
    }

    public static function name(?string $code): string
    {
        return self::ALL[(string) $code] ?? '';
    }

    public static function rule(): string
    {
        return 'in:' . implode(',', array_map('strval', array_keys(self::ALL)));
    }
}
