<?php

require_once __DIR__ . '/../lib/simplexlsx.php';

if (!class_exists('SimpleXLSX')) {
    class_alias(\Shuchkin\SimpleXLSX::class, 'SimpleXLSX');
}

if (!class_exists('PhpOffice\PhpSpreadsheet\Reader\Xls')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * MasterReader — membaca Excel master data customer (format Sample NIK.xlsx)
 * menjadi array baris asosiatif.
 *
 * v3: mendukung kedua format Excel:
 *   - .xlsx → SimpleXLSX (ringan, tanpa Composer)
 *   - .xls  → PhpSpreadsheet Reader\Xls (format lama BIFF)
 * Output selalu array asosiatif yang sama, sehingga Matcher tidak perlu diubah.
 */
final class MasterReader
{
    /** Header yang dikenali (lowercase) => field hasil */
    private const COLUMNS = [
        'custumer code' => 'cardCode',
        'customer code' => 'cardCode', // varian ejaan
        'foreign name'  => 'foreignName',
        'cust name'     => 'custName',
        'do'            => 'do',
        'invoice'       => 'invoice',
        'npwp'          => 'npwp',
        'alamat faktur' => 'alamatFaktur',
        'ktp'           => 'ktp',
        'alamat kirim'  => 'alamatKirim',
    ];

    /**
     * @return array[] Baris data: ['cardCode' => string, 'foreignName' => string,
     *   'custName' => string, 'do' => string, 'invoice' => string, 'npwp' => string,
     *   'alamatFaktur' => string, 'ktp' => string, 'alamatKirim' => string]
     */
    public static function read(string $xlsxPath): array
    {
        $ext = strtolower(pathinfo($xlsxPath, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            return self::readXlsx($xlsxPath);
        }

        if ($ext === 'xls') {
            return self::readXls($xlsxPath);
        }

        return [];
    }

    /** Baca .xlsx via SimpleXLSX */
    private static function readXlsx(string $path): array
    {
        $xlsx = SimpleXLSX::parse($path);
        if ($xlsx === false || !$xlsx->rows()) {
            return [];
        }

        return self::normalize($xlsx->rows());
    }

    /** Baca .xls via PhpSpreadsheet Reader\Xls */
    private static function readXls(string $path): array
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return [];
        }

        $worksheet = $spreadsheet->getActiveSheet();
        if ($worksheet === null) {
            return [];
        }

        $rows = [];
        foreach ($worksheet->toArray(null, true, true, true) as $row) {
            $rows[] = array_values($row);
        }

        if (count($rows) < 2) {
            return [];
        }

        return self::normalize($rows);
    }

    /** Header wajib: kolom kunci pencocokan. Jika tidak ada, file bukan master customer. */
    private static function hasRequiredHeader(array $header): bool
    {
        $headerSet = array_flip($header);
        $hasCode = isset($headerSet['custumer code']) || isset($headerSet['customer code']);
        return $hasCode && isset($headerSet['invoice']);
    }

    /** Normalisasi baris mentah menjadi array asosiatif dengan kunci field standar. */
    private static function normalize(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $header = array_map(fn($c) => strtolower(trim((string) $c)), $rows[0]);

        if (!self::hasRequiredHeader($header)) {
            return [];
        }

        $out = [];
        foreach (array_slice($rows, 1) as $data) {
            $row = [
                'cardCode' => '', 'foreignName' => '', 'custName' => '', 'do' => '',
                'invoice' => '', 'npwp' => '', 'alamatFaktur' => '', 'ktp' => '', 'alamatKirim' => '',
            ];
            foreach ($header as $i => $name) {
                if (!isset(self::COLUMNS[$name])) {
                    continue;
                }
                $field = self::COLUMNS[$name];
                $row[$field] = trim((string) ($data[$i] ?? ''));
            }
            $out[] = $row;
        }

        return $out;
    }
}
