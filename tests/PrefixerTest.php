<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrefixerTest extends TestCase
{
    private static function prefix(string $css, string $vendors = 'webkit'): string
    {
        $result = new css_optimizer(['prefixes' => $vendors, 'optimize' => false])->process($css);
        self::assertIsString($result);
        return $result;
    }

    public function testPutsPrefixedDeclarationsBeforeTheStandardOne(): void
    {
        $this->assertSame(
            'a{-moz-transition:color 1s;-webkit-transition:color 1s;-o-transition:color 1s;transition:color 1s}',
            self::prefix('a{transition:color 1s}', 'all'),
        );
    }

    public function testOnlyAddsSelectedVendors(): void
    {
        $this->assertSame('a{-webkit-transition:color 1s;transition:color 1s}', self::prefix('a{transition:color 1s}'));
    }

    public function testDoesNotDuplicateExistingPrefixes(): void
    {
        $css = 'a{-webkit-transition:color 1s;transition:color 1s}';
        $this->assertSame($css, self::prefix($css));
    }

    public function testAddsNothingWhenDisabled(): void
    {
        $this->assertSame('a{transition:color 1s}', self::prefix('a{transition:color 1s}', ''));
    }

    /** @return array<string, array{string, string}> */
    public static function gradientProvider(): array
    {
        return [
            'no direction' => ['linear-gradient(red,blue)', '-webkit-linear-gradient(red,blue)'],
            'to bottom'    => ['linear-gradient(to bottom,red,blue)', '-webkit-linear-gradient(top,red,blue)'],
            'to top right' => ['linear-gradient(to top right,red,blue)', '-webkit-linear-gradient(bottom left,red,blue)'],
            'zero degrees' => ['linear-gradient(0deg,red,blue)', '-webkit-linear-gradient(90deg,red,blue)'],
            '135 degrees'  => ['linear-gradient(135deg,red,blue)', '-webkit-linear-gradient(-45deg,red,blue)'],
            'radial'       => ['radial-gradient(circle,red,blue)', '-webkit-radial-gradient(circle,red,blue)'],
        ];
    }

    #[DataProvider('gradientProvider')]
    public function testConvertsGradientsToTheLegacySyntax(string $standard, string $legacy): void
    {
        $this->assertSame("a{background:{$legacy};background:{$standard}}", self::prefix("a{background:{$standard}}"));
    }

    /** @return array<string, array{string}> */
    public static function unconvertibleGradientProvider(): array
    {
        return [
            'turn angle'      => ['a{background:linear-gradient(.25turn,red,blue)}'],
            'radial position' => ['a{background:radial-gradient(circle at top,red,blue)}'],
            'radial size'     => ['a{background:radial-gradient(50px,red,blue)}'],
            'already legacy'  => ['a{background:-webkit-linear-gradient(top,red,blue)}'],
        ];
    }

    #[DataProvider('unconvertibleGradientProvider')]
    public function testDoesNotPrefixGradientsItCannotConvert(string $css): void
    {
        $this->assertSame($css, self::prefix($css));
    }

    public function testUsesTheRightOperaRepeatingGradientName(): void
    {
        $this->assertSame(
            'a{background:-o-repeating-linear-gradient(red,blue);background:repeating-linear-gradient(red,blue)}',
            self::prefix('a{background:repeating-linear-gradient(red,blue)}', 'opera'),
        );
    }

    public function testDoesNotAddDeclarationsThatChangeModernRendering(): void
    {
        $result = self::prefix('a{opacity:.5;white-space:pre-wrap;filter:blur(2px)}', 'all');

        $this->assertStringNotContainsString('zoom', $result);
        $this->assertStringNotContainsString('word-wrap', $result);
        $this->assertStringNotContainsString('-ms-filter:\'blur', $result);
        $this->assertStringContainsString('opacity:.5;', $result);
        $this->assertStringEndsWith('white-space:pre-wrap;filter:blur(2px)}', $result);
    }

    public function testCreatesPrefixedKeyframes(): void
    {
        $this->assertSame(
            '@-webkit-keyframes spin{to{-webkit-transform:rotate(1turn)}}@keyframes spin{to{transform:rotate(1turn)}}',
            self::prefix('@keyframes spin{to{transform:rotate(1turn)}}'),
        );
    }
}
