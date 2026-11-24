<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/MasterReader.php';

final class MasterReaderTest extends TestCase
{
    private const MASTER_XLSX = __DIR__ . '/fixtures/master-mini.xlsx';

    public function testReadXlsxReturnsAssociativeRows(): void
    {
        if (!is_file(self::MASTER_XLSX)) {
            $this->markTestSkipped('Fixture master-mini.xlsx tidak tersedia.');
        }
        $rows = MasterReader::read(self::MASTER_XLSX);
        $this->assertIsArray($rows);
        if ($rows) {
            $this->assertArrayHasKey('cardCode', $rows[0]);
            $this->assertArrayHasKey('invoice', $rows[0]);
        }
    }

    public function testReadUnknownExtensionReturnsEmpty(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.txt';
        file_put_contents($tmp, 'not excel');
        $rows = MasterReader::read($tmp);
        $this->assertSame([], $rows);
        @unlink($tmp);
    }

    public function testNormalizeMissingRequiredHeaderReturnsEmpty(): void
    {
        $ref = new ReflectionClass(MasterReader::class);
        $method = $ref->getMethod('normalize');
        $method->setAccessible(true);

        $rows = [
            ['Foo', 'Bar'],
            ['A', 'B'],
        ];
        $result = $method->invoke(null, $rows);
        $this->assertSame([], $result);
    }

    public function testNormalizeMapsKnownHeaders(): void
    {
        $ref = new ReflectionClass(MasterReader::class);
        $method = $ref->getMethod('normalize');
        $method->setAccessible(true);

        $rows = [
            ['Customer Code', 'Invoice', 'NPWP', 'KTP', 'Cust Name', 'Foreign Name', 'DO', 'Alamat Faktur', 'Alamat Kirim'],
            ['C001', 'INV001', '002231039503800', '', 'John Doe', '', 'DO001', 'Alamat 1', 'Alamat 2'],
        ];
        $result = $method->invoke(null, $rows);
        $this->assertCount(1, $result);
        $this->assertSame('C001', $result[0]['cardCode']);
        $this->assertSame('INV001', $result[0]['invoice']);
        $this->assertSame('002231039503800', $result[0]['npwp']);
        $this->assertSame('John Doe', $result[0]['custName']);
        $this->assertSame('DO001', $result[0]['do']);
    }
}
