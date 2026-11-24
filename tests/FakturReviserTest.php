<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FakturReviser.php';
require_once __DIR__ . '/../src/FakturLineResolver.php';

final class FakturReviserTest extends TestCase
{
    private const KUTIP = __DIR__ . '/fixtures/fixture-kutip.xml';
    private const PANJANG = __DIR__ . '/fixtures/fixture-panjang.xml';
    private const HEALTHY = __DIR__ . '/fixtures/fixture-healthy.xml';

    public function testApplySanitizesApostropheNumbers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        $stats = FakturReviser::apply(self::KUTIP, [], $tmp);

        $this->assertSame(2, $stats['sanitized']);
        $this->assertSame(0, $stats['invalidLeft']);
        $this->assertSame(0, $stats['updated']);

        $content = file_get_contents($tmp);
        $this->assertStringNotContainsString('&#039;', $content);
        $this->assertStringContainsString('3175042004810002', $content);
        $this->assertStringContainsString('3175023112720011', $content);

        @unlink($tmp);
    }

    public function testApplyReportsWrongLengthNumbers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        $stats = FakturReviser::apply(self::PANJANG, [], $tmp);

        $this->assertSame(0, $stats['sanitized']);
        $this->assertSame(2, $stats['invalidLeft']);

        $content = file_get_contents($tmp);
        $this->assertStringContainsString('31740096112900001', $content); // 17 digit left as-is
        $this->assertStringContainsString('317204410900006', $content);    // 15 digit left as-is

        @unlink($tmp);
    }

    public function testApplyHealthyUntouched(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        $stats = FakturReviser::apply(self::HEALTHY, [], $tmp);

        $this->assertSame(0, $stats['sanitized']);
        $this->assertSame(0, $stats['invalidLeft']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(2, $stats['untouched']);

        @unlink($tmp);
    }

    public function testApplyWithMatchesUpdatesInvoices(): void
    {
        $matches = [
            '111215417-111217992-01-Dedi Saripudin' => [
                'refdesc' => '111215417-111217992-01-Dedi Saripudin',
                'status' => 'matched',
                'docType' => 'NPWP',
                'number' => '0022310395038000',
                'name' => 'Dedi',
            ],
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        $stats = FakturReviser::apply(self::KUTIP, $matches, $tmp);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(2, $stats['untouched']);

        $content = file_get_contents($tmp);
        $this->assertStringContainsString('<BuyerDocument>NPWP</BuyerDocument>', $content);
        $this->assertStringContainsString('<BuyerDocumentNumber>0022310395038000</BuyerDocumentNumber>', $content);

        @unlink($tmp);
    }

    public function testApplyInvalidStructureThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        file_put_contents($tmp, 'not xml');
        $this->expectException(RuntimeException::class);
        FakturReviser::apply($tmp, [], $tmp . '.out');
        @unlink($tmp);
        @unlink($tmp . '.out');
    }
}
