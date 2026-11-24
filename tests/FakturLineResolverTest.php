<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FakturLineResolver.php';

final class FakturLineResolverTest extends TestCase
{
    private const KUTIP = __DIR__ . '/fixtures/fixture-kutip.xml';
    private const PANJANG = __DIR__ . '/fixtures/fixture-panjang.xml';
    private const HEALTHY = __DIR__ . '/fixtures/fixture-healthy.xml';
    private const INVALID_ROOT = __DIR__ . '/fixtures/fixture-invalid-root.xml';
    private const SAMPLE_KUTIP = __DIR__ . '/../xml case/case kutip/faktur 061126 (000).xml';
    private const SAMPLE_PANJANG = __DIR__ . '/../xml case/case minta di takeout by invoice/faktur 071026 (000).xml';
    private const SAMPLE_HEALTHY = __DIR__ . '/../xml case/case takeout/faktur 301026.xml';

    public function testValidateStructureHealthy(): void
    {
        $result = FakturLineResolver::validateStructure(self::HEALTHY);
        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testValidateStructureInvalidRoot(): void
    {
        $result = FakturLineResolver::validateStructure(self::INVALID_ROOT);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('bukan', $result['error']);
    }

    /** validateStructure tidak boleh membocorkan libxml_use_internal_errors(true) ke pemanggil berikutnya. */
    public function testValidateStructureRestoresLibxmlStateFalse(): void
    {
        libxml_use_internal_errors(false);
        $result = FakturLineResolver::validateStructure(self::INVALID_ROOT);
        $this->assertFalse($result['valid']);
        $restored = libxml_use_internal_errors(false);
        $this->assertFalse($restored, 'state libxml harus dipulihkan ke false (nilai sebelumnya)');
    }

    public function testValidateStructureRestoresLibxmlStateTrue(): void
    {
        libxml_use_internal_errors(true);
        $result = FakturLineResolver::validateStructure(self::INVALID_ROOT);
        $this->assertFalse($result['valid']);
        $restored = libxml_use_internal_errors(false);
        $this->assertTrue($restored, 'state libxml harus dipulihkan ke true (nilai sebelumnya)');
    }

    public function testBuildIndexKutip(): void
    {
        $index = FakturLineResolver::buildIndex(self::KUTIP);
        $this->assertCount(3, $index);
        $this->assertSame('111215417-111217992-01-Dedi Saripudin', $index[0]['refdesc']);
        $this->assertSame('National ID', $index[0]['docType']);
        $this->assertNotNull($index[0]['start']);
        $this->assertNotNull($index[0]['end']);
    }

    public function testScanFindsApostropheIssues(): void
    {
        $scan = FakturLineResolver::scan(self::KUTIP);
        $this->assertCount(2, $scan);
        foreach ($scan as $row) {
            $this->assertSame('problem', $row['status']);
            $this->assertSame('Mengandung karakter non-digit', $row['issue']);
            $this->assertNotNull($row['refdesc']);
        }
    }

    public function testScanFindsLengthIssues(): void
    {
        $scan = FakturLineResolver::scan(self::PANJANG);
        $this->assertCount(2, $scan);
        $issues = array_column($scan, 'issue');
        $this->assertStringContainsString('Panjang 17', $issues[0] ?? '');
        $this->assertStringContainsString('Panjang 15', $issues[1] ?? '');
    }

    public function testScanHealthyFindsNothing(): void
    {
        $scan = FakturLineResolver::scan(self::HEALTHY);
        $this->assertCount(0, $scan);
    }

    /** Cek tag wajib hilang: blok tanpa BuyerName harus ditandai. */
    public function testScanFindsMissingMandatoryTag(): void
    {
        $scan = FakturLineResolver::scan(__DIR__ . '/fixtures/fixture-missing-tag.xml');
        $this->assertCount(1, $scan);
        $this->assertSame('problem', $scan[0]['status']);
        $this->assertSame('Tag wajib hilang: BuyerName', $scan[0]['issue']);
        $this->assertSame('BuyerName', $scan[0]['tag']);
        $this->assertNotNull($scan[0]['refdesc']);
    }

    /** Cek RefDesc dobel: RefDesc yang muncul di 2 blok harus ditandai di kedua blok. */
    public function testScanFindsDuplicateRefDesc(): void
    {
        $scan = FakturLineResolver::scan(__DIR__ . '/fixtures/fixture-dup-refdesc.xml');
        $this->assertCount(2, $scan);
        foreach ($scan as $row) {
            $this->assertSame('problem', $row['status']);
            $this->assertSame('RefDesc dobel (muncul 2 kali)', $row['issue']);
            $this->assertSame('RefDesc', $row['tag']);
        }
    }

    public function testResolveApostropheLine(): void
    {
        $scan = FakturLineResolver::scan(self::KUTIP);
        $lineNo = $scan[0]['line'];
        $resolved = FakturLineResolver::resolve(self::KUTIP, [$lineNo]);
        $this->assertSame('problem', $resolved[0]['status']);
        $this->assertSame('Mengandung karakter non-digit', $resolved[0]['issue']);
        $this->assertNotNull($resolved[0]['refdesc']);
    }

    /** Karakterisasi resolve(): mengunci field-field agar refactor line-reader tidak mengubah output. */
    public function testResolveApostropheLineExactFields(): void
    {
        $resolved = FakturLineResolver::resolve(self::KUTIP, [9]);
        $this->assertCount(1, $resolved);
        $r = $resolved[0];
        $this->assertSame(9, $r['line']);
        $this->assertSame('problem', $r['status']);
        $this->assertSame('111215417-111217992-01-Dedi Saripudin', $r['refdesc']);
        $this->assertSame('BuyerDocumentNumber', $r['tag']);
        $this->assertSame('BuyerDocumentNumber', $r['nearTag']);
        $this->assertSame("'3175042004810002", $r['value']);
        $this->assertStringContainsString('&#039;', $r['snippet']);
        $this->assertSame('Mengandung karakter non-digit', $r['issue']);
        $this->assertSame('National ID', $r['docType']);
    }

    public function testResolveHealthyLineIsOk(): void
    {
        $scan = FakturLineResolver::scan(self::HEALTHY);
        $this->assertCount(0, $scan);

        $index = FakturLineResolver::buildIndex(self::HEALTHY);
        $firstLine = $index[0]['start'];
        $resolved = FakturLineResolver::resolve(self::HEALTHY, [$firstLine]);
        $this->assertSame('ok', $resolved[0]['status']);
        $this->assertNull($resolved[0]['issue']);
    }

    public function testResolveOutOfBlockLine(): void
    {
        $resolved = FakturLineResolver::resolve(self::HEALTHY, [1]);
        $this->assertSame('out_of_block', $resolved[0]['status']);
    }

    /** Lewati tes sample dinamis bila file sample (di xml case/) tidak tersedia. */
    private function requireSampleFile(string $path): void
    {
        if (!is_file($path)) {
            $this->markTestSkipped("File sample tidak tersedia: $path");
        }
    }

    public function testDynamicSampleKutip(): void
    {
        $this->requireSampleFile(self::SAMPLE_KUTIP);
        $scan = FakturLineResolver::scan(self::SAMPLE_KUTIP);
        $this->assertGreaterThan(0, count($scan));
        foreach ($scan as $row) {
            $this->assertSame('problem', $row['status']);
            $this->assertSame('Mengandung karakter non-digit', $row['issue']);
        }
    }

    public function testDynamicSamplePanjang(): void
    {
        $this->requireSampleFile(self::SAMPLE_PANJANG);
        $scan = FakturLineResolver::scan(self::SAMPLE_PANJANG);
        $this->assertGreaterThan(0, count($scan));
    }

    public function testDynamicSampleHealthy(): void
    {
        $this->requireSampleFile(self::SAMPLE_HEALTHY);
        $scan = FakturLineResolver::scan(self::SAMPLE_HEALTHY);
        $this->assertCount(0, $scan);
    }

    public function testScanAndBuildIndexConsistency(): void
    {
        $index = FakturLineResolver::buildIndex(self::KUTIP);
        $scan = FakturLineResolver::scan(self::KUTIP);

        foreach ($scan as $row) {
            $found = false;
            foreach ($index as $entry) {
                if ($row['line'] >= $entry['start']
                    && $row['line'] <= ($entry['end'] ?? PHP_INT_MAX)) {
                    $found = true;
                    $this->assertSame($entry['refdesc'], $row['refdesc']);
                    break;
                }
            }
            $this->assertTrue($found, 'Baris bermasalah harus berada dalam blok indeks');
        }
    }

    public function testFindByInvoiceNumberFound(): void
    {
        $this->requireSampleFile(self::SAMPLE_PANJANG);
        // Invoice 111211976 dan 111212705 ada di file sample (dari RefDesc yang diekstrak).
        $found = FakturLineResolver::findByInvoiceNumber(self::SAMPLE_PANJANG, ['111211976', '111212705']);
        $this->assertCount(2, $found);
        $this->assertSame('111211976', $found[0]['invoice']);
        $this->assertSame('1100000079-111211976-01-Agus S', $found[0]['refdesc']);
        $this->assertNotNull($found[0]['start']);
        $this->assertSame('111212705', $found[1]['invoice']);
        $this->assertSame('1100000127-111212705-01-Idham K', $found[1]['refdesc']);
        $this->assertNotNull($found[1]['start']);
    }

    public function testFindByInvoiceNumberReadmeInvoice(): void
    {
        $this->requireSampleFile(self::SAMPLE_PANJANG);
        // Invoice 111212159 dan 111212246 disebutkan readme case takeout by invoice
        // sebagai nomor invoice yang di-takeout. Keduanya ada dalam file sample.
        $found = FakturLineResolver::findByInvoiceNumber(self::SAMPLE_PANJANG, ['111212159', '111212246']);
        $this->assertCount(2, $found);
        $invoices = array_column($found, 'invoice');
        $this->assertContains('111212159', $invoices);
        $this->assertContains('111212246', $invoices);
        foreach ($found as $f) {
            $this->assertNotNull($f['start']);
            $this->assertNotNull($f['refdesc']);
            $this->assertNotNull($f['docType']);
        }
    }

    public function testFindByInvoiceNumberNotFound(): void
    {
        $this->requireSampleFile(self::SAMPLE_PANJANG);
        $found = FakturLineResolver::findByInvoiceNumber(self::SAMPLE_PANJANG, ['999999999', '000000000']);
        $this->assertIsArray($found);
        $this->assertCount(0, $found);
    }

    public function testFindByInvoiceNumberEmptyList(): void
    {
        $found = FakturLineResolver::findByInvoiceNumber(self::HEALTHY, []);
        $this->assertSame([], $found);
    }

    public function testFindByInvoiceNumberMixedFoundAndNotFound(): void
    {
        $this->requireSampleFile(self::SAMPLE_PANJANG);
        $found = FakturLineResolver::findByInvoiceNumber(self::SAMPLE_PANJANG, ['111211976', '999999999']);
        $this->assertCount(1, $found);
        $this->assertSame('111211976', $found[0]['invoice']);
    }
}
