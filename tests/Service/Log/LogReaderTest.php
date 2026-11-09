<?php

namespace App\Tests\Service\Log;

use App\Service\Log\LogQuery;
use App\Service\Log\LogReader;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'log');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function read(string $content): array
    {
        file_put_contents($this->path, $content);

        return (new LogReader(sys_get_temp_dir(), 'test'))->read($this->path, new LogQuery());
    }

    public function testJsonLinesBecomeSeparateEntries(): void
    {
        $result = $this->read(
            '{"message":"first","context":{"registration_id":12},"level":200,"level_name":"INFO","channel":"payment","datetime":"2026-11-09T09:20:40.304555+01:00","extra":{}}'."\n"
            .'{"message":"second","context":[],"level":400,"level_name":"ERROR","channel":"app","datetime":"2026-11-09T09:21:00+01:00","extra":[]}'."\n"
        );

        self::assertSame(2, $result['total']);
        self::assertSame('second', $result['entries'][0]->message);
        self::assertSame('ERROR', $result['entries'][0]->level);
        self::assertSame('app', $result['entries'][0]->channel);
        self::assertSame(12, $result['entries'][1]->context['registration_id']);
        self::assertSame(['INFO' => 1, 'ERROR' => 1], $result['stats']);
    }

    public function testTextLinesAndTheirContinuationsStillParse(): void
    {
        $result = $this->read("[2026-11-09T09:20:40+01:00] app.ERROR: boom {\"a\":1} []\n#0 trace\n");

        self::assertSame(1, $result['total']);
        self::assertSame('ERROR', $result['entries'][0]->level);
        self::assertStringContainsString('#0 trace', $result['entries'][0]->message);
    }
}
