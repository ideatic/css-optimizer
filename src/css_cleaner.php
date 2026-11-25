<?php

declare(strict_types=1);

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
    const int METHOD_BEST_CLEAN = 0;

    /**
     * Safe cleaning by looking for ALL words and tokens used in the original project source code, which
     * is translated in worse compression but less chance of removing used selectors.
     *
     * @warning This method can use a lot of time and memory on large projects
     */
    const int METHOD_SAFE = 1;

    public int $method = self::METHOD_SAFE;

    /**
     * Input files or folders where the project source files are located
     * (templates, javascript code, etc.)
     * @var string[]
     */
    public array $project_files = [];

    /**
     * List of extensions, separated by commas, which will be processed
     */
    public string $extensions = 'php,twig,tpl,htm,html,js,rb,py,djt';

    /**
     * Print information during the process
     */
    public bool $verbose = false;

    /**
     * Path where a selector usage report will be saved
     */
    public ?string $report_path = null;

    /**
     * Clean a CSS file
     *
     * @param css_group $css_doc CSS file to clean
     */
    public function clean(css_group $css_doc): css_group
    {
        //Find tokens
        $project_tokens = $this->_find_tokens();

        //Clean selectors
        $removed = 0;
        $selector_usage = [];
        foreach ($css_doc->find_all(css_group::class) as $group) {
            if ($group->name === '' || str_starts_with($group->name, '@') || !$this->_is_style_rule($group)) {
                continue; // Ignorar @media, @keyframes, sus pasos (from, 50%...) y futuros grupos especiales
            }

            $current_selectors = $group->selectors();
            $valid_selectors = [];
            foreach ($current_selectors as $selector) {
                $include = true;

                //Remove pseudo-class
                $clean_selector = preg_replace('/:.*/s', '', $selector) ?? $selector;

                //Look for selector tokens in the token list
                foreach ($this->_get_tokens($clean_selector) as $token) {
                    if (!isset($project_tokens[$token])) {
                        //Remove current selector
                        $include = false;
                    }
                }

                if ($include) {
                    $valid_selectors[] = $selector;

                    if ($this->report_path !== null) {
                        $count = 0;
                        foreach ($this->_get_tokens($clean_selector) as $token) {
                            $count += $project_tokens[$token];
                        }
                        $selector_usage[$clean_selector] = ($selector_usage[$clean_selector] ?? 0) + $count;
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
                    echo 'Removed ' . implode(', ', $dif) . "\n";
                }
            }
        }

        //Create report
        if ($this->report_path !== null) {
            asort($selector_usage);
            $lines = [
                'Selector usage report. Generated on ' . date('r'),
                ''
            ];
            foreach ($selector_usage as $selector => $freq) {
                $lines[] = "{$selector} found {$freq} references";
            }

            file_put_contents($this->report_path, implode(PHP_EOL, $lines));
        }

        if ($this->verbose) {
            echo "\nClean done, removed {$removed} unused selectors\n";
        }

        return $css_doc;
    }

    /**
     * Indica si el grupo es una regla de estilo (fuera de at-rules o dentro de @media, @supports...)
     */
    private function _is_style_rule(css_group $group): bool
    {
        foreach ($group->parents(false) as $parent) {
            // Dentro de @keyframes, @page... los "selectores" no son clases ni etiquetas
            if ($parent instanceof css_group && str_starts_with($parent->name, '@') && !in_array(css_value::at_rule_name($parent->name), css_value::STYLE_AT_RULES, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, int> Número de apariciones de cada token en los ficheros del proyecto
     */
    protected function _find_tokens(): array
    {
        // Buscar los ficheros del proyecto
        $valid_extensions = array_map(static fn(string $extension): string => $extension |> trim(...) |> strtolower(...), explode(',', $this->extensions));

        $files = [];
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
            foreach ($this->_parse_file($path) as $token) {
                $tokens[$token] = ($tokens[$token] ?? 0) + 1;
            }
        }

        if ($this->verbose) {
            $c = count($tokens);
            echo "Found $c tokens\n";
        }

        return $tokens;
    }

    /**
     * @param list<string> $files
     * @param list<string> $valid_extensions
     */
    protected function _find_files(string $path, array &$files, array $valid_extensions): void
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
                if (in_array(strtolower($extension), $valid_extensions, true)) {
                    $files[] = $file_path;
                }
            }
        }

        closedir($dirh);
    }

    /**
     * @return list<string>
     */
    protected function _parse_file(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Could not read file {$path}");
        }

        // El método seguro usa TODOS los tokens del fichero, además de los de las cadenas y etiquetas
        $tokens = match ($this->method) {
            self::METHOD_SAFE => $this->_get_tokens($content),
            self::METHOD_BEST_CLEAN => [],
            default => throw new RuntimeException("Invalid clean method {$this->method}"),
        };

        // Buscar cadenas entre comillas
        $concat = '';
        for ($i = 0, $c = strlen($content); $i < $c; $i++) {
            $char = $content[$i];
            if ($char === '"' || $char === "'") {
                $string = $concat . self::_read_string($content, $i);

                array_push($tokens, ...$this->_get_tokens($string));

                // Cadenas concatenadas ('a' + 'b', 'a' . 'b')
                if (preg_match('/^\s*[\+\.]\s*[\'\"]/', substr($content, $i + 1), $match) === 1) {
                    $i += strlen($match[0]) - 1;
                    $concat = $string;
                } else {
                    $concat = '';
                }
            }
        }

        // Buscar etiquetas HTML
        preg_match_all('/<(\w+)\b/i', $content, $matches);
        array_push($tokens, ...$matches[1]);

        return $tokens;
    }

    /**
     * @return list<string>
     */
    protected function _get_tokens(string $string): array
    {
        preg_match_all('/[\p{L}\p{N}_-]+/u', $string, $matches);
        return $matches[0];
    }

    /**
     * Lee una cadena encerrada entre comillas simples o dobles, devolviendo su
     * contenido y avanzando $offset las posiciones necesarias
     */
    public static function _read_string(string $code, int &$offset): string
    {
        $string = '';
        $in_string = false;
        $prev = '';
        for ($c = strlen($code); $offset < $c; $offset++) {
            $char = $code[$offset];

            if ($in_string !== false && $in_string === $char && $prev !== '\\') {
                return $string;
            } elseif ($in_string === false && ($char === '"' || $char === "'")) {
                $in_string = $char;
                $prev = $char;
            } else {
                $prev = $char;
                $string .= $char;
            }
        }

        return $string;
    }

    /**
     * @return list<string>
     */
    protected function _default_tokens(): array
    {
        return [];
    }
}