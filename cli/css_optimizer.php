#!/usr/bin/env php
<?php
// Command line utility to optimize CSS using css_optimizer
// Javier Marín <contacto@ideatic.net>, 2014
// Based on leafo/lessphp cli utility

error_reporting(E_ALL);
require dirname(__FILE__).'/../vendor/autoload.php';

$exe = array_shift($argv); // remove filename

$HELP = <<<EOT
Usage: $exe [options] input-file [output-file]

Options include:

    -h, --help  Show this message
    -c=comp     Compress CSS (default: true)
    -o=opt      Optimize CSS (default: true)
    -v=vendors  Add vendor prefixes (default: all)
    -r          Read from STDIN instead of input-file
    -u          Clean unused selectors (css_cleaner)

css_cleaner options:

    -p=paths    Required. Path(s) of the project source files
    -m=mode     Clean mode (safe/best)
    -e=exts     Extensions to analice


EOT;

$opts = getopt('hc:o:ruv:p:m:e:', array('help'));
if ($opts === false) {
    exit($HELP);
}

//Clean options from $argv
while (count($argv) > 0 && preg_match('/^-/', $argv[0])) {
    array_shift($argv);
}

function has()
{
    global $opts;
    foreach (func_get_args() as $arg) {
        if (isset($opts[$arg])) {
            return true;
        }
    }
    return false;
}

function get($option, $default)
{
    global $opts;
    return isset($opts[$option]) ? $opts[$option] : $default;
}

function err($msg)
{
    fwrite(STDERR, "FATAL ERROR: $msg \n");
}

if (has("h", "help")) {
    exit($HELP);
}

if (php_sapi_name() != "cli") {
    err("$exe must be run in the command line.");
    exit(1);
}


//Process
try {
    $start = microtime(true);

    //Get input
    if (has("r")) {
        //Read from STDIN
        if (!empty($argv)) {
            $data = $argv[0];
        } else {
            $data = "";
            while (!feof(STDIN)) {
                $data .= fread(STDIN, 8192);
            }
        }
    } else {
        //Read from input file
        $in = array_shift($argv);

        if (!$in) {
            echo $HELP;
            exit(1);
        }

        $data = file_get_contents($in);

        if ($data === false) {
            err("Could not read to file $in");
            exit(1);
        }
    }

    //Parse input
    $parser = new css_parser();
    $css_doc = $parser->parse($data);

    if (has('u')) {
        //css_cleaner
        if (!has('p')) {
            err('Please, indicate the project source path. Multiple paths can be concatenated using ' . PATH_SEPARATOR);
            exit(1);
        }
        $cleaner = new css_cleaner();
        $cleaner->verbose = true;
        $cleaner->project_files = explode(PATH_SEPARATOR, $opts['p']);
        $cleaner->method = get('m', 'safe') == 'safe' ? css_cleaner::METHOD_SAFE : css_cleaner::METHOD_BEST_CLEAN;
        if (has('e')) {
            $cleaner->extensions = $opts['e'];
        }
        $cleaner->clean($css_doc);
    }

    //Optimize
    $optimizer = new css_optimizer();
    $optimizer->compress = get('c', true);
    $optimizer->optimize = get('o', true);
    $optimizer->prefixes = get('v', 'all');

    $optimizer->process($css_doc);

    //Generate output
    $out = $css_doc->render($optimizer->compress);

    //Save output
    if (!$fout = array_shift($argv)) {
        echo $out;
    } else {
        //Show stats
        echo 'Optimized in ' . ReadableTime(microtime(true) - $start) . "\n";
        echo 'Input size ' . ReadableSize(strlen($data)) . ' (' . ReadableSize(strlen(gzencode($data, 9))) . ' gziped)' . "\n";
        $ratio = round(strlen($out) / strlen($data), 2) * 100;
        echo 'Output size ' . ReadableSize(strlen($out)) . " ($ratio% of original, " . ReadableSize(strlen(gzencode($out, 9))) . ' gziped)' . "\n";


        //Save file
        file_put_contents($fout, $out);
    }

} catch (exception $ex) {
    err($ex->getMessage());
    exit(1);
}


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
