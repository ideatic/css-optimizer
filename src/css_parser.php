<?php


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
     *
     * @param string $css
     *
     * @return css_group
     */
    public function parse(string $css): css_group
    {
        # Split at all position not after the start: ^ 
        # and not before the end: $ 
        $chars = preg_split('/(?<!^)(?!$)/u', $css);

        $current_group = $initial_group = new css_group;

        $partial = '';
        for ($i = 0, $c = count($chars); $i < $c; $i++) {
            $char = $chars[$i];

            switch ($char) {
                case '{': //Group start
                    $new_group = new css_group;
                    $new_group->name = $this->_process_string($partial);
                    $current_group->add_child($new_group);
                    $current_group = $new_group;
                    $partial = '';
                    break;

                case ';': //Property or import end
                case '}': //Group end
                    //End current property
                    if (($separator = strpos($partial, ':')) !== false) {
                        $property = new css_property;
                        $property->name = $this->_process_string(substr($partial, 0, $separator));
                        $property->value = $this->_process_string(substr($partial, $separator + 1));
                        $current_group->add_child($property);
                        $partial = '';
                    } else {
                        if ($char == ';') {
                            if (str_starts_with(trim($partial), '@import')) { //@import
                                $import = new css_element();
                                $import->value = $this->_process_string($partial) . ';';
                                $import->type = 'import';
                                $current_group->add_child($import);
                                $partial = '';
                            } else { //Out-of-property semicolon
                                if (strlen(trim($partial)) > 0) {
                                    $partial .= $char;
                                }
                            }
                        }
                    }

                    //End current group 
                    if ($char == '}') {
                        if (isset($current_group->parent)) {
                            $current_group = $current_group->parent;
                        } else { //Out-of-property bracket
                            $partial .= $char;
                        }
                    }
                    break;

                case '"':
                case "'": //String
                    $string = $this->_read_string($chars, $i);
                    $partial .= $string;
                    break;

                case '/': //Comment
                    if ($chars[$i + 1] == '*') {
                        $comment = $this->_read_comment($chars, $i);
                        $comment->parent = $current_group;
                        $current_group->children[] = $comment;
                    } else {
                        $partial .= $char;
                    }
                    break;

                default:
                    $partial .= $char;
                    break;
            }

            $prev = $char;
        }

        return $initial_group;
    }

    private function _process_string($val)
    {
        return $this->trim ? trim($val) : $val;
    }

    private function _read_string($chars, &$i): bool|string
    {
        //Read until '*/' is found
        $delimiter = $chars[$i];
        $string = '';
        $prev = null;
        for ($c = count($chars); $i < $c; $i++) {
            if (isset($prev) && $prev != '\\' && $chars[$i] == $delimiter) {
                $string .= $delimiter;
                return $string;
            }
            $prev = $chars[$i];
            $string .= $prev;
        }
        return false;
    }

    /**
     * @return css_element
     */
    private function _read_comment($chars, &$i): css_element|bool
    {
        //Read until '*/' is found
        $value = '';
        $prev = '';
        for ($c = count($chars); $i < $c; $i++) {
            if ($prev == '*' && $chars[$i] == '/') {
                $value .= '/';
                $element = new css_element;
                $element->type = 'comment';
                $element->value = $value;
                return $element;
            }
            $prev = $chars[$i];
            $value .= $prev;
        }
        return false;
    }

}

class css_element
{
    public css_group|null $parent = null;
    public $value;

    /**
     * enum(property, comment, import)
     */
    public string $type;

    public function render(bool $compressed = false):string
    {
        return $this->value;
    }

    public function remove(): void
    {
        foreach ($this->parent->children as $key => $value) {
            if ($this === $value) {
                unset($this->parent->children[$key]);
            }
        }
        $this->parent = null;
    }

    /**
     * @return css_element[]
     */
    public function siblings($type = null, bool $include_self = false): array
    {
        if (!isset($this->parent)) {
            return [];
        }
        $siblings = [];
        foreach ($this->parent->children as $sibling) {
            if ($include_self || $sibling !== $this) {
                if (!isset($type) || $sibling instanceof $type) {
                    $siblings[] = $sibling;
                }
            }
        }
        return $siblings;
    }

    public function insert_after(css_element $element): void
    {
        if (!isset($this->parent)) {
            throw new RuntimeException('The current element has been removed');
        }
        $pos = 0;
        foreach ($this->parent->children as $child) {
            $pos++;
            if ($child === $this) {
                break;
            }
        }

        $this->parent->children = array_merge(array_slice($this->parent->children, 0, $pos), [$element], array_slice($this->parent->children, $pos));
    }

    /**
     *
     * @param boolean $include_self
     *
     * @return css_element[]
     */
    public function parents(bool $include_self = true): array
    {
        $found = [];

        $current = $include_self ? $this : $this->parent;
        while ($current) {
            $found[] = $current;
            $current = $current->parent;
        }

        return $found;
    }

    public function make_clone()
    {
        //unreference parent to avoid memory leaking on huge files
        $parent = $this->parent;
        $this->parent = null;
        $copy = unserialize(serialize($this));
        $this->parent = $parent;
        return $copy;
    }

}

/**
 * Represents a group of css property (selectors, @media, @keyframes, etc.)
 */
class css_group extends css_element
{

    public $name;

    /**
     *
     * @var css_property[]|css_group[]
     */
    public array $children = [];

    public function __construct()
    {
        $this->type = 'property';
    }

    public function render(bool$compressed = false): string
    {
        $content = [];
        foreach ($this->children as $child) {
            $content[] = $child->render($compressed);
        }


        if ($this->name) {
            $parts = $this->selectors();
            if ($compressed) {
                return implode(',', $parts) . "{" . implode('', $content) . "}";
            } else {
                return implode(', ', $parts) . "{\n\t" . implode("\n\t", $content) . "\n}\n";
            }
        } else {
            return implode($compressed ? '' : "\n", $content);
        }
    }

    /**
     * Gets or sets the different selectors represented by this group
     *
     * @param string[]|null $set
     *
     * @return string[]
     */
    public function selectors(array $set = null): array
    {
        if (isset($set)) {
            $this->name = implode(', ', $set);
        }
        $parts = [];

        if ($this->name) {
            /**
             * @todo Better parsing, this won't work on selectors such as input[name="some,name"]
             */
            foreach (explode(',', $this->name) as $part) {
                $parts[] = trim($part);
            }
        }

        return $parts;
    }

    public function add_child(css_element $element): void
    {
        $element->parent = $this;
        $this->children[] = $element;
    }

    /**
     * Find all elements of the selected type of all children of this group
     *
     * @param string $type Name of the class representing the type of elements to find
     *
     * @return css_element[]
     */
    public function find_all(string $type): array
    {
        $result = [];
        $this->_find($type, $this->children, $result);
        return $result;
    }

    private function _find($type, $items, &$result): void
    {
        foreach ($items as $element) {
            if ($element instanceof $type) {
                $result[] = $element;
            }
            if ($element instanceof css_group) {
                $this->_find($type, $element->children, $result);
            }
        }
    }

}

class css_property extends css_element
{

    public string|null $name;

    public function __construct(string $name = null, string|int $value = null)
    {
        $this->type = 'property';
        $this->name = $name;
        $this->value = $value;
    }

    public function render($compressed = false): string
    {
        $last = $this->parent && end($this->parent->children) === $this;
        if ($compressed && $last) {
            return "{$this->name}:{$this->value}";
        } else {
            return "{$this->name}:{$this->value};";
        }
    }

    public function insert_after($property, $value = null): void
    {
        if (is_string($property)) {
            $property = new self($property, $value);
        }
        parent::insert_after($property);
    }

}
