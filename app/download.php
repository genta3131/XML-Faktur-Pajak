<?php
// download.php — kirim file hasil (token) dari app/tmp; GC file > 1 jam.

declare(strict_types=1);
ini_set('display_errors', '0');

$token = $_GET['token'] ?? '';
if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $token)) {
    http_response_code(400);
    exit('Token tidak valid.');
}

$dir = __DIR__ . '/tmp';

// GC: hapus file sementara lebih tua dari 1 jam
if (is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

$xmlPath = "$dir/$token.xml";
$xlsxPath = "$dir/$token.xlsx";

/** Nama download: dari <token>.name (nama asli, dibersihkan api.php) atau fallback token. */
function dispositionHeader(?string $niceName, string $defaultName): string {
    $name = ($niceName !== null && $niceName !== '') ? $niceName : $defaultName;
    $name = preg_replace('/["\r\n]+/', '', $name) ?: 'file';
    $ascii = preg_replace('/[^A-Za-z0-9._\-() ]+/', '_', $name);
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

$niceName = null;
$nameFile = "$dir/$token.name";
if (is_file($nameFile)) {
    $raw = trim((string) file_get_contents($nameFile));
    if ($raw !== '' && strlen($raw) <= 255) {
        $niceName = $raw;
    }
}

if (is_file($xmlPath)) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: ' . dispositionHeader($niceName, 'faktur-revisi-' . $token . '.xml'));
    header('Content-Length: ' . filesize($xmlPath));
    readfile($xmlPath);
    exit;
}

if (is_file($xlsxPath)) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: ' . dispositionHeader($niceName, 'faktur-' . $token . '.xlsx'));
    header('Content-Length: ' . filesize($xlsxPath));
    readfile($xlsxPath);
    exit;
}

http_response_code(404);
exit('File tidak ditemukan (mungkin sudah lewat 1 jam). Ulangi prosesnya.');
