<?php
require '../vendor/autoload.php';
require 'common.php';
?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>css_cleaner test</title>
        <link rel="stylesheet" href="assets/CodeMirror/codemirror.css">
        <link rel="stylesheet" href="assets/editor.css">
        <script src="assets/CodeMirror/codemirror.js"></script>
    </head>
    <body>
    <h1><a href="">css_cleaner</a></h1>
    <?php $process_data = do_optimization(); ?>
    <form method="post">
        <div id="editor">
            <div>
                <label for="source">Original CSS</label>
                <textarea id="source" name="source"><?php echo isset($_POST['source']) ? $_POST['source'] : file_get_contents('assets/editor.css') ?></textarea>
            </div>
            <div>
                <label for="result">Cleaned CSS</label>
                <textarea id="result" name="result"><?php echo isset($process_data['css']) ? $process_data['css'] : ''; ?></textarea>
            </div>
        </div>
        <div id="extra">
            <div>
                <h3>Options</h3>

                <div>
                    <label>Method <select name="method">
                            <option value="<?= css_cleaner::METHOD_BEST_CLEAN ?>" <?=
                            $process_data['settings']['method'] == css_cleaner::METHOD_BEST_CLEAN ? 'selected' : '' ?>>Best clean
                            </option>
                            <option value="<?= css_cleaner::METHOD_SAFE ?>" <?= $process_data['settings']['method'] == css_cleaner::METHOD_SAFE ? 'selected' : '' ?>>Safe
                                clean
                            </option>
                        </select></label>
                </div>
                <div>
                    <input type="checkbox" id="compress" name="compress" <?php echo $process_data['settings']['compress'] ? 'checked' : ''; ?> /><label for="compress"
                                                                                                                                                        title="Compress the code, removing whitespaces and unnecessary characters">Compress
                        code</label>
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
    if (!empty($process_data['output'])) {
        ?>
        <h3>Output</h3>
        <pre><?= $process_data['output'] ?></pre>
    <?php
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

        //Dummy JS to test concatenated string finding
        var dummy = "used" + "_"
            + "on" + "_" +
            "concatenation";
    </script>
    </body>
    </html>
<?php

function do_optimization()
{

    $settings = array();
    $cleaner = new css_cleaner();
    foreach (array('method', 'compress') as $prop) {
        $settings[$prop] = isset($_POST[$prop]) ? $_POST[$prop] : (isset($cleaner->$prop) ? $cleaner->$prop : false);
        $cleaner->$prop = $settings[$prop];
    }


    $result = array();
    $result['settings'] = $settings;


    if (!empty($_POST['source'])) {
        $cleaner->verbose = true;
        $cleaner->project_files[] = dirname(__FILE__);

        $result['source'] = $_POST['source'];
        $parser = new css_parser();

        //Clean CSS
        ob_start();
        $start = microtime(true);
        $result['css'] = $cleaner->clean($parser->parse($result['source']))
                                 ->render($settings['compress']);
        $result['execution_time'] = microtime(true) - $start;
        $result['output'] = ob_get_clean();
    }

    return $result;
}