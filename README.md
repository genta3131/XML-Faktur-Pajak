# XML Faktur Toolkit v3.4

Aplikasi PHP portable (tanpa database) untuk memproses e-Faktur XML.  
Dijalankan lewat PHP built-in server di port 8080.

## Quick Start

1. Salin seluruh folder `XML-V3.4` (termasuk `php/`, `vendor/`, `lib/`).
2. Jalankan `START.bat` — atau `START (portable).bat` untuk tampil sebagai jendela aplikasi.
3. Buka `http://localhost:8080`.

Tidak ada instalasi, tidak ada migrasi data. `START.bat` mencari PHP berurutan: `php\php.exe` → `..\php\php.exe` → Laragon → `PATH`. Karena `php/` ikut dibundel, mesin target tidak perlu memasang PHP.

**Kebutuhan minimum:** PHP 8.0+ (diuji 8.3.36) dengan ekstensi `mbstring`, `zip`, `fileinfo`, `openssl`. Di Windows butuh `VCRUNTIME140.dll` (sudah ada di Windows 10/11).

## Struktur Folder

```
XML-V3.4/
├─ app/
│  ├─ index.php          # UI utama
│  ├─ api.php            # Endpoint JSON
│  ├─ download.php       # Unduh hasil (GC >1 jam)
│  ├─ history.php        # Riwayat file hasil
│  ├─ assets/            # app.js, escape.js, style.css, icon.svg
│  └─ tmp/               # File sementara (auto-dibuat saat runtime)
├─ src/
│  ├─ FakturLineResolver.php   # Pemetaan baris → invoice + validasi
│  ├─ FakturReviser.php        # Terapkan match/override ke XML
│  ├─ MasterReader.php         # Baca master Excel .xlsx/.xls
│  ├─ Matcher.php              # Cocokkan RefDesc + validasi nomor
│  └─ FakturExporter.php       # XML → Excel (2 sheet)
├─ tests/                # PHPUnit + fixtures + csv.test.js
├─ lib/                  # SimpleXLSX / SimpleXLSXGen (MIT)
├─ vendor/               # Dependensi Composer
├─ php/                  # Runtime PHP 8.3.36 portable
├─ docs/flowchart/       # Diagram alur
├─ xml case/             # Contoh berkas kasus
├─ Dockerfile            # Image PHP-CLI untuk CI
├─ START.bat / START (portable).bat
└─ composer.json / composer.lock / phpunit.xml.dist / php.ini
```

## Perubahan v3.4

Ringkasan yang sudah terpasang (detail: [`CHANGELOG.md`](CHANGELOG.md) bagian `[v3.4]`):

| Area | v3.3 | v3.4 |
|------|------|------|
| Simpan batch Tab 1 | Edit tampil, tombol simpan tidak berfungsi | Aksi `batch_apply`, simpan per berkas |
| Cek otomatis | Hanya `BuyerDocumentNumber` | Tambah tag wajib hilang & `RefDesc` dobel |
| Undo / draft | Tidak ada | Tombol "Pulihkan Cadangan" + draft `localStorage` |
| Konversi | 1 file per operasi | `batch_convert` multi-XML |
| Baca master | Dibaca dua kali di aksi `match` | Sekali baca (`Matcher::matchesFromXml`) |
| File besar | `file()` full-load, memori O(N) | Line-reader streaming, memori O(1) |
| GC `app/tmp/` | Scan penuh tiap request | Di-throttle, maks sekali per 60 detik |
| Riwayat | Halaman dasar | Ikut design system + dark mode |
| Test | Line number hardcoded | 48 test PHPUnit + fixtures |

Belum dikerjakan (masih "akan diisi" di changelog): relokasi `tmp` ke `var/tmp/`, bind `127.0.0.1`, penutupan akses `/tmp/*`. Saat ini `app/tmp/` masih di dalam docroot dan server bind `0.0.0.0`.

## Aksi API (`app/api.php`)

| Action | Deskripsi |
|--------|-----------|
| `check_lines` | Cek baris bermasalah satu XML |
| `batch_check` | Cek beberapa XML sekaligus |
| `match` | Cocokkan KTP/NPWP dengan master, buat revisi |
| `match_apply` | Simpan edit revisi + buat `.bak.xml` |
| `match_backup` | Cadangkan file revisi |
| `match_restore` | Pulihkan file revisi dari cadangan |
| `convert` | Konversi XML → Excel |
| `batch_apply` | Simpan perbaikan batch per berkas |
| `batch_convert` | Konversi multi-XML ke Excel |
| `takeout_invoice` | Ambil data invoice |
| `takeout_export` | Ekspor CSV baris bermasalah |

## Pengembangan

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit --configuration phpunit.xml.dist
```

Hasil saat ini: **48 test, 156 assertion, 1 skipped**.

CI (`.github/workflows/php.yml`): `composer install` → `php -l` → PHPUnit, pada push/PR ke `main`/`master`. `Dockerfile` (PHP 8.3-cli) tersedia untuk build serupa secara lokal.

## Catatan Keamanan

Dirancang untuk pemakaian lokal. Login, CSRF, auth, dan rate limiting **tidak** diimplementasikan.

- `app/tmp/` berisi upload, master (PII), dan hasil — masih dapat diakses lewat URL `/tmp/*` karena berada di dalam docroot.
- Server bind `0.0.0.0:8080` → terjangkau dari LAN.
- File hasil dihapus otomatis setelah 1 jam.

## Lisensi

