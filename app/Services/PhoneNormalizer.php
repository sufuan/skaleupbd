<?php

namespace App\Services;

/** Canonical BD mobile form: 01XXXXXXXXX (01... and +8801... are the same number). */
class PhoneNormalizer
{
    public static function normalize(?string $phone): string
    {
        // Bangla digits (০–৯) type exactly like ASCII ones on BD phones.
        $phone = strtr((string) $phone, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $d = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($d, '880')) {
            $d = substr($d, 2); // 8801... -> 01...
        }
        if (strlen($d) === 10 && str_starts_with($d, '1')) {
            $d = '0'.$d;
        }
        return $d;
    }

    public static function isValid(?string $phone): bool
    {
        return (bool) preg_match('/^01\d{9}$/', self::normalize($phone));
    }
}
