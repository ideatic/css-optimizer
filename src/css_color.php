<?php

declare(strict_types=1);

/**
 * Represents a CSS Color
 */
class css_color
{
    public bool $valid = false;

    /**
     * Falso cuando el color se ha redondeado para caber en 8 bits por canal (algunos colores hsl())
     */
    public bool $exact = true;

    public int $r = 0;
    public int $g = 0;
    public int $b = 0;
    public float $a = 1.0;

    public function __construct(string $color)
    {
        $this->parse($color);
    }

    /**
     * Lee el color indicado. Formatos válidos: rgb(), rgba(), hsl(), hsla() sin alfa, #hex (con o sin #) y
     * nombres de color CSS
     */
    public function parse(string $color): bool
    {
        $this->valid = false;
        $this->exact = true;
        $this->r = $this->g = $this->b = 0;
        $this->a = 1.0;

        // Limpiar la entrada y traducir nombres de color
        $color = strtolower(trim($color));
        $color = self::color_names()[$color] ?? $color;
        if (str_starts_with($color, '#')) {
            $color = substr($color, 1);
        }

        $number = '(\d{1,3})';
        $alpha = '(\d*\.?\d+)';

        if (preg_match("/^rgba?\(\s*{$number}\s*,\s*{$number}\s*,\s*{$number}\s*(?:,\s*{$alpha}\s*)?\)$/", $color, $match) === 1
            || preg_match("/^rgba?\(\s*{$number}\s+{$number}\s+{$number}\s*\)$/", $color, $match) === 1) {
            // rgb(255, 0, 0), rgba(255, 0, 0, 0.5), rgb(255 0 0)
            [$this->r, $this->g, $this->b] = [(int)$match[1], (int)$match[2], (int)$match[3]];
            $this->a = isset($match[4]) ? (float)$match[4] : 1.0;
        } elseif (preg_match('/^([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $color, $match) === 1) {
            // 336699
            [$this->r, $this->g, $this->b] = [(int)hexdec($match[1]), (int)hexdec($match[2]), (int)hexdec($match[3])];
        } elseif (preg_match('/^([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $match) === 1) {
            // fb0
            [$this->r, $this->g, $this->b] = [(int)hexdec($match[1] . $match[1]), (int)hexdec($match[2] . $match[2]), (int)hexdec($match[3] . $match[3])];
        } elseif (preg_match("/^hsla?\(\s*{$number}\s*,\s*{$number}%\s*,\s*{$number}%\s*\)$/", $color, $match) === 1
                  || preg_match("/^hsla?\(\s*{$number}\s+{$number}%\s+{$number}%\s*\)$/", $color, $match) === 1) {
            // hsl(120, 50%, 50%), hsl(120 50% 50%)
            $this->_parse_hsl((int)$match[1], (int)$match[2], (int)$match[3]);
        } else {
            return false;
        }

        // Los valores fuera de rango se recortan, como hacen los navegadores
        $this->r = min($this->r, 255);
        $this->g = min($this->g, 255);
        $this->b = min($this->b, 255);
        $this->a = max(0.0, min($this->a, 1.0));

        return $this->valid = true;
    }

    /**
     * Convert a HSL value to RGB
     *
     * Based on: {@link http://www.easyrgb.com/index.php?X=MATH&H=19#text19}
     */
    private function _parse_hsl(int $hue, int $saturation, int $lightness): void
    {
        $hue = ($hue % 360) / 360;
        $saturation = min($saturation, 100) / 100;
        $lightness = min($lightness, 100) / 100;

        if ($saturation == 0) {
            $channels = [$lightness, $lightness, $lightness];
        } else {
            $v2 = $lightness < 0.5 ? $lightness * (1 + $saturation) : ($lightness + $saturation) - ($saturation * $lightness);
            $v1 = 2 * $lightness - $v2;
            $channels = [
                self::_hue_to_rgb($v1, $v2, $hue + (1 / 3)),
                self::_hue_to_rgb($v1, $v2, $hue),
                self::_hue_to_rgb($v1, $v2, $hue - (1 / 3)),
            ];
        }

        $rounded = [];
        foreach ($channels as $channel) {
            $value = $channel * 255;
            $rounded[] = (int)round($value);
            if (abs($value - round($value)) > 1e-9) {
                $this->exact = false;
            }
        }
        [$this->r, $this->g, $this->b] = $rounded;
    }

    private static function _hue_to_rgb(float $v1, float $v2, float $hue): float
    {
        if ($hue < 0) {
            $hue += 1;
        }
        if ($hue > 1) {
            $hue -= 1;
        }
        return match (true) {
            6 * $hue < 1 => $v1 + ($v2 - $v1) * 6 * $hue,
            2 * $hue < 1 => $v2,
            3 * $hue < 2 => $v1 + ($v2 - $v1) * ((2 / 3) - $hue) * 6,
            default => $v1,
        };
    }

    /**
     * Get the color in CSS rgb/rgba format
     */
    public function to_rgb(bool $alpha = true): string
    {
        if ($alpha && $this->a != 1) {
            return "rgba({$this->r}, {$this->g}, {$this->b}, {$this->a})";
        }
        return "rgb({$this->r}, {$this->g}, {$this->b})";
    }

    /**
     * Get the color in HEX format
     */
    #[\NoDiscard]
    public function to_hex(bool $allow_short = true): string
    {
        $hex = sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);

        if ($allow_short && $hex[1] === $hex[2] && $hex[3] === $hex[4] && $hex[5] === $hex[6]) {
            $hex = "#{$hex[1]}{$hex[3]}{$hex[5]}";
        }

        return $hex;
    }

    /**
     * Get the color in a RGB/RGBA array
     *
     * @return list<int|float>
     */
    public function to_array(bool $alpha = true): array
    {
        if ($alpha && $this->a != 1) {
            return [$this->r, $this->g, $this->b, $this->a];
        }
        return [$this->r, $this->g, $this->b];
    }

    /**
     * Parse the input color
     */
    public static function create(string $color): self
    {
        return new self($color);
    }

    /**
     * Colores con nombre de CSS Color Level 4 (salvo transparent y currentcolor)
     *
     * @return array<string, string>
     */
    public static function color_names(): array
    {
        return [
            'aliceblue'            => 'f0f8ff',
            'antiquewhite'         => 'faebd7',
            'aqua'                 => '00ffff',
            'aquamarine'           => '7fffd4',
            'azure'                => 'f0ffff',
            'beige'                => 'f5f5dc',
            'bisque'               => 'ffe4c4',
            'black'                => '000000',
            'blanchedalmond'       => 'ffebcd',
            'blue'                 => '0000ff',
            'blueviolet'           => '8a2be2',
            'brown'                => 'a52a2a',
            'burlywood'            => 'deb887',
            'cadetblue'            => '5f9ea0',
            'chartreuse'           => '7fff00',
            'chocolate'            => 'd2691e',
            'coral'                => 'ff7f50',
            'cornflowerblue'       => '6495ed',
            'cornsilk'             => 'fff8dc',
            'crimson'              => 'dc143c',
            'cyan'                 => '00ffff',
            'darkblue'             => '00008b',
            'darkcyan'             => '008b8b',
            'darkgoldenrod'        => 'b8860b',
            'darkgray'             => 'a9a9a9',
            'darkgrey'             => 'a9a9a9',
            'darkgreen'            => '006400',
            'darkkhaki'            => 'bdb76b',
            'darkmagenta'          => '8b008b',
            'darkolivegreen'       => '556b2f',
            'darkorange'           => 'ff8c00',
            'darkorchid'           => '9932cc',
            'darkred'              => '8b0000',
            'darksalmon'           => 'e9967a',
            'darkseagreen'         => '8fbc8f',
            'darkslateblue'        => '483d8b',
            'darkslategray'        => '2f4f4f',
            'darkslategrey'        => '2f4f4f',
            'darkturquoise'        => '00ced1',
            'darkviolet'           => '9400d3',
            'deeppink'             => 'ff1493',
            'deepskyblue'          => '00bfff',
            'dimgray'              => '696969',
            'dimgrey'              => '696969',
            'dodgerblue'           => '1e90ff',
            'firebrick'            => 'b22222',
            'floralwhite'          => 'fffaf0',
            'forestgreen'          => '228b22',
            'fuchsia'              => 'ff00ff',
            'gainsboro'            => 'dcdcdc',
            'ghostwhite'           => 'f8f8ff',
            'gold'                 => 'ffd700',
            'goldenrod'            => 'daa520',
            'gray'                 => '808080',
            'grey'                 => '808080',
            'green'                => '008000',
            'greenyellow'          => 'adff2f',
            'honeydew'             => 'f0fff0',
            'hotpink'              => 'ff69b4',
            'indianred'            => 'cd5c5c',
            'indigo'               => '4b0082',
            'ivory'                => 'fffff0',
            'khaki'                => 'f0e68c',
            'lavender'             => 'e6e6fa',
            'lavenderblush'        => 'fff0f5',
            'lawngreen'            => '7cfc00',
            'lemonchiffon'         => 'fffacd',
            'lightblue'            => 'add8e6',
            'lightcoral'           => 'f08080',
            'lightcyan'            => 'e0ffff',
            'lightgoldenrodyellow' => 'fafad2',
            'lightgray'            => 'd3d3d3',
            'lightgrey'            => 'd3d3d3',
            'lightgreen'           => '90ee90',
            'lightpink'            => 'ffb6c1',
            'lightsalmon'          => 'ffa07a',
            'lightseagreen'        => '20b2aa',
            'lightskyblue'         => '87cefa',
            'lightslategray'       => '778899',
            'lightslategrey'       => '778899',
            'lightsteelblue'       => 'b0c4de',
            'lightyellow'          => 'ffffe0',
            'lime'                 => '00ff00',
            'limegreen'            => '32cd32',
            'linen'                => 'faf0e6',
            'magenta'              => 'ff00ff',
            'maroon'               => '800000',
            'mediumaquamarine'     => '66cdaa',
            'mediumblue'           => '0000cd',
            'mediumorchid'         => 'ba55d3',
            'mediumpurple'         => '9370db',
            'mediumseagreen'       => '3cb371',
            'mediumslateblue'      => '7b68ee',
            'mediumspringgreen'    => '00fa9a',
            'mediumturquoise'      => '48d1cc',
            'mediumvioletred'      => 'c71585',
            'midnightblue'         => '191970',
            'mintcream'            => 'f5fffa',
            'mistyrose'            => 'ffe4e1',
            'moccasin'             => 'ffe4b5',
            'navajowhite'          => 'ffdead',
            'navy'                 => '000080',
            'oldlace'              => 'fdf5e6',
            'olive'                => '808000',
            'olivedrab'            => '6b8e23',
            'orange'               => 'ffa500',
            'orangered'            => 'ff4500',
            'orchid'               => 'da70d6',
            'palegoldenrod'        => 'eee8aa',
            'palegreen'            => '98fb98',
            'paleturquoise'        => 'afeeee',
            'palevioletred'        => 'db7093',
            'papayawhip'           => 'ffefd5',
            'peachpuff'            => 'ffdab9',
            'peru'                 => 'cd853f',
            'pink'                 => 'ffc0cb',
            'plum'                 => 'dda0dd',
            'powderblue'           => 'b0e0e6',
            'purple'               => '800080',
            'rebeccapurple'        => '663399',
            'red'                  => 'ff0000',
            'rosybrown'            => 'bc8f8f',
            'royalblue'            => '4169e1',
            'saddlebrown'          => '8b4513',
            'salmon'               => 'fa8072',
            'sandybrown'           => 'f4a460',
            'seagreen'             => '2e8b57',
            'seashell'             => 'fff5ee',
            'sienna'               => 'a0522d',
            'silver'               => 'c0c0c0',
            'skyblue'              => '87ceeb',
            'slateblue'            => '6a5acd',
            'slategray'            => '708090',
            'slategrey'            => '708090',
            'snow'                 => 'fffafa',
            'springgreen'          => '00ff7f',
            'steelblue'            => '4682b4',
            'tan'                  => 'd2b48c',
            'teal'                 => '008080',
            'thistle'              => 'd8bfd8',
            'tomato'               => 'ff6347',
            'turquoise'            => '40e0d0',
            'violet'               => 'ee82ee',
            'wheat'                => 'f5deb3',
            'white'                => 'ffffff',
            'whitesmoke'           => 'f5f5f5',
            'yellow'               => 'ffff00',
            'yellowgreen'          => '9acd32',
        ];
    }

}
