<?php

/**
 * FakturLineResolver — memetakan nomor baris error (dari error report
 * aplikasi e-Faktur DJP) ke invoice yang mengandungnya, beserta RefDesc
 * dan tag XML terdekat dengan baris tersebut.
 *
 * Berjalan di atas file XML mentah (per baris), tanpa offset ajaib:
 * indeks dibangun dari baris pembuka/penutup <TaxInvoice>.
 */
final class FakturLineResolver
{
    /**
     * Tag wajib di setiap <TaxInvoice> yang diperiksa scan() (cek otomatis).
     * Default konservatif: tag inti yang ada di semua e-Faktur. Daftar ini dapat
     * disesuaikan — verifikasi terhadap TaxInvoice.xsd resmi sebelum menambah
     * (mis. SellerIDTKU, ListOfGoodService) agar tidak false-positive.
     */
    private const MANDATORY_TAGS = [
        'TaxInvoiceDate',
        'RefDesc',
        'BuyerDocument',
        'BuyerDocumentNumber',
        'BuyerName',
    ];

    /**
     * Indeks semua blok <TaxInvoice> dalam file.
     *
     * @return array[] Setiap elemen: ['start' => int, 'end' => int|null, 'refdesc' => string|null]
     *                 Nomor baris 1-based; 'end' null jika file berakhir tanpa penutup.
     */
    public static function buildIndex(string $xmlPath): array
    {
        return self::buildIndexFromFile($xmlPath);
    }

    /**
     * Cari blok <TaxInvoice> berdasarkan nomor invoice yang diekstrak dari RefDesc.
     * Format RefDesc: CardCode-Invoice-Seq-Nama, bagian ke-2 adalah nomor invoice.
     *
     * @param string $xmlPath path file XML faktur
     * @param int[]|string[] $invoiceNumbers daftar nomor invoice yang dicari
     * @return array[] Setiap elemen: ['start' => int, 'end' => int|null,
     *                 'refdesc' => string|null, 'docType' => string|null, 'invoice' => string|null]
     *                 Hanya blok yang RefDesc-nya cocok dengan salah satu nomor invoice.
     */
    public static function findByInvoiceNumber(string $xmlPath, array $invoiceNumbers): array
    {
        $index = self::buildIndexFromFile($xmlPath);
        $wanted = [];
        foreach ($invoiceNumbers as $n) {
            $wanted[] = trim((string) $n);
        }
        if (!$wanted) {
            return [];
        }

        $results = [];
        foreach ($index as $entry) {
            $refdesc = $entry['refdesc'] ?? null;
            if ($refdesc === null || $refdesc === '') {
                continue;
            }
            // Format: CardCode-Invoice-Seq-Nama
            $parts = explode('-', $refdesc, 3);
            if (count($parts) < 2) {
                continue;
            }
            $invoice = trim($parts[1]);
            if ($invoice !== '' && in_array($invoice, $wanted, true)) {
                $results[] = [
                    'start'   => $entry['start'],
                    'end'     => $entry['end'] ?? null,
                    'refdesc' => $refdesc,
                    'docType' => $entry['docType'] ?? null,
                    'invoice' => $invoice,
                ];
            }
        }

        return $results;
    }

    /**
     * Ekstrak blok <TaxInvoice> tertentu ke file XML baru (format e-Faktur bulk).
     * Hanya blok yang start/end-nya cocok yang disalin; sisa tidak ikut disertakan.
     *
     * @param string $srcPath file sumber (XML faktur asli)
     * @param array[] $entries daftar entri, masing-masing butuh kunci 'start' (int) dan 'end' (int|null)
     * @param string $outPath file tujuan yang akan dibuat
     * @return bool true bila berhasil
     */
    public static function extractInvoices(string $srcPath, array $entries, string $outPath): bool
    {
        if (!$entries) {
            return false;
        }

        // Urutkan berdasarkan baris awal agar penyalinan berurutan.
        usort($entries, fn($a, $b) => $a['start'] <=> $b['start']);

        $src = @fopen($srcPath, 'r');
        if ($src === false) {
            return false;
        }

        $dst = @fopen($outPath, 'w');
        if ($dst === false) {
            fclose($src);
            return false;
        }

        fwrite($dst, '<?xml version="1.0" encoding="utf-8" ?>' . PHP_EOL);
        fwrite($dst, '<TaxInvoiceBulk>' . PHP_EOL);
        fwrite($dst, '    <ListOfTaxInvoice>' . PHP_EOL);

        $lineNo = 0;
        $entryIdx = 0;
        $count = count($entries);
        $copying = false;
        $currentEnd = null;

        while (($raw = fgets($src)) !== false) {
            $lineNo++;

            // Mulai menyalin saat sampai baris awal entri berikutnya.
            if (!$copying && $entryIdx < $count && $lineNo >= $entries[$entryIdx]['start']) {
                $copying = true;
                $currentEnd = $entries[$entryIdx]['end'] ?? null;
            }

            if ($copying) {
                fwrite($dst, $raw);

                if ($currentEnd !== null && $lineNo >= $currentEnd) {
                    $copying = false;
                    $entryIdx++;
                    $currentEnd = null;
                }
            }
        }

        fwrite($dst, '    </ListOfTaxInvoice>' . PHP_EOL);
        fwrite($dst, '</TaxInvoiceBulk>' . PHP_EOL);

        $ok = fclose($src) !== false && fclose($dst) !== false;
        return $ok;
    }

    /**
     * Validasi struktur e-Faktur sebelum pemrosesan lebih lanjut.
     *
     * Memeriksa apakah file dapat di-parse sebagai XML, root tag adalah
     * <TaxInvoiceBulk>, dan <ListOfTaxInvoice> minimal memiliki satu <TaxInvoice>.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function validateStructure(string $xmlPath): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($xmlPath);
            $parseErrors = libxml_get_errors();

            if ($xml === false) {
                $detail = '';
                foreach ($parseErrors as $e) {
                    $detail .= ' [' . $e->line . '] ' . trim($e->message);
                }
                return [
                    'valid' => false,
                    'error' => 'File XML tidak dapat di-parse.' . ($detail !== '' ? ' Detail:' . $detail : ''),
                ];
            }

            if ((string) $xml->getName() !== 'TaxInvoiceBulk') {
                return [
                    'valid' => false,
                    'error' => "Root tag '<" . $xml->getName() . ">' bukan '<TaxInvoiceBulk>. File ini bukan e-Faktur bulk.",
                ];
            }

            if (!isset($xml->ListOfTaxInvoice)) {
                return [
                    'valid' => false,
                    'error' => 'Tag <ListOfTaxInvoice> tidak ditemukan dalam file XML.',
                ];
            }

            $invoices = $xml->ListOfTaxInvoice->TaxInvoice;
            if ($invoices === null || count($invoices) === 0) {
                return [
                    'valid' => false,
                    'error' => 'Tidak ada elemen <TaxInvoice> di dalam <ListOfTaxInvoice>.',
                ];
            }

            return ['valid' => true, 'error' => null];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Deteksi blok <TaxInvoice> (logika tunggal yang dipakai buildIndex + scan).
     *
     * @return array[] Setiap elemen: ['start' => int, 'end' => int|null, 'refdesc' => string|null, 'docType' => string|null]
     */
    private static function buildIndexFromFile(string $xmlPath): array
    {
        $handle = @fopen($xmlPath, 'r');
        if ($handle === false) {
            return [];
        }

        $entries = [];
        $open = null;       // ['start' => int, 'refdesc' => ?string, 'docType' => ?string, 'tags' => array<string,true>]
        $lineNo = 0;

        while (($raw = fgets($handle)) !== false) {
            $lineNo++;
            $line = rtrim($raw, "\r\n");

            if ($open === null && preg_match('/<TaxInvoice>\s*$/', $line)) {
                $open = ['start' => $lineNo, 'refdesc' => null, 'docType' => null, 'tags' => []];
            } elseif ($open !== null) {
                // Catat setiap tag pembuka di dalam blok (untuk cek tag wajib hilang).
                if (preg_match_all('/<([A-Za-z][A-Za-z0-9]*)/', $line, $tm)) {
                    foreach ($tm[1] as $t) {
                        $open['tags'][$t] = true;
                    }
                }
                if ($open['refdesc'] === null
                    && preg_match('/<RefDesc>(.*?)<\/RefDesc>/u', $line, $m)) {
                    $open['refdesc'] = trim($m[1]);
                }
                if ($open['docType'] === null
                    && preg_match('/<BuyerDocument>(.*?)<\/BuyerDocument>/u', $line, $m)) {
                    $open['docType'] = trim($m[1]);
                }
                if (preg_match('/<\/TaxInvoice>\s*$/', $line)) {
                    $entries[] = [
                        'start'   => $open['start'],
                        'end'     => $lineNo,
                        'refdesc' => $open['refdesc'],
                        'docType' => $open['docType'],
                        'tags'    => $open['tags'],
                    ];
                    $open = null;
                }
            }
        }
        fclose($handle);

        if ($open !== null) {
            $entries[] = ['start' => $open['start'], 'end' => null, 'refdesc' => $open['refdesc'], 'docType' => $open['docType'], 'tags' => $open['tags']];
        }

        return $entries;
    }

    /**
     * Pindai seluruh XML dan kembalikan hanya baris yang bermasalah.
     *
     * @return array[] Sama seperti resolve(), hanya status='problem'.
     */
    public static function scan(string $xmlPath): array
    {
        $index = self::buildIndexFromFile($xmlPath);
        $handle = @fopen($xmlPath, 'r');
        if ($handle === false) {
            return [];
        }
        $results = [];
        $lineNo = 0;
        $entryIdx = 0;
        $count = count($index);

        while (($raw = fgets($handle)) !== false) {
            $lineNo++;
            $line = rtrim($raw, "\r\n");

            // Majukan pointer entri ke blok yang belum berakhir sebelum baris ini.
            while ($entryIdx < $count) {
                $end = $index[$entryIdx]['end'] ?? PHP_INT_MAX;
                if ($lineNo > $end) {
                    $entryIdx++;
                    continue;
                }
                break;
            }

            $inBlock = $entryIdx < $count
                && $lineNo >= $index[$entryIdx]['start']
                && $lineNo <= ($index[$entryIdx]['end'] ?? PHP_INT_MAX);
            if (!$inBlock) {
                continue;
            }

            $entry = $index[$entryIdx];
            $refdesc = $entry['refdesc'];
            $docType = $entry['docType'] ?? null;

            $tag = self::tagOnLine($line);
            $value = self::valueOnLine($line);
            $issue = self::issue($tag, $value, $docType);
            if ($issue !== null) {
                $results[] = [
                    'line'        => $lineNo,
                    'status'      => 'problem',
                    'invoiceIndex' => null,
                    'refdesc'     => $refdesc,
                    'nearTag'     => null,
                    'tag'         => $tag,
                    'value'       => $value,
                    'docType'     => $docType,
                    'issue'       => $issue,
                    'snippet'     => self::snippet($line),
                ];
            }
        }

        fclose($handle);

        // Cek struktural per blok: tag wajib hilang & RefDesc dobel (cek otomatis).
        $results = array_merge($results, self::detectStructuralProblems($index));

        return $results;
    }

    /**
     * Cek struktural per blok: tag wajib hilang & RefDesc dobel.
     * Dipakai scan() (cek otomatis seluruh file), bukan resolve() (per-baris spesifik).
     *
     * @param array[] $index hasil buildIndexFromFile()
     * @return array[] baris problem (status='problem')
     */
    private static function detectStructuralProblems(array $index): array
    {
        $results = [];

        $refCounts = [];
        foreach ($index as $entry) {
            $rd = $entry['refdesc'];
            if ($rd !== null && $rd !== '') {
                $refCounts[$rd] = ($refCounts[$rd] ?? 0) + 1;
            }
        }

        foreach ($index as $entry) {
            $seen = $entry['tags'] ?? [];
            $refdesc = $entry['refdesc'];
            $docType = $entry['docType'] ?? null;

            foreach (self::MANDATORY_TAGS as $t) {
                if (empty($seen[$t])) {
                    $results[] = [
                        'line'        => $entry['start'],
                        'status'      => 'problem',
                        'invoiceIndex' => null,
                        'refdesc'     => $refdesc,
                        'nearTag'     => null,
                        'tag'         => $t,
                        'value'       => null,
                        'docType'     => $docType,
                        'issue'       => 'Tag wajib hilang: ' . $t,
                        'snippet'     => '',
                    ];
                }
            }

            $rd = $refdesc;
            if ($rd !== null && $rd !== '' && ($refCounts[$rd] ?? 0) > 1) {
                $results[] = [
                    'line'        => $entry['start'],
                    'status'      => 'problem',
                    'invoiceIndex' => null,
                    'refdesc'     => $rd,
                    'nearTag'     => 'RefDesc',
                    'tag'         => 'RefDesc',
                    'value'       => $rd,
                    'docType'     => $docType,
                    'issue'       => 'RefDesc dobel (muncul ' . $refCounts[$rd] . ' kali)',
                    'snippet'     => $rd,
                ];
            }
        }

        return $results;
    }

    /**
     * Petakan nomor-nomor baris error ke invoice yang mengandungnya.
     *
     * @param int[] $lineNumbers
     * @return array[] Setiap elemen: [
     *   'line' => int, 'status' => 'ok'|'out_of_block', 'invoiceIndex' => int|null,
     *   'refdesc' => string|null, 'nearTag' => string|null, 'snippet' => string
     * ]
     */
    public static function resolve(string $xmlPath, array $lineNumbers): array
    {
        $index = self::buildIndex($xmlPath);
        $lines = self::readLines($xmlPath, $lineNumbers);
        $results = [];

        foreach ($lineNumbers as $lineNo) {
            $text = $lines[$lineNo] ?? null;
            $entryIdx = self::containingEntry($index, $lineNo);
            $tag = self::tagOnLine($text);
            $value = self::valueOnLine($text);

            if ($entryIdx === null) {
                $results[] = [
                    'line'        => (int) $lineNo,
                    'status'      => 'out_of_block',
                    'invoiceIndex' => null,
                    'refdesc'     => null,
                    'nearTag'     => null,
                    'tag'         => $tag,
                    'value'       => $value,
                    'docType'     => null,
                    'issue'       => null,
                    'snippet'     => self::snippet($text),
                ];
                continue;
            }

            $refdesc = $index[$entryIdx]['refdesc'];
            $docType = $index[$entryIdx]['docType'] ?? null;
            $nearTag = self::nearTag($xmlPath, $lineNo);
            $issue = self::issue($tag, $value, $docType);
            $display = $text !== null
                ? preg_replace('/<\/?RefDesc>\s*/u', '', $text) : null;

            $results[] = [
                'line'        => (int) $lineNo,
                'status'      => $issue === null ? 'ok' : 'problem',
                'invoiceIndex' => $entryIdx + 1,
                'refdesc'     => $refdesc,
                'nearTag'     => $nearTag,
                'tag'         => $tag,
                'value'       => $value,
                'docType'     => $docType,
                'issue'       => $issue,
                'snippet'     => self::snippet($display),
            ];
        }

        return $results;
    }

    /** Tag pada baris itu sendiri (bukan tetangga). */
    private static function tagOnLine(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        return preg_match('/<\s*([A-Za-z][A-Za-z0-9]*)/', $text, $m) ? $m[1] : null;
    }

    /** Isi tag pada baris itu (entity XML sudah didekode). */
    private static function valueOnLine(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        if (preg_match('/<\s*([A-Za-z][A-Za-z0-9]*)\s*>([^<]*)<\/\s*\1\s*>/u', $text, $m)) {
            return html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return null;
    }

    /** Alasan BuyerDocumentNumber ditolak e-Faktur, atau null jika tidak ada masalah. */
    private static function issue(?string $tag, ?string $value, ?string $docType): ?string
    {
        if ($tag !== 'BuyerDocumentNumber' || $value === null || $value === '') {
            return null;
        }
        if (preg_replace('/\D+/', '', $value) !== $value) {
            return 'Mengandung karakter non-digit';
        }
        $len = strlen($value);
        $ok = $docType === 'NPWP'
            ? ($len === 15 || $len === 16)
            : ($len === 16);
        if ($ok) {
            return null;
        }
        return 'Panjang ' . $len . ' digit, harus ' . ($docType === 'NPWP' ? '15/16' : '16');
    }

    /** @return int|null posisi entri (0-based) yang memuat baris, atau null */
    private static function containingEntry(array $index, int $lineNo): ?int
    {
        foreach ($index as $i => $entry) {
            $end = $entry['end'] ?? PHP_INT_MAX;
            if ($lineNo >= $entry['start'] && $lineNo <= $end) {
                return $i;
            }
        }
        return null;
    }

    /** Nama tag pada baris itu, atau tag baris terdekat (maks ±3 baris). */
    private static function nearTag(string $xmlPath, int $lineNo): ?string
    {
        for ($offset = 0; $offset <= 3; $offset++) {
            foreach ([$offset, -$offset] as $delta) {
                $text = self::lineAt($xmlPath, $lineNo + $delta);
                if ($text === null) {
                    continue;
                }
                if (preg_match('/<\/?([A-Za-z][A-Za-z0-9]*)/', $text, $m)) {
                    return $m[1];
                }
            }
            if ($offset === 0) {
                continue; // jangan cek offset negatif dua kali saat 0
            }
        }
        return null;
    }

    private static function lineAt(string $xmlPath, int $lineNo): ?string
    {
        if ($lineNo < 1) {
            return null;
        }
        try {
            $f = new \SplFileObject($xmlPath);
            $f->setFlags(0);
        } catch (\Throwable $e) {
            return null;
        }
        $f->seek($lineNo - 1);
        if (!$f->valid()) {
            return null;
        }
        $line = $f->current();
        return $line === false ? null : rtrim((string) $line, "\r\n");
    }

    private static function readLines(string $xmlPath, array $lineNumbers): array
    {
        // Single-pass streaming: kumpulkan hanya baris yang diminta, tanpa memuat seluruh file.
        $want = [];
        foreach ($lineNumbers as $n) {
            $n = (int) $n;
            if ($n >= 1) {
                $want[$n] = null;
            }
        }
        if (!$want) {
            return [];
        }
        $maxWant = (int) max(array_keys($want));
        $handle = @fopen($xmlPath, 'r');
        if ($handle === false) {
            return [];
        }
        $lineNo = 0;
        while (($raw = fgets($handle)) !== false) {
            $lineNo++;
            if (array_key_exists($lineNo, $want)) {
                $want[$lineNo] = rtrim($raw, "\r\n");
            }
            if ($lineNo >= $maxWant) {
                break;
            }
        }
        fclose($handle);
        return $want;
    }

    private static function snippet(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = trim($text);
        if (strlen($text) > 120) {
            $text = substr($text, 0, 120) . '…';
        }
        return $text;
    }
}
