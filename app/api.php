<?php
// api.php — endpoint JSON untuk GUI XML Faktur Toolkit (v3)
// POST multipart: action=check_lines | match | match_apply | convert
// Master Excel: support .xlsx dan .xls

declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/../src/FakturLineResolver.php';
require_once __DIR__ . '/../src/MasterReader.php';
require_once __DIR__ . '/../src/Matcher.php';
require_once __DIR__ . '/../src/FakturReviser.php';
require_once __DIR__ . '/../src/FakturExporter.php';

define('MAX_UPLOAD_BYTES', 50 * 1024 * 1024);

function logError(string $action, string $message): void {
    $dir = tmpDir();
    $line = date('c') . ' [' . $action . '] ' . $message . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . PHP_EOL;
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

function jsonOut(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void {
    jsonOut(['error' => $message], $code);
}

function tmpDir(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

/** Hapus file di tmp yang lebih tua dari 1 jam (GC). Dipanggil setiap request. */
function gcTmp(): void {
    $dir = tmpDir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        if (basename($f) === 'app.log') {
            continue;
        }
        if (filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

/** Simpan satu file upload (dari array multi-file) ke tmp dan kembalikan path-nya, atau null jika gagal. */
function storeUploadByIndex(array $files, int $index, array $extensions): ?string {
    $name = $files['name'][$index] ?? '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        return null;
    }
    $size = (int) ($files['size'][$index] ?? 0);
    if ($size > MAX_UPLOAD_BYTES) {
        return null;
    }
    $token = bin2hex(random_bytes(8));
    $path = tmpDir() . "/up_$token.$ext";
    if (!move_uploaded_file($files['tmp_name'][$index], $path)) {
        return null;
    }
    return $path;
}

/** Simpan file upload ke tmp dan kembalikan path-nya. */
function storeUpload(string $field, array $extensions): string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        fail("File upload '$field' tidak terkirim atau gagal diunggah.");
    }
    $name = $_FILES[$field]['name'] ?? '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) {
        fail("Tipe file .$ext tidak didukung untuk '$field' (harus: ." . implode('/.', $extensions) . ').');
    }
    $size = (int) ($_FILES[$field]['size'] ?? 0);
    if ($size > MAX_UPLOAD_BYTES) {
        fail('File terlalu besar (maks 50MB).', 413);
    }
    $token = bin2hex(random_bytes(8));
    $path = tmpDir() . "/up_$token.$ext";
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
        fail('Gagal menyimpan file unggahan.');
    }
    return $path;
}

/** Simpan nama download yang bermakna (dari nama file asli) ke samping file hasil; dibaca download.php. */
function saveDownloadName(string $token, string $originalName, string $prefix, string $extension, string $suffix = ''): void {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $base);
    $base = trim((string) $base);
    if ($base === '') {
        $base = 'faktur';
    }
    $base = mb_substr($base, 0, 60);
    file_put_contents(tmpDir() . "/$token.name", $prefix . $base . $suffix . '.' . $extension);
}

/** Peta: RefDesc penuh => hasil match (status matched/both_empty/not_found). Master tidak dibaca ulang di sini. */
function buildMatches(string $xmlPath, array $masterRows): array {
    if (!$masterRows) {
        fail('Excel master kosong atau format tidak dikenali (butuh kolom: Custumer Code, Invoice, NPWP, KTP).');
    }
    return Matcher::matchesFromXml($xmlPath, $masterRows);
}

/** Pastikan file XML adalah e-Faktur bulk yang valid sebelum diproses lebih lanjut. */
function requireValidXml(string $xmlPath): void {
    $check = FakturLineResolver::validateStructure($xmlPath);
    if (!$check['valid']) {
        logError('validate', $check['error'] . ' path=' . $xmlPath);
        fail($check['error'], 422);
    }
}

$action = $_POST['action'] ?? '';
gcTmp();

try {
switch ($action) {

    case 'check_lines': {
        $xmlPath = storeUpload('xml', ['xml']);
        requireValidXml($xmlPath);
        $raw = (string) ($_POST['lines'] ?? '');
        $lineNumbers = [];
        foreach (preg_split('/[\s,;]+/u', $raw) as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $lineNumbers[] = (int) $part;
            }
        }
        if ($lineNumbers) {
            $lineNumbers = array_values(array_unique($lineNumbers));
            $resolved = FakturLineResolver::resolve($xmlPath, $lineNumbers);
        } else {
            // Nomor baris kosong = cek otomatis seluruh XML.
            $resolved = FakturLineResolver::scan($xmlPath);
        }

        // Hitung total invoice untuk indikator progres.
        $index = FakturLineResolver::buildIndex($xmlPath);
        $invoiceCount = count($index);

        // Simpan file agar hasil cek bisa langsung diedit (pakai action=match_apply).
        $token = bin2hex(random_bytes(8));
        if (!rename($xmlPath, tmpDir() . "/$token.xml")) {
            fail('Gagal menyiapkan file untuk diedit.', 500);
        }
        saveDownloadName($token, (string) ($_FILES['xml']['name'] ?? ''), 'revisi-', 'xml');

        $rows = [];
        foreach ($resolved as $r) {
            $rows[] = [
                'line'    => $r['line'],
                'refdesc' => $r['refdesc'],
                'nearTag' => $r['nearTag'],
                'snippet' => $r['snippet'],
                'status'  => $r['status'],
                'issue'   => $r['issue'],
                'tag'     => $r['tag'],
                'value'   => $r['value'],
                'docType' => $r['docType'],
            ];
        }
        jsonOut(['rows' => $rows, 'downloadToken' => $token, 'invoiceCount' => $invoiceCount]);
    }

    case 'batch_check': {
        if (!isset($_FILES['xml']) || !is_array($_FILES['xml']['name'])) {
            fail('Upload minimal satu file XML untuk batch.');
        }
        $files = $_FILES['xml'];
        $fileCount = count($files['name']);
        if ($fileCount === 0) {
            fail('Tidak ada file XML yang diunggah.');
        }

        $rawLines = (string) ($_POST['lines'] ?? '');
        $lineNumbers = [];
        foreach (preg_split('/[\s,;]+/u', $rawLines) as $part) {
            if (ctype_digit($part) && (int) $part > 0) {
                $lineNumbers[] = (int) $part;
            }
        }
        if ($lineNumbers) {
            $lineNumbers = array_values(array_unique($lineNumbers));
        }

        $results = [];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $results[] = [
                    'file'      => $files['name'][$i] ?? 'unknown',
                    'error'     => 'Gagal diunggah (kode error: ' . $files['error'][$i] . ')',
                    'rows'      => [],
                    'token'     => null,
                ];
                continue;
            }

            $xmlPath = storeUploadByIndex($files, $i, ['xml']);
            if ($xmlPath === null) {
                $results[] = [
                    'file'      => $files['name'][$i] ?? 'unknown',
                    'error'     => 'Tipe file tidak didukung atau file terlalu besar (maks 50MB).',
                    'rows'      => [],
                    'token'     => null,
                ];
                continue;
            }
            try {
                requireValidXml($xmlPath);
            } catch (\Throwable $e) {
                $results[] = [
                    'file'      => $files['name'][$i] ?? 'unknown',
                    'error'     => $e->getMessage(),
                    'rows'      => [],
                    'token'     => null,
                ];
                @unlink($xmlPath);
                continue;
            }

            if ($lineNumbers) {
                $resolved = FakturLineResolver::resolve($xmlPath, $lineNumbers);
            } else {
                $resolved = FakturLineResolver::scan($xmlPath);
            }

            $index = FakturLineResolver::buildIndex($xmlPath);
            $invoiceCount = count($index);

            $token = bin2hex(random_bytes(8));
            if (!rename($xmlPath, tmpDir() . "/$token.xml")) {
                $results[] = [
                    'file'      => $files['name'][$i] ?? 'unknown',
                    'error'     => 'Gagal menyiapkan file.',
                    'rows'      => [],
                    'token'     => null,
                ];
                @unlink($xmlPath);
                continue;
            }
            saveDownloadName($token, $files['name'][$i] ?? '', 'revisi-', 'xml');

            $rows = [];
            foreach ($resolved as $r) {
                $rows[] = [
                    'line'    => $r['line'],
                    'refdesc' => $r['refdesc'],
                    'nearTag' => $r['nearTag'],
                    'snippet' => $r['snippet'],
                    'status'  => $r['status'],
                    'issue'   => $r['issue'],
                    'tag'     => $r['tag'],
                    'value'   => $r['value'],
                    'docType' => $r['docType'],
                ];
            }
            $results[] = [
                'file'          => $files['name'][$i] ?? 'unknown',
                'error'         => null,
                'rows'          => $rows,
                'token'         => $token,
                'count'         => count($rows),
                'invoiceCount'  => $invoiceCount,
            ];
        }

        jsonOut(['results' => $results, 'total' => $fileCount]);
    }

    case 'match': {
        $xmlPath = storeUpload('xml', ['xml']);
        requireValidXml($xmlPath);
        $masterPath = storeUpload('master', ['xlsx', 'xls']);

        $masterRows = MasterReader::read($masterPath);
        if (!$masterRows) {
            fail('Excel master tidak dikenali. Gunakan format Sample NIK.xlsx (atau .xls) '
                . '(harus ada kolom: Custumer Code dan Invoice).');
        }
        $matches = buildMatches($xmlPath, $masterRows);

        $token = bin2hex(random_bytes(8));
        $outPath = tmpDir() . "/$token.xml";
        saveDownloadName($token, (string) ($_FILES['xml']['name'] ?? ''), 'revisi-', 'xml');
        $stats = FakturReviser::apply($xmlPath, $matches, $outPath);

        $rows = [];
        $counts = ['matched' => 0, 'both_empty' => 0, 'not_found' => 0];
        $invalidFormat = 0;
        foreach ($matches as $m) {
            $counts[$m['status']] = ($counts[$m['status']] ?? 0) + 1;
            $formatOk = $m['status'] === 'matched'
                ? Matcher::validNumber((string) $m['docType'], (string) $m['number'])
                : null;
            if ($formatOk === false) {
                $invalidFormat++;
            }
            $rows[] = [
                'refdesc'  => $m['refdesc'],
                'name'     => $m['name'],
                'docType'  => $m['docType'],
                'number'   => $m['number'],
                'status'   => $m['status'],
                'formatOk' => $formatOk,
            ];
        }

        $warning = null;
        $totalFaktur = count($rows);
        if ($totalFaktur > 0 && $counts['not_found'] / $totalFaktur >= 0.8) {
            $warning = 'Mayoritas faktur tidak ketemu di master ('
                . $counts['not_found'] . ' dari ' . $totalFaktur . '). '
                . 'Master yang terbaca hanya ' . count($masterRows) . ' baris data, '
                . 'kemungkinan file master salah atau belum berisi semua customer.';
        }

        unlink($xmlPath);
        unlink($masterPath);

        jsonOut([
            'rows' => $rows,
            'stats' => [
                'total'      => count($rows),
                'updated'    => $stats['updated'],
                'untouched'  => $stats['untouched'],
                'sanitized'  => $stats['sanitized'],
                'invalidLeft' => $stats['invalidLeft'],
                'matched'    => $counts['matched'],
                'both_empty' => $counts['both_empty'],
                'not_found'  => $counts['not_found'],
                'invalidFormat' => $invalidFormat,
            ],
            'masterRows' => count($masterRows),
            'warning' => $warning,
            'downloadToken' => $token,
        ]);
    }

    case 'match_apply': {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            fail('Token tidak valid.');
        }
        $file = tmpDir() . "/$token.xml";
        if (!is_file($file)) {
            fail('File revisi tidak ditemukan. Ulangi pencocokan (unggah XML + master lagi).', 404);
        }
        if (time() - (int) filemtime($file) > 3600) {
            @unlink($file);
            @unlink(tmpDir() . "/$token.name");
            fail('Sesi edit kedaluwarsa (lewat 1 jam). Unduh draft editan bila perlu, lalu ulangi pencocokan.', 410);
        }

        requireValidXml($file);

        $items = json_decode((string) ($_POST['rows'] ?? ''), true);
        if (!is_array($items) || !$items) {
            fail('Tidak ada baris untuk disimpan.');
        }

        $overrides = [];
        foreach ($items as $it) {
            $ref = trim((string) ($it['refdesc'] ?? ''));
            $doc = (string) ($it['docType'] ?? '');
            $num = trim((string) ($it['number'] ?? ''));
            if ($ref === '') {
                continue;
            }
            if (!in_array($doc, ['NPWP', 'National ID'], true)) {
                fail("Dokumen tidak dikenal untuk $ref.");
            }
            if (!Matcher::validNumber($doc, $num)) {
                fail("Nomor tidak valid untuk $ref ($doc).");
            }
            $overrides[$ref] = [
                'refdesc' => $ref,
                'status'  => 'matched',
                'docType' => $doc,
                'number'  => Matcher::normalizeNumber($num),
                'name'    => null,
            ];
        }
        if (!$overrides) {
            fail('Tidak ada baris untuk disimpan.');
        }

        $outPath = tmpDir() . "/$token.new.xml";
        try {
            $stats = FakturReviser::apply($file, $overrides, $outPath);
        } catch (Throwable $e) {
            @unlink($outPath);
            fail('Gagal menerapkan revisi: ' . $e->getMessage(), 500);
        }

        // Cadangkan file lama sebelum ditimpa (untuk undo/restore).
        $bak = tmpDir() . "/$token.bak.xml";
        if (is_file($file)) {
            @copy($file, $bak);
        }

        if (!rename($outPath, $file)) {
            fail('Gagal menyimpan file revisi.', 500);
        }

        jsonOut(['stats' => $stats, 'downloadToken' => $token]);
    }

    case 'match_backup': {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            fail('Token tidak valid.');
        }
        $file = tmpDir() . "/$token.xml";
        $bak = tmpDir() . "/$token.bak.xml";
        if (!is_file($file)) {
            fail('File revisi tidak ditemukan.', 404);
        }
        if (!copy($file, $bak)) {
            fail('Gagal membuat cadangan file.', 500);
        }
        jsonOut(['backup' => $bak, 'token' => $token]);
    }

    case 'match_restore': {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            fail('Token tidak valid.');
        }
        $file = tmpDir() . "/$token.xml";
        $bak = tmpDir() . "/$token.bak.xml";
        if (!is_file($bak)) {
            fail('Tidak ada berkas cadangan untuk dipulihkan.', 404);
        }
        if (!rename($bak, $file)) {
            fail('Gagal memulihkan berkas cadangan.', 500);
        }
        jsonOut(['restored' => true, 'token' => $token]);
    }

    case 'convert': {
        $xmlPath = storeUpload('xml', ['xml']);
        requireValidXml($xmlPath);
        $token = bin2hex(random_bytes(8));
        $outPath = tmpDir() . "/$token.xlsx";
        saveDownloadName($token, (string) ($_FILES['xml']['name'] ?? ''), 'rekap-', 'xlsx');
        try {
            $stats = FakturExporter::export($xmlPath, $outPath);
        } catch (Throwable $e) {
            fail('Konversi gagal: ' . $e->getMessage(), 500);
        }
        unlink($xmlPath);
        jsonOut(['stats' => $stats, 'downloadToken' => $token]);
    }

    case 'takeout_invoice': {
        $xmlPath = storeUpload('xml', ['xml']);
        requireValidXml($xmlPath);
        $raw = (string) ($_POST['invoices'] ?? '');
        $invoiceNumbers = [];
        foreach (preg_split('/[\\s,;]+/u', $raw) as $part) {
            if ($part !== '') {
                $invoiceNumbers[] = $part;
            }
        }
        if (!$invoiceNumbers) {
            fail('Daftar nomor invoice tidak boleh kosong (pisahkan dengan spasi, koma, atau titik koma).');
        }
        $found = FakturLineResolver::findByInvoiceNumber($xmlPath, $invoiceNumbers);
        $index = FakturLineResolver::buildIndex($xmlPath);
        $invoiceCount = count($index);
        $foundInvoices = array_column($found, 'invoice');
        $notFound = array_values(array_diff($invoiceNumbers, $foundInvoices));
        $token = bin2hex(random_bytes(8));
        if (!rename($xmlPath, tmpDir() . "/$token.xml")) {
            fail('Gagal menyiapkan file.', 500);
        }
        $tanggal = date('Y-m-d');
        saveDownloadName($token, (string) ($_FILES['xml']['name'] ?? ''), 'takeout-', 'xml', "-{$tanggal}");

        jsonOut([
            'found' => $found,
            'notFound' => $notFound,
            'requested' => count($invoiceNumbers),
            'foundCount' => count($found),
            'invoiceCount' => $invoiceCount,
            'downloadToken' => $token,
        ]);
    }

    case 'takeout_export': {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            fail('Token tidak valid.');
        }
        $file = tmpDir() . "/$token.xml";
        if (!is_file($file)) {
            fail('File XML tidak ditemukan. Lakukan pencarian takeout dulu.', 404);
        }

        $raw = (string) ($_POST['invoices'] ?? '');
        $invoiceNumbers = [];
        foreach (preg_split('/[\\s,;]+/u', $raw) as $part) {
            if ($part !== '') {
                $invoiceNumbers[] = $part;
            }
        }
        if (!$invoiceNumbers) {
            fail('Daftar nomor invoice tidak boleh kosong.');
        }

        $found = FakturLineResolver::findByInvoiceNumber($file, $invoiceNumbers);
        if (!$found) {
            fail('Tidak ada invoice yang ditemukan untuk diekspor.');
        }

        $outPath = tmpDir() . "/$token.takeout.xml";
        if (!FakturLineResolver::extractInvoices($file, $found, $outPath)) {
            fail('Gagal membuat XML takeout.', 500);
        }

        $downloadToken = bin2hex(random_bytes(8));
        if (!rename($outPath, tmpDir() . "/$downloadToken.xml")) {
            fail('Gagal menyimpan file takeout.', 500);
        }
        $tanggal = date('Y-m-d');
        saveDownloadName($downloadToken, (string) ($_FILES['xml']['name'] ?? 'faktur'), 'takeout-', 'xml', "-{$tanggal}");

        jsonOut([
            'downloadToken' => $downloadToken,
            'exported' => count($found),
        ]);
    }

    case 'batch_apply': {
        $items = json_decode((string) ($_POST['items'] ?? ''), true);
        if (!is_array($items) || !$items) {
            fail('Tidak ada item untuk disimpan.');
        }
        $results = [];
        foreach ($items as $it) {
            $token = (string) ($it['token'] ?? '');
            $rows  = $it['rows'] ?? [];
            if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
                $results[] = ['token' => $token, 'error' => 'Token tidak valid.'];
                continue;
            }
            $file = tmpDir() . "/$token.xml";
            if (!is_file($file)) {
                $results[] = ['token' => $token, 'error' => 'File revisi tidak ditemukan. Ulangi pengecekan.'];
                continue;
            }
            if (time() - (int) filemtime($file) > 3600) {
                $results[] = ['token' => $token, 'error' => 'Sesi edit kedaluwarsa (lebih dari 1 jam).'];
                continue;
            }
            // Inline validasi (tidak pakai requireValidXml agar satu file gagal tidak membatalk batch).
            $check = FakturLineResolver::validateStructure($file);
            if (!$check['valid']) {
                $results[] = ['token' => $token, 'error' => $check['error']];
                continue;
            }
            if (!is_array($rows) || !$rows) {
                $results[] = ['token' => $token, 'error' => 'Tidak ada baris untuk token ini.'];
                continue;
            }
            $overrides = [];
            $rowError  = null;
            foreach ($rows as $r) {
                $ref = trim((string) ($r['refdesc'] ?? ''));
                $doc = (string) ($r['docType'] ?? '');
                $num = trim((string) ($r['number'] ?? ''));
                if ($ref === '') {
                    continue;
                }
                if (!in_array($doc, ['NPWP', 'National ID'], true)) {
                    $rowError = "Dokumen tidak dikenal untuk $ref.";
                    break;
                }
                if (!Matcher::validNumber($doc, $num)) {
                    $rowError = "Nomor tidak valid untuk $ref ($doc).";
                    break;
                }
                $overrides[$ref] = [
                    'refdesc' => $ref,
                    'status'  => 'matched',
                    'docType' => $doc,
                    'number'  => Matcher::normalizeNumber($num),
                    'name'    => null,
                ];
            }
            if ($rowError !== null) {
                $results[] = ['token' => $token, 'error' => $rowError];
                continue;
            }
            if (!$overrides) {
                $results[] = ['token' => $token, 'error' => 'Tidak ada baris valid untuk token ini.'];
                continue;
            }
            $outPath = tmpDir() . "/$token.new.xml";
            try {
                $stats = FakturReviser::apply($file, $overrides, $outPath);
            } catch (Throwable $e) {
                @unlink($outPath);
                $results[] = ['token' => $token, 'error' => 'Gagal menerapkan revisi: ' . $e->getMessage()];
                continue;
            }
            // Cadangkan file lama sebelum ditimpa (untuk undo/restore).
            $bak = tmpDir() . "/$token.bak.xml";
            if (is_file($file)) {
                @copy($file, $bak);
            }
            if (!rename($outPath, $file)) {
                $results[] = ['token' => $token, 'error' => 'Gagal menyimpan file revisi.'];
                continue;
            }
            $results[] = ['token' => $token, 'stats' => $stats, 'downloadToken' => $token];
        }
        jsonOut(['results' => $results]);
    }

    case 'batch_convert': {
        if (!isset($_FILES['xml']) || !is_array($_FILES['xml']['name'])) {
            fail('Upload minimal satu file XML untuk batch konversi.');
        }
        $files = $_FILES['xml'];
        $fileCount = count($files['name']);
        if ($fileCount === 0) {
            fail('Tidak ada file XML yang diunggah.');
        }
        $results = [];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $results[] = ['file' => $files['name'][$i] ?? 'unknown', 'error' => 'Gagal diunggah (kode error: ' . $files['error'][$i] . ')'];
                continue;
            }
            $xmlPath = storeUploadByIndex($files, $i, ['xml']);
            if ($xmlPath === null) {
                $results[] = ['file' => $files['name'][$i] ?? 'unknown', 'error' => 'Tipe file tidak didukung atau file terlalu besar (maks 50MB).'];
                continue;
            }
            $check = FakturLineResolver::validateStructure($xmlPath);
            if (!$check['valid']) {
                $results[] = ['file' => $files['name'][$i] ?? 'unknown', 'error' => $check['error']];
                @unlink($xmlPath);
                continue;
            }
            $token = bin2hex(random_bytes(8));
            $outPath = tmpDir() . "/$token.xlsx";
            saveDownloadName($token, $files['name'][$i] ?? '', 'rekap-', 'xlsx');
            try {
                $stats = FakturExporter::export($xmlPath, $outPath);
            } catch (Throwable $e) {
                $results[] = ['file' => $files['name'][$i] ?? 'unknown', 'error' => 'Konversi gagal: ' . $e->getMessage()];
                @unlink($xmlPath);
                continue;
            }
            @unlink($xmlPath);
            $results[] = ['file' => $files['name'][$i] ?? 'unknown', 'token' => $token, 'stats' => $stats];
        }
        jsonOut(['results' => $results, 'total' => $fileCount]);
    }

    default:
        fail('Action tidak dikenal: ' . htmlspecialchars($action));
}

} catch (Throwable $e) {
    logError((string) $action, $e->getMessage());
    fail('Terjadi kesalahan server: ' . $e->getMessage(), 500);
}
