<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CleanerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/css-cleaner-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/index.html", '<div class="used-class other"><span id="main">x</span></div>');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    }

    private function clean(string $css, int $method = css_cleaner::METHOD_SAFE): string
    {
        $cleaner = new css_cleaner();
        $cleaner->project_files = [$this->dir];
        $cleaner->method = $method;
        return $cleaner->clean(new css_parser()->parse($css))->render(true);
    }

    public function testKeepsUsedSelectorsAndRemovesUnusedOnes(): void
    {
        $this->assertSame('.used-class,#main{color:red}div span{top:0}', $this->clean('.used-class, .unused, #main{color:red} .nope{color:blue} div span{top:0}'));
    }

    public function testKeepsCompoundSelectorsWhenAllTokensAreUsed(): void
    {
        $this->assertSame('.used-class.other{color:red}', $this->clean('.used-class.other{color:red}.used-class.nope{color:blue}'));
    }

    public function testDoesNotRemoveKeyframeSteps(): void
    {
        $css = '@keyframes fade{from{opacity:0}50%{opacity:.5}to{opacity:1}}';
        $this->assertSame($css, $this->clean($css));
    }

    public function testWorksWithBestCleanMethod(): void
    {
        $this->assertSame('.used-class{color:red}', $this->clean('.used-class{color:red}.unused{color:blue}', css_cleaner::METHOD_BEST_CLEAN));
    }

    public function testWritesReport(): void
    {
        $cleaner = new css_cleaner();
        $cleaner->project_files = [$this->dir];
        $cleaner->report_path = "{$this->dir}/report.txt";
        $cleaner->clean(new css_parser()->parse('.used-class{color:red}'));

        $this->assertStringContainsString('.used-class found', (string)file_get_contents($cleaner->report_path));
    }
}
