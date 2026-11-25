<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private static function roundTrip(string $css): string
    {
        return new css_parser()->parse($css)->render(true);
    }

    public function testParsesRulesAndDeclarations(): void
    {
        $doc = new css_parser()->parse('a { color: red; margin: 0 } b{top:1px}');

        $groups = $doc->find_all(css_group::class);
        $this->assertCount(2, $groups);
        $this->assertSame('a', $groups[0]->name);

        $properties = $doc->find_all(css_property::class);
        $this->assertCount(3, $properties);
        $this->assertSame('color', $properties[0]->name);
        $this->assertSame('red', $properties[0]->value);
    }

    public function testKeepsSemicolonsInsideUnquotedDataUri(): void
    {
        $css = 'a{background:url(data:image/png;base64,iVBORw0KGgo=) no-repeat}';
        $this->assertSame($css, self::roundTrip($css));
    }

    public function testKeepsCommentLikeTextInsideUnquotedUrl(): void
    {
        $css = 'a{background:url(http://x.com/a/*b.png)}b{color:red}';
        $this->assertSame($css, self::roundTrip($css));
    }

    public function testKeepsBracesAndSemicolonsInsideStrings(): void
    {
        $css = 'a::before{content:"a;b{c}d"}';
        $this->assertSame($css, self::roundTrip($css));
    }

    public function testHandlesEscapedBackslashBeforeClosingQuote(): void
    {
        $css = 'a::before{content:"\\\\"}b{color:red}';
        $this->assertSame($css, self::roundTrip($css));
    }

    public function testHandlesEscapedCharactersInSelectors(): void
    {
        $css = '.a\\{b{color:red}.md\\:flex{display:flex}';
        $this->assertSame($css, self::roundTrip($css));
    }

    /** @return array<string, array{string}> */
    public static function atStatementProvider(): array
    {
        return [
            'charset'          => ['@charset "utf-8";a{color:red}'],
            'layer statement'  => ['@layer reset,base;a{color:red}'],
            'namespace'        => ['@namespace svg url(http://www.w3.org/2000/svg);a{color:red}'],
            'import with media' => ['@import url(print.css) print and (min-width:100px);a{color:red}'],
            'import with layer' => ['@import url(a.css) layer(base) supports(display:grid);a{color:red}'],
        ];
    }

    #[DataProvider('atStatementProvider')]
    public function testKeepsAtStatementsApartFromTheNextRule(string $css): void
    {
        $doc = new css_parser()->parse($css);

        $groups = $doc->find_all(css_group::class);
        $this->assertCount(1, $groups);
        $this->assertSame('a', $groups[0]->name);
        $this->assertSame($css, $doc->render(true));
    }

    public function testDoesNotSplitSelectorListsInsideBracketsOrParentheses(): void
    {
        $doc = new css_parser()->parse('[title="a, b"], :is(.x, .y) > p, c{color:red}');

        $this->assertSame(['[title="a, b"]', ':is(.x, .y) > p', 'c'], $doc->find_all(css_group::class)[0]->selectors());
    }

    public function testKeepsNestedRules(): void
    {
        $css = '.card{color:red;&:hover{color:blue}.title{margin:0}}';
        $this->assertSame($css, self::roundTrip($css));
    }

    /** @return array<string, array{string}> */
    public static function brokenInputProvider(): array
    {
        return [
            'unterminated comment' => ['a{color:red}/* not closed'],
            'trailing slash'       => ['a{color:red}/'],
            'unterminated string'  => ['a::before{content:"abc'],
            'invalid utf-8'        => ["a::before{content:\"\xff\xfe\"}"],
            'stray closing brace'  => ['}a{color:red}'],
            'empty input'          => [''],
        ];
    }

    #[DataProvider('brokenInputProvider')]
    public function testDoesNotCrashOnBrokenInput(string $css): void
    {
        self::roundTrip($css);
        $this->addToAssertionCount(1);
    }

    public function testKeepsCommentsAsElements(): void
    {
        $doc = new css_parser()->parse('/* a */a{color:red}');

        $comments = array_values(array_filter($doc->find_all(css_element::class), static fn(css_element $e): bool => $e->type === 'comment'));
        $this->assertCount(1, $comments);
        $this->assertSame('/* a */', $comments[0]->value);
    }
}
