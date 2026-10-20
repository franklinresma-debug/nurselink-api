<?php
namespace App\Services\Communications;

final class NotificationBrand
{
    public const NAME = 'LBS NurseLink';

    public static function text(?string $value): string
    {
        // Preserve URLs, addresses and identifiers; change human-readable brand mentions.
        return preg_replace('/(?<![\w@\/\\\\.\-])(?:LBS\s+)?NurseLink(?![\w@\/\\\\\-]|\.[a-z0-9])/iu', self::NAME, $value ?? '') ?? ($value ?? '');
    }

    public static function subject(?string $value): string
    {
        $value = self::text($value);
        return str_contains($value, self::NAME) ? $value : self::NAME . ' — ' . $value;
    }
}

