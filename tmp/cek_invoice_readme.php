<?php
require_once 'src/FakturLineResolver.php';

$path = 'xml case/case takeout/faktur 301026.xml';
$index = FakturLineResolver::buildIndex($path);
echo 'Total faktur di faktur 301026.xml: ' . count($index) . PHP_EOL;

// Nomor invoice dari readme "case minta di takeout by invoice"
$targets = ['111212159', '111212246'];
echo PHP_EOL . 'Nomor dari readme "case minta di takeout by invoice":' . PHP_EOL;
$found = FakturLineResolver::findByInvoiceNumber($path, $targets);
$foundInvoices = array_column($found, 'invoice');
foreach ($targets as $t) {
    if (in_array($t, $foundInvoices, true)) {
        foreach ($found as $f) {
            if ($f['invoice'] === $t) {
                echo '  ' . $t . ' DITEMUKAN => ' . $f['refdesc'] . PHP_EOL;
            }
        }
    } else {
        echo '  ' . $t . ' TIDAK ada di faktur 301026.xml' . PHP_EOL;
    }
}

echo PHP_EOL . 'Beberapa invoice yang SEBENARNYA ada di faktur 301026.xml:' . PHP_EOL;
$n = 0;
foreach ($index as $e) {
    if ($e['refdesc']) {
        $p = explode('-', $e['refdesc'], 3);
        $inv = $p[1] ?? '?';
        echo '  ' . $inv . ' | ' . $e['refdesc'] . PHP_EOL;
        $n++;
        if ($n >= 8) break;
    }
}
