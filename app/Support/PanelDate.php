<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Morilog\Jalali\Jalalian;

final class PanelDate
{
    public static function format(mixed $value, string $format = 'Y/m/d H:i'): string
    {
        if (! $value) {
            return '—';
        }
        try {
            $date = CarbonImmutable::parse($value)->setTimezone('Asia/Tehran');
        } catch (InvalidFormatException) {
            return '—';
        }

        return strtr(Jalalian::fromDateTime($date)->format($format), array_combine(range(0, 9), ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']));
    }
}
