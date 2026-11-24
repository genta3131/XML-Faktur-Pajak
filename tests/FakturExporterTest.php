<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FakturExporter.php';

final class FakturExporterTest extends TestCase
{
    private const HEALTHY = __DIR__ . '/fixtures/fixture-healthy.xml';

    public function testExportProducesTwoSheets(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xlsx';
        $stats = FakturExporter::export(self::HEALTHY, $tmp);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('invoices', $stats);
        $this->assertArrayHasKey('items', $stats);
        $this->assertSame(2, $stats['invoices']);
        $this->assertTrue(is_file($tmp));
        $this->assertGreaterThan(0, filesize($tmp));

        @unlink($tmp);
    }

    public function testExportInvalidXmlThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        file_put_contents($tmp, 'not xml');
        $this->expectException(RuntimeException::class);
        FakturExporter::export($tmp, $tmp . '.xlsx');
        @unlink($tmp);
        @unlink($tmp . '.xlsx');
    }
}
