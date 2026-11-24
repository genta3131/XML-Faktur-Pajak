<?php
/**
 * Takeout By Invoice dengan tanggal hari ini dalam nama file output.
 * Tanggal: 2026-11-23 (dari environment)
 *
 * Alur: buildIndex -> findByInvoiceNumber -> extractInvoices
 */
require_once __DIR__ . '/../src/FakturLineResolver.php';

$tanggal = date('Y-m-d'); // 2026-11-23
echo "Tanggal takeout: $tanggal\n\n";

// Sumber: case takeout (faktur 301026.xml) — 91 faktur
$xmlPath = 'xml case/case takeout/faktur 301026.xml';

// Invoice number yang BENAR-BENAR ada di file ini (dari verifikasi sebelumnya)
$invoiceNumbers = ['111217992', '111217993', '111218083'];

echo "File sumber: $xmlPath\n";
echo "Total faktur di index: " . count(FakturLineResolver::buildIndex($xmlPath)) . "\n";
echo "Invoice yang di-takeout: " . implode(', ', $invoiceNumbers) . "\n\n";

// 1. Cari invoice
$found = FakturLineResolver::findByInvoiceNumber($xmlPath, $invoiceNumbers);
$foundInvoices = array_column($found, 'invoice');
$notFound = array_values(array_diff($invoiceNumbers, $foundInvoices));

echo "Hasil pencarian:\n";
foreach ($found as $f) {
    echo "  ✓ {$f['invoice']} => {$f['refdesc']} (baris {$f['start']})\n";
}
if ($notFound) {
    echo "  ✗ Tidak ditemukan: " . implode(', ', $notFound) . "\n";
}

echo "\n";

// 2. Ekspor ke XML takeout dengan nama file yang mengandung tanggal
$outName = "takeout_${tanggal}_" . implode('_', $invoiceNumbers) . ".xml";
$outPath = __DIR__ . '/../tmp/' . $outName;

$ok = FakturLineResolver::extractInvoices($xmlPath, $found, $outPath);

if ($ok) {
    $xml = simplexml_load_file($outPath);
    $count = $xml ? count($xml->ListOfTaxInvoice->TaxInvoice) : 0;
    echo "Ekspor takeout: BERHASIL\n";
    echo "File output: $outPath\n";
    echo "Jumlah faktur diekspor: $count\n";
    echo "Valid XML: " . ($xml ? 'YA' : 'TIDAK') . "\n";

    // Tampilkan isi singkat setiap faktur yang diekspor
    echo "\nDetail faktur yang diekspor:\n";
    foreach ($xml->ListOfTaxInvoice->TaxInvoice as $inv) {
        $refDesc = (string) $inv->RefDesc;
        $date = (string) $inv->TaxInvoiceDate;
        echo "  - RefDesc: $refDesc | Tanggal Faktur: $date\n";
    }
} else {
    echo "Ekspor takeout: GAGAL\n";
    exit(1);
}

echo "\nSelesai. File takeout siap diunduh: $outName\n";
