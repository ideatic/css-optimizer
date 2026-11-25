<?php

declare(strict_types=1);

function ReadableTime(float $time): string
{
    return match (true) {
        $time > 60 => floor($time / 60) . 'm ' . (round($time) % 60) . 's',
        $time > 1 => round($time, 3) . ' s',
        $time > 0.001 => round($time * 1000) . ' ms',
        default => round($time * 1000000) . ' us',
    };
}

function ReadableSize(int|float $size, int $kilobyte = 1024, string $format = '%size% %unit%'): string
{
    $unit = 'bytes';
    foreach (['KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'] as $next_unit) {
        if ($size < $kilobyte) {
            break;
        }
        $size /= $kilobyte;
        $unit = $next_unit;
    }

    return strtr($format, ['%size%' => number_format($size, 2), '%unit%' => $unit]);
}
