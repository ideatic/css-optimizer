<?php

declare(strict_types=1);

/**
 * css_prefixer - Add vendor prefixes to css documents
 *
 * Las declaraciones con prefijo se colocan siempre antes de la estándar, para que los navegadores que
 * conocen la sintaxis estándar usen ésta.
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

    /**
     * Índice de cada navegador en las listas de $_transformations
     */
    private const array VENDORS = [
        0 => 'mozilla',
        1 => 'webkit',
        2 => 'opera',
        3 => 'msie',
    ];

    public function add_prefixes(css_group $css_doc): void
    {
        $this->_add_prefixes($css_doc);
    }

    /**
     * @param array<int|string, bool>|null $vendor_override Navegadores a usar en lugar de los configurados
     */
    protected function _add_prefixes(css_group $css_doc, ?array $vendor_override = null, bool $ignore_keyframes = false, bool $remove_original_property = false): void
    {
        // Aplicar temporalmente los navegadores indicados
        $originals = [];
        $apply_vendors = [];
        foreach (self::VENDORS as $id => $vendor) {
            $originals[$vendor] = $this->$vendor;
            if ($vendor_override !== null) {
                $this->$vendor = $vendor_override[$id] ?? ($vendor_override[$vendor] ?? false);
            }
            $apply_vendors[$id] = $this->$vendor;
        }

        foreach ($css_doc->find_all(css_property::class) as $property) {
            // Las propiedades personalizadas no se prefijan
            if ($property->parent === null || str_starts_with($property->name, '--')) {
                continue;
            }

            // Comprobar si la propiedad está dentro de un @keyframes. Los @keyframes con prefijo ya son de un
            // navegador concreto y no se tocan
            $keyframe = null;
            if (!$ignore_keyframes) {
                foreach ($property->parents() as $parent) {
                    if ($parent instanceof css_group && stripos($parent->name, '@keyframes') === 0) {
                        $keyframe = $parent;
                    } elseif ($parent instanceof css_group && preg_match('/^@-\w+-keyframes/i', $parent->name) === 1) {
                        continue 2;
                    }
                }
            }

            if ($keyframe !== null) {
                // Crear un @keyframes para cada navegador, cada uno con sus propios prefijos
                $this->_prefix_keyframe($keyframe, $apply_vendors);
            } elseif (array_key_exists($property->name, $this->_transformations)) {
                $applied = $this->_apply_transformation($property);

                if ($applied && $remove_original_property) {
                    $property->remove();
                }
            } else {
                // Funciones con prefijo (degradados)
                $this->_prefix_gradients($property);
            }
        }

        // Restaurar los valores originales
        foreach ($originals as $vendor => $value) {
            $this->$vendor = $value;
        }
    }

    private function _apply_transformation(css_property $property): bool
    {
        $transformation = $this->_transformations[$property->name];
        if ($transformation instanceof Closure) {
            $transformation($property, $this);
            return false;
        }

        $applied = false;
        foreach ($transformation as $vendor_id => $new_name) {
            if ($new_name === null || !$this->{self::VENDORS[$vendor_id]}) {
                continue;
            }

            // Comprobar si la propiedad ya está definida
            foreach ($property->siblings(css_property::class) as $sibling) {
                if ($sibling->name === $new_name) {
                    continue 2;
                }
            }

            $property->insert_before(new css_property($new_name, $property->value));
            $applied = true;
        }
        return $applied;
    }

    /**
     * @param array<int, bool> $apply_list
     */
    private function _prefix_keyframe(css_group $keyframe, array $apply_list): void
    {
        $prefixes = [
            3 => '@-ms-keyframes',
            2 => '@-o-keyframes',
            0 => '@-moz-keyframes',
            1 => '@-webkit-keyframes',
        ];

        foreach ($prefixes as $id => $prefix) {
            if (!($apply_list[$id] ?? false)) {
                continue;
            }

            $new_name = preg_replace('/^@keyframes/i', $prefix, $keyframe->name) ?? $keyframe->name;

            // Comprobar si ya existe el @keyframes con prefijo
            foreach ($keyframe->siblings(css_group::class) as $sibling) {
                if ($sibling->name === $new_name) {
                    continue 2;
                }
            }

            // Crear un nuevo @keyframes sólo con los prefijos del navegador actual
            $new_keyframe = $keyframe->make_clone();
            $new_keyframe->name = $new_name;
            $keyframe->insert_before($new_keyframe);
            $this->_add_prefixes($new_keyframe, [$id => true], true, true);
        }
    }

    /**
     * Transforms the Internet Explorer specific declaration property "filter" to Internet Explorer 8+ compatible
     * declaration property "-ms-filter". Sólo para filtros de IE: los filtros modernos (blur(), drop-shadow()...) no se tocan.
     */
    protected static function filter(css_property $property, css_prefixer $prefixer): void
    {
        if ($prefixer->msie && stripos($property->value, 'progid:') === 0) {
            self::_insert_once($property, '-ms-filter', !str_contains($property->value, "'") ? "'{$property->value}'" : "\"{$property->value}\"");
        }
    }

    /**
     * Transforms "opacity: {value}" into Internet Explorer specific counterparts.
     */
    protected static function opacity(css_property $property, css_prefixer $prefixer): void
    {
        if ($prefixer->msie && is_numeric($property->value)) {
            $ie_value = (int)round((float)$property->value * 100);

            // Internet Explorer >= 8
            self::_insert_once($property, '-ms-filter', "\"alpha(opacity={$ie_value})\"");
            // Internet Explorer >= 4 <= 7
            self::_insert_once($property, 'filter', "alpha(opacity={$ie_value})");
        }
    }

    /**
     * Transforms "white-space: pre-wrap" into browser specific counterparts.
     */
    protected static function whiteSpace(css_property $property, css_prefixer $prefixer): void
    {
        if (strtolower($property->value) !== 'pre-wrap') {
            return;
        }

        // Firefox < 3
        if ($prefixer->mozilla) {
            self::_insert_once($property, 'white-space', '-moz-pre-wrap');
        }
        // Webkit
        if ($prefixer->webkit) {
            self::_insert_once($property, 'white-space', '-webkit-pre-wrap');
        }
        if ($prefixer->opera) {
            // Opera >= 4 <= 6
            self::_insert_once($property, 'white-space', '-pre-wrap');
            // Opera >= 7
            self::_insert_once($property, 'white-space', '-o-pre-wrap');
        }
    }

    /**
     * Inserta una declaración antes de la propiedad, si el bloque no la tiene ya
     */
    private static function _insert_once(css_property $property, string $name, string $value): void
    {
        foreach ($property->siblings(css_property::class) as $sibling) {
            if ($sibling->name === $name && $sibling->value === $value) {
                return;
            }
        }
        $property->insert_before($name, $value);
    }

    /**
     * Añade versiones con prefijo de los degradados, con la sintaxis antigua. Sólo cuando la conversión es
     * exacta: dirección "to <lado>", ángulos en grados o sin dirección; degradados radiales sin "at"
     */
    protected function _prefix_gradients(css_property $property): void
    {
        $gradient_transforms = [
            'linear-gradient'           => ['-moz-linear-gradient', '-webkit-linear-gradient', '-o-linear-gradient', null],
            'repeating-linear-gradient' => ['-moz-repeating-linear-gradient', '-webkit-repeating-linear-gradient', '-o-repeating-linear-gradient', null],
            'radial-gradient'           => ['-moz-radial-gradient', '-webkit-radial-gradient', '-o-radial-gradient', null],
            'repeating-radial-gradient' => ['-moz-repeating-radial-gradient', '-webkit-repeating-radial-gradient', '-o-repeating-radial-gradient', null],
        ];

        if (stripos($property->value, 'gradient') === false) {
            return;
        }

        foreach (self::VENDORS as $vendor_id => $vendor) {
            if (!$this->$vendor) {
                continue;
            }

            $tokens = css_value::tokenize($property->value);
            if ($tokens === null) {
                return;
            }

            // Convertir los degradados de primer nivel a la sintaxis antigua
            $converted = false;
            foreach ($tokens as $token) {
                $function = strtolower($token->text);
                if ($token->type !== css_token::FUNCTION || !isset($gradient_transforms[$function])) {
                    continue;
                }

                $new_name = $gradient_transforms[$function][$vendor_id];
                $legacy_args = $new_name === null ? null : self::_legacy_gradient_args($token->args, str_contains($function, 'linear'));
                if ($new_name === null || $legacy_args === null) {
                    continue 2; // No se puede convertir: no se añade este prefijo
                }

                $token->text = $new_name;
                $token->args = $legacy_args;
                $converted = true;
            }

            if (!$converted) {
                continue;
            }

            // Comprobar si el valor ya está definido
            $new_value = css_value::render($tokens);
            foreach ($property->siblings(css_property::class) as $sibling) {
                if ($sibling->name === $property->name && $sibling->value === $new_value) {
                    continue 2;
                }
            }

            $property->insert_before(new css_property($property->name, $new_value));
        }
    }

    /**
     * Convierte los argumentos de un degradado a la sintaxis antigua con prefijo, o null si no es posible
     *
     * @param list<css_token> $args
     * @return list<css_token>|null
     */
    private static function _legacy_gradient_args(array $args, bool $linear): ?array
    {
        // Separar el primer argumento
        $comma = null;
        foreach ($args as $index => $token) {
            if ($token->type === css_token::COMMA) {
                $comma = $index;
                break;
            }
        }
        if ($comma === null) {
            return null;
        }

        $first = array_values(array_filter(array_slice($args, 0, $comma), static fn(css_token $token): bool => $token->type !== css_token::SPACE));
        $rest = array_slice($args, $comma);
        $words = array_map(static fn(css_token $token): string => $token->type === css_token::WORD ? strtolower($token->text) : '', $first);

        // Degradados radiales: la sintaxis antigua no tiene "at <posición>",
        // y un primer tamaño (50px, 10% 20%) se lee como posición
        if (!$linear) {
            foreach ($words as $word) {
                if ($word === 'at' || $word === '' || preg_match('/^[+-]?\.?\d/', $word) === 1) {
                    return null;
                }
            }
            return $args;
        }

        // Sin dirección: el primer argumento es un color
        if ($words === [] || ($words[0] !== 'to' && preg_match('/^[+-]?(\d*\.)?\d+(deg|grad|rad|turn)$/', $words[0]) !== 1)) {
            return $args;
        }

        // "to bottom right" -> "top left" (la sintaxis antigua indica el punto de inicio)
        if ($words[0] === 'to') {
            $opposites = ['top' => 'bottom', 'bottom' => 'top', 'left' => 'right', 'right' => 'left'];
            $sides = array_slice($words, 1);
            if ($sides === [] || count($sides) > 2) {
                return null;
            }
            foreach ($sides as $side) {
                if (!isset($opposites[$side])) {
                    return null;
                }
            }
            $direction = implode(' ', array_map(static fn(string $side): string => $opposites[$side], $sides));
            return [new css_token(css_token::WORD, $direction), ...$rest];
        }

        // Ángulos: en la sintaxis antigua 0deg apunta a la derecha y crecen en sentido antihorario
        if (count($words) !== 1 || preg_match('/^([+-]?(?:\d*\.)?\d+)deg$/', $words[0], $match) !== 1) {
            return null;
        }
        $angle = rtrim(rtrim(sprintf('%.4F', 90 - (float)$match[1]), '0'), '.');
        return [new css_token(css_token::WORD, "{$angle}deg"), ...$rest];
    }

    /**
     * @var array<string, list<string|null>|Closure(css_property, css_prefixer): void>
     */
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
        'border-border-right-colors'          => ['-moz-border-right-colors', null, null, null],
        'border-start'                        => ['-moz-border-start', '-webkit-border-start', null, null],
        'border-start-color'                  => ['-moz-border-start-color', '-webkit-border-start-color', null, null],
        'border-start-style'                  => ['-moz-border-start-style', '-webkit-border-start-style', null, null],
        'border-start-width'                  => ['-moz-border-start-width', '-webkit-border-start-width', null, null],
        'border-top-colors'                   => ['-moz-border-top-colors', null, null, null],
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
        'filter'                              => self::filter(...),
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
        'image-region'                        => ['-moz-image-region', null, null, null],
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
        'marquee'                             => [null, '-webkit-marquee', null, null],
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
        'opacity'                             => self::opacity(...),
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
        'white-space'                         => self::whiteSpace(...),
        'window-shadow'                       => ['-moz-window-shadow', null, null, null],
        'word-break'                          => [null, null, null, '-ms-word-break'],
        'word-wrap'                           => [null, null, null, '-ms-word-wrap'],
        'writing-mode'                        => [null, '-webkit-writing-mode', null, '-ms-writing-mode'],
        'zoom'                                => [null, null, null, '-ms-zoom'],
    ];

}
