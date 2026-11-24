<?php
require_once 'src/FakturLineResolver.php';

$path = 'xml case/case takeout/faktur 301026.xml';
$index = FakturLineResolver::buildIndex($path);
echo "File: $path\n";
echo "Total faktur: " . count($index) . "\n\n";
echo "Daftar invoice (RefDesc part ke-2):\n";
$count = 0;
foreach ($index as $entry) {
    if ($entry['refdesc']) {
        $parts = explode('-', $entry['refdesc'], 3);
        $invoice = $parts[1] ?? '?';
        echo "  $invoice | {$entry['refdesc']} | baris {$entry['start']}\n";
        $count++;
        if ($count >= 15) break;
    }
}
echo "... dan masih ada lagi (" . count($index) . " total)\n";

// Coba takeout 3 invoice pertama
$first3 = [];
foreach ($index as $entry) {
    if ($entry['refdesc']) {
        $parts = explode('-', $entry['refdesc'], 3);
        $first3[] = $parts[1] ?? '';
        if (count($first3) >= 3) break;
    }
}
echo "\nContoh takeout 3 invoice pertama: " . implode(', ', $first3) . "\n";
$found = FakturLineResolver::findByInvoiceNumber($path, $first3);
echo "Ditemukan: " . count($found) . "\n";
foreach ($found as $f) {
    echo "  - {$f['invoice']} => {$f['refdesc']}\n";
}

// Ekspor
$out = 'tmp/takeout_case_takeout.xml';
$ok = FakturLineResolver::extractInvoices($path, $found, $out);
echo "Ekspor: " . ($ok ? 'BERHASIL' : 'GAGAL') . "\n";
if ($ok) {
    $xml = simplexml_load_file($out);
    echo "File hasil: " . count($xml->ListOfTaxInvoice->TaxInvoice) . " faktur, valid XML\n";
    unlink($out);
}