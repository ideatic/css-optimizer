<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptimizerTest extends TestCase
{
    /** @param array<string, mixed> $settings */
    private static function optimize(string $css, array $settings = []): string
    {
        $result = new css_optimizer($settings + ['prefixes' => ''])->process($css);
        self::assertIsString($result);
        return $result;
    }

    /** @return array<string, array{string, string}> */
    public static function safeOptimizationProvider(): array
    {
        return [
            'color name to hex'           => ['a{color:white}', 'a{color:#fff}'],
            'long hex to short hex'       => ['a{color:#FFFFFF}', 'a{color:#fff}'],
            'rgb to hex'                  => ['a{color:rgb(255, 0, 0)}', 'a{color:#f00}'],
            'important is kept'           => ['a{color:white !important}', 'a{color:#fff!important}'],
            'zero length'                 => ['a{margin:0px}', 'a{margin:0}'],
            'leading zero'                => ['a{opacity:0.5}', 'a{opacity:.5}'],
            'negative leading zero'       => ['a{margin:-0.5em}', 'a{margin:-.5em}'],
            'four equal margins'          => ['a{margin:10px 10px 10px 10px}', 'a{margin:10px}'],
            'four zero paddings'          => ['a{padding:0 0 0 0}', 'a{padding:0}'],
            'four to three values'        => ['a{margin:1px 2px 3px 2px}', 'a{margin:1px 2px 3px}'],
            'four to two values'          => ['a{margin:1px 2px 1px 2px}', 'a{margin:1px 2px}'],
            'two equal gaps'              => ['a{gap:1rem 1rem}', 'a{gap:1rem}'],
            'font-weight bold'            => ['a{font-weight:bold}', 'a{font-weight:700}'],
            'font-weight normal'          => ['a{font-weight:normal}', 'a{font-weight:400}'],
            'background-position keywords' => ['a{background-position:top left}', 'a{background-position:0 0}'],
            'background-position layers'  => ['a{background-position:right bottom, center center}', 'a{background-position:100% 100%,50% 50%}'],
            'spaces after commas'         => ['a{font-family:Arial,  sans-serif}', 'a{font-family:Arial,sans-serif}'],
            'spaces inside functions'     => ['a{color:rgba(0, 0, 0, 0.5)}', 'a{color:rgba(0,0,0,.5)}'],
            'lowercase property names'    => ['a{COLOR:red}', 'a{color:red}'],
            'four margin longhands'       => ['a{margin-top:1px;margin-right:2px;margin-bottom:3px;margin-left:4px}', 'a{margin:1px 2px 3px 4px}'],
        ];
    }

    #[DataProvider('safeOptimizationProvider')]
    public function testAppliesSafeOptimizations(string $input, string $expected): void
    {
        $this->assertSame($expected, self::optimize($input));
    }

    /** @return array<string, array{string}> */
    public static function unsafeOptimizationProvider(): array
    {
        return [
            'color name inside string'       => ['a::before{content:"black"}'],
            'color name inside grid areas'   => ['a{grid-template-areas:"red blue"}'],
            'color name inside custom ident' => ['a{animation-name:dark-red}'],
            'color name in font family'      => ['a{font-family:Silver,serif}'],
            'number inside url'              => ['a{background:url(img-0.50px.png)}'],
            'quoted url'                     => ['a{background:url("img 0.5px white.png")}'],
            'unit inside calc'               => ['a{width:calc(0px + 1em)}'],
            'percent inside hsla'            => ['a{color:hsla(0,0%,0%,.5)}'],
            'zero in flex shorthand'         => ['a{flex:0px}'],
            'percent in flex-basis'          => ['a{flex-basis:0%}'],
            'two background sizes'           => ['a{background-size:10px 10px}'],
            'two background positions'       => ['a{background-position:10px 10px}'],
            'four value position'            => ['a{background-position:right 10px top 5px}'],
            'box-shadow spread'              => ['a{box-shadow:1px 2px 3px 2px red}'],
            'transform-origin'               => ['a{transform-origin:10px 10px}'],
            'font shorthand normal'          => ['a{font:normal normal 12px Arial}'],
            'font family named bold'         => ['a{font:12px Foo Bold}'],
            'margin with var'                => ['a{margin:var(--a) var(--a) var(--a) var(--a)}'],
            'modern rgb syntax'              => ['a{color:rgb(0 0 0 / 50%)}'],
            'oklch'                          => ['a{color:oklch(70% .1 200)}'],
            'color-mix'                      => ['a{color:color-mix(in srgb,white 50%,black)}'],
            'inexact hsl'                    => ['a{color:hsl(120,50%,50%)}'],
            'eight digit hex'                => ['a{color:#ffffff80}'],
            'custom property case'           => ['a{--Main-Color:white}'],
            'custom property value'          => ['a{--gap:0px;--c:WHITE;--n:0.50}'],
            'property descriptor'            => ['@property --x{syntax:"<length>";inherits:false;initial-value:0px}'],
            'font-face descriptor'           => ['@font-face{font-family:X;src:url(x.woff2) format("woff2");font-weight:normal bold}'],
            'ie progid filter'               => ['a{filter:progid:DXImageTransform.Microsoft.gradient(startColorstr=\'#FFFFFFFF\', endColorstr=\'#FF000000\')}'],
            'non css color name'             => ['a{color:feldspar}'],
            'escaped space in selector'      => ['.a\\ b .c{color:red}'],
            'empty layer block'              => ['@layer base{}'],
            'empty keyframes'                => ['@keyframes x{}'],
            'longhands with important'       => ['a{margin-top:1px!important;margin-right:2px;margin-bottom:3px;margin-left:4px}'],
            'font longhands'                 => ['a{font-style:italic;font-variant:normal;font-weight:700;font-size:12px;line-height:1.5;font-family:Arial}'],
            'background longhands'           => ['a{background-size:10px;background-color:red;background-image:none;background-repeat:no-repeat;background-position:0 0;background-attachment:fixed}'],
            'elliptic radius longhands'      => ['a{border-top-left-radius:10px 5px;border-top-right-radius:10px 5px;border-bottom-right-radius:10px 5px;border-bottom-left-radius:10px 5px}'],
        ];
    }

    #[DataProvider('unsafeOptimizationProvider')]
    public function testLeavesUnsafeCasesUnchanged(string $css): void
    {
        $this->assertSame($css, self::optimize($css));
    }

    public function testKeepsImportantComments(): void
    {
        $this->assertSame('/*! license */a{color:red}', self::optimize('/*! license */ /* normal */ a{color:red}'));
    }

    public function testRemovesEmptyRulesRecursively(): void
    {
        $this->assertSame('b{color:red}', self::optimize('a{} @media print{.x{}} b{color:red}'));
    }

    public function testMinifiesSelectorWhitespace(): void
    {
        $this->assertSame('a>b+c~d e,f{color:red}', self::optimize("a  >  b + c ~ d\n e ,\n f{color:red}"));
    }

    public function testKeepsAtRulePreludeSpaces(): void
    {
        $this->assertSame('@media screen and (min-width:100px){a{color:red}}', self::optimize('@media   screen and (min-width:100px) { a { color: red } }'));
    }

    public function testRemovesOnlyIeHacks(): void
    {
        $css = 'a{_width:1px;*zoom:1;color:red\\9;filter:progid:DXImageTransform.Microsoft.Alpha(Opacity=50);width:expression(1+1);filter:blur(2px);top:0}';
        $this->assertSame('a{filter:blur(2px);top:0}', self::optimize($css, ['remove_ie_hacks' => true]));
    }

    public function testMergesOnlyAdjacentIdenticalRules(): void
    {
        $css = '.a{color:red}.b{color:red}.c{color:blue}.d{color:red}';
        $this->assertSame('.a,.b{color:red}.c{color:blue}.d{color:red}', self::optimize($css, ['extra_optimize' => true]));
    }

    public function testDoesNotMergeAtRulesOrVendorPseudoElements(): void
    {
        $css = '@media print{a{color:red}}@media screen{a{color:red}}::-webkit-input-placeholder{color:red}::-moz-placeholder{color:red}';
        $this->assertSame($css, self::optimize($css, ['extra_optimize' => true]));
    }

    public function testKeepsReadableOutputWhenNotCompressing(): void
    {
        $this->assertStringContainsString("\n", self::optimize('a{color:red}b{color:blue}', ['compress' => false]));
    }

    public function testAcceptsParsedDocument(): void
    {
        $doc = new css_parser()->parse('a{color:white}');
        $this->assertSame($doc, new css_optimizer(['prefixes' => ''])->process($doc));
        $this->assertSame('a{color:#fff}', $doc->render(true));
    }

    public function testRejectsUnknownSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Los ajustes suelen venir de ficheros de configuración, sin tipos conocidos
        $settings = json_decode('{"compres": true}', true);
        new css_optimizer(is_array($settings) ? $settings : null);
    }

    public function testCastsLooseSettingTypes(): void
    {
        $optimizer = new css_optimizer(['compress' => '0', 'optimize' => 1, 'prefixes' => false]);
        $this->assertFalse($optimizer->compress);
        $this->assertTrue($optimizer->optimize);
        $this->assertSame('', $optimizer->prefixes);
    }
}
