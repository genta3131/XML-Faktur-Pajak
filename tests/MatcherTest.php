<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Matcher.php';

final class MatcherTest extends TestCase
{
    public function testNormalizeNumberStripsNonDigits(): void
    {
        $this->assertSame('123456', Matcher::normalizeNumber('12.345.6'));
        $this->assertSame('123456', Matcher::normalizeNumber('12 345 6'));
        $this->assertSame('123456', Matcher::normalizeNumber('12-345-6'));
        $this->assertSame('', Matcher::normalizeNumber(''));
        $this->assertSame('123', Matcher::normalizeNumber('abc123def'));
    }

    public function testValidNumberNpwp(): void
    {
        $this->assertTrue(Matcher::validNumber('NPWP', '002231039503800'));   // 15 digit
        $this->assertTrue(Matcher::validNumber('NPWP', '0022310395038000'));  // 16 digit
        $this->assertFalse(Matcher::validNumber('NPWP', '1234567890'));       // 10 digit
        $this->assertFalse(Matcher::validNumber('NPWP', ''));
    }

    public function testValidNumberNationalId(): void
    {
        $this->assertTrue(Matcher::validNumber('National ID', '3173071007760006'));  // 16 digit
        $this->assertFalse(Matcher::validNumber('National ID', '317307100776000'));  // 15 digit
        $this->assertFalse(Matcher::validNumber('National ID', ''));
    }

    public function testBuildIndexDuplicateKeysWarnsSilently(): void
    {
        $rows = [
            ['cardCode' => 'A001', 'invoice' => 'INV001', 'npwp' => '123', 'ktp' => '', 'custName' => 'A', 'foreignName' => ''],
            ['cardCode' => 'A001', 'invoice' => 'INV001', 'npwp' => '456', 'ktp' => '', 'custName' => 'B', 'foreignName' => ''],
        ];
        $index = Matcher::buildIndex($rows);
        $this->assertCount(1, $index);
        $this->assertSame('456', $index['A001|INV001']['npwp']); // later overwrites earlier
    }

    public function testBuildIndexSkipsEmptyKeys(): void
    {
        $rows = [
            ['cardCode' => '', 'invoice' => '', 'npwp' => '', 'ktp' => '', 'custName' => '', 'foreignName' => ''],
            ['cardCode' => 'A001', 'invoice' => 'INV001', 'npwp' => '123', 'ktp' => '', 'custName' => 'A', 'foreignName' => ''],
        ];
        $index = Matcher::buildIndex($rows);
        $this->assertCount(1, $index);
    }

    public function testMatchRefDescNpwpMatched(): void
    {
        $index = Matcher::buildIndex([
            ['cardCode' => 'C001', 'invoice' => 'INV001', 'npwp' => '002231039503800', 'ktp' => '', 'custName' => 'John', 'foreignName' => ''],
        ]);
        $result = Matcher::matchRefDesc('C001-INV001-01-John Doe', $index);
        $this->assertSame('matched', $result['status']);
        $this->assertSame('NPWP', $result['docType']);
        $this->assertSame('002231039503800', $result['number']);
        $this->assertSame('C001', $result['cardCode']);
        $this->assertSame('INV001', $result['invoice']);
        $this->assertSame('John', $result['name']);
    }

    public function testMatchRefDescKtpMatched(): void
    {
        $index = Matcher::buildIndex([
            ['cardCode' => 'C001', 'invoice' => 'INV001', 'npwp' => '', 'ktp' => '3173071007760006', 'custName' => '', 'foreignName' => 'John'],
        ]);
        $result = Matcher::matchRefDesc('C001-INV001-01-John Doe', $index);
        $this->assertSame('matched', $result['status']);
        $this->assertSame('National ID', $result['docType']);
        $this->assertSame('3173071007760006', $result['number']);
    }

    public function testMatchRefDescBothEmpty(): void
    {
        $index = Matcher::buildIndex([
            ['cardCode' => 'C001', 'invoice' => 'INV001', 'npwp' => '', 'ktp' => '', 'custName' => 'John', 'foreignName' => ''],
        ]);
        $result = Matcher::matchRefDesc('C001-INV001-01-John Doe', $index);
        $this->assertSame('both_empty', $result['status']);
    }

    public function testMatchRefDescNotFound(): void
    {
        $index = Matcher::buildIndex([
            ['cardCode' => 'C001', 'invoice' => 'INV001', 'npwp' => '002231039503800', 'ktp' => '', 'custName' => 'John', 'foreignName' => ''],
        ]);
        $result = Matcher::matchRefDesc('C999-INV999-01-Unknown', $index);
        $this->assertSame('not_found', $result['status']);
    }

    public function testMatchRefDescNameWithHyphenNotTruncated(): void
    {
        // CardCode-Invoice-Seq-Name with hyphen in name
        $index = Matcher::buildIndex([
            ['cardCode' => 'C001', 'invoice' => 'INV001', 'npwp' => '002231039503800', 'ktp' => '', 'custName' => 'John-Doe', 'foreignName' => ''],
        ]);
        $result = Matcher::matchRefDesc('C001-INV001-01-John-Doe', $index);
        $this->assertSame('matched', $result['status']);
    }

    public function testMatchRefDescMalformedReturnsNotFound(): void
    {
        $index = Matcher::buildIndex([]);
        $result = Matcher::matchRefDesc('nohyphen', $index);
        $this->assertSame('not_found', $result['status']);

        $result = Matcher::matchRefDesc('-', $index);
        $this->assertSame('not_found', $result['status']);
    }

    /** matchesFromXml memakai baris master yang diberikan (tidak membaca ulang master). */
    public function testMatchesFromXmlUsesProvidedRows(): void
    {
        $masterRows = [
            ['cardCode' => '1100000458', 'invoice' => '111217992', 'npwp' => '', 'ktp' => '3173071007760006', 'custName' => 'Achmad', 'foreignName' => '', 'do' => '', 'alamatFaktur' => '', 'alamatKirim' => ''],
            ['cardCode' => '1100000459', 'invoice' => '111217993', 'npwp' => '002231039503800', 'ktp' => '', 'custName' => 'Achmad', 'foreignName' => '', 'do' => '', 'alamatFaktur' => '', 'alamatKirim' => ''],
        ];
        $matches = Matcher::matchesFromXml(__DIR__ . '/fixtures/fixture-healthy.xml', $masterRows);

        $this->assertCount(2, $matches);
        $r1 = $matches['1100000458-111217992-01-Dedi Saripudin'];
        $this->assertSame('matched', $r1['status']);
        $this->assertSame('National ID', $r1['docType']);
        $this->assertSame('3173071007760006', $r1['number']);
        $r2 = $matches['1100000459-111217993-02-Dedi Saripudin'];
        $this->assertSame('matched', $r2['status']);
        $this->assertSame('NPWP', $r2['docType']);
    }

    public function testMatchesFromXmlThrowsOnEmptyRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Matcher::matchesFromXml(__DIR__ . '/fixtures/fixture-healthy.xml', []);
    }

    public function testMatchesFromXmlThrowsOnUnparseableXml(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xmlf') . '.xml';
        file_put_contents($tmp, 'not xml');
        try {
            $this->expectException(\RuntimeException::class);
            Matcher::matchesFromXml($tmp, [
                ['cardCode' => 'X', 'invoice' => 'Y', 'npwp' => '', 'ktp' => '', 'custName' => '', 'foreignName' => '', 'do' => '', 'alamatFaktur' => '', 'alamatKirim' => ''],
            ]);
        } finally {
            @unlink($tmp);
        }
    }
}
