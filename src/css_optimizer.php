<?php

/**
 * css_optimizer - Optimize, compress and add vendor prefixes in your CSS files for cross browser compatibility
 *
 * [MIT Licensed](http://www.opensource.org/licenses/mit-license.php)
 * @author Javier Marín
 */
class css_optimizer
{

    /**
     * Compress CSS code, removing unused whitespace and symbols
     */
    public bool $compress = true;

    /**
     * Remove comments
     */
    public bool $remove_comments = true;

    /**
     * Optimize CSS colors, units, etc.
     */
    public bool $optimize = true;

    /**
     * Merge selectors, (may be unsafe)
     */
    public bool $extra_optimize = false;

    /**
     * Remove Internet Explorer hacks (filter, expressions, ...)
     */
    public bool $remove_ie_hacks = false;

    /**
     * Remove empty groups and selectos
     */
    public bool $remove_empty = true;
    public string $prefixes = 'all';

    public function __construct(?array $settings = null)
    {
        if (isset($settings)) {
            foreach ($settings as $prop => $value) {
                $this->$prop = $value;
            }
        }
    }

    /**
     * Optimize an input CSS string or parsed css_group
     */
    public function process(css_group|string $css): css_group|string
    {
        //Parse CSS
        if ($css instanceof css_group) {
            $css_doc = $css;
        } else {
            $parser = new css_parser();
            $css_doc = $parser->parse($css);
        }

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

        //Extra optimize
        if ($this->extra_optimize) {
            $this->_extra_optimize($css_doc);
        }

        //Add vendor prefixes
        if ($this->prefixes) {
            $prefixer = new css_prefixer;
            $options = explode(',', $this->prefixes);
            $prefixer->webkit = $this->prefixes == 'all' || in_array('webkit', $options);
            $prefixer->mozilla = $this->prefixes == 'all' || in_array('mozilla', $options);
            $prefixer->opera = $this->prefixes == 'all' || in_array('opera', $options);
            $prefixer->msie = $this->prefixes == 'all' || in_array('msie', $options);

            $prefixer->add_prefixes($css_doc);
        }

        return $css instanceof css_group ? $css_doc : $css_doc->render($this->compress);
    }

    protected function _optimize(css_group $document): void
    {
        $color_regex = '/(^|\b)(\#[0-9A-Fa-f]{3,6}|\w+\(.*?\)|' . implode('|', array_map('preg_quote', array_keys(css_color::color_names()))) . ')($|\b)/i';

        foreach ($document->find_all('css_property') as $property) {
            //Optimize font-weight
            if (in_array($property->name, ['font', 'font-weight'])) {
                $transformation = [
                    "normal" => "400",
                    "bold"   => "700"
                ];
                foreach ($transformation as $s => $r) {
                    $property->value = trim(preg_replace('#(^|\s)+(' . preg_quote($s, '#') . ')(\s|$)+#i', " $r ", $property->value));
                }
            }

            //Optimize colors       
            if (!in_array($property->name, ['filter', '-ms-filter'])) {
                $property->value = preg_replace_callback($color_regex, [$this, '_compress_color'], $property->value);
            }

            //Optimize background position
            if ($property->name == 'background-position') {
                $property->value = str_replace(
                    [
                        'top left',
                        'top center',
                        'top right',
                        'center left',
                        'center center',
                        'center right',
                        'bottom left',
                        'bottom center',
                        'bottom right'
                    ],
                    [
                        '0 0',
                        '50% 0',
                        '100% 0',
                        '0 50%',
                        '50% 50%',
                        '100% 50%',
                        '0 100%',
                        '50% 100%',
                        '100% 100%'
                    ],
                    $property->value
                );

                $property->value = str_replace([' top', ' left', ' center', ' right', ' bottom'], [' 0', ' 0', ' 50%', ' 100%', ' 100%'], $property->value);
            }

            //Use shorthand anotation
            $this->_shorthand($property);

            //Optimize units
            //0.5% -> .5%
            $property->value = preg_replace('#\b0+(\.\d+(px|em|ex|%|in|cm|mm|pt|pc))(\b|$)#i', '$1', $property->value);
            //Combine to turn things like "margin: 10px 10px 10px 10px" into "margin: 10px"
            $css_unit = '\d+(?:\.\d+)?(?:px|em|ex|%|in|cm|mm|pt|pc)';
            $property->value = preg_replace("/^($css_unit)\s+($css_unit)\s+($css_unit)\s+\\2$/", '$1 $2 $3', $property->value); // Make from 4 to 3
            $property->value = preg_replace("/^($css_unit)\s+($css_unit)\s+\\1$/", '$1 $2', $property->value); // Make from 3 to 2
            $property->value = preg_replace("/^($css_unit)\s+\\1$/", '$1', $property->value); // Make from 2 to 1
            //0px -> 0
            $property->value = preg_replace('#\b0+(px|em|ex|%|in|cm|mm|pt|pc)\b#i', '0', $property->value);
        }

        //Remove empty groups
        foreach ($document->find_all('css_group') as $group) {
            if (empty($group->children)) {
                $group->remove();
            }
        }
    }

    private function _compress_color(array $color_match)
    {
        $color = new css_color($color_match[0]);
        if ($color->valid && $color->a == 1) {
            $hex = $color->to_hex();
            if (strlen($hex) < strlen($color_match[0])) {
                return $hex;
            }
        }
        return $color_match[0];
    }

    private function _shorthand(css_property|stdClass $property): void
    {
        $shorthands = [
            'background'    => [
                'background-color',
                'background-image',
                'background-repeat',
                'background-position',
                'background-attachment',
            ],
            'font'          => [
                'font-style',
                'font-variant',
                'font-weight',
                'font-size',
                'line-height',
                'font-family'
            ],
            /*   'border' => array( //Problem with multiple border -> border-style: solid; border-width: 100px 100px 0 100px; border-color: #007bff transparent transparent transparent;
              'border-width',
              'border-style',
              'border-color'
              ), */
            'margin'        => [
                'margin-top',
                'margin-right',
                'margin-bottom',
                'margin-left',
            ],
            'padding'       => [
                'padding-top',
                'padding-right',
                'padding-bottom',
                'padding-left',
            ],
            'list-style'    => [
                'list-style-type',
                'list-style-position',
                'list-style-image',
            ],
            'border-width'  => [
                'border-top-width',
                'border-right-width',
                'border-bottom-width',
                'border-left-width',
            ],
            'border-radius' => [
                'border-top-left-radius',
                'border-top-right-radius',
                'border-bottom-right-radius',
                'border-bottom-left-radius',
            ]
        ];

        foreach ($shorthands as $shorthand => $shorthand_properties) {
            if (in_array($property->name, $shorthand_properties)) {
                //All properties must be defined in order to use the shorthand version
                $properties = [];
                $siblings = $property->siblings('css_property', true);
                foreach ($shorthand_properties as $name) {
                    $found = false;
                    foreach ($siblings as $sibling) {
                        if ($sibling->name == $name) {
                            $properties[] = $sibling;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        break;
                    }
                }

                if ($found && count($properties) == count($shorthand_properties)) {
                    //Replace with shorthand
                    $values = [];
                    foreach ($properties as $p) {
                        $values[] = $p->value;
                        if ($p != $property) {
                            $p->remove();
                        }
                    }
                    $property->name = $shorthand;
                    $property->value = implode(' ', $values);
                }
            }
        }
    }

    /**
     * @see http://net.tutsplus.com/tutorials/html-css-techniques/quick-tip-how-to-target-ie6-ie7-and-ie8-uniquely-with-4-characters/
     */
    protected function _remove_ie_hacks(css_group $document): void
    {
        foreach ($document->find_all('css_property') as $property) {
            $is_hack = in_array($property->name, ['filter', '-ms-filter']) //Filter
                       || in_array($property->name[0], ['*', '_']) //Hack (_width, *background)
                       || stripos($property->value, 'expression') === 0 //CSS Expression
                       || str_ends_with($property->value, '\9'); //IE8 Hack

            if ($is_hack) {
                $property->remove();
            }
        }
    }

    protected function _extra_optimize($css_doc): void
    {
        //Merge selectors
        $dummy_selector = 'selector';
        foreach ($css_doc->find_all('css_group') as $group) {
            $reference = $group->make_clone();
            $reference->name = $dummy_selector;
            $reference = $reference->render();

            foreach ($group->siblings('css_group') as $sibling) {
                $sibling_content = $sibling->make_clone();
                $sibling_content->name = $dummy_selector;
                $sibling_content = $sibling_content->render();

                if ($reference == $sibling_content) {
                    $group->name .= ',' . $sibling->name;
                    $sibling->remove();
                }
            }
        }
    }

}
