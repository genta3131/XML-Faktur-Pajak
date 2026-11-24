# Flowchart — XML Faktur Toolkit (v3)

File: `flowchart.mmd` (diagram Mermaid). Render dengan Mermaid Live Editor
(mermaid.live) atau ekstensi Mermaid di VS Code.

## Ringkasan alur

Aplikasi adalah GUI PHP tunggal (`app/index.php`) dengan 3 tab, semua diproses
oleh `app/api.php` dan hasil unduhan melalui `app/download.php`:

1. **Cek Baris Bermasalah** — XML + nomor baris error →
   `FakturLineResolver` mencari invoice/RefDesc yang mengandung baris itu →
   ditampilkan dalam tabel.
2. **Cocokkan KTP / NPWP** — XML + Excel master customer → `Matcher` mencocokkan
   `CardCode|Invoice` dari RefDesc → menentukan dokumen (NPWP / National ID /
   kosong) → `FakturReviser` menulis ulang XML → unduh file revisi.
3. **XML → Excel** — XML → `FakturExporter` buat XLSX 2 sheet
   (Ringkasan + Detail Barang) → unduh.

File hasil disimpan sementara di `app/tmp` dan dibersihkan otomatis setelah
±1 jam oleh `download.php`.

## Struktur folder terkait

```
app/        index.php, api.php, download.php, assets/
src/        FakturExporter, FakturLineResolver, FakturReviser, MasterReader, Matcher
lib/        SimpleXLSX, SimpleXLSXGen
php/        PHP 8.3 portable (runtime)
docs/       dokumentasi & diagram (folder ini)
tests/      (kosong)
```
