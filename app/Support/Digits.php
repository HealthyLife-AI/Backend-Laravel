<?php

namespace App\Support;

/** Arabic-Indic (٠-٩) and Persian (۰-۹) digits to 0-9: phones and usernames are typed on Arabic keyboards. */
final class Digits
{
    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function toLatin(string $value): string
    {
        return strtr($value, self::MAP);
    }
}
