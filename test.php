<!DOCTYPE html>
<html>
    <head>
        <title>css_optimizer test</title>
        <link rel="stylesheet" href="CodeMirror/codemirror.css">
        <script src="CodeMirror/codemirror.js"></script>
        <style>
<?php
$css = <<<CSS
html, body {
    height:100%;
    padding:0;
    margin:0;
    background: #d8ecf2;
    opacity:1;
}
    
body {
    background: radial-gradient(center 0, ellipse cover, #fbfcfe 0%,#d8ecf2 100%) no-repeat;
    height:100%;
    font-family: "Segoe UI",Arial,Verdana,"Trebuchet MS",Helvetica,Tahoma,Verdana;
    padding:20px;
}

@media print {
   body {
    background:white;
   }
}
    
h1, h1 a {
   color: #fff; 
   text-shadow: 0px 1px 0px #999, 0px 2px 0px #888, 0px 3px 0px #777, 0px 4px 0px #666, 0px 5px 0px #555, 0px 6px 0px #444, 0px 7px 0px #333, 0px 8px 7px #001135;
   font-size: 80px;
   margin:5px 0 15px 0;
   text-decoration:none;
}

#editor, #extra, #submit {
    clear:both;
    margin: 15px 0;
}

#editor:after, #extra:after {
    content: ".";
    display: block;
    height: 0;
    clear: both;
    visibility: hidden;
}

#editor>div {
    float:left;
    width:40%;
    margin:0 20px;
}
    
#editor label, h3 {
    display:block;
    text-shadow: 0 1px 0 #fff;
    line-height:2.5em;
    font-size: 95%;
    font-weight: bold;
}

h3 {
    border-bottom: 1px solid #555;
    line-height:2em;
}

textarea, .CodeMirror{
    height:450px;
    transition: box-shadow 500ms;
    overflow: hidden;
    
    background-image: linear-gradient(top, #E3E3E3 10%, white 40%);
    
    box-shadow: 0 0 0 1px #aaa,0 0 20px rgba(0,0,0,.4);
}

.CodeMirror-scroll{
     height: 450px;
}

textarea:hover, .CodeMirror:hover,textarea:focus, .CodeMirror-focused{
    box-shadow: 0 0 0 1px #777,0 0 20px rgba(0,0,0,.4);
}

#extra > div {
    float:left;
    min-width: 250px;
    margin:0 10px;
}

#submit input {
    background: linear-gradient(center top , #DDDDDD 0%, #FFFFFF 100%);
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

@-webkit-keyframes "slide" {
   0% { left: 0; box-shadow: 10px 10px 10px black; }
   100% { left: 50px; box-shadow: 5px 5px 5px black; }
}

CSS;
echo prefix($css, array('compress' => true, 'optimize' => true));
//echo $css;
?>
        </style>
    </head>
    <body>
        <h1><a href="">css_optimizer</a></h1>
        <?php $process_data = do_optimization(); ?>
        <form method="post">
            <div id="editor">
                <div>
                    <label for="source">Paste your CSS</label>
                    <textarea id="source" name="source"><?php echo isset($_POST['source']) ? $_POST['source'] : $css ?></textarea>
                </div>
                <div>
                    <label for="result">Prefixed CSS</label>
                    <textarea id="result" name="result"><?php echo isset($process_data['css']) ? $process_data['css'] : ''; ?></textarea>
                </div>
            </div>
            <div id="extra">
                <div>
                    <h3>Options</h3>
                    <div>
                        <input type="checkbox" id="optimize" name="optimize" <?php echo $process_data['settings']['optimize'] ? 'checked' : ''; ?> /><label for="optimize">Optimize</label>
                    </div>
                    <div> 
                        <input type="checkbox" id="compress" name="compress" <?php echo $process_data['settings']['compress'] ? 'checked' : ''; ?> /><label for="compress" title="Compress the code, removing whitespaces and unnecessary characters">Compress code</label>
                    </div>
                    <div>
                        <input type="checkbox" id="extra_optimize" name="extra_optimize" <?php echo $process_data['settings']['extra_optimize'] ? 'checked' : ''; ?> /><label for="extra_optimize" title="Apply some extra optimizations, like reorder selectors and rules in order to improve gzip compression ratio">Extra optimizations (may be unsafe)</label>
                    </div>
                </div>  
                <div>
                    <h3>Prefix</h3>
                    <div>
                        <div>
                            <input type="checkbox" id="prefix-webkit" name="prefix[webkit]" <?php echo $process_data['settings']['prefix']['webkit'] ? 'checked' : ''; ?> /><label for="prefix-webkit" title="Add prefix for webkit-based browser such as Chrome or Safari">Webkit</label>
                        </div>
                        <div>
                            <input type="checkbox" id="prefix-mozilla" name="prefix[mozilla]" <?php echo $process_data['settings']['prefix']['mozilla'] ? 'checked' : ''; ?> /><label for="prefix-mozilla" title="Add prefix for Mozilla Firefox">Firefox</label>
                        </div>
                        <div> 
                            <input type="checkbox" id="prefix-opera" name="prefix[opera]" <?php echo $process_data['settings']['prefix']['opera'] ? 'checked' : ''; ?> /><label for="prefix-opera" title="Add prefix for Opera Browser">Opera</label>
                        </div>
                        <div>
                            <input type="checkbox" id="prefix-microsoft" name="prefix[microsoft]" <?php echo $process_data['settings']['prefix']['microsoft'] ? 'checked' : ''; ?> /><label for="prefix-microsoft" title="Add prefix for Internet Explorer">Internet Explorer</label>
                        </div>
                    </div>
                </div>   
                <?php if (isset($process_data['css'])): ?>
                    <div>
                        <h3>Statistics</h3>
                        <ul>
                            <li>Original size: <?php echo ReadableSize(strlen($process_data['source'])) ?> (<?php echo strlen(gzencode($process_data['source'], 9)) ?> bytes gzipped)</li>
                            <li>Final size: <?php echo ReadableSize(strlen($process_data['css'])) ?> (<?php echo strlen(gzencode($process_data['css'], 9)) ?> bytes gzipped)</li>
                            <li>Difference: <strong><?php printf('%s (%+g%%)', ReadableSize(strlen($process_data['css']) - strlen($process_data['source']), true), round(strlen($process_data['css']) / strlen($process_data['source']), 2) * 100) ?></strong></li>
                        </ul>
                        <ul>
                            <li>Duration: <?php echo ReadableTime($process_data['execution_time']) ?></li>
                        </ul>
                    </div>     
                <?php endif ?>
            </div>
            <div id="submit">
                <input type="submit" />
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
            var settings={
                mode: "text/css",
                matchBrackets: true
            };
            CodeMirror.fromTextArea(document.getElementById("source"),settings );
            settings['readOnly']=true;
            CodeMirror.fromTextArea(document.getElementById("result"),settings );
        </script>
    </body>
</html>
<?php

function do_optimization() {
    $data = array();

    if (empty($_POST)) {
        //Default settings
        $data['settings'] = css_optimizer::default_settings();
    } else {
        //User settings
        $data['settings'] = array(
            'compress' => isset($_POST['compress']),
            'optimize' => isset($_POST['optimize']),
            'extra_optimize' => isset($_POST['extra_optimize']),
            'prefix' => array(),
        );
        foreach (array('webkit', 'mozilla', 'opera', 'microsoft') as $type) {
            $data['settings']['prefix'][$type] = isset($_POST['prefix'][$type]);
        }
    }
    $data['errors'] = '';

    if (!isset($_POST['source']))
        return $data;

    $data['source'] = $_POST['source'];

    $start = microtime(true);
    $data['css'] = prefix($_POST['source'], $data['settings'], $data['errors']);
    $data['execution_time'] = microtime(true) - $start;

    return $data;
}

function prefix($css, $settings, &$errors = null) {
    require_once 'css_optimizer.php';
    $prefixer = new css_optimizer($settings);

    $result = $prefixer->process($css);

    $errors = $prefixer->errors();

    return $result;
}

function ReadableTime($time) {
    if ($time > 60) {
        $min = floor($time / 60);
        $sec = round($time) % 60;
        return "{$min}m {$sec}s";
    } elseif ($time > 1) {
        return round($time, 3) . ' s';
    } elseif ($time > 0.001) {
        return round($time * 1000) . ' ms';
    } else {
        return round($time * 1000000) . ' &micro;s';
    }
}

function ReadableSize($bytes, $sign = false, $precission = 2) {
    if ($bytes > 1000000000) {
        $count = round($bytes / 1000000000, $precission);
        $unit = 'GB';
    } elseif ($bytes > 1000000) {
        $count = round($bytes / 1000000, $precission);
        $unit = 'MB';
    } elseif ($bytes > 1000) {
        $count = round($bytes / 1000, $precission);
        $unit = 'KB';
    } else {
        $count = $bytes;
        $unit = 'bytes';
    }

    return sprintf($sign ? '%+g %s' : '%g %s', $count, $unit);
}