<?php

declare(strict_types=1);

/**
 * css_optimizer - Optimize, compress and add vendor prefixes in your CSS files for cross browser compatibility
 *
 * Cada optimización se aplica sólo donde se sabe que el resultado es equivalente. Todo lo que el optimizador
 * no entiende (sintaxis nueva, at-rules desconocidas, propiedades personalizadas...) se deja tal cual.
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
     * Remove comments (except /*! important comments)
     */
    public bool $remove_comments = true;

    /**
     * Optimize CSS colors, units, etc.
     */
    public bool $optimize = true;

    /**
     * Unir reglas contiguas con las mismas declaraciones
     */
    public bool $extra_optimize = false;

    /**
     * Remove Internet Explorer hacks (filter, expressions, ...)
     */
    public bool $remove_ie_hacks = false;

    /**
     * Eliminar reglas vacías
     */
    public bool $remove_empty = true;

    /**
     * Prefijos de navegador a añadir: 'all', '' (ninguno) o una lista separada por comas de webkit, mozilla, opera, msie
     */
    public string $prefixes = 'all';

    /**
     * At-rules cuyo contenido son declaraciones normales, que se pueden optimizar.
     * Los demás (@font-face, @property, @counter-style, @page, desconocidos...) se dejan intactos
     */
    private const array OPTIMIZABLE_AT_RULES = [
        ...css_value::STYLE_AT_RULES,
        'keyframes', '-webkit-keyframes', '-moz-keyframes', '-o-keyframes', '-ms-keyframes', 'position-try',
    ];

    /**
     * At-rules que se pueden eliminar cuando están vacías (un @layer o @keyframes vacío sí tiene efecto)
     */
    private const array REMOVABLE_EMPTY_AT_RULES = ['media', 'supports', 'container', 'scope', 'starting-style', 'document', '-moz-document'];

    /**
     * Propiedades que aceptan colores, en las que se pueden comprimir los nombres de color
     */
    private const array COLOR_PROPERTIES = [
        'background', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-block',
        'border-inline', 'border-block-start', 'border-block-end', 'border-inline-start', 'border-inline-end',
        'outline', 'box-shadow', 'text-shadow', 'text-decoration', 'text-emphasis', 'column-rule', 'fill', 'stroke',
        '-webkit-text-stroke',
    ];

    /**
     * Propiedades con 1 a 4 valores (arriba, derecha, abajo, izquierda) que se pueden abreviar
     */
    private const array BOX_PROPERTIES = [
        'margin', 'padding', 'inset', 'border-width', 'border-style', 'border-color', 'border-radius', 'scroll-margin',
        'scroll-padding',
    ];

    /**
     * Propiedades con 1 o 2 valores, donde un único valor se aplica a los dos
     */
    private const array PAIR_PROPERTIES = [
        'margin-block', 'margin-inline', 'padding-block', 'padding-inline', 'inset-block', 'inset-inline',
        'scroll-margin-block', 'scroll-margin-inline', 'scroll-padding-block', 'scroll-padding-inline', 'gap',
        'border-spacing', 'overflow',
    ];

    /**
     * Propiedades donde un 0 sin unidades no equivale a una longitud cero
     */
    private const array UNITLESS_ZERO_UNSAFE = ['flex', 'flex-basis', '-webkit-flex', '-ms-flex', '-webkit-flex-basis'];

    private const string LENGTH_UNITS = 'px|em|rem|ex|rex|ch|rch|ic|ric|cap|rcap|lh|rlh|vw|vh|vi|vb|vmin|vmax|svw|svh|svi|svb|svmin|svmax|lvw|lvh|lvi|lvb|lvmin|lvmax|dvw|dvh|dvi|dvb|dvmin|dvmax|cqw|cqh|cqi|cqb|cqmin|cqmax|cm|mm|q|in|pt|pc';

    /**
     * Valores para las propiedades públicas de la clase. Los ajustes booleanos también aceptan cadenas y enteros
     * ('0', 'false', 'off'...), porque suelen venir de ficheros de configuración o de la línea de comandos
     *
     * @param array{
     *     compress?: bool|int|string,
     *     remove_comments?: bool|int|string,
     *     optimize?: bool|int|string,
     *     extra_optimize?: bool|int|string,
     *     remove_ie_hacks?: bool|int|string,
     *     remove_empty?: bool|int|string,
     *     prefixes?: string|bool|null,
     * }|null $settings
     */
    public function __construct(?array $settings = null)
    {
        foreach ($settings ?? [] as $setting => $value) {
            if (!property_exists($this, $setting) || !new ReflectionProperty($this, $setting)->isPublic()) {
                throw new InvalidArgumentException("Unknown css_optimizer setting '{$setting}'");
            }

            // Los ajustes pueden venir de la configuración o de la línea de comandos como cadenas o enteros
            if ($setting === 'prefixes') {
                $this->prefixes = match (true) {
                    $value === true => 'all',
                    $value === false, $value === null => '',
                    is_scalar($value) => (string)$value,
                    default => throw new InvalidArgumentException("Invalid value for css_optimizer setting 'prefixes'"),
                };
            } else {
                $this->$setting = is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (bool)$value;
            }
        }
    }

    /**
     * Optimize an input CSS string or parsed css_group
     *
     * @return ($css is string ? string : css_group)
     */
    public function process(css_group|string $css): css_group|string
    {
        // Leer CSS
        $document = $css instanceof css_group ? $css : new css_parser()->parse($css);

        // Eliminar comentarios, salvo los importantes (/*! licencia */)
        if ($this->remove_comments) {
            foreach ($document->find_all(css_element::class) as $element) {
                if ($element->type === 'comment' && !str_starts_with($element->value, '/*!')) {
                    $element->remove();
                }
            }
        }

        // Nombres de propiedad en minúsculas (las propiedades personalizadas distinguen mayúsculas)
        foreach ($document->find_all(css_property::class) as $property) {
            if (!str_starts_with($property->name, '--')) {
                $property->name = strtolower($property->name);
            }
        }

        if ($this->remove_ie_hacks) {
            $this->_remove_ie_hacks($document);
        }

        if ($this->optimize) {
            $this->_optimize($document);
        }

        if ($this->remove_empty) {
            $this->_remove_empty($document);
        }

        if ($this->extra_optimize) {
            $this->_extra_optimize($document);
        }

        // Añadir prefijos de navegador
        if ($this->prefixes !== '') {
            $options = array_map(trim(...), explode(',', strtolower($this->prefixes)));
            $all = in_array('all', $options, true);

            $prefixer = new css_prefixer();
            $prefixer->webkit = $all || in_array('webkit', $options, true);
            $prefixer->mozilla = $all || in_array('mozilla', $options, true) || in_array('firefox', $options, true) || in_array('moz', $options, true);
            $prefixer->opera = $all || in_array('opera', $options, true);
            $prefixer->msie = $all || in_array('msie', $options, true) || in_array('ms', $options, true);
            $prefixer->add_prefixes($document);
        }

        return $css instanceof css_group ? $document : $document->render($this->compress);
    }

    protected function _optimize(css_group $document): void
    {
        // Unir propiedades individuales en su abreviatura (margin-top... -> margin)
        foreach ($document->find_all(css_property::class) as $property) {
            if ($property->parent !== null && $this->_is_optimizable($property)) {
                $this->_shorthand($property);
            }
        }

        // Optimizar valores
        foreach ($document->find_all(css_property::class) as $property) {
            if ($property->parent === null || !$this->_is_optimizable($property)) {
                continue;
            }

            $tokens = css_value::tokenize($property->value);
            if ($tokens === null) {
                continue; // Valor mal formado: no se toca
            }

            $tokens = $this->_optimize_tokens($tokens);
            $tokens = $this->_optimize_top_level($property->name, $tokens);
            $property->value = css_value::render($tokens);
        }
    }

    /**
     * Indica si el valor de una propiedad se puede optimizar sin riesgo
     */
    private function _is_optimizable(css_property $property): bool
    {
        // Las propiedades personalizadas son secuencias de tokens: su texto importa (consultas de estilo, JavaScript...)
        if ($property->name === '' || str_starts_with($property->name, '--')) {
            return false;
        }

        // Filtros y expresiones antiguas de Internet Explorer
        if (preg_match('/progid:|expression\s*\(/i', $property->value) === 1) {
            return false;
        }

        // Sólo dentro de reglas de estilo y at-rules conocidas
        foreach ($property->parents(false) as $parent) {
            if ($parent instanceof css_group && str_starts_with($parent->name, '@')) {
                if (!in_array(css_value::at_rule_name($parent->name), self::OPTIMIZABLE_AT_RULES, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Optimizaciones seguras a cualquier nivel, también dentro de funciones
     *
     * @param list<css_token> $tokens
     * @return list<css_token>
     */
    private function _optimize_tokens(array $tokens): array
    {
        // Quitar los espacios que no hacen falta: al principio y al final, junto a comas y antes de '!'
        $result = [];
        foreach ($tokens as $index => $token) {
            if ($token->type === css_token::SPACE) {
                $previous = array_last($result);
                $next = $tokens[$index + 1] ?? null;
                if ($previous === null || $next === null
                    || in_array($previous->type, [css_token::COMMA, css_token::BANG], true)
                    || in_array($next->type, [css_token::COMMA, css_token::BANG], true)) {
                    continue;
                }
                $token->text = ' ';
            } elseif ($token->type === css_token::FUNCTION) {
                $token->args = $this->_optimize_tokens($token->args);
            } elseif ($token->type === css_token::WORD) {
                // 0.5 -> .5
                $token->text = preg_replace('/^([+-]?)0+(\.\d)/', '$1$2', $token->text) ?? $token->text;
            }
            $result[] = $token;
        }

        return $result;
    }

    /**
     * Optimizaciones que dependen de la propiedad, sólo sobre el valor principal (fuera de funciones)
     *
     * @param list<css_token> $tokens
     * @return list<css_token>
     */
    private function _optimize_top_level(string $name, array $tokens): array
    {
        // Longitudes cero: 0px -> 0
        if (!in_array($name, self::UNITLESS_ZERO_UNSAFE, true)) {
            foreach ($tokens as $token) {
                if ($token->type === css_token::WORD && preg_match('/^[+-]?(?:0+\.?0*|\.0+)(?:' . self::LENGTH_UNITS . ')$/i', $token->text) === 1) {
                    $token->text = '0';
                }
            }
        }

        // Colores
        if (in_array($name, self::COLOR_PROPERTIES, true) || str_ends_with($name, 'color')) {
            foreach ($tokens as $token) {
                $this->_compress_color($token);
            }
        }

        // Pesos de fuente
        if ($name === 'font-weight' && count($tokens) === 1 && $tokens[0]->type === css_token::WORD) {
            $tokens[0]->text = match (strtolower($tokens[0]->text)) {
                'normal' => '400',
                'bold' => '700',
                default => $tokens[0]->text,
            };
        }

        // Posición de fondo con dos palabras clave: top left -> 0 0
        if ($name === 'background-position') {
            $this->_optimize_background_position($tokens);
        }

        // Valores repetidos: margin: 1px 1px 1px 1px -> margin: 1px
        $words = [];
        foreach ($tokens as $token) {
            if ($token->type === css_token::WORD) {
                $words[] = $token->text;
            } elseif ($token->type !== css_token::SPACE) {
                return $tokens; // Funciones, barras, !important...: no se abrevia
            }
        }

        $words = match (true) {
            in_array($name, self::BOX_PROPERTIES, true) && count($words) >= 2 && count($words) <= 4 => self::_collapse_box($words),
            in_array($name, self::PAIR_PROPERTIES, true) && count($words) === 2 && $words[0] === $words[1] => [$words[0]],
            default => null,
        };

        if ($words === null) {
            return $tokens;
        }

        $result = [];
        foreach ($words as $word) {
            if ($result !== []) {
                $result[] = new css_token(css_token::SPACE, ' ');
            }
            $result[] = new css_token(css_token::WORD, $word);
        }
        return $result;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function _collapse_box(array $values): array
    {
        if (count($values) === 4 && $values[3] === $values[1]) {
            array_pop($values);
        }
        if (count($values) === 3 && $values[2] === $values[0]) {
            array_pop($values);
        }
        if (count($values) === 2 && $values[1] === $values[0]) {
            array_pop($values);
        }
        return $values;
    }

    private function _compress_color(css_token $token): void
    {
        $original = match ($token->type) {
            css_token::WORD => $token->text,
            css_token::FUNCTION => css_value::render([$token]),
            default => null,
        };

        // Sólo colores hexadecimales de 3 o 6 cifras, nombres CSS y funciones rgb()/hsl()
        if ($original === null
            || ($token->type === css_token::WORD && preg_match('/^(#([0-9a-f]{3}|[0-9a-f]{6})|[a-z]+)$/i', $original) !== 1)
            || ($token->type === css_token::FUNCTION && preg_match('/^(rgb|hsl)a?$/i', $token->text) !== 1)
            || ($token->type === css_token::WORD && $original[0] !== '#' && !isset(css_color::color_names()[strtolower($original)]))) {
            return;
        }

        $color = new css_color($original);
        if (!$color->valid || !$color->exact || $color->a != 1) {
            return;
        }

        $hex = $color->to_hex();
        if (strlen($hex) < strlen($original) || ($original[0] === '#' && strlen($hex) === strlen($original))) {
            $token->type = css_token::WORD;
            $token->text = $hex;
            $token->args = [];
        }
    }

    /**
     * @param list<css_token> $tokens
     */
    private function _optimize_background_position(array $tokens): void
    {
        $horizontal = ['left' => '0', 'center' => '50%', 'right' => '100%'];
        $vertical = ['top' => '0', 'center' => '50%', 'bottom' => '100%'];

        // Cada capa separada por comas se trata por separado; sólo se cambian las que tienen exactamente dos palabras clave
        $layers = [[]];
        foreach ($tokens as $token) {
            if ($token->type === css_token::COMMA) {
                $layers[] = [];
            } elseif ($token->type !== css_token::SPACE) {
                $layers[array_key_last($layers)][] = $token;
            }
        }

        foreach ($layers as $layer) {
            if (count($layer) !== 2 || $layer[0]->type !== css_token::WORD || $layer[1]->type !== css_token::WORD) {
                continue;
            }

            [$first, $second] = [strtolower($layer[0]->text), strtolower($layer[1]->text)];
            if (isset($horizontal[$first], $vertical[$second])) {
                [$x, $y] = [$horizontal[$first], $vertical[$second]];
            } elseif (isset($vertical[$first], $horizontal[$second])) {
                [$x, $y] = [$horizontal[$second], $vertical[$first]];
            } else {
                continue;
            }

            $layer[0]->text = $x;
            $layer[1]->text = $y;
        }
    }

    /**
     * Une margin-top, margin-right, margin-bottom y margin-left (y equivalentes) en su abreviatura, sólo
     * cuando el resultado es idéntico: cada una aparece una sola vez, con un valor simple y sin !important,
     * y no hay otras propiedades que las afecten en el mismo bloque
     */
    private function _shorthand(css_property $property): void
    {
        $shorthands = [
            'margin'       => ['margin-top', 'margin-right', 'margin-bottom', 'margin-left'],
            'padding'      => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left'],
            'border-width' => ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'],
        ];
        $conflicts = [
            'margin'       => '/^(-\w+-)?margin(-|$)/',
            'padding'      => '/^(-\w+-)?padding(-|$)/',
            'border-width' => '/^(-\w+-)?border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-width)?$/',
        ];

        foreach ($shorthands as $shorthand => $longhands) {
            if (!in_array($property->name, $longhands, true)) {
                continue;
            }

            $found = [];
            foreach ($property->siblings(css_property::class, true) as $sibling) {
                if (in_array($sibling->name, $longhands, true)) {
                    if (isset($found[$sibling->name])) {
                        return; // Repetida (por ejemplo, como alternativa para navegadores antiguos)
                    }
                    if (!self::_is_simple_value($sibling->value)) {
                        return;
                    }
                    $found[$sibling->name] = $sibling;
                } elseif (preg_match($conflicts[$shorthand], $sibling->name) === 1) {
                    return;
                }
            }

            if (count($found) !== count($longhands)) {
                return;
            }

            // Sustituir por la abreviatura en la posición de la propiedad actual
            $values = [];
            foreach ($longhands as $name) {
                $values[] = $found[$name]->value;
                if ($found[$name] !== $property) {
                    $found[$name]->remove();
                }
            }
            $property->name = $shorthand;
            $property->value = implode(' ', $values);
            return;
        }
    }

    /**
     * Un único valor sin !important, sin variables y sin palabras clave globales
     */
    private static function _is_simple_value(string $value): bool
    {
        $tokens = css_value::tokenize($value);
        return $tokens !== null
               && count($tokens) === 1
               && in_array($tokens[0]->type, [css_token::WORD, css_token::FUNCTION], true)
               && preg_match('/\b(var|env|attr)\s*\(/i', $value) !== 1
               && !in_array(strtolower($value), ['inherit', 'initial', 'unset', 'revert', 'revert-layer'], true);
    }

    /**
     * Elimina los grupos vacíos, empezando por los más internos
     */
    private function _remove_empty(css_group $group): void
    {
        foreach ($group->children as $child) {
            if (!$child instanceof css_group) {
                continue;
            }

            $this->_remove_empty($child);

            $removable = !str_starts_with($child->name, '@') || in_array(css_value::at_rule_name($child->name), self::REMOVABLE_EMPTY_AT_RULES, true);
            if ($child->children === [] && $removable) {
                $child->remove();
            }
        }
    }

    /**
     * @see http://net.tutsplus.com/tutorials/html-css-techniques/quick-tip-how-to-target-ie6-ie7-and-ie8-uniquely-with-4-characters/
     */
    protected function _remove_ie_hacks(css_group $document): void
    {
        foreach ($document->find_all(css_property::class) as $property) {
            $is_hack = (in_array($property->name, ['filter', '-ms-filter'], true) && preg_match('/progid:|alpha\s*\(/i', $property->value) === 1) // Filtros de IE
                       || str_starts_with($property->name, '*') || str_starts_with($property->name, '_') // _width, *background
                       || stripos($property->value, 'expression') === 0 // Expresiones CSS
                       || str_ends_with($property->value, '\9'); // IE8

            if ($is_hack) {
                $property->remove();
            }
        }
    }

    /**
     * Une reglas consecutivas con las mismas declaraciones: .a{x} .b{x} -> .a,.b{x}
     * Sólo se unen reglas contiguas (unir reglas separadas cambiaría el orden de la cascada) y
     * selectores con pseudo-clases conocidas (un selector no soportado invalidaría toda la lista)
     */
    protected function _extra_optimize(css_group $document): void
    {
        foreach ([$document, ...$document->find_all(css_group::class)] as $group) {
            $previous = null;
            $previous_body = null;

            foreach ($group->children as $child) {
                if (!$child instanceof css_group || !self::_is_mergeable($child)) {
                    $previous = null;
                    continue;
                }

                $body = implode('', array_map(static fn(css_element $element): string => $element->render(true), $child->children));
                if ($previous !== null && $body === $previous_body) {
                    $previous->selectors([...$previous->selectors(), ...$child->selectors()]);
                    $child->remove();
                    continue;
                }

                $previous = $child;
                $previous_body = $body;
            }
        }
    }

    private static function _is_mergeable(css_group $group): bool
    {
        if ($group->name === '' || str_starts_with($group->name, '@')) {
            return false;
        }

        $safe_pseudo = [
            'hover', 'focus', 'active', 'visited', 'link', 'first-child', 'last-child', 'only-child', 'first-of-type',
            'last-of-type', 'only-of-type', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'not',
            'empty', 'root', 'target', 'checked', 'disabled', 'enabled', 'before', 'after', 'first-line',
            'first-letter', 'lang',
        ];

        preg_match_all('/::?([\w-]+)/', $group->name, $matches);
        foreach ($matches[1] as $pseudo) {
            if (!in_array(strtolower($pseudo), $safe_pseudo, true)) {
                return false;
            }
        }

        return true;
    }
}
