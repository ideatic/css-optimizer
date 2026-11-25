#!/usr/bin/env php
<?php

declare(strict_types=1);

// Command line utility to optimize CSS using css_optimizer
// Javier Marín <contacto@ideatic.net>, 2014
// Based on leafo/lessphp cli utility

error_reporting(E_ALL);

if (file_exists(__DIR__ . '/../../../autoload.php')) {
    require __DIR__ . '/../../../autoload.php';
} else {
    require __DIR__ . '/../vendor/autoload.php';
}

$args = $_SERVER['argv'] ?? [];
$exe = (string)array_shift($args);

$help = <<<EOT
Usage: {$exe} [options] input-file [output-file]

Options include:

    -h, --help  Show this message
    -c=comp     Compress CSS (default: true)
    -o=opt      Optimize CSS (default: true)
    -v=vendors  Add vendor prefixes: all, none or a list of webkit,mozilla,opera,msie (default: webkit,msie,mozilla)
    -s          Read from STDIN instead of input-file
    -u          Clean unused selectors (css_cleaner)

css_cleaner options:

    -p=paths    Required. Path(s) of the project source files
    -m=mode     Clean mode (safe/best)
    -e=exts     Extensions to analice
    -r=path     Path to selector usage report (optional)


EOT;

$opts = getopt('hc:o:suv:p:m:e:r:', ['help'], $rest_index);
if ($opts === false) {
    exit($help);
}

// Quitar las opciones de los argumentos
$args = array_values(array_slice($_SERVER['argv'] ?? [], $rest_index));

// Leer una opción como cadena (getopt deja el '=' de "-c=false")
$option = static fn(string $name, string $default = ''): string => isset($opts[$name]) && is_string($opts[$name]) ? ltrim($opts[$name], '=') : $default;
$flag = static fn(string $name, bool $default): bool => isset($opts[$name]) ? filter_var($option($name), FILTER_VALIDATE_BOOLEAN) : $default;
$error = static function (string $message): void {
    fwrite(STDERR, "FATAL ERROR: {$message}\n");
};

if (isset($opts['h']) || isset($opts['help'])) {
    exit($help);
}

if (PHP_SAPI !== 'cli') {
    $error("{$exe} must be run in the command line.");
    exit(1);
}

try {
    $start = microtime(true);

    // Leer la entrada
    if (isset($opts['s'])) {
        $data = $args !== [] ? (string)array_shift($args) : (string)stream_get_contents(STDIN);
    } else {
        $in = array_shift($args);
        if ($in === null || $in === '') {
            echo $help;
            exit(1);
        }

        $data = file_get_contents($in);
        if ($data === false) {
            $error("Could not read file {$in}");
            exit(1);
        }
    }

    $css_doc = new css_parser()->parse($data);

    // Eliminar selectores no usados
    if (isset($opts['u'])) {
        if (!isset($opts['p'])) {
            $error('Please, indicate the project source path. Multiple paths can be concatenated using ' . PATH_SEPARATOR);
            exit(1);
        }
        $cleaner = new css_cleaner();
        $cleaner->verbose = true;
        $cleaner->project_files = explode(PATH_SEPARATOR, $option('p'));
        $cleaner->method = $option('m', 'safe') === 'safe' ? css_cleaner::METHOD_SAFE : css_cleaner::METHOD_BEST_CLEAN;
        if (isset($opts['e'])) {
            $cleaner->extensions = $option('e');
        }
        if (isset($opts['r'])) {
            $cleaner->report_path = $option('r');
        }
        $cleaner->clean($css_doc);
    }

    // Optimizar
    $vendors = $option('v', 'webkit,msie,mozilla');
    $optimizer = new css_optimizer(
        [
            'compress' => $flag('c', true),
            'optimize' => $flag('o', true),
            'prefixes' => $vendors === 'none' ? '' : $vendors,
        ],
    );
    $optimizer->process($css_doc);

    $out = $css_doc->render($optimizer->compress);

    // Guardar o mostrar el resultado
    $fout = array_shift($args);
    if ($fout === null || $fout === '') {
        echo $out;
    } else {
        $gzip = static fn(string $text): string => ReadableSize(strlen((string)gzencode($text, 9)));
        $ratio = $data === '' ? 100 : round(strlen($out) / strlen($data) * 100);

        echo 'Optimized in ' . ReadableTime(microtime(true) - $start) . "\n";
        echo 'Input size ' . ReadableSize(strlen($data)) . " ({$gzip($data)} gziped)\n";
        echo 'Output size ' . ReadableSize(strlen($out)) . " ({$ratio}% of original, {$gzip($out)} gziped)\n";

        if (file_put_contents($fout, $out) === false) {
            $error("Could not write file {$fout}");
            exit(1);
        }
    }
} catch (Exception $ex) {
    $error($ex->getMessage());
    exit(1);
}

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
