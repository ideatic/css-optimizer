<?php


/**
 * css_prefixer - Add vendor prefixes to css documents
 *
 * [MIT Licensed](http://www.opensource.org/licenses/mit-license.php)
 * @author Javier Marín
 */
class css_prefixer
{

    public bool $webkit = true;
    public bool $mozilla = true;
    public bool $opera = true;
    public bool $msie = true;

    public function add_prefixes(css_group $css_doc): void
    {
        $this->_add_prefixes($css_doc);
    }

    protected function _add_prefixes(css_group $css_doc, array $vendor_override = null, bool $ignore_keyframes = false, bool $remove_original_property = false): void
    {
        $vendors_ids = [
            1 => 'webkit',
            0 => 'mozilla',
            2 => 'opera',
            3 => 'msie',
        ];
        $originals = [];
        $apply_vendors = [];
        foreach ($vendors_ids as $id => $prop) {
            $originals[$prop] = $this->$prop;
            if (isset($vendor_override)) {
                $this->$prop = $vendor_override[$id] ?? ($vendor_override[$prop] ?? false);
            }
            $apply_vendors[$id] = $this->$prop;
        }

        foreach ($css_doc->find_all('css_property') as $property) {
            /* @var $property css_property */
            //Check if property is inside a @keyframes
            $keyframe = null;
            if (!$ignore_keyframes) {
                foreach ($property->parents() as $parent) {
                    if ($parent instanceof css_group && stripos($parent->name ?? '', '@keyframes') === 0) {
                        $keyframe = $parent;
                    }
                }
            }

            if (isset($keyframe)) {
                //Create vendor keyframes, each one with its own vendor prefixes
                $this->_prefix_keyframe($keyframe, $apply_vendors);
            } else {
                if (array_key_exists($property->name, $this->_transformations)) {
                    $applied = $this->_apply_transformation($property, $vendors_ids);

                    if ($applied && $remove_original_property) {
                        $property->remove();
                    }
                } else {
                    //Replace vendor functions (gradients)
                    $this->_prefix_gradients($property, $vendors_ids);
                }
            }
        }

        //Restore original values
        foreach ($originals as $prop => $value) {
            $this->$prop = $value;
        }
    }

    private function _ie_filter_color($color): string
    {
        $color = trim($color);
        if (preg_match('/#[0-9a-f]+/i', $color)) {
            if (strlen($color) == 4) {
                $color = "#FF$color[1]$color[1]$color[2]$color[2]$color[3]$color[3]";
            } elseif (strlen($color) == 7) {
                $color = "#FF$color[1]$color[2]$color[3]$color[4]$color[5]$color[6]";
            }
        }
        return strtoupper($color);
    }

    private function _apply_transformation(css_property $property, array $vendors_ids): bool
    {
        $transformation = $this->_transformations[$property->name];
        $applied = false;
        if (is_callable($transformation)) {
            call_user_func($transformation, $property, $this);
        } else {
            foreach ($transformation as $vendor_id => $new_name) {
                if ($new_name == null) {
                    continue;
                }

                $prop = $vendors_ids[$vendor_id];
                if ($this->$prop) {
                    //Check if property is not already defined
                    $already_defined = false;
                    foreach ($property->siblings() as $sibling) {
                        if ($sibling->name == $new_name) {
                            $already_defined = true;
                        }
                    }

                    //Create vendor prefix
                    if (!$already_defined) {
                        $property->insert_after(new css_property($new_name, $property->value));
                        $applied = true;
                    }
                }
            }
        }
        return $applied;
    }

    private function _prefix_keyframe(css_group $keyframe, array $apply_list): void
    {
        $prefixes = [
            3 => "@-ms-keyframes",
            2 => "@-o-keyframes",
            0 => "@-moz-keyframes",
            1 => "@-webkit-keyframes"
        ];

        foreach ($prefixes as $id => $value) {
            if (isset($apply_list[$id]) && $apply_list[$id]) {
                $new_name = str_replace('@keyframes', $value, $keyframe->name);

                //Check if keyframe with prefix exists
                $found = false;
                foreach ($keyframe->siblings('css_group') as $sibling) {
                    if ($sibling->name == $new_name) {
                        $found = true;
                    }
                }

                if (!$found) {
                    //Create new keyframe only with prefix for the current vendor
                    $new_keyframe = $keyframe->make_clone();
                    $new_keyframe->name = $new_name;
                    $keyframe->insert_after($new_keyframe);
                    $this->_add_prefixes($new_keyframe, [$id => true], true, true);
                }
            }
        }
    }

    /**
     * Transforms the Internet Explorer specific declaration property "filter" to Internet Explorer 8+ compatible
     * declaratiopn property "-ms-filter".
     */
    private static function filter(css_property $property, css_prefixer $prefixer): void
    {
        if ($prefixer->msie) {
            $property->insert_after('-ms-filter', !str_contains($property->value, "'") ? "'$property->value'" : '"' . $property->value . '"');
        }
    }

    /**
     * Transforms "opacity: {value}" into browser specific counterparts.
     */
    private static function opacity(css_property $property, css_prefixer $prefixer): void
    {
        if ($prefixer->msie && is_numeric($property->value)) {
            $ie_value = (int)((float)$property->value * 100);

            // Internet Explorer >= 8
            $property->insert_after('-ms-filter', "\"alpha(opacity=" . $ie_value . ")\"");
            // Internet Explorer >= 4 <= 7
            $property->insert_after('filter', "alpha(opacity=" . $ie_value . ")");
            $property->insert_after('zoom', '1');
        }
    }

    /**
     * Transforms "white-space: pre-wrap" into browser specific counterparts.
     */
    private static function whiteSpace(css_property $property, css_prefixer $prefixer): void
    {
        if (strtolower($property->value) === "pre-wrap") {
            // Firefox < 3
            if ($prefixer->mozilla) {
                $property->insert_after("white-space", "-moz-pre-wrap");
            }
            // Webkit
            if ($prefixer->webkit) {
                $property->insert_after("white-space", "-webkit-pre-wrap");
            }
            if ($prefixer->opera) {
                // Opera >= 4 <= 6
                $property->insert_after("white-space", "-pre-wrap");
                // Opera >= 7
                $property->insert_after("white-space", "-o-pre-wrap");
            }
            // Internet Explorer >= 5.5
            if ($prefixer->msie) {
                $property->insert_after("word-wrap", "break-word");
            }
        }
    }

    protected function _prefix_gradients(css_property $property, array $vendors_ids): void
    {
        $gradient_transforms = [
            'linear-gradient'           => ['-moz-linear-gradient', '-webkit-linear-gradient', '-o-linear-gradient', null],
            'repeating-linear-gradient' => ['-moz-repeating-linear-gradient', '-webkit-repeating-linear-gradient', '-o-linear-repeating-gradient', null],
            'radial-gradient'           => ['-moz-radial-gradient', '-webkit-radial-gradient', '-o-radial-gradient', null],
            'repeating-radial-gradient' => ['-moz-repeating-radial-gradient', '-webkit-repeating-radial-gradient', '-o-radial-repeating-gradient', null]
        ];
        foreach ($gradient_transforms as $function => $transformations) {
            $regex = '/\b(?<!-)' . preg_quote($function) . '\b/i';
            if (preg_match($regex, $property->value)) {
                foreach ($transformations as $vendor_id => $new_name) {
                    if ($new_name == null) {
                        continue;
                    }

                    $new_value = preg_replace($regex, $new_name, $property->value);
                    $new_value = strtr(
                        $new_value,
                        [
                            'to bottom' => 'top',
                            'to right'  => 'left',
                        ]
                    );

                    $prop = $vendors_ids[$vendor_id];
                    if ($this->$prop) {
                        //Check if value is not already defined
                        $already_defined = false;
                        foreach ($property->siblings() as $sibling) {
                            if ($sibling->value == $new_value) {
                                $already_defined = true;
                            }
                        }

                        //Create vendor prefix
                        if (!$already_defined) {
                            $property->insert_after(new css_property($property->name, $new_value));
                        }
                    }
                }

                //Old webkit format (buggy)
                $color_stops_regex = '(?<color>(rgb|hsl)a?\s*\([^\)]+\)|#[\da-f]+|\w+)\s+(?<unit>\d+(%|em|px|in|cm|mm|ex|em|pt|pc)?)';

                //Old IE format (buggy)
                if ($this->msie) {
                    preg_match_all("/$color_stops_regex/i", $property->value, $matches, PREG_SET_ORDER);
                    if (!empty($matches)) {
                        $first = reset($matches);
                        $last = end($matches);
                        if ($first[0] == '#' && $last[0] == '#') { //Colors must be in HEX format
                            $gradient_type = stripos($value, 'top') !== false ? 0 : 1;
                            $property->insert_after(
                                new css_property(
                                    'filter', "progid:DXImageTransform.Microsoft.gradient( startColorstr='{$this->_ie_filter_color(
                                                                                                                                  $first['color']
                                     )}', endColorstr='{$this->_ie_filter_color($last['color'])}',GradientType=$gradient_type)"
                                )
                            );
                        }
                    }
                }
            }
        }
    }

    protected array $_transformations = [
        // Property						Array(Mozilla, Webkit, Opera, Internet Explorer); NULL values are placeholders and will get ignored
        'animation'                           => [null, '-webkit-animation', null, null],
        'animation-delay'                     => [null, '-webkit-animation-delay', null, null],
        'animation-direction'                 => [null, '-webkit-animation-direction', null, null],
        'animation-duration'                  => [null, '-webkit-animation-duration', null, null],
        'animation-fill-mode'                 => [null, '-webkit-animation-fill-mode', null, null],
        'animation-iteration-count'           => [null, '-webkit-animation-iteration-count', null, null],
        'animation-name'                      => [null, '-webkit-animation-name', null, null],
        'animation-play-state'                => [null, '-webkit-animation-play-state', null, null],
        'animation-timing-function'           => [null, '-webkit-animation-timing-function', null, null],
        'appearance'                          => ['-moz-appearance', '-webkit-appearance', null, null],
        'backface-visibility'                 => [null, '-webkit-backface-visibility', null, null],
        'background-clip'                     => [null, '-webkit-background-clip', null, null],
        'background-composite'                => [null, '-webkit-background-composite', null, null],
        'background-inline-policy'            => ['-moz-background-inline-policy', null, null, null],
        'background-origin'                   => [null, '-webkit-background-origin', null, null],
        'background-position-x'               => [null, null, null, '-ms-background-position-x'],
        'background-position-y'               => [null, null, null, '-ms-background-position-y'],
        'background-size'                     => [null, '-webkit-background-size', null, null],
        'behavior'                            => [null, null, null, '-ms-behavior'],
        'binding'                             => ['-moz-binding', null, null, null],
        'border-after'                        => [null, '-webkit-border-after', null, null],
        'border-after-color'                  => [null, '-webkit-border-after-color', null, null],
        'border-after-style'                  => [null, '-webkit-border-after-style', null, null],
        'border-after-width'                  => [null, '-webkit-border-after-width', null, null],
        'border-before'                       => [null, '-webkit-border-before', null, null],
        'border-before-color'                 => [null, '-webkit-border-before-color', null, null],
        'border-before-style'                 => [null, '-webkit-border-before-style', null, null],
        'border-before-width'                 => [null, '-webkit-border-before-width', null, null],
        'border-border-bottom-colors'         => ['-moz-border-bottom-colors', null, null, null],
        'border-bottom-left-radius'           => ['-moz-border-radius-bottomleft', '-webkit-border-bottom-left-radius', null, null],
        'border-bottom-right-radius'          => ['-moz-border-radius-bottomright', '-webkit-border-bottom-right-radius', null, null],
        'border-end'                          => ['-moz-border-end', '-webkit-border-end', null, null],
        'border-end-color'                    => ['-moz-border-end-color', '-webkit-border-end-color', null, null],
        'border-end-style'                    => ['-moz-border-end-style', '-webkit-border-end-style', null, null],
        'border-end-width'                    => ['-moz-border-end-width', '-webkit-border-end-width', null, null],
        'border-fit'                          => [null, '-webkit-border-fit', null, null],
        'border-horizontal-spacing'           => [null, '-webkit-border-horizontal-spacing', null, null],
        'border-image'                        => ['-moz-border-image', '-webkit-border-image', null, null],
        'border-left-colors'                  => ['-moz-border-left-colors', null, null, null],
        'border-radius'                       => ['-moz-border-radius', '-webkit-border-radius', null, null],
        'border-top-right-radius'             => ['-moz-border-radius-topright', '-webkit-border-top-right-radius', null, null],
        'border-top-left-radius'              => ['-moz-border-radius-topleft', '-webkit-border-top-left-radius', null, null],
        'border-bottom-right-radius'          => ['-moz-border-radius-bottomright', '-webkit-border-bottom-right-radius', null, null],
        'border-bottom-left-radius'           => ['-moz-border-radius-bottomleft', '-webkit-border-bottom-left-radius', null, null],
        'border-border-right-colors'          => ['-moz-border-right-colors', null, null, null],
        'border-start'                        => ['-moz-border-start', '-webkit-border-start', null, null],
        'border-start-color'                  => ['-moz-border-start-color', '-webkit-border-start-color', null, null],
        'border-start-style'                  => ['-moz-border-start-style', '-webkit-border-start-style', null, null],
        'border-start-width'                  => ['-moz-border-start-width', '-webkit-border-start-width', null, null],
        'border-top-colors'                   => ['-moz-border-top-colors', null, null, null],
        'border-top-left-radius'              => ['-moz-border-radius-topleft', '-webkit-border-top-left-radius', null, null],
        'border-top-right-radius'             => ['-moz-border-radius-topright', '-webkit-border-top-right-radius', null, null],
        'border-vertical-spacing'             => [null, '-webkit-border-vertical-spacing', null, null],
        'box-align'                           => ['-moz-box-align', '-webkit-box-align', null, null],
        'box-direction'                       => ['-moz-box-direction', '-webkit-box-direction', null, null],
        'box-flex'                            => ['-moz-box-flex', '-webkit-box-flex', null, null],
        'box-flex-group'                      => [null, '-webkit-box-flex-group', null, null],
        'box-flex-lines'                      => [null, '-webkit-box-flex-lines', null, null],
        'box-ordinal-group'                   => ['-moz-box-ordinal-group', '-webkit-box-ordinal-group', null, null],
        'box-orient'                          => ['-moz-box-orient', '-webkit-box-orient', null, null],
        'box-pack'                            => ['-moz-box-pack', '-webkit-box-pack', null, null],
        'box-reflect'                         => [null, '-webkit-box-reflect', null, null],
        'box-shadow'                          => ['-moz-box-shadow', '-webkit-box-shadow', null, null],
        'box-sizing'                          => ['-moz-box-sizing', null, null, null],
        'color-correction'                    => [null, '-webkit-color-correction', null, null],
        'column-break-after'                  => [null, '-webkit-column-break-after', null, null],
        'column-break-before'                 => [null, '-webkit-column-break-before', null, null],
        'column-break-inside'                 => [null, '-webkit-column-break-inside', null, null],
        'column-count'                        => ['-moz-column-count', '-webkit-column-count', null, null],
        'column-gap'                          => ['-moz-column-gap', '-webkit-column-gap', null, null],
        'column-rule'                         => ['-moz-column-rule', '-webkit-column-rule', null, null],
        'column-rule-color'                   => ['-moz-column-rule-color', '-webkit-column-rule-color', null, null],
        'column-rule-style'                   => ['-moz-column-rule-style', '-webkit-column-rule-style', null, null],
        'column-rule-width'                   => ['-moz-column-rule-width', '-webkit-column-rule-width', null, null],
        'column-span'                         => [null, '-webkit-column-span', null, null],
        'column-width'                        => ['-moz-column-width', '-webkit-column-width', null, null],
        'columns'                             => [null, '-webkit-columns', null, null],
        'filter'                              => [__CLASS__, 'filter'],
        'float-edge'                          => ['-moz-float-edge', null, null, null],
        'font-feature-settings'               => ['-moz-font-feature-settings', null, null, null],
        'font-language-override'              => ['-moz-font-language-override', null, null, null],
        'font-size-delta'                     => [null, '-webkit-font-size-delta', null, null],
        'font-smoothing'                      => [null, '-webkit-font-smoothing', null, null],
        'force-broken-image-icon'             => ['-moz-force-broken-image-icon', null, null, null],
        'highlight'                           => [null, '-webkit-highlight', null, null],
        'hyphenate-character'                 => [null, '-webkit-hyphenate-character', null, null],
        'hyphenate-locale'                    => [null, '-webkit-hyphenate-locale', null, null],
        'hyphens'                             => [null, '-webkit-hyphens', null, null],
        'force-broken-image-icon'             => ['-moz-image-region', null, null, null],
        'ime-mode'                            => [null, null, null, '-ms-ime-mode'],
        'interpolation-mode'                  => [null, null, null, '-ms-interpolation-mode'],
        'layout-flow'                         => [null, null, null, '-ms-layout-flow'],
        'layout-grid'                         => [null, null, null, '-ms-layout-grid'],
        'layout-grid-char'                    => [null, null, null, '-ms-layout-grid-char'],
        'layout-grid-line'                    => [null, null, null, '-ms-layout-grid-line'],
        'layout-grid-mode'                    => [null, null, null, '-ms-layout-grid-mode'],
        'layout-grid-type'                    => [null, null, null, '-ms-layout-grid-type'],
        'line-break'                          => [null, '-webkit-line-break', null, '-ms-line-break'],
        'line-clamp'                          => [null, '-webkit-line-clamp', null, null],
        'line-grid-mode'                      => [null, null, null, '-ms-line-grid-mode'],
        'logical-height'                      => [null, '-webkit-logical-height', null, null],
        'logical-width'                       => [null, '-webkit-logical-width', null, null],
        'margin-after'                        => [null, '-webkit-margin-after', null, null],
        'margin-after-collapse'               => [null, '-webkit-margin-after-collapse', null, null],
        'margin-before'                       => [null, '-webkit-margin-before', null, null],
        'margin-before-collapse'              => [null, '-webkit-margin-before-collapse', null, null],
        'margin-bottom-collapse'              => [null, '-webkit-margin-bottom-collapse', null, null],
        'margin-collapse'                     => [null, '-webkit-margin-collapse', null, null],
        'margin-end'                          => ['-moz-margin-end', '-webkit-margin-end', null, null],
        'margin-start'                        => ['-moz-margin-start', '-webkit-margin-start', null, null],
        'margin-top-collapse'                 => [null, '-webkit-margin-top-collapse', null, null],
        'marquee '                            => [null, '-webkit-marquee', null, null],
        'marquee-direction'                   => [null, '-webkit-marquee-direction', null, null],
        'marquee-increment'                   => [null, '-webkit-marquee-increment', null, null],
        'marquee-repetition'                  => [null, '-webkit-marquee-repetition', null, null],
        'marquee-speed'                       => [null, '-webkit-marquee-speed', null, null],
        'marquee-style'                       => [null, '-webkit-marquee-style', null, null],
        'mask'                                => [null, '-webkit-mask', null, null],
        'mask-attachment'                     => [null, '-webkit-mask-attachment', null, null],
        'mask-box-image'                      => [null, '-webkit-mask-box-image', null, null],
        'mask-clip'                           => [null, '-webkit-mask-clip', null, null],
        'mask-composite'                      => [null, '-webkit-mask-composite', null, null],
        'mask-image'                          => [null, '-webkit-mask-image', null, null],
        'mask-origin'                         => [null, '-webkit-mask-origin', null, null],
        'mask-position'                       => [null, '-webkit-mask-position', null, null],
        'mask-position-x'                     => [null, '-webkit-mask-position-x', null, null],
        'mask-position-y'                     => [null, '-webkit-mask-position-y', null, null],
        'mask-repeat'                         => [null, '-webkit-mask-repeat', null, null],
        'mask-repeat-x'                       => [null, '-webkit-mask-repeat-x', null, null],
        'mask-repeat-y'                       => [null, '-webkit-mask-repeat-y', null, null],
        'mask-size'                           => [null, '-webkit-mask-size', null, null],
        'match-nearest-mail-blockquote-color' => [null, '-webkit-match-nearest-mail-blockquote-color', null, null],
        'max-logical-height'                  => [null, '-webkit-max-logical-height', null, null],
        'max-logical-width'                   => [null, '-webkit-max-logical-width', null, null],
        'min-logical-height'                  => [null, '-webkit-min-logical-height', null, null],
        'min-logical-width'                   => [null, '-webkit-min-logical-width', null, null],
        'object-fit'                          => [null, null, '-o-object-fit', null],
        'object-position'                     => [null, null, '-o-object-position', null],
        'opacity'                             => [__CLASS__, 'opacity'],
        'outline-radius'                      => ['-moz-outline-radius', null, null, null],
        'outline-bottom-left-radius'          => ['-moz-outline-radius-bottomleft', null, null, null],
        'outline-bottom-right-radius'         => ['-moz-outline-radius-bottomright', null, null, null],
        'outline-top-left-radius'             => ['-moz-outline-radius-topleft', null, null, null],
        'outline-top-right-radius'            => ['-moz-outline-radius-topright', null, null, null],
        'padding-after'                       => [null, '-webkit-padding-after', null, null],
        'padding-before'                      => [null, '-webkit-padding-before', null, null],
        'padding-end'                         => ['-moz-padding-end', '-webkit-padding-end', null, null],
        'padding-start'                       => ['-moz-padding-start', '-webkit-padding-start', null, null],
        'perspective'                         => [null, '-webkit-perspective', null, null],
        'perspective-origin'                  => [null, '-webkit-perspective-origin', null, null],
        'perspective-origin-x'                => [null, '-webkit-perspective-origin-x', null, null],
        'perspective-origin-y'                => [null, '-webkit-perspective-origin-y', null, null],
        'rtl-ordering'                        => [null, '-webkit-rtl-ordering', null, null],
        'scrollbar-3dlight-color'             => [null, null, null, '-ms-scrollbar-3dlight-color'],
        'scrollbar-arrow-color'               => [null, null, null, '-ms-scrollbar-arrow-color'],
        'scrollbar-base-color'                => [null, null, null, '-ms-scrollbar-base-color'],
        'scrollbar-darkshadow-color'          => [null, null, null, '-ms-scrollbar-darkshadow-color'],
        'scrollbar-face-color'                => [null, null, null, '-ms-scrollbar-face-color'],
        'scrollbar-highlight-color'           => [null, null, null, '-ms-scrollbar-highlight-color'],
        'scrollbar-shadow-color'              => [null, null, null, '-ms-scrollbar-shadow-color'],
        'scrollbar-track-color'               => [null, null, null, '-ms-scrollbar-track-color'],
        'stack-sizing'                        => ['-moz-stack-sizing', null, null, null],
        'svg-shadow'                          => [null, '-webkit-svg-shadow', null, null],
        'tab-size'                            => ['-moz-tab-size', null, '-o-tab-size', null],
        'table-baseline'                      => [null, null, '-o-table-baseline', null],
        'text-align-last'                     => [null, null, null, '-ms-text-align-last'],
        'text-autospace'                      => [null, null, null, '-ms-text-autospace'],
        'text-combine'                        => [null, '-webkit-text-combine', null, null],
        'text-decorations-in-effect'          => [null, '-webkit-text-decorations-in-effect', null, null],
        'text-emphasis'                       => [null, '-webkit-text-emphasis', null, null],
        'text-emphasis-color'                 => [null, '-webkit-text-emphasis-color', null, null],
        'text-emphasis-position'              => [null, '-webkit-text-emphasis-position', null, null],
        'text-emphasis-style'                 => [null, '-webkit-text-emphasis-style', null, null],
        'text-fill-color'                     => [null, '-webkit-text-fill-color', null, null],
        'text-justify'                        => [null, null, null, '-ms-text-justify'],
        'text-kashida-space'                  => [null, null, null, '-ms-text-kashida-space'],
        'text-overflow'                       => [null, null, '-o-text-overflow', '-ms-text-overflow'],
        'text-security'                       => [null, '-webkit-text-security', null, null],
        'text-size-adjust'                    => [null, '-webkit-text-size-adjust', null, '-ms-text-size-adjust'],
        'text-stroke'                         => [null, '-webkit-text-stroke', null, null],
        'text-stroke-color'                   => [null, '-webkit-text-stroke-color', null, null],
        'text-stroke-width'                   => [null, '-webkit-text-stroke-width', null, null],
        'text-underline-position'             => [null, null, null, '-ms-text-underline-position'],
        'transform'                           => ['-moz-transform', '-webkit-transform', '-o-transform', '-ms-transform'],
        'transform-origin'                    => ['-moz-transform-origin', '-webkit-transform-origin', '-o-transform-origin', null],
        'transform-origin-x'                  => [null, '-webkit-transform-origin-x', null, null],
        'transform-origin-y'                  => [null, '-webkit-transform-origin-y', null, null],
        'transform-origin-z'                  => [null, '-webkit-transform-origin-z', null, null],
        'transform-style'                     => [null, '-webkit-transform-style', null, null],
        'transition'                          => ['-moz-transition', '-webkit-transition', '-o-transition', null],
        'transition-delay'                    => ['-moz-transition-delay', '-webkit-transition-delay', '-o-transition-delay', null],
        'transition-duration'                 => ['-moz-transition-duration', '-webkit-transition-duration', '-o-transition-duration', null],
        'transition-property'                 => ['-moz-transition-property', '-webkit-transition-property', '-o-transition-property', null],
        'transition-timing-function'          => ['-moz-transition-timing-function', '-webkit-transition-timing-function', '-o-transition-timing-function', null],
        'user-drag'                           => [null, '-webkit-user-drag', null, null],
        'user-focus'                          => ['-moz-user-focus', null, null, null],
        'user-input'                          => ['-moz-user-input', null, null, null],
        'user-modify'                         => ['-moz-user-modify', '-webkit-user-modify', null, null],
        'user-select'                         => ['-moz-user-select', '-webkit-user-select', null, null],
        'white-space'                         => [__CLASS__, 'whiteSpace'],
        'window-shadow'                       => ['-moz-window-shadow', null, null, null],
        'word-break'                          => [null, null, null, '-ms-word-break'],
        'word-wrap'                           => [null, null, null, '-ms-word-wrap'],
        'writing-mode'                        => [null, '-webkit-writing-mode', null, '-ms-writing-mode'],
        'zoom'                                => [null, null, null, '-ms-zoom']
    ];

}
