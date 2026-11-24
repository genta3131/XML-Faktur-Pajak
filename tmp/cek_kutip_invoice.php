<?php
require_once 'src/FakturLineResolver.php';
$path = 'xml case/case kutip/faktur 061126 (000).xml';
$index = FakturLineResolver::buildIndex($path);
echo 'Total faktur: ' . count($index) . PHP_EOL;
$count = 0;
$invoices = [];
foreach ($index as $e) {
    if ($e['refdesc']) {
        $parts = explode('-', $e['refdesc'], 3);
        $inv = $parts[1] ?? '?';
        $invoices[] = $inv;
        echo '  ' . $inv . ' | ' . $e['refdesc'] . ' | baris ' . $e['start'] . PHP_EOL;
        $count++;
        if ($count >= 5) break;
    }
}
echo '...' . PHP_EOL;

// Takeout 2 invoice pertama dari case kutip
$takeout = array_slice($invoices, 0, 2);
echo 'Takeout invoice: ' . implode(', ', $takeout) . PHP_EOL;
$found = FakturLineResolver::findByInvoiceNumber($path, $takeout);
echo 'Ditemukan: ' . count($found) . PHP_EOL;
foreach ($found as $f) {
    echo '  - ' . $f['invoice'] . ' => ' . $f['refdesc'] . PHP_EOL;
}
$out = 'tmp/takeout_kutip.xml';
$ok = FakturLineResolver::extractInvoices($path, $found, $out);
echo 'Ekspor: ' . ($ok ? 'BERHASIL' : 'GAGAL') . PHP_EOL;
if ($ok) {
    $xml = simplexml_load_file($out);
    echo 'File hasil: ' . count($xml->ListOfTaxInvoice->TaxInvoice) . ' faktur, valid XML: ' . ($xml ? 'YA' : 'TIDAK') . PHP_EOL;
    @unlink($out);
}
