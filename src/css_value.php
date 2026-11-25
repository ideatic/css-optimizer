<?php

declare(strict_types=1);

/**
 * Token de un valor CSS. Las funciones guardan su nombre en $text y sus argumentos en $args
 */
final class css_token
{
    public const string WORD = 'word';
    public const string SPACE = 'space';
    public const string COMMA = ',';
    public const string SLASH = '/';
    public const string BANG = '!';
    public const string STRING = 'string';
    public const string URL = 'url';
    public const string FUNCTION = 'function';

    /**
     * @param list<css_token> $args
     */
    public function __construct(
        public string $type,
        public string $text,
        public array $args = [],
    ) {
    }
}

/**
 * Utilidades para leer y escribir valores, selectores y preludios de at-rules sin romper cadenas,
 * URLs, escapes ni paréntesis
 *
 * [MIT Licensed](http://www.opensource.org/licenses/mit-license.php)
 */
abstract class css_value
{
    /**
     * At-rules cuyo contenido son reglas de estilo normales (selectores y declaraciones)
     */
    public const array STYLE_AT_RULES = ['media', 'supports', 'container', 'layer', 'scope', 'starting-style', 'document', '-moz-document'];

    /**
     * Nombre en minúsculas de la at-rule de un grupo ('@media screen' -> 'media'), o '' si no es una at-rule
     */
    public static function at_rule_name(string $name): string
    {
        return preg_match('/^@([\w-]+)/', $name, $match) === 1 ? strtolower($match[1]) : '';
    }

    /**
     * Divide un valor CSS en tokens. Devuelve null si el valor no está bien formado (paréntesis o
     * cadenas sin cerrar): en ese caso no se debe optimizar
     *
     * @return list<css_token>|null
     */
    #[\NoDiscard]
    public static function tokenize(string $value): ?array
    {
        $offset = 0;
        return self::_tokenize($value, $offset, false);
    }

    /**
     * @return list<css_token>|null
     */
    private static function _tokenize(string $value, int &$offset, bool $nested): ?array
    {
        $tokens = [];
        $word = '';
        $length = strlen($value);

        while ($offset < $length) {
            $char = $value[$offset];

            // Escapes: forman parte de la palabra actual
            if ($char === '\\') {
                $word .= self::read_escape($value, $offset);
                continue;
            }

            // Espacios en blanco
            if (ctype_space($char)) {
                self::_flush_word($tokens, $word);
                $start = $offset;
                while ($offset < $length && ctype_space($value[$offset])) {
                    $offset++;
                }
                $tokens[] = new css_token(css_token::SPACE, substr($value, $start, $offset - $start));
                continue;
            }

            // Separadores
            if ($char === ',' || $char === '/' || $char === '!') {
                self::_flush_word($tokens, $word);
                $tokens[] = new css_token($char, $char);
                $offset++;
                continue;
            }

            // Cadenas
            if ($char === '"' || $char === "'") {
                self::_flush_word($tokens, $word);
                $string = self::read_string($value, $offset);
                if ($string === null) {
                    return null;
                }
                $tokens[] = new css_token(css_token::STRING, $string);
                continue;
            }

            // Funciones. url() se guarda sin analizar, porque su contenido sin comillas es literal
            if ($char === '(') {
                if (strcasecmp($word, 'url') === 0) {
                    $start = $offset - strlen($word);
                    $offset++;
                    while ($offset < $length && $value[$offset] !== ')') {
                        if ($value[$offset] === '"' || $value[$offset] === "'") {
                            if (self::read_string($value, $offset) === null) {
                                return null;
                            }
                        } elseif ($value[$offset] === '\\') {
                            self::read_escape($value, $offset);
                        } else {
                            $offset++;
                        }
                    }
                    if ($offset >= $length) {
                        return null;
                    }
                    $offset++;
                    $tokens[] = new css_token(css_token::URL, substr($value, $start, $offset - $start));
                    $word = '';
                    continue;
                }

                $name = $word;
                $word = '';
                $offset++;
                $args = self::_tokenize($value, $offset, true);
                if ($args === null) {
                    return null;
                }
                $tokens[] = new css_token(css_token::FUNCTION, $name, $args);
                continue;
            }

            if ($char === ')') {
                if (!$nested) {
                    return null;
                }
                self::_flush_word($tokens, $word);
                $offset++;
                return $tokens;
            }

            $word .= $char;
            $offset++;
        }

        // Un paréntesis sin cerrar invalida el valor completo
        if ($nested) {
            return null;
        }

        self::_flush_word($tokens, $word);
        return $tokens;
    }

    /**
     * @param list<css_token> $tokens
     */
    private static function _flush_word(array &$tokens, string &$word): void
    {
        if ($word !== '') {
            $tokens[] = new css_token(css_token::WORD, $word);
            $word = '';
        }
    }

    /**
     * Genera el código CSS de una lista de tokens
     *
     * @param list<css_token> $tokens
     */
    #[\NoDiscard]
    public static function render(array $tokens): string
    {
        $result = '';
        foreach ($tokens as $token) {
            $result .= $token->type === css_token::FUNCTION
                ? "{$token->text}(" . self::render($token->args) . ')'
                : $token->text;
        }
        return $result;
    }

    /**
     * Lee una cadena entre comillas que empieza en $offset y avanza $offset hasta después de ella.
     * Devuelve null si la cadena no se cierra
     */
    public static function read_string(string $code, int &$offset): ?string
    {
        $delimiter = $code[$offset];
        $start = $offset;
        $length = strlen($code);

        for ($offset++; $offset < $length; $offset++) {
            if ($code[$offset] === '\\') {
                $offset++;
            } elseif ($code[$offset] === $delimiter) {
                $offset++;
                return substr($code, $start, $offset - $start);
            }
        }

        return null;
    }

    /**
     * Lee una secuencia de escape que empieza en $offset (la barra invertida) y avanza $offset hasta después de ella.
     * Un escape hexadecimal incluye el espacio en blanco que lo termina
     */
    public static function read_escape(string $code, int &$offset): string
    {
        if (preg_match('/\G\\\\(?:[0-9a-fA-F]{1,6}(?:\r\n|[ \t\r\n\f])?|.)?/s', $code, $match, 0, $offset) !== 1) {
            $offset++;
            return '\\';
        }
        $offset += strlen($match[0]);
        return $match[0];
    }

    /**
     * Divide una lista separada por $delimiter, ignorando los separadores dentro de cadenas, paréntesis,
     * corchetes y escapes
     *
     * @return list<string>
     */
    public static function split(string $list, string $delimiter = ','): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($list);

        for ($offset = 0; $offset < $length;) {
            $char = $list[$offset];

            if ($char === '\\') {
                $current .= self::read_escape($list, $offset);
                continue;
            }
            if ($char === '"' || $char === "'") {
                // Una cadena sin cerrar llega hasta el final (read_string deja $offset al final)
                $start = $offset;
                $current .= self::read_string($list, $offset) ?? substr($list, $start);
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif (($char === ')' || $char === ']') && $depth > 0) {
                $depth--;
            } elseif ($char === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                $offset++;
                continue;
            }

            $current .= $char;
            $offset++;
        }

        $parts[] = trim($current);
        return $parts;
    }

    /**
     * Reduce el espacio en blanco de un selector: une los espacios seguidos y quita los que rodean
     * a los combinadores y comas
     */
    public static function minify_selector(string $selector): string
    {
        $result = '';
        $space = false;
        $after_separator = true; // No se escribe espacio al principio ni tras un combinador
        $length = strlen($selector);

        for ($offset = 0; $offset < $length;) {
            $char = $selector[$offset];

            if (ctype_space($char)) {
                $space = true;
                $offset++;
                continue;
            }

            if (str_contains('>+~,)', $char)) {
                $result .= $char;
                $offset++;
                $space = false;
                $after_separator = $char !== ')';
                continue;
            }

            // Token normal: escape, cadena o carácter
            if ($char === '\\') {
                $token = self::read_escape($selector, $offset);
            } elseif ($char === '"' || $char === "'") {
                $start = $offset;
                $token = self::read_string($selector, $offset) ?? substr($selector, $start);
                $offset = $start + strlen($token);
            } else {
                $token = $char;
                $offset++;
            }

            if ($space && !$after_separator) {
                $result .= ' ';
            }
            $result .= $token;
            $space = false;
            $after_separator = $char === '(';
        }

        return $result;
    }

    /**
     * Une los espacios en blanco seguidos fuera de las cadenas (preludios de at-rules)
     */
    public static function collapse_spaces(string $code): string
    {
        $result = '';
        $length = strlen($code);

        for ($offset = 0; $offset < $length;) {
            $char = $code[$offset];

            if ($char === '\\') {
                $result .= self::read_escape($code, $offset);
            } elseif ($char === '"' || $char === "'") {
                $start = $offset;
                $string = self::read_string($code, $offset) ?? substr($code, $start);
                $offset = $start + strlen($string);
                $result .= $string;
            } elseif (ctype_space($char)) {
                while ($offset < $length && ctype_space($code[$offset])) {
                    $offset++;
                }
                $result .= ' ';
            } else {
                $result .= $char;
                $offset++;
            }
        }

        return trim($result);
    }
}
