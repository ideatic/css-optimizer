<?php



function ReadableTime($time)
{
    if ($time > 60) {
        $min = floor($time / 60);
        $sec = round($time) % 60;
        return "{$min}m {$sec}s";
    } elseif ($time > 1) {
        return round($time, 3) . ' s';
    } elseif ($time > 0.001) {
        return round($time * 1000) . ' ms';
    } else {
        return round($time * 1000000) . ' us';
    }
}

function ReadableSize($size, $kilobyte = 1024, $format = '%size% %unit%')
{
    if ($size < $kilobyte) {
        $unit = 'bytes';
    } else {
        $size = $size / $kilobyte;
        $units = array('KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        foreach ($units as $unit) {
            if ($size > $kilobyte) {
                $size = $size / $kilobyte;
            } else {
                break;
            }
        }
    }

    return strtr(
        $format,
        array(
            '%size%' => number_format($size, 2),
            '%unit%' => $unit
        )
    );
}
