<?php
require '../vendor/autoload.php';
require 'common.php';
?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>css_optimizer test</title>
        <link rel="stylesheet" href="assets/CodeMirror/codemirror.css">
        <script src="assets/CodeMirror/codemirror.js"></script>
    </head>
    <style>

        html, body {
            height: 100%;
            padding: 0;
            margin: 0;
            background: #d8ecf2;
            opacity: 1;
        }

        body {
            background: radial-gradient(center 0, ellipse cover, #fbfcfe 0%, #d8ecf2 100%) no-repeat;
            height: 100%;
            font-family: "Segoe UI", Arial, Verdana, "Trebuchet MS", Helvetica, Tahoma, "Verdana";
            padding: 20px;
        }

        @media print {
            body {
                background: white;
            }
        }

        h1, h1 a {
            color: #fff;
            text-shadow: 0px 1px 0px #999, 0px 2px 0px #888, 0px 3px 0px #777, 0px 4px 0px #666, 0px 5px 0px #555, 0px 6px 0px #444, 0px 7px 0px #333, 0px 8px 7px #001135;
            font-size: 80px;
            margin: 5px 0 15px 0;
            text-decoration: none;
        }

        #editor, #extra, #submit {
            clear: both;
            margin: 15px 0;
        }

        #editor:after, #extra:after {
            content: ".";
            display: block;
            height: 0;
            clear: both;
            visibility: hidden;
        }

        #editor > div {
            float: left;
            width: 40%;
            margin: 0 20px;
        }

        #editor label, h3 {
            display: block;
            text-shadow: 0 1px 0 #fff;
            line-height: 2.5em;
            font-size: 95%;
            font-weight: bold;
        }

        h3 {
            border-bottom: 1px solid #555;
            line-height: 2em;
        }

        textarea, .CodeMirror {
            height: 450px;
            transition: box-shadow 500ms;
            overflow: hidden;

            background-image: linear-gradient(top, #E3E3E3 10%, white 40%);

            box-shadow: 0 0 0 1px #aaa, 0 0 20px rgba(0, 0, 0, .4);
        }

        .CodeMirror-scroll {
            height: 450px;
        }

        textarea:hover, .CodeMirror:hover, textarea:focus, .CodeMirror-focused {
            box-shadow: 0 0 0 1px #777, 0 0 20px rgba(0, 0, 0, .4);
        }

        #extra > div {
            float: left;
            min-width: 250px;
            _width: 300px;
            margin: 0 10px;
        }

        #submit input {
            background: linear-gradient(center top, #DDDDDD 0%, #FFFFFF 100%);
            border: 1px solid #BBB;
            border-radius: 11px;
            color: #464646;
            cursor: pointer;
            line-height: 16px;
            padding: 2px 8px;
        }

        #submit input:hover {
            border-color: #666666;
            color: #000000;
        }
    </style>
    <body>
    <h1><a href="">css_optimizer</a></h1>
    <?php $process_data = do_optimization(); ?>
    <form method="post">
        <div id="editor">
            <div>
                <label for="source">Paste your CSS</label>
                <textarea id="source" name="source"><?php echo isset($_POST['source']) ? $_POST['source'] : file_get_contents('test-files/test.css') ?></textarea>
            </div>
            <div>
                <label for="result">Optimized CSS</label>
                <textarea id="result" name="result"><?php echo isset($process_data['css']) ? $process_data['css'] : ''; ?></textarea>
            </div>
        </div>
        <div id="extra">
            <div>
                <h3>Options</h3>

                <div>
                    <input type="checkbox" id="optimize" name="optimize" <?php echo $process_data['settings']['optimize'] ? 'checked' : ''; ?> /><label
                        for="optimize">Optimize</label>
                </div>
                <div>
                    <input type="checkbox" id="compress" name="compress" <?php echo $process_data['settings']['compress'] ? 'checked' : ''; ?> /><label for="compress"
                                                                                                                                                        title="Compress the code, removing whitespaces and unnecessary characters">Compress
                        code</label>
                </div>
                <div>
                    <input type="checkbox" id="remove_comments" name="remove_comments" <?php echo $process_data['settings']['remove_comments'] ? 'checked' : ''; ?> /><label
                        for="remove_comments" title="Remove CSS comments">Remove comments</label>
                </div>
                <div>
                    <input type="checkbox" id="extra_optimize" name="extra_optimize" <?php echo $process_data['settings']['extra_optimize'] ? 'checked' : ''; ?> /><label
                        for="extra_optimize" title="Apply some extra optimizations, like reorder selectors and rules in order to improve gzip compression ratio">Extra
                        optimizations (may be unsafe)</label>
                </div>
                <div>
                    <input type="checkbox" id="remove_ie_hacks" name="remove_ie_hacks" <?php echo $process_data['settings']['remove_ie_hacks'] ? 'checked' : ''; ?> /><label
                        for="remove_ie_hacks" title="Remove IE Hacks like _name, expressions and filters">Remove IE Hacks</label>
                </div>
            </div>
            <div>
                <h3>Prefix</h3>

                <div>
                    <div>
                        <input type="checkbox" id="prefix-webkit" name="prefix[webkit]" <?php echo
                        $process_data['settings']['prefixes'] == 'all' || strpos($process_data['settings']['prefixes'], 'webkit') !== false ? 'checked' : ''; ?> /><label
                            for="prefix-webkit" title="Add prefix for webkit-based browser such as Chrome or Safari">Webkit</label>
                    </div>
                    <div>
                        <input type="checkbox" id="prefix-mozilla" name="prefix[mozilla]" <?php echo
                        $process_data['settings']['prefixes'] == 'all' || strpos($process_data['settings']['prefixes'], 'mozilla') !== false ? 'checked' : ''; ?> /><label
                            for="prefix-mozilla" title="Add prefix for Mozilla Firefox">Firefox</label>
                    </div>
                    <div>
                        <input type="checkbox" id="prefix-msie" name="prefix[msie]" <?php echo
                        $process_data['settings']['prefixes'] == 'all' || strpos($process_data['settings']['prefixes'], 'msie') !== false ? 'checked' : ''; ?> /><label
                            for="prefix-msie" title="Add prefix for Internet Explorer">Internet Explorer</label>
                    </div>
                    <div>
                        <input type="checkbox" id="prefix-opera" name="prefix[opera]" <?php echo
                        $process_data['settings']['prefixes'] == 'all' || strpos($process_data['settings']['prefixes'], 'opera') !== false ? 'checked' : ''; ?> /><label
                            for="prefix-opera" title="Add prefix for Opera Browser">Opera</label>
                    </div>

                </div>
            </div>
            <?php if (isset($process_data['css'])): ?>
                <div>
                    <h3>Statistics</h3>
                    <ul>
                        <li>Original size: <?php echo ReadableSize(strlen($process_data['source'])) ?> (<?php echo strlen(gzencode($process_data['source'], 9)) ?> bytes
                            gzipped)
                        </li>
                        <li>Final size: <?php echo ReadableSize(strlen($process_data['css'])) ?> (<?php echo strlen(gzencode($process_data['css'], 9)) ?> bytes gzipped)</li>
                        <li>Difference: <strong><?php printf(
                                    '%s (%+g%%)',
                                    ReadableSize(strlen($process_data['css']) - strlen($process_data['source']), true),
                                    round(strlen($process_data['css']) / strlen($process_data['source']), 2) * 100
                                ) ?></strong></li>
                    </ul>
                    <ul>
                        <li>Duration: <?php echo ReadableTime($process_data['execution_time']) ?></li>
                    </ul>
                </div>
            <?php endif ?>
        </div>
        <div id="submit">
            <input type="submit"/>
        </div>
    </form>
    <?php
    if (!empty($process_data['errors'])) {
        echo '<h3>Errors</h3>';
        echo '<ul>';
        foreach ($process_data['errors'] as $error) {
            echo "<li>$error</li>";
        }
        echo '</ul>';
    }
    ?>
    <script>
        var settings = {
            mode: "text/css",
            matchBrackets: true
        };
        CodeMirror.fromTextArea(document.getElementById("source"), settings);
        settings['readOnly'] = true;
        CodeMirror.fromTextArea(document.getElementById("result"), settings);
    </script>
    </body>
    </html>
<?php

function do_optimization()
{
    $result = array();

    $settings = array();
    $default_optimizer = new css_optimizer();
    foreach (array('remove_comments', 'compress', 'optimize', 'extra_optimize', 'remove_ie_hacks') as $prop) {
        $settings[$prop] = !empty($_POST) ? isset($_POST[$prop]) : $default_optimizer->$prop;
    }
    $settings['prefixes'] = empty($_POST) ? 'all' : implode(',', array_keys(isset($_POST['prefix']) ? $_POST['prefix'] : array()));
    $result['settings'] = $settings;
    $result['errors'] = '';

    if (!empty($_POST['source'])) {
        $result['source'] = $_POST['source'];

        $start = microtime(true);
        $result['css'] = optimize($result['source'], $settings, $settings['errors']);
        $result['execution_time'] = microtime(true) - $start;
    }

    return $result;
}

function optimize($css, $settings, &$errors = null)
{
    $optimizer = new css_optimizer($settings);

    $result = $optimizer->process($css);

    //$errors = $optimizer->errors();

    return $result;
}