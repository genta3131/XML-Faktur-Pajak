<?php
require_once 'src/FakturLineResolver.php';

$path = 'xml case/case takeout/faktur 301026.xml';
$index = FakturLineResolver::buildIndex($path);
echo 'Total faktur di faktur 301026.xml: ' . count($index) . PHP_EOL;

// Cari invoice 111212994 (dari contoh readme case takeout)
$target = '111212994';
$found = FakturLineResolver::findByInvoiceNumber($path, [$target]);
echo PHP_EOL . 'Mencari invoice ' . $target . ':' . PHP_EOL;
if ($found) {
    foreach ($found as $f) {
        echo '  DITEMUKAN => ' . $f['refdesc'] . ' | baris ' . $f['start'] . PHP_EOL;
    }
} else {
    echo '  TIDAK ada di faktur 301026.xml' . PHP_EOL;
}

// Coba cari secara partial / cocokkan refdesc yang mengandung 111212994
echo PHP_EOL . 'RefDesc yang mengandung "111212994":' . PHP_EOL;
$ketemu = false;
foreach ($index as $e) {
    if ($e['refdesc'] && strpos($e['refdesc'], '111212994') !== false) {
        echo '  ' . $e['refdesc'] . ' | baris ' . $e['start'] . PHP_EOL;
        $ketemu = true;
    }
}
if (!$ketemu) {
    echo '  (tidak ada refdesc yang mengandung angka ini)' . PHP_EOL;
}

// Tampilkan semua invoice yang diawali 111212 (agar mudah dibandingkan)
echo PHP_EOL . 'Invoice yang dimulai 111212 (sample):' . PHP_EOL;
$n = 0;
foreach ($index as $e) {
    if ($e['refdesc']) {
        $p = explode('-', $e['refdesc'], 3);
        $inv = $p[1] ?? '?';
        if (strpos($inv, '111212') === 0) {
            echo '  ' . $inv . ' | ' . $e['refdesc'] . PHP_EOL;
            $n++;
            if ($n >= 10) break;
        }
    }
}
if ($n === 0) {
    echo '  (tidak ada)' . PHP_EOL;
}
