<?php

namespace App\Services;

/** Canonical BD mobile form: 01XXXXXXXXX (01... and +8801... are the same number). */
class PhoneNormalizer
{
    public static function normalize(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
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
