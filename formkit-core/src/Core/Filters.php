<?php
declare(strict_types=1);

namespace FormKit\Core;

final class Filters
{
    public function apply(string $name, mixed $value, mixed $arg = null): mixed
    {
        return match ($name) {
            'number' => $this->number($value, $arg),
            'date'   => $this->date($value, $arg),
            'upper'  => $this->upper($value),
            'lower'  => $this->lower($value),
            default  => $value, // unknown filter: noop (extensible in Pro)
        };
    }

    private function number(mixed $v, mixed $arg): string
    {
        $decimals = is_numeric($arg) ? (int)$arg : 0;
        $num = is_numeric($v) ? (float)$v : 0.0;
        return number_format($num, $decimals, ',', '.');
    }

    private function date(mixed $v, mixed $arg): string
    {
        $fmt = $arg ?: 'Y-m-d';
        if ($v instanceof \DateTimeInterface) {
            return $v->format($fmt);
        }
        $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
        if ($ts === false) return '';
        return date($fmt, $ts);
    }

    private function upper(mixed $v): string
    {
        return mb_strtoupper((string)$v);
    }

    private function lower(mixed $v): string
    {
        return mb_strtolower((string)$v);
    }
}
