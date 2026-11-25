<?php

declare(strict_types=1);

/**
 * css_parser - Basic CSS parser
 *
 * [MIT Licensed](http://www.opensource.org/licenses/mit-license.php)
 * @author Javier Marín
 */
class css_parser
{
    public bool $trim = true;

    /**
     * Parse CSS code, returning its OO representation
     */
    public function parse(string $css): css_group
    {
        $root = $current = new css_group();

        $partial = '';
        $parentheses = 0; // Paréntesis abiertos: dentro de ellos ';' es literal (url(data:...;base64,...))
        $custom_braces = 0; // Llaves abiertas dentro del valor de una propiedad personalizada (--x: {...})
        $length = strlen($css);

        // Se recorre byte a byte: todos los caracteres especiales son ASCII, así que es seguro con UTF-8
        for ($i = 0; $i < $length;) {
            $char = $css[$i];

            switch ($char) {
                case '\\': // Escape
                    $partial .= css_value::read_escape($css, $i);
                    continue 2;

                case '"':
                case "'": // Cadena
                    $start = $i;
                    $partial .= css_value::read_string($css, $i) ?? substr($css, $start);
                    continue 2;

                case '/': // Comentario
                    if (($css[$i + 1] ?? '') === '*') {
                        $end = strpos($css, '*/', $i + 2);
                        $end = $end === false ? $length : $end + 2;

                        $comment = new css_element();
                        $comment->type = 'comment';
                        $comment->value = substr($css, $i, $end - $i);
                        $current->add_child($comment);

                        $i = $end;
                        continue 2;
                    }
                    break;

                case '(':
                    // url() sin comillas: su contenido es literal, incluso "/*" (http://a.com/*.png)
                    if (preg_match('/(?<![\w-])url$/i', $partial) === 1 && preg_match('/\G\(\s*[^\s"\']/', $css, $match, 0, $i) === 1) {
                        $end = $i + 1;
                        while ($end < $length && $css[$end] !== ')') {
                            $end += $css[$end] === '\\' ? 2 : 1;
                        }
                        $end = min($end + 1, $length);
                        $partial .= substr($css, $i, $end - $i);
                        $i = $end;
                        continue 2;
                    }
                    $parentheses++;
                    break;

                case ')':
                    $parentheses = max(0, $parentheses - 1);
                    break;

                case '{': // Inicio de grupo
                    if ($custom_braces > 0 || preg_match('/^\s*--[^:]*:/', $partial) === 1) {
                        $custom_braces++;
                        break;
                    }

                    $group = new css_group();
                    $group->name = $this->_process_string($partial);
                    $current->add_child($group);
                    $current = $group;
                    $partial = '';
                    $parentheses = 0;
                    $i++;
                    continue 2;

                case '}': // Fin de grupo
                    if ($custom_braces > 0) {
                        $custom_braces--;
                        break;
                    }

                    $this->_end_statement($current, $partial);
                    $partial = '';
                    $parentheses = 0;

                    // Una llave de cierre sin grupo abierto se ignora, como hacen los navegadores
                    if ($current->parent !== null) {
                        $current = $current->parent;
                    }
                    $i++;
                    continue 2;

                case ';': // Fin de declaración o de at-rule sin bloque
                    if ($parentheses > 0 || $custom_braces > 0) {
                        break;
                    }

                    if (!$this->_end_statement($current, $partial) && $current->parent === null) {
                        // Texto suelto en la raíz: se conserva para que, como en los navegadores, invalide la regla siguiente
                        $partial .= ';';
                    } else {
                        $partial = '';
                    }
                    $i++;
                    continue 2;
            }

            $partial .= $char;
            $i++;
        }

        // Última declaración sin cerrar
        $this->_end_statement($current, $partial);

        return $root;
    }

    /**
     * Añade al grupo la declaración o at-rule sin bloque que contiene $partial.
     * Devuelve false si $partial no contiene ninguna de ellas
     */
    private function _end_statement(css_group $group, string $partial): bool
    {
        $statement = trim($partial);
        if ($statement === '') {
            return true;
        }

        // At-rule sin bloque: @import, @charset, @layer a, b; @namespace...
        if ($statement[0] === '@') {
            $element = new css_element();
            $element->type = preg_match('/^@import\b/i', $statement) === 1 ? 'import' : 'at-rule';
            $element->value = "{$this->_process_string($partial)};";
            $group->add_child($element);
            return true;
        }

        // Declaración
        $separator = strpos($partial, ':');
        if ($separator !== false) {
            $group->add_child(
                new css_property(
                    $this->_process_string(substr($partial, 0, $separator)),
                    $this->_process_string(substr($partial, $separator + 1)),
                ),
            );
            return true;
        }

        return false;
    }

    private function _process_string(string $value): string
    {
        return $this->trim ? trim($value) : $value;
    }
}

class css_element
{
    public ?css_group $parent = null;
    public string $value = '';

    /**
     * enum(property, comment, import, at-rule)
     */
    public string $type = '';

    public function render(bool $compressed = false): string
    {
        return $compressed && $this->type !== 'comment' ? css_value::collapse_spaces($this->value) : $this->value;
    }

    public function remove(): void
    {
        if ($this->parent === null) {
            return;
        }

        $this->parent->children = array_values(array_filter($this->parent->children, fn(css_element $child): bool => $child !== $this));
        $this->parent = null;
    }

    /**
     * @template T of css_element
     * @param class-string<T>|null $type
     * @return ($type is null ? list<css_element> : list<T>)
     */
    public function siblings(?string $type = null, bool $include_self = false): array
    {
        if ($this->parent === null) {
            return [];
        }

        $siblings = [];
        foreach ($this->parent->children as $sibling) {
            if (($include_self || $sibling !== $this) && ($type === null || $sibling instanceof $type)) {
                $siblings[] = $sibling;
            }
        }
        return $siblings;
    }

    public function insert_after(css_element $element): void
    {
        $this->_insert($element, 1);
    }

    public function insert_before(css_element $element): void
    {
        $this->_insert($element, 0);
    }

    private function _insert(css_element $element, int $offset): void
    {
        if ($this->parent === null) {
            throw new RuntimeException('The current element has been removed');
        }

        $position = array_search($this, $this->parent->children, true);
        if ($position === false) {
            throw new RuntimeException('The current element is not a child of its parent');
        }

        $element->parent = $this->parent;
        array_splice($this->parent->children, $position + $offset, 0, [$element]);
    }

    /**
     * @return list<css_element>
     */
    public function parents(bool $include_self = true): array
    {
        $found = [];

        $current = $include_self ? $this : $this->parent;
        while ($current !== null) {
            $found[] = $current;
            $current = $current->parent;
        }

        return $found;
    }

    /**
     * Copia profunda del elemento, sin padre
     */
    public function make_clone(): static
    {
        return clone($this, ['parent' => null]);
    }
}

/**
 * Represents a group of css property (selectors, @media, @keyframes, etc.)
 */
class css_group extends css_element
{
    public string $name = '';

    /**
     * @var list<css_element>
     */
    public array $children = [];

    public function __construct()
    {
        $this->type = 'property';
    }

    public function __clone()
    {
        // Copiar también los hijos, apuntando al nuevo grupo
        foreach ($this->children as $key => $child) {
            $copy = clone $child;
            $copy->parent = $this;
            $this->children[$key] = $copy;
        }
    }

    #[\Override]
    public function render(bool $compressed = false): string
    {
        $content = [];
        foreach ($this->children as $child) {
            $content[] = $child->render($compressed);
        }

        // Documento raíz
        if ($this->parent === null && $this->name === '') {
            return implode($compressed ? '' : "\n", $content);
        }

        if ($compressed) {
            $name = str_starts_with($this->name, '@')
                ? css_value::collapse_spaces($this->name)
                : implode(',', array_map(css_value::minify_selector(...), $this->selectors()));
            return "{$name}{" . implode('', $content) . '}';
        }

        $name = str_starts_with($this->name, '@') ? $this->name : implode(', ', $this->selectors());
        return "{$name}{\n\t" . implode("\n\t", $content) . "\n}\n";
    }

    /**
     * Gets or sets the different selectors represented by this group
     *
     * @param list<string>|null $set
     *
     * @return list<string>
     */
    public function selectors(?array $set = null): array
    {
        if ($set !== null) {
            $this->name = implode(', ', $set);
        }

        if ($this->name === '') {
            return [];
        }

        return css_value::split($this->name);
    }

    public function add_child(css_element $element): void
    {
        $element->parent = $this;
        $this->children[] = $element;
    }

    /**
     * Find all elements of the selected type of all children of this group
     *
     * @template T of css_element
     * @param class-string<T> $type Name of the class representing the type of elements to find
     *
     * @return list<T>
     */
    public function find_all(string $type): array
    {
        $result = [];
        foreach ($this->children as $element) {
            if ($element instanceof $type) {
                $result[] = $element;
            }
            if ($element instanceof css_group) {
                array_push($result, ...$element->find_all($type));
            }
        }
        return $result;
    }
}

class css_property extends css_element
{
    public string $name;

    public function __construct(?string $name = null, string|int|float|null $value = null)
    {
        $this->type = 'property';
        $this->name = $name ?? '';
        $this->value = (string)$value;
    }

    #[\Override]
    public function render(bool $compressed = false): string
    {
        // En modo comprimido, la última declaración del bloque no necesita ';'
        if ($compressed && $this->parent !== null && array_last($this->parent->children) === $this) {
            return "{$this->name}:{$this->value}";
        }
        return "{$this->name}:{$this->value};";
    }

    /**
     * @param css_element|string $property Elemento a insertar, o nombre de la nueva propiedad
     */
    #[\Override]
    public function insert_after(css_element|string $property, string|int|null $value = null): void
    {
        parent::insert_after(is_string($property) ? new self($property, $value) : $property);
    }

    /**
     * @param css_element|string $property Elemento a insertar, o nombre de la nueva propiedad
     */
    #[\Override]
    public function insert_before(css_element|string $property, string|int|null $value = null): void
    {
        parent::insert_before(is_string($property) ? new self($property, $value) : $property);
    }
}
