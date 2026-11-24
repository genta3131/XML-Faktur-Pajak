<?php
// history.php — daftar file hasil yang masih tersedia di app/tmp untuk diunduh.
declare(strict_types=1);
ini_set('display_errors', '0');

$dir = __DIR__ . '/tmp';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

// GC: hapus file lebih tua dari 1 jam
foreach (glob($dir . '/*') ?: [] as $f) {
    if (is_file($f) && filemtime($f) < time() - 3600) {
        @unlink($f);
    }
}

// Kumpulkan file hasil (hasil akhir, bukan file upload up_* atau .name/.bak)
$files = [];
foreach (glob($dir . '/*') ?: [] as $f) {
    if (!is_file($f)) continue;
    $name = basename($f);
    if (preg_match('/^up_/', $name)) continue;          // file upload sementara
    if (preg_match('/\.name$/', $name)) continue;       // metadata nama
    if (preg_match('/\.bak\.xml$/', $name)) continue;   // cadangan revisi

    $token = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $mtime = filemtime($f);
    $age = time() - $mtime;
    $expiry = max(0, 3600 - $age);

    // Nama asli dari .name file
    $niceNameFile = $dir . '/' . $token . '.name';
    $niceName = null;
    if (is_file($niceNameFile)) {
        $raw = trim((string) file_get_contents($niceNameFile));
        if ($raw !== '' && strlen($raw) <= 255) {
            $niceName = $raw;
        }
    }

    $files[] = [
        'token' => $token,
        'ext' => $ext,
        'path' => $f,
        'size' => filesize($f),
        'mtime' => $mtime,
        'age' => $age,
        'expiry' => $expiry,
        'niceName' => $niceName,
    ];
}

// Urutkan: terbaru dulu
usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

function formatAge(int $seconds): string {
    if ($seconds < 60) return $seconds . ' detik lalu';
    if ($seconds < 3600) return floor($seconds / 60) . ' menit lalu';
    return floor($seconds / 3600) . ' jam lalu';
}

function formatSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
}

function formatExpiry(int $seconds): string {
    if ($seconds <= 0) return 'Sudah kedaluwarsa';
    if ($seconds < 60) return $seconds . ' detik lagi';
    if ($seconds < 3600) return floor($seconds / 60) . ' menit lagi';
    return floor($seconds / 3600) . ' jam lagi';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Riwayat File — XML Faktur Toolkit</title>
<link rel="stylesheet" href="assets/style.css?v=8">
<style>
    body { padding: 20px; max-width: 900px; margin: 0 auto; font-family: system-ui, -apple-system, sans-serif; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f5f5f5; }
    .expired { color: #b00020; }
    .valid { color: #006400; }
    .btn { display: inline-block; padding: 6px 12px; background: #007bff; color: #fff; text-decoration: none; border-radius: 4px; }
    .btn:hover { background: #0056b3; }
    .back { margin-bottom: 16px; }
    .empty { padding: 24px; text-align: center; color: #666; }
</style>
</head>
<body>
    <h2>Riwayat File Hasil Proses</h2>
    <p>File di bawah ini masih tersedia untuk diunduh dan akan dihapus otomatis setelah 1 jam sejak terakhir diproses.</p>
    <a class="btn back" href="index.php">← Kembali ke Aplikasi</a>

    <?php if (empty($files)): ?>
        <div class="empty">Belum ada file hasil proses yang tersedia.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Token</th>
                    <th>Nama Asli</th>
                    <th>Tipe</th>
                    <th>Ukuran</th>
                    <th>Waktu Proses</th>
                    <th>Kadaluarsa</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $f): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($f['token']) ?></td>
                    <td><?= htmlspecialchars($f['niceName'] ?? $f['token'] . '.' . $f['ext']) ?></td>
                    <td><?= strtoupper($f['ext']) ?></td>
                    <td><?= formatSize((int) $f['size']) ?></td>
                    <td><?= formatAge((int) $f['age']) ?></td>
                    <td class="<?= $f['expiry'] <= 0 ? 'expired' : 'valid' ?>">
                        <?= formatExpiry((int) $f['expiry']) ?>
                    </td>
                    <td>
                        <a class="btn" href="download.php?token=<?= urlencode($f['token']) ?>" download>Unduh</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
