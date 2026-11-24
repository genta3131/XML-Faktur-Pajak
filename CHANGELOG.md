# Changelog

Semua perubahan penting XML Faktur Toolkit didokumentasikan di sini.
Format mengikuti [Keep a Changelog](https://keepachangelog.com/id/1.1.0/).

## [v3.4] — dalam pengembangan

Baseline: salinan `XML-V3.3` (lean copy tanpa `php/` runtime — memakai fallback `..\php\php.exe`).

### Added
- Cek otomatis tambahan di `FakturLineResolver::scan()`: **tag wajib hilang** (default konservatif: TaxInvoiceDate, RefDesc, BuyerDocument, BuyerDocumentNumber, BuyerName — konstanta `MANDATORY_TAGS` dapat disesuaikan) & **RefDesc dobel** (muncul >1 kali). Fixture baru `fixture-missing-tag.xml` & `fixture-dup-refdesc.xml`. Tampil sebagai pill merah di Tab 1; tidak editable (bukan nomor dokumen).
- Tombol "Pulihkan Cadangan" di Tab 1 — single-file (1 token) & batch (loop semua token unik di hasil). Terverifikasi: siklus apply → restore mengembalikan `restored:true`.
- Aksi `batch_convert` — konversi multi-XML ke Excel sekaligus (Tab 3 input `multiple`). Tiap file → token xlsx + link unduh sendiri. Terverifikasi: 2 file → 2 token.
- Draft recovery Tab 1 (`localStorage` key `check-draft`, anti-hilang saat crash/tutup tab). Menyimpan editan belum tersimpan; tawarkan pulihkan saat reload jika <1 jam. `beforeunload` kini jaga editan Tab 1 & Tab 2.
- (akan diisi) Halaman riwayat ikut design system + dark mode.

### Changed
- (akan diisi) `tmp` direlokasi ke `var/tmp/` di luar docroot `app/`.
- (akan diisi) `START*.bat` bind `127.0.0.1` (sebelumnya `0.0.0.0`).
- `FakturLineResolver` line-reader streaming (memori O(1)): `readLines` jadi single-pass `fgets` (kumpulkan hanya baris diminta), `lineAt` pakai `SplFileObject::seek`. Dihapus `file()` full-load + static cache. Perilaku identik (tes karakterisasi mengunci field resolve).
- (akan diisi) GC tmp di-throttle (maks sekali per 60 detik).

### Fixed
- Mode batch Tab 1 kini bisa menyimpan perbaikan per berkas via aksi baru `batch_apply` (sebelumnya edit muncul tapi "Simpan perbaikan" diam-diam mati). Edit-tracking diubah ke identitas per-baris (`_id`+`_token`) karena nomor baris bisa sama antar file. Satu file gagal tidak membatalk yang lain. Terverifikasi lewat server sungguhan (updated=1).
- Master Excel tidak lagi dibaca ganda di aksi `match` — logika match diekstrak ke `Matcher::matchesFromXml($xmlPath, $masterRows)` yang menerima baris master (teruji unit).
- `validateStructure` memulihkan state `libxml_use_internal_errors` (sebelumnya membocor `true` ke seluruh request). Sekaligus membuat `FakturReviser::apply` & `FakturExporter::export` mandiri mengelola state libxml — menghilangkan 6 PHP warning tersembunyi di suite.

### Security
- (akan diisi) File `tmp` (upload, master PII, hasil) tidak lagi ter-serve langsung via URL `/tmp/*`.
- (akan diisi) Server lokal hanya terjangkau dari mesin sendiri (`127.0.0.1`), bukan seluruh LAN.

## [v3.3] — 2026-11

- Validasi struktur e-Faktur (`validateStructure`): root `<TaxInvoiceBulk>`, `<ListOfTaxInvoice>`, minimal 1 `<TaxInvoice>`, pesan error jelas (422).
- Deteksi blok `<TaxInvoice>` disatukan ke `buildIndexFromFile` (hapus duplikasi regex).
- Backup/undo revisi: `match_backup` + `match_restore` (file `.bak.xml`).
- Export CSV baris bermasalah (Tab 1) dengan `csvCell()` anti formula-injection.
- Multi-file batch (`batch_check`) + indikator progres (`invoiceCount`).
- Halaman riwayat (`history.php`).
- Suite PHPUnit otomatis (35 test, 101 assertion) dengan fixture; `composer.lock` dikunci; `Dockerfile` + CI GitHub Actions.

## [v3.2] — rilis stabil cadangan

- Rilis dasar sebelum perbaikan v3.3. Tetap utuh sebagai cadangan.
