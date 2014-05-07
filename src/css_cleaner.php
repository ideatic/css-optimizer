<?php

/**
 * css_cleaner - Clean CSS files removing unused selectors
 *
 * [MIT Licensed](http://www.opensource.org/licenses/mit-license.php)
 * @author Javier Marín
 */
class css_cleaner
{
    /**
     * This method looks for words only inside quoted strings and HTML tags.
     * This can achieve a better compression, but with the risk of removing used selectors.
     */
    const METHOD_BEST_CLEAN = 0;

    /**
     * Safe cleaning by looking for ALL words and tokens used in the original project source code, which
     * is translated in worse compression but less chance of removing used selectors.
     *
     * @warning This method can use a lot of time and memory on large projects
     */
    const METHOD_SAFE = 1;

    public $method = self::METHOD_SAFE;

    /**
     * Input files or folders where the project source files are located
     * (templates, javascript code, etc.)
     * @var string[]
     */
    public $project_files = array();

    /**
     * List of extensions, separated by commas, which will be processed
     * @var string
     */
    public $extensions = 'php,twig,tpl,htm,html,js,rb,py,djt';

    /**
     * Print information during the process
     * @var bool
     */
    public $verbose = false;

    /**
     * Path where a selector usage report will be saved
     * @var string
     */
    public $report_path;

    /**
     * Clean a CSS file
     *
     * @param css_group $css_doc CSS file to clean
     *
     * @return css_group
     */
    public function clean(css_group $css_doc)
    {
        //Find tokens
        $project_tokens = $this->_find_tokens();

        //Clean selectors
        $removed = 0;
        $selector_usage = array();
        foreach ($css_doc->find_all('css_group') as $group) {
            /* @var $group css_group */

            if (!$group->name || stripos($group->name, '@') !== false) {
                continue; //Ignore @media, @keyframes and future especial groups
            }

            $current_selectors = $group->selectors();
            $valid_selectors = array();
            foreach ($current_selectors as $selector) {
                $include = true;

                //Remove pseudo-class
                $clean_selector = preg_replace('/\:.*/', '', $selector);

                //Look for selector tokens in the token list
                foreach ($this->_get_tokens($clean_selector) as $token) {
                    if (!isset($project_tokens[$token])) {
                        //Remove current selector
                        $include = false;
                    }
                }

                if ($include) {
                    $valid_selectors[] = $selector;

                    if ($this->report_path) {
                        $count = 0;
                        foreach ($this->_get_tokens($clean_selector) as $token) {
                            $count += $project_tokens[$token];
                        }
                        $selector_usage[$clean_selector] = (isset($selector_usage[$clean_selector])) ? $selector_usage[$clean_selector] + $count : $count;
                    }
                }
            }

            //Set cleaned selectors, or remove entire group if empty
            if (empty($valid_selectors)) {
                $group->remove();
            } else {
                $group->selectors($valid_selectors);
            }


            if ($this->verbose) {
                $dif = array_diff($current_selectors, $valid_selectors);
                if (!empty($dif)) {
                    $removed += count($dif);
                    $dif = implode(', ', $dif);
                    echo "Removed $dif\n";
                }
            }
        }

        //Create report
        if ($this->report_path) {
            asort($selector_usage);
            $lines = array(
                'Selector usage report. Generated on ' . date('r'),
                ''
            );
            foreach ($selector_usage as $selector => $freq) {
                $lines[] = "$selector found $freq references";
            }

            file_put_contents($this->report_path, implode(PHP_EOL, $lines));
        }

        if ($this->verbose) {
            echo "\nClean done, removed $removed unused selectors\n";
        }

        return $css_doc;
    }

    protected function _find_tokens()
    {
        //Find input files
        $valid_extensions = array_map(
            function ($item) {
                return strtolower(trim($item));
            },
            explode(',', $this->extensions)
        );

        $files = array();
        foreach ($this->project_files as $path) {
            if (is_dir($path)) {
                $this->_find_files($path, $files, $valid_extensions);
            } else {
                $files[] = $path;
            }
        }

        if ($this->verbose) {
            $c = count($files);
            echo "Found $c input files\n";
        }

        //Find tokens in the input files
        $tokens = array_fill_keys($this->_default_tokens(), 0);
        foreach ($files as $path) {
            $file_tokens = $this->_parse_file($path);

            //Process found tokens
            foreach ($file_tokens as $token) {
                if (isset($tokens[$token])) {
                    $tokens[$token]++;
                } else {
                    $tokens[$token] = 1;
                }
            }
        }

        if ($this->verbose) {
            $c = count($tokens);
            echo "Found $c tokens\n";
        }

        return $tokens;
    }

    protected function _find_files($path, &$files, $valid_extensions)
    {
        $dirh = opendir($path);
        if ($dirh === false) {
            return;
        }

        while (($file = readdir($dirh)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $file_path = $path . DIRECTORY_SEPARATOR . $file;

            if (is_dir($file_path)) {
                $this->_find_files($file_path, $files, $valid_extensions);
            } else {
                $extension = pathinfo($file_path, PATHINFO_EXTENSION);
                if (in_array(strtolower($extension), $valid_extensions)) {
                    $files[] = $file_path;
                }
            }
        }

        closedir($dirh);
    }

    protected function _parse_file($path)
    {
        $content = file_get_contents($path);
        $tokens = array();

        switch ($this->method) {
            case self::METHOD_SAFE:
                //Find ALL tokens
                $tokens = $this->_get_tokens($content);

            case self::METHOD_BEST_CLEAN:

                //Find quoted strings
                $concat = '';
                for ($i = 0, $c = strlen($content); $i < $c; $i++) {
                    $char = $content[$i];
                    if ($char == '"' || $char == "'") {
                        $string = $concat . self::_read_string($content, $i);

                        foreach ($this->_get_tokens($string) as $token) {
                            $tokens[] = $token;
                        }

                        //Find concatenated
                        if (preg_match('/^\s*[\+\.]\s*[\'\"]/', substr($content, $i + 1), $match)) {
                            $i += strlen($match[0]) - 1;
                            $concat = $string;
                        } else {
                            $concat = '';
                        }
                    }
                }

                //Find HTML tags
                preg_match_all('/<(\w+)\b/i', $content, $matches);

                foreach ($matches[1] as $token) {
                    $tokens[] = $token;
                }

                return $tokens;

            default:
                throw new RuntimeException("Invalid clean method");
        }
    }

    protected function _get_tokens($string)
    {
        preg_match_all('/[\p{L}\p{N}-_]+/u', $string, $matches);
        return $matches[0];
    }

    /**
     * Lee una cadena encerrada entre comillas simples o dobles, devolviendo su
     * contenido y avanzando $offset las posiciones necesarias
     * @access private
     *
     * @param string $code
     * @param int    $offset
     *
     * @return string
     */
    public static function _read_string($code, &$offset)
    {
        $string = '';
        $in_string = false;
        $prev = '';
        for ($c = strlen($code); $offset < $c; $offset++) {
            $char = $code[$offset];

            if ($in_string && $in_string == $char && $prev != '\\') {
                return $string;
            } elseif (!$in_string && ($char == '"' || $char == "'")) {
                $in_string = $char;
                $prev = $char;
            } else {
                $prev = $char;
                $string .= $char;
            }
        }

        return $string;
    }

    protected function _default_tokens()
    {
        return array();
    }
}