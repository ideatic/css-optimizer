<?php

/**
 * css_optimizer - Optimize, compress and add vendor prefixes in your CSS files for cross browser compatibility
 *
 * --
 * Copyright (c) Javier Marín
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * --
 *
 * @package         css_optimizer
 * @link            https://github.com/javiermarinros/css_optimizer
 * @version         2
 * @author          Javier Marín <https://github.com/javiermarinros>
 * @copyright       Javier Marín <https://github.com/javiermarinros>
 * @license         http://opensource.org/licenses/mit-license.php MIT License
 */
class css_optimizer {

    /**
     * Compress CSS code, removing unused whitespace and symbols
     * @var boolean 
     */
    public $compress = TRUE;

    /**
     * Remove comments
     * @var boolean 
     */
    public $remove_comments = TRUE;

    /**
     * Optimize CSS colors, units, etc.
     * @var boolean
     */
    public $optimize = TRUE;
    public $extra_optimize = FALSE;

    /**
     * Remove Internet Explorer hacks (filter, expressions, ...)
     * @var boolean
     */
    public $remove_ie_hacks = FALSE;

    /**
     * Remove empty groups and selectos
     * @var boolean
     */
    public $remove_empty = TRUE;
    public $prefixes = 'all';
    protected $_errors;

    public function __construct($settings = NULL) {
        if (isset($settings)) {
            foreach ($settings as $prop => $value) {
                $this->$prop = $value;
            }
        }
    }

    public function process($css) {
        //Parse CSS
        require_once 'css_parser.php';

        $parser = new css_parser();

        $css_doc = $parser->parse($css);

        //Remove comments
        if ($this->remove_comments) {
            foreach ($css_doc->find_all('css_element') as $element) {
                if ($element->type == 'comment') {
                    $element->remove();
                }
            }
        }

        //Lowercase all property names
        foreach ($css_doc->find_all('css_property') as $property) {
            $property->name = strtolower($property->name);
        }

        //Remove IE hacks
        if ($this->remove_ie_hacks) {
            $this->_remove_ie_hacks($css_doc);
        }

        //Optimize
        if ($this->optimize) {
            $this->_optimize($css_doc);
        }

        //Add vendor prefixes
        if ($this->prefixes) {
            require_once 'css_prefixer.php';

            $prefixer = new css_prefixer;
            $options = explode(',', $this->prefixes);
            $prefixer->webkit = $this->prefixes == 'all' || in_array('webkit', $options);
            $prefixer->mozilla = $this->prefixes == 'all' || in_array('mozilla', $options);
            $prefixer->opera = $this->prefixes == 'all' || in_array('opera', $options);
            $prefixer->msie = $this->prefixes == 'all' || in_array('msie', $options);

            $prefixer->add_prefixes($css_doc);
        }

        return $css_doc->render($this->compress);
    }

    protected function _optimize(css_group $document) {
        require_once 'css_color.php';
        $color_regex = '/(^|\b)(\#[0-9A-Fa-f]{3,6}|\w+\(.*?\)|' . implode('|', array_map('preg_quote', array_keys(css_color::color_names()))) . ')($|\b)/i';

        foreach ($document->find_all('css_property') as $property) {
            //Optimize units
            //0.5% -> .5%
            $property->value = preg_replace('#\b0+(\.\d+(px|em|ex|%|in|cm|mm|pt|pc))(\b|$)#i', '$1', $property->value);
            //0 0 0 0 -> 0
            $property->value = preg_replace('#(\b(\d+(\.\d+)?(px|em|ex|%|in|cm|mm|pt|pc))\b)\s+\1\s+\1\s+\1#i', '$1', $property->value);
            //0px -> 0
            $property->value = preg_replace('#\b0+(px|em|ex|%|in|cm|mm|pt|pc)\b#i', '0', $property->value);

            //Optimize font-weight
            if (in_array($property->name, array('font', 'font-weight'))) {
                $transformation = array(
                    "normal" => "400",
                    "bold" => "700"
                );
                foreach ($transformation as $s => $r) {
                    $property->value = preg_replace('#(^|\s)+(' . preg_quote($s, '#') . ')(\s|$)+#i', $r, $property->value);
                }
            }

            //Optimize colors       
            if (!in_array($property->name, array('filter', '-ms-filter')))
                $property->value = preg_replace_callback($color_regex, array($this, '_compress_color'), $property->value);
        }

        //Remove empty groups
        foreach ($document->find_all('css_group') as $group) {
            if (empty($group->children)) {
                $group->remove();
            }
        }
    }

    private function _compress_color($color_match) {
        $color = new css_color($color_match[0]);
        if ($color->valid && $color->a==1) {
            $hex = $color->to_hex();
            if (strlen($hex) < strlen($color_match[0])) {
                return $hex;
            }
        }
        return $color_match[0];
    }

    protected function _remove_ie_hacks(css_group $document) {
        foreach ($document->find_all('css_property') as $property) {
            if (in_array($property->name, array('filter', '-ms-filter'))) {//Filter
                $property->remove();
            } else if (in_array($property->name[0], array('*', '_'))) { //Hack (_width, *background)
                $property->remove();
            } else if (stripos($property->value, 'expression') === 0) { //CSS Expression
                $property->remove();
            }
        }
    }

}