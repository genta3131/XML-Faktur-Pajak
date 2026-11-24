<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Alat revisi faktur pajak: cek baris bermasalah, cocokkan KTP/NPWP, konversi XML ke Excel.">
<meta name="theme-color" content="#f6f7f6">
<title>XML Faktur Toolkit</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=8">
<script>
(function(){try{var t=localStorage.getItem('theme');if(!t){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.dataset.theme=t;}catch(e){}});
</script>
</head>
<body>
<header>
    <div class="head-text">
        <h1>XML Faktur Toolkit</h1>
        <p class="sub">Alat revisi faktur pajak: pilih menu, pilih file, klik proses.</p>
    </div>
    <button class="theme-toggle" id="theme-toggle" type="button" aria-pressed="false" aria-label="Aktifkan mode gelap">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
</header>

<nav class="tabs" role="tablist" aria-label="Menu alat">
    <button class="tab active" data-tab="check" role="tab" aria-selected="true" aria-controls="tab-check" id="tab-check-btn">Cek baris bermasalah</button>
    <button class="tab" data-tab="match" role="tab" aria-selected="false" aria-controls="tab-match" id="tab-match-btn">Cocokkan KTP / NPWP</button>
    <button class="tab" data-tab="convert" role="tab" aria-selected="false" aria-controls="tab-convert" id="tab-convert-btn">XML ke Excel</button>
    <button class="tab" data-tab="takeout" role="tab" aria-selected="false" aria-controls="tab-takeout" id="tab-takeout-btn">Takeout by Invoice</button>
</nav>

<main>

<!-- ============ TAB 1: CEK BARIS ============ -->
<section id="tab-check" class="panel active" role="tabpanel" aria-labelledby="tab-check-btn">
    <div class="card">
        <h2>Cek baris bermasalah</h2>
        <p>Masukkan file XML faktur. Biarkan kolom nomor baris kosong untuk <strong>cek otomatis</strong> seluruh faktur, atau isi nomor baris dari error report e-Faktur. Aplikasi akan menunjukkan <strong>RefDesc</strong> dan baris yang bermasalah.</p>

        <label class="file-field">
            <span>File XML faktur</span>
            <input type="file" id="check-xml" accept=".xml" multiple>
            <small class="file-info" id="check-xml-info"></small>
        </label>

        <label for="check-lines">Nomor baris bermasalah (opsional — kosongkan untuk cek otomatis)</label>
        <textarea id="check-lines" rows="5" placeholder="Kosongkan untuk cek semua baris otomatis&#10;atau isi nomor baris: 12, 6024, 700"></textarea>

        <button class="btn" id="check-run">Proses</button>
        <span class="spinner hidden" id="check-spin">Memproses…</span>
        <div class="error hidden" id="check-error" role="alert" aria-live="polite"></div>
    </div>

    <div class="card hidden" id="check-result">
        <h2>Hasil</h2>
        <div class="table-tools">
            <input type="search" id="check-search" placeholder="Cari RefDesc / isi baris…" aria-label="Cari RefDesc / isi baris">
            <span id="check-count" class="table-count"></span>
        </div>
        <div class="table-wrap">
            <table id="check-table">
                <thead><tr><th>No. Baris</th><th>RefDesc</th><th>Tag Terdekat</th><th>Isi Baris</th><th>Status</th><th>Perbaikan</th><th class="hidden" id="check-col-file">File</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="actions">
            <button class="btn secondary" id="check-save" type="button" disabled>Simpan perbaikan</button>
            <button class="btn secondary" id="check-csv" type="button">Unduh CSV Bermasalah</button>
            <button class="btn secondary" id="check-restore" type="button">Pulihkan Cadangan</button>
            <a class="btn hidden" id="check-download" href="#" download>Unduh XML revisi</a>
        </div>
        <p class="note hidden" id="check-note" role="status" aria-live="polite"></p>
    </div>
</section>

<!-- ============ TAB 2: MATCH ============ -->
<section id="tab-match" class="panel" role="tabpanel" aria-labelledby="tab-match-btn">
    <div class="card">
        <h2>Cocokkan KTP / NPWP</h2>
        <p>Unggah XML faktur dan Excel master data customer (format <em>Sample NIK.xlsx</em>). Aplikasi mencocokkan lewat Customer Code + Invoice, lalu mengisi dokumen pembeli:</p>
        <ul class="rules">
            <li>NPWP terisi &rarr; dokumen <strong>NPWP</strong> (+ BuyerTin)</li>
            <li>NPWP kosong, KTP terisi &rarr; dokumen <strong>National ID</strong></li>
            <li>Dua-duanya kosong / tidak ada di master &rarr; faktur <strong>tidak diubah</strong></li>
        </ul>

        <label class="file-field">
            <span>File XML faktur</span>
            <input type="file" id="match-xml" accept=".xml">
            <small class="file-info" id="match-xml-info"></small>
        </label>

        <label class="file-field">
            <span>Excel master (Sample NIK.xlsx)</span>
            <input type="file" id="match-master" accept=".xlsx,.xls">
            <small class="file-info" id="match-master-info"></small>
        </label>

        <button class="btn" id="match-run">Cocokkan dan revisi</button>
        <span class="spinner hidden" id="match-spin">Memproses…</span>
        <div class="error hidden" id="match-error" role="alert" aria-live="polite"></div>
    </div>

    <div class="card hidden" id="match-result">
        <h2>Hasil</h2>
        <div id="match-warning" class="warning hidden"></div>
        <div id="match-stats" class="stats"></div>

        <div class="actions">
            <a class="btn" id="match-download" href="#" download>Unduh XML revisi</a>
            <button class="btn secondary" id="match-save" type="button" disabled>Simpan revisi</button>
            <button class="btn secondary" id="match-restore" type="button">Pulihkan Cadangan</button>
            <button class="btn secondary" id="match-issues" type="button">Unduh daftar bermasalah</button>
            <button class="btn secondary hidden" id="match-draft" type="button">Unduh draft editan</button>
        </div>
        <p class="hint" id="match-edit-hint">Ada nomor yang salah? Ubah dokumen atau nomor langsung di tabel, lalu klik Simpan revisi.<br>
        Tanpa edit, file revisi bisa langsung diunduh.</p>
        <p class="note hidden" id="match-save-note" role="status" aria-live="polite"></p>

        <div class="table-tools">
            <input type="search" id="match-search" placeholder="Cari RefDesc / nama…" aria-label="Cari RefDesc / nama">
            <select id="match-filter" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="matched">Cocok</option>
                <option value="not_found">Tidak ketemu</option>
                <option value="both_empty">Kosong</option>
                <option value="invalid">Perlu diperiksa</option>
            </select>
            <span id="match-count" class="table-count"></span>
        </div>
        <div class="table-wrap">
            <table id="match-table">
                <thead><tr><th>RefDesc</th><th>Nama</th><th>Dokumen</th><th>Nomor</th><th>Status</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ============ TAB 3: CONVERT ============ -->
<section id="tab-convert" class="panel" role="tabpanel" aria-labelledby="tab-convert-btn">
    <div class="card">
        <h2>XML ke Excel</h2>
        <p>Ubah file XML faktur menjadi Excel dengan 2 sheet:
           <strong>Ringkasan</strong> (1 baris per faktur) dan
           <strong>Detail Barang</strong> (1 baris per barang).</p>

        <label class="file-field">
            <span>File XML faktur</span>
            <input type="file" id="convert-xml" accept=".xml" multiple>
            <small class="file-info" id="convert-xml-info"></small>
        </label>

        <button class="btn" id="convert-run">Konversi ke Excel</button>
        <span class="spinner hidden" id="convert-spin">Memproses…</span>
        <div class="error hidden" id="convert-error" role="alert" aria-live="polite"></div>
    </div>

    <div class="card hidden" id="convert-result">
        <h2>Hasil</h2>
        <div id="convert-stats" class="stats"></div>
        <a class="btn" id="convert-download" href="#" download>Unduh Excel</a>
    </div>
</section>

<!-- ============ TAB 4: TAKEOUT BY INVOICE ============ -->
<section id="tab-takeout" class="panel" role="tabpanel" aria-labelledby="tab-takeout-btn">
    <div class="card">
        <h2>Takeout by Invoice</h2>
        <p>Cari faktur di XML berdasarkan <strong>nomor invoice</strong> yang ada di RefDesc (format: <code>CardCode-Invoice-Seq-Nama</code>). Cocok untuk kasus takeout di mana hanya perlu faktur dengan nomor invoice tertentu.</p>

        <label class="file-field">
            <span>File XML faktur</span>
            <input type="file" id="takeout-xml" accept=".xml">
            <small class="file-info" id="takeout-xml-info"></small>
        </label>

        <label for="takeout-invoices">Nomor invoice yang dicari (pisahkan spasi, koma, atau titik koma)</label>
        <textarea id="takeout-invoices" rows="4" placeholder="Contoh: 111212159 111212246"></textarea>

        <button class="btn" id="takeout-run">Cari Invoice</button>
        <span class="spinner hidden" id="takeout-spin">Memproses…</span>
        <div class="error hidden" id="takeout-error" role="alert" aria-live="polite"></div>
    </div>

    <div class="card hidden" id="takeout-result">
        <h2>Hasil Pencarian</h2>
        <div class="stats" id="takeout-stats"></div>
        <div class="table-tools">
            <input type="search" id="takeout-search" placeholder="Cari RefDesc / nomor invoice…" aria-label="Cari RefDesc / nomor invoice">
            <span id="takeout-count" class="table-count"></span>
        </div>
        <div class="table-wrap">
            <table id="takeout-table">
                <thead><tr><th>Nomor Invoice</th><th>RefDesc</th><th>No. Baris Awal</th><th>No. Baris Akhir</th><th>Tipe Dokumen</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="actions">
            <button class="btn secondary" id="takeout-export" type="button" disabled>Ekspor XML Takeout</button>
            <a class="btn hidden" id="takeout-download" href="#" download>Unduh XML Takeout</a>
        </div>
        <p class="note hidden" id="takeout-notfound" role="status" aria-live="polite"></p>
        <p class="note hidden" id="takeout-note" role="status" aria-live="polite"></p>
    </div>
</section>

</main>

<footer>
    <p>File hasil disimpan sementara di server dan terhapus otomatis setelah ±1 jam. <a href="history.php">Lihat riwayat file</a></p>
</footer>

<script src="assets/escape.js?v=8"></script>
<script src="assets/app.js?v=11"></script>
</body>
</html>
