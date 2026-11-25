<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Modern CSS that the optimizer must keep exactly as it is (it is already minified),
 * and real files that must give the same result when optimized twice.
 */
final class CorpusTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function modernCssProvider(): array
    {
        return [
            'nesting'            => ['.card{color:red;&:hover{color:blue}& .title{margin:0}@media (width>=40em){padding:1rem}}'],
            'layers'             => ['@layer reset,base;@layer base{a{color:red}}'],
            'container query'    => ['.p{container:card/inline-size}@container card (min-width:400px){.c{display:grid}}'],
            'style query'        => ['@container style(--theme:dark){a{color:#fff}}'],
            'property rule'      => ['@property --angle{syntax:"<angle>";inherits:false;initial-value:0deg}'],
            'has and is'         => [':is(h1,h2):has(+p),a:where(.x,.y)>b{margin-block:1em .5em}'],
            'custom properties'  => [':root{--Brand:#FFFFFF;--space:0px;--json:{"a":1}}a{color:var(--Brand,white)}'],
            'color functions'    => ['a{color:oklch(70% .1 200/.5);background:color-mix(in oklab,red 40%,blue);border-color:lab(50% 40 59.5)}'],
            'math functions'     => ['a{width:clamp(1rem,calc(100% - 2*var(--gap)),40rem);height:min(10vh,5em);rotate:calc(tan(45deg)*1rad)}'],
            'data uri'           => ['a{background:url(data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22/>)}'],
            'import conditions'  => ['@import url(theme.css) layer(theme) supports(display:grid) screen;a{color:red}'],
            'scope'              => ['@scope (.card) to (.content){img{border:1px solid}}'],
            'starting style'     => ['@starting-style{.x{opacity:0}}'],
            'view transitions'   => ['::view-transition-old(root){animation:none}'],
            'anchor positioning' => ['.tip{position-anchor:--a;top:anchor(bottom);position-try-fallbacks:flip-block}'],
            'unicode'            => ['.ñ::before{content:"→ ✓"}'],
            'escapes'            => ['.\\31 0{color:red}.a\\:b{top:0}'],
        ];
    }

    #[DataProvider('modernCssProvider')]
    public function testKeepsModernCss(string $css): void
    {
        $result = new css_optimizer(['prefixes' => ''])->process($css);
        $this->assertSame($css, $result);
    }

    /** @return array<string, array{string}> */
    public static function fileProvider(): array
    {
        $files = [];
        foreach (glob(__DIR__ . '/../examples/assets/{*,*/*}.css', GLOB_BRACE) ?: [] as $path) {
            $files[basename($path)] = [$path];
        }
        return $files;
    }

    #[DataProvider('fileProvider')]
    public function testIsIdempotent(string $path): void
    {
        foreach (['', 'all'] as $prefixes) {
            $optimizer = new css_optimizer(['prefixes' => $prefixes]);
            $once = $optimizer->process((string)file_get_contents($path));
            $this->assertIsString($once);
            $this->assertSame($once, $optimizer->process($once), "Prefixes: '{$prefixes}'");
        }
    }
}
