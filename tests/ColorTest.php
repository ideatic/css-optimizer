<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validColorProvider(): array
    {
        return [
            'long hex'      => ['#336699', '#369'],
            'short hex'     => ['#FB0', '#fb0'],
            'name'          => ['white', '#fff'],
            'rgb'           => ['rgb(255, 0, 0)', '#f00'],
            'space rgb'     => ['rgb(255 0 0)', '#f00'],
            'exact hsl'     => ['hsl(0, 100%, 50%)', '#f00'],
            'rebeccapurple' => ['rebeccapurple', '#639'],
        ];
    }

    #[DataProvider('validColorProvider')]
    public function testParsesValidColors(string $input, string $hex): void
    {
        $color = new css_color($input);
        $this->assertTrue($color->valid);
        $this->assertSame($hex, $color->to_hex());
    }

    /** @return array<string, array{string}> */
    public static function invalidColorProvider(): array
    {
        return [
            'empty'        => [''],
            'not hex'      => ['#gggggg'],
            'not a color'  => ['zzz'],
            'not css name' => ['feldspar'],
            'var'          => ['var(--x)'],
        ];
    }

    #[DataProvider('invalidColorProvider')]
    public function testRejectsInvalidColors(string $input): void
    {
        $this->assertFalse(new css_color($input)->valid);
    }

    public function testKeepsAlpha(): void
    {
        $color = new css_color('rgba(0, 0, 0, 0.5)');
        $this->assertSame(0.5, $color->a);
        $this->assertSame('rgba(0, 0, 0, 0.5)', $color->to_rgb());
    }

    public function testRoundsInexactHsl(): void
    {
        $color = new css_color('hsl(120, 50%, 50%)');
        $this->assertTrue($color->valid);
        $this->assertFalse($color->exact);
        $this->assertSame([64, 191, 64], $color->to_array());
    }
}
