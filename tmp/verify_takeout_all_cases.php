<?php
/**
 * Verifikasi end-to-end fitur Takeout By Invoice untuk 3 case:
 *  1. case takeout        -> SAMPLE_HEALTHY (tidak ada masalah)
 *  2. case kutip          -> SAMPLE_KUTIP (masalah apostrophe/non-digit)
 *  3. case minta di takeout by invoice -> SAMPLE_PANJANG (masalah panjang NPWP)
 *
 * Alur per case: buildIndex -> findByInvoiceNumber (beberapa invoice) -> extractInvoices
 */
require_once __DIR__ . '/../src/FakturLineResolver.php';

$cases = [
    'case takeout' => [
        'path'   => 'xml case/case takeout/faktur 301026.xml',
        'invoice' => ['111217992', '111217993', '111218083'],
    ],
    'case kutip' => [
        'path'   => 'xml case/case kutip/faktur 061126 (000).xml',
        'invoice' => ['111217992', '111217993'],
    ],
    'case minta di takeout by invoice' => [
        'path'   => 'xml case/case minta di takeout by invoice/faktur 071026 (000).xml',
        'invoice' => ['111212159', '111212246'], // dari readme case
    ],
];

$allOk = true;

foreach ($cases as $label => $c) {
    echo "\n========================================\n";
    echo "CASE: $label\n";
    echo "File: {$c['path']}\n";
    echo "========================================\n";

    if (!is_file($c['path'])) {
        echo "  [SKIP] File tidak ditemukan.\n";
        $allOk = false;
        continue;
    }

    // 1. Build index
    $index = FakturLineResolver::buildIndex($c['path']);
    echo "  Total faktur di index: " . count($index) . "\n";

    // 2. Find by invoice number
    $found = FakturLineResolver::findByInvoiceNumber($c['path'], $c['invoice']);
    $foundInvoices = array_column($found, 'invoice');
    echo "  Invoice yang diminta: " . implode(', ', $c['invoice']) . "\n";
    echo "  Invoice yang ditemukan: " . count($found) . " -> " . implode(', ', $foundInvoices) . "\n";

    $notFound = array_values(array_diff($c['invoice'], $foundInvoices));
    if ($notFound) {
        echo "  Invoice TIDAK ditemukan: " . implode(', ', $notFound) . "\n";
    }

    // 3. Extract/takeout export (simulasi takeout_export di api.php)
    $out = 'tmp/verify_takeout_' . preg_replace('/[^a-zA-Z0-9]/', '_', $label) . '.xml';
    $ok = false;
    if ($found) {
        $ok = FakturLineResolver::extractInvoices($c['path'], $found, $out);
        echo "  Ekspor takeout: " . ($ok ? 'BERHASIL' : 'GAGAL') . "\n";
        if ($ok) {
            $xml = simplexml_load_file($out);
            $count = $xml ? count($xml->ListOfTaxInvoice->TaxInvoice) : 0;
            echo "  File hasil: $count faktur, valid XML: " . ($xml ? 'YA' : 'TIDAK') . "\n";
            @unlink($out);
        }
    } else {
        echo "  Ekspor takeout: DILEWATI (tidak ada invoice yang ditemukan)\n";
        $allOk = false;
    }

    if (!$ok) {
        $allOk = false;
    }
}

echo "\n========================================\n";
echo $allOk ? "SEMUA CASE TAKEOUT: LULUS\n" : "SEMUA CASE TAKEOUT: ADA GAGAL\n";
echo "========================================\n";
exit($allOk ? 0 : 1);
