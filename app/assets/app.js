// app.js — logika UI untuk XML Faktur Toolkit
'use strict';

// ---------- theme ----------
const themeBtn = document.getElementById('theme-toggle');
if (themeBtn) {
    const syncTheme = () => {
        const dark = document.documentElement.dataset.theme === 'dark';
        themeBtn.setAttribute('aria-pressed', String(dark));
        themeBtn.setAttribute('aria-label', dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', dark ? '#101413' : '#f6f7f6');
    };
    syncTheme();
    themeBtn.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('theme', next); } catch { /* private mode */ }
        syncTheme();
    });
}

// ---------- tab ----------
const tabs = [...document.querySelectorAll('.tab')];
const activateTab = (btn) => {
    tabs.forEach(b => {
        const on = b === btn;
        b.classList.toggle('active', on);
        b.setAttribute('aria-selected', String(on));
        b.tabIndex = on ? 0 : -1;
    });
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
};
tabs.forEach(b => { b.tabIndex = b.getAttribute('aria-selected') === 'true' ? 0 : -1; });
tabs.forEach((btn, i) => {
    btn.addEventListener('click', () => activateTab(btn));
    btn.addEventListener('keydown', e => {
        const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        const next = tabs[(i + step + tabs.length) % tabs.length];
        next.focus();
        activateTab(next);
    });
});

// ---------- helpers ----------
function spinner(id, show) {
    document.getElementById(id).classList.toggle('hidden', !show);
}

function resultCard(id, show) {
    document.getElementById(id).classList.toggle('hidden', !show);
}

function showError(id, message) {
    const box = document.getElementById(id);
    if (!message) {
        box.classList.add('hidden');
        box.textContent = '';
        return;
    }
    box.textContent = message;
    box.classList.remove('hidden');
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Catatan status di dekat tabel match (kartu upload bisa sudah di luar layar).
function showNote(message, ok) {
    const el = document.getElementById('match-save-note');
    el.textContent = message;
    el.classList.toggle('note-ok', !!ok);
    el.classList.toggle('note-error', !ok);
    el.classList.remove('hidden');
}

function clearNote() {
    const el = document.getElementById('match-save-note');
    el.textContent = '';
    el.classList.add('hidden');
}

function pill(status, labelOk, labelWarn, labelBad) {
    const cls = status === 'ok' || status === 'matched' ? 'ok'
        : status === 'not_found' || status === 'both_empty' || status === 'out_of_block' ? 'warn'
        : 'bad';
    const text = { ok: labelOk, matched: labelOk, not_found: labelWarn, both_empty: labelWarn, out_of_block: labelBad }[status] || status;
    return `<span class="pill ${cls}">${text}</span>`;
}

async function postForm(formData) {
    const res = await fetch('api.php', { method: 'POST', body: formData });
    const body = await res.text();
    let data;
    try {
        data = JSON.parse(body);
    } catch {
        throw new Error(`Respons server bukan JSON (HTTP ${res.status}). Periksa log PHP/server.`);
    }
    if (data.error) {
        throw new Error(data.error);
    }
    if (!res.ok) {
        throw new Error(`Permintaan gagal (HTTP ${res.status}).`);
    }
    return data;
}

function fillTable(tableId, rows, cells) {
    const tbody = document.querySelector('#' + tableId + ' tbody');
    tbody.innerHTML = rows.map(r => '<tr>' + cells(r).join('') + '</tr>').join('');
}

function formatSize(bytes) {
    if (!Number.isFinite(bytes)) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// Tampilkan nama + ukuran file setelah dipilih (biar user yakin filenya benar).
function bindFileInfo(inputId, infoId) {
    const input = document.getElementById(inputId);
    const info = document.getElementById(infoId);
    input.addEventListener('change', () => {
        const f = input.files[0];
        info.textContent = f ? `${f.name} (${formatSize(f.size)})` : '';
    });
}

// Kunci tombol saat proses berjalan supaya tidak terklik 2x.
function setBusy(btnId, busy, labelIdle, labelBusy) {
    const btn = document.getElementById(btnId);
    btn.disabled = busy;
    btn.textContent = busy ? labelBusy : labelIdle;
}

// Filter instan untuk tabel hasil: cari teks + (opsional) filter status.
// Nilai 'invalid' = semua baris yang perlu diperiksa (tidak cocok / format salah).
function bindTableFilter(searchId, filterId, countId, renderRows, allRows, rowText) {
    const text = rowText || (r => String(r.refdesc ?? ''));
    const searchEl = document.getElementById(searchId);
    const filterEl = filterId ? document.getElementById(filterId) : null;
    const countEl = document.getElementById(countId);

    let cache = new WeakMap();   // row -> teks huruf kecil (dihitung sekali)
    let lastKey = null;          // refdesc hasil render terakhir (skip render identik)
    let timer = null;

    const haystack = r => {
        let s = cache.get(r);
        if (s === undefined) {
            s = text(r).toLowerCase();
            cache.set(r, s);
        }
        return s;
    };

    const apply = () => {
        const q = searchEl.value.trim().toLowerCase();
        const st = filterEl ? filterEl.value : '';
        const shown = allRows().filter(r => {
            if (st === 'invalid') {
                return r.status !== 'matched' || r.formatOk === false;
            }
            return !st || r.status === st;
        }).filter(r => !q || haystack(r).includes(q));
        const key = shown.map(r => r.line ?? r.refdesc).join('\u0000');
        if (key !== lastKey) {
            renderRows(shown);
            lastKey = key;
        }
        countEl.textContent = `Menampilkan ${shown.length} dari ${allRows().length} baris`;
    };

    // Debounce 500 ms: render setelah user berhenti mengetik.
    searchEl.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(apply, 500);
    });
    if (filterEl) {
        // Dropdown status: langsung, tanpa debounce.
        filterEl.addEventListener('change', () => {
            clearTimeout(timer);
            apply();
        });
    }
    apply.invalidate = r => cache.delete(r);            // baris diedit → teks basi
    apply.reset = () => { cache = new WeakMap(); lastKey = null; };
    return apply;
}

// ---------- TAB 1: cek baris ----------
let checkRows = [];
let checkToken = '';
let checkRowSeq = 0; // counter _id unik per baris (mode batch butuh identitas per baris, bukan nomor baris)
const checkEdits = new Map(); // _id -> { refdesc, docType, number, _token }

const checkEditable = r =>
    r.tag === 'BuyerDocumentNumber' && (r.docType === 'NPWP' || r.docType === 'National ID');

const checkRowText = r => `${r.line} ${r.refdesc ?? ''} ${r.nearTag} ${r.snippet} ${r._file ?? ''}`;
const renderCheckRows = bindTableFilter(
    'check-search', null, 'check-count',
    rows => fillTable('check-table', rows, r => [
        `<td class="mono">${escapeHtml(r.line)}</td>`,
        `<td class="mono">${escapeHtml(r.refdesc)}</td>`,
        `<td>${escapeHtml(r.nearTag)}</td>`,
        `<td class="mono">${escapeHtml(r.snippet)}</td>`,
        `<td>${r.issue
            ? `<span class="pill bad">${escapeHtml(r.issue)}</span>`
            : pill(r.status, 'OK', 'Di luar blok', 'Di luar blok')}</td>`,
        `<td>${checkEditable(r)
            ? `<input class="cell-input mono${numValid(r.docType, checkEdits.get(r._id)?.number ?? r.value) ? '' : ' invalid'}"
                      data-id="${escapeHtml(r._id)}" inputmode="numeric" autocomplete="off"
                      value="${escapeHtml(checkEdits.get(r._id)?.number ?? r.value ?? '')}"
                      aria-label="Nomor dokumen untuk ${escapeHtml(r.refdesc)}">`
            : '&mdash;'}</td>`,
        `<td class="${r._file ? '' : 'hidden'}">${escapeHtml(r._file ?? '')}</td>`,
    ]),
    () => checkRows,
    checkRowText
);

function syncCheckSave() {
    const btn = document.getElementById('check-save');
    const valid = [...checkEdits.values()].every(v => numValid(v.docType, v.number));
    btn.disabled = checkEdits.size === 0 || !valid;
    btn.textContent = checkEdits.size ? `Simpan perbaikan (${checkEdits.size})` : 'Simpan perbaikan';
}

document.querySelector('#check-table tbody').addEventListener('input', e => {
    const el = e.target.closest('input[data-id]');
    if (!el) return;
    const row = checkRows.find(r => String(r._id) === el.dataset.id);
    if (!row) return;
    const number = el.value;
    if (number === (row.value ?? '')) {
        checkEdits.delete(row._id);
    } else {
        checkEdits.set(row._id, { refdesc: row.refdesc, docType: row.docType, number, _token: row._token });
    }
    el.classList.toggle('invalid', !numValid(row.docType, number));
    syncCheckSave();
    scheduleCheckDraftSave();
});

document.getElementById('check-save').addEventListener('click', async () => {
    if (!checkEdits.size) { showError('check-error', 'Tidak ada perbaikan untuk disimpan.'); return; }

    // Kelompokkan editan per token (berkas). Mode 1 file = 1 token; mode batch = banyak token.
    const byToken = new Map();
    for (const v of checkEdits.values()) {
        if (!v._token) continue;
        if (!byToken.has(v._token)) byToken.set(v._token, []);
        byToken.get(v._token).push({
            refdesc: v.refdesc,
            docType: v.docType,
            number: digitsOnly(v.number),
        });
    }
    if (!byToken.size) { showError('check-error', 'Ulangi pengecekan dulu.'); return; }
    const items = [...byToken.entries()].map(([token, rows]) => ({ token, rows }));

    const fd = new FormData();
    fd.append('action', 'batch_apply');
    fd.append('items', JSON.stringify(items));

    const btn = document.getElementById('check-save');
    setBusy('check-save', true, btn.textContent, 'Menyimpan…');
    try {
        const data = await postForm(fd);
        const okTokens = [];
        const failures = [];
        for (const res of (data.results || [])) {
            if (res.error) { failures.push(res.error); }
            else if (res.token) { okTokens.push(res.token); }
        }

        // Update baris yang tersimpan (token sukses) & bersihkan editan terkait.
        for (const [id, v] of [...checkEdits]) {
            if (okTokens.includes(v._token)) {
                const row = checkRows.find(r => String(r._id) === id);
                if (row) {
                    row.value = digitsOnly(v.number);
                    row.issue = null;
                    row.status = 'ok';
                }
                checkEdits.delete(id);
            }
        }
        renderCheckRows();
        syncCheckSave();
        if (checkEdits.size === 0) { clearCheckDraft(); } else { scheduleCheckDraftSave(); }

        const link = document.getElementById('check-download');
        const note = document.getElementById('check-note');
        if (okTokens.length === 1) {
            link.href = `download.php?token=${encodeURIComponent(okTokens[0])}`;
            link.classList.remove('hidden');
            note.textContent = 'Perbaikan tersimpan. Unduh XML revisi, lalu unggah ulang ke e-Faktur.';
            note.classList.remove('hidden');
        } else if (okTokens.length > 1) {
            link.classList.add('hidden');
            note.innerHTML = `${okTokens.length} file revisi tersimpan. Unduh masing-masing via <a href="history.php">halaman Riwayat</a>.`;
            note.classList.remove('hidden');
        }
        if (failures.length) {
            showError('check-error', failures.join(' | '));
        }
    } catch (e) {
        showError('check-error', e.message);
    } finally {
        setBusy('check-save', false, checkEdits.size ? `Simpan perbaikan (${checkEdits.size})` : 'Simpan perbaikan', 'Menyimpan…');
        syncCheckSave();
    }
});

// Pulihkan cadangan (.bak.xml) untuk file di hasil Tab 1 (single = 1 token, batch = semua token unik).
document.getElementById('check-restore').addEventListener('click', async () => {
    const tokens = [...new Set(checkRows.map(r => r._token).filter(Boolean))];
    if (!tokens.length) {
        showError('check-error', 'Tidak ada file untuk dipulihkan. Lakukan pengecekan dulu.');
        return;
    }
    if (!confirm(`Pulihkan ${tokens.length} file ke cadangan terakhir? Perubahan simpanan terakhir akan hilang.`)) {
        return;
    }
    setBusy('check-restore', true, 'Pulihkan Cadangan', 'Memulihkan…');
    const ok = [];
    const fail = [];
    for (const token of tokens) {
        const fd = new FormData();
        fd.append('action', 'match_restore');
        fd.append('token', token);
        try {
            await postForm(fd);
            ok.push(token);
        } catch (e) {
            fail.push(e.message);
        }
    }
    const link = document.getElementById('check-download');
    const note = document.getElementById('check-note');
    if (ok.length === 1) {
        link.href = `download.php?token=${encodeURIComponent(ok[0])}`;
        link.classList.remove('hidden');
        note.textContent = 'File dipulihkan dari cadangan. Unduh untuk versi yang dipulihkan.';
        note.classList.remove('hidden');
    } else if (ok.length > 1) {
        link.classList.add('hidden');
        note.textContent = `${ok.length} file dipulihkan dari cadangan. Unduh masing-masing via halaman Riwayat.`;
        note.classList.remove('hidden');
    }
    if (fail.length) {
        showError('check-error', fail.join(' | '));
    }
    setBusy('check-restore', false, 'Pulihkan Cadangan', 'Memulihkan…');
});

// Ekspor CSV baris bermasalah (tab 1). Digunakan csvCell() dari escape.js.
document.getElementById('check-csv').addEventListener('click', () => {
    if (!checkRows.length) {
        showError('check-error', 'Tidak ada data untuk diekspor.');
        return;
    }
    const bad = checkRows.filter(r => r.status === 'problem');
    if (!bad.length) {
        showError('check-error', 'Tidak ada baris bermasalah.');
        return;
    }
    const stamp = () => new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
    const rows = [
        ['No. Baris', 'RefDesc', 'Tag Terdekat', 'Isi Baris', 'Status', 'Masalah', 'Tag', 'Nilai', 'Tipe Dokumen'],
        ...bad.map(r => [
            r.line, r.refdesc ?? '', r.nearTag ?? '', r.snippet ?? '', r.status, r.issue ?? '', r.tag ?? '', r.value ?? '', r.docType ?? ''
        ]),
    ];
    const lines = rows.map(row => row.map(csvCell).join(';'));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `bermasalah-${stamp()}.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
});

bindFileInfo('check-xml', 'check-xml-info');

document.getElementById('check-run').addEventListener('click', async () => {
    const files = document.getElementById('check-xml').files;
    const lines = document.getElementById('check-lines').value.trim();

    if (!files || files.length === 0) { showError('check-error', 'Pilih file XML faktur dulu.'); return; }

    const isBatch = files.length > 1;
    const fd = new FormData();
    fd.append('action', isBatch ? 'batch_check' : 'check_lines');
    fd.append('lines', lines);

    if (isBatch) {
        for (let i = 0; i < files.length; i++) {
            fd.append('xml[]', files[i]);
        }
    } else {
        fd.append('xml', files[0]);
    }

    setBusy('check-run', true, 'Proses', 'Memproses…');
    spinner('check-spin', true);
    showError('check-error', null);
    document.getElementById('check-note').classList.add('hidden');
    document.getElementById('check-download').classList.add('hidden');
    try {
        const data = await postForm(fd);
        if (isBatch) {
            // Tampilkan hasil batch: gabungkan semua rows, tandai berkas asal + token per file.
            checkEdits.clear();
            clearCheckDraft();
            checkRows = [];
            checkRowSeq = 0;
            data.results.forEach(r => {
                if (r.error) {
                    showError('check-error', `${r.file}: ${r.error}`);
                }
                r.rows.forEach(row => {
                    checkRows.push({ ...row, _file: r.file, _token: r.token, _id: ++checkRowSeq });
                });
            });
            checkToken = ''; // batch: tidak ada token tunggal
            renderCheckRows();
            syncCheckSave();
            document.getElementById('check-col-file').classList.remove('hidden');
            const totalInv = data.results.reduce((s, r) => s + (r.invoiceCount || 0), 0);
            const totalProb = checkRows.filter(r => r.status === 'problem').length;
            showNote(`${totalProb} dari ${totalInv} invoice memiliki baris bermasalah.`, true);
            resultCard('check-result', true);
        } else {
            checkRowSeq = 0;
            checkRows = data.rows.map(row => ({ ...row, _token: data.downloadToken || '', _id: ++checkRowSeq }));
            checkToken = data.downloadToken || '';
            checkEdits.clear();
            clearCheckDraft();
            document.getElementById('check-col-file').classList.add('hidden');
            renderCheckRows();
            syncCheckSave();
            const totalInv = data.invoiceCount || 0;
            const totalProb = checkRows.filter(r => r.status === 'problem').length;
            showNote(`${totalProb} dari ${totalInv} invoice memiliki baris bermasalah.`, true);
            resultCard('check-result', true);
        }
    } catch (e) {
        resultCard('check-result', false);
        showError('check-error', e.message);
    } finally {
        spinner('check-spin', false);
        setBusy('check-run', false, 'Proses', 'Memproses…');
    }
});

// ---------- TAB 2: match ----------
let matchRows = [];
let matchToken = '';
let matchApplyFilter = () => {};
const matchEdits = new Map(); // refdesc -> { docType, number }

const digitsOnly = v => String(v ?? '').replace(/\D+/g, '');

function numValid(docType, number) {
    const digits = digitsOnly(number);
    if (docType === 'NPWP') return digits.length === 15 || digits.length === 16;
    if (docType === 'National ID') return digits.length === 16;
    return false;
}

function docSelect(row) {
    const opts = ['NPWP', 'National ID'].map(v =>
        `<option value="${v}"${row.docType === v ? ' selected' : ''}>${v}</option>`).join('');
    const none = row.docType ? '' : '<option value="" selected>Pilih…</option>';
    return `<select class="cell-input" data-field="docType" aria-label="Dokumen untuk ${escapeHtml(row.refdesc)}">${none}${opts}</select>`;
}

function renderMatchTable(rows) {
    const tbody = document.querySelector('#match-table tbody');
    tbody.innerHTML = rows.map(r => `
        <tr data-ref="${escapeHtml(r.refdesc)}"${r.edited ? ' class="row-edited"' : ''}>
            <td class="mono">${escapeHtml(r.refdesc)}</td>
            <td>${escapeHtml(r.name)}</td>
            <td>${docSelect(r)}</td>
            <td><input class="cell-input mono${r.touched && !numValid(r.docType, r.number) ? ' invalid' : ''}"
                       data-field="number" value="${escapeHtml(r.number)}"
                       inputmode="numeric" autocomplete="off"
                       aria-label="Nomor dokumen untuk ${escapeHtml(r.refdesc)}"></td>
            <td>${statusPill(r.status)}${r.formatOk === false ? ' <span class="pill bad">Format</span>' : ''}</td>
        </tr>`).join('');
}

// Label status eksplisit per nilai.
function statusPill(status) {
    const label = { matched: 'Cocok', not_found: 'Tidak ketemu', both_empty: 'Kosong' }[status] || status;
    const cls = status === 'matched' ? 'ok' : 'warn';
    return `<span class="pill ${cls}">${label}</span>`;
}

const matchRowText = r => `${r.refdesc} ${r.name ?? ''} ${r.docType ?? ''} ${r.number ?? ''}`;
matchApplyFilter = bindTableFilter(
    'match-search', 'match-filter', 'match-count',
    renderMatchTable,
    () => matchRows,
    matchRowText
);

bindFileInfo('match-xml', 'match-xml-info');
bindFileInfo('match-master', 'match-master-info');

const matchSave = document.getElementById('match-save');
const matchHint = document.getElementById('match-edit-hint');

function syncSaveButton() {
    const valid = [...matchEdits.values()].every(v => numValid(v.docType, v.number));
    matchSave.disabled = matchEdits.size === 0 || !valid;
    matchSave.textContent = matchEdits.size ? `Simpan revisi (${matchEdits.size})` : 'Simpan revisi';
}

document.querySelector('#match-table tbody').addEventListener('input', onMatchEdit);
document.querySelector('#match-table tbody').addEventListener('change', onMatchEdit);

function onMatchEdit(e) {
    const el = e.target.closest('.cell-input');
    if (!el) return;
    const tr = el.closest('tr');
    const ref = tr.dataset.ref;
    const row = matchRows.find(r => r.refdesc === ref);
    if (!row) return;

    const docEl = tr.querySelector('[data-field="docType"]');
    const numEl = tr.querySelector('[data-field="number"]');
    const doc = docEl.value;
    // Saring non-digit langsung saat diketik/tempel; server tetap validasi ulang.
    const num = digitsOnly(numEl.value);
    if (num !== numEl.value) numEl.value = num;

    numEl.classList.toggle('invalid', !numValid(doc, num));

    // Sinkronkan state baris: filter/render ulang tidak boleh menghapus editan di layar.
    const edited = doc !== (row.origDoc || '')
        || num !== digitsOnly(row.origNum ?? row.number);
    row.docType = doc;
    row.number = num;
    row.touched = true;
    row.edited = edited;
    tr.classList.toggle('row-edited', edited);
    if (edited) {
        matchEdits.set(ref, { docType: doc, number: num });
    } else {
        matchEdits.delete(ref);
    }
    syncSaveButton();
    matchApplyFilter.invalidate(row);
    scheduleDraftSave();
}

let matchSaving = false; // anti double-submit

matchSave.addEventListener('click', async () => {
    if (matchSaving) return;
    if (!matchToken) {
        showNote('Token tidak ada — cocokkan ulang dulu.', false);
        return;
    }
    const items = [...matchEdits].map(([refdesc, v]) => ({
        refdesc,
        docType: v.docType,
        number: digitsOnly(v.number),
    }));
    if (!items.length) return;

    const fd = new FormData();
    fd.append('action', 'match_apply');
    fd.append('token', matchToken);
    fd.append('rows', JSON.stringify(items));

    matchSaving = true;
    setBusy('match-save', true, 'Simpan revisi', 'Menyimpan…');
    showError('match-error', null);
    try {
        await postForm(fd);
        items.forEach(it => {
            const row = matchRows.find(r => r.refdesc === it.refdesc);
            if (row) {
                row.docType = it.docType;
                row.number = it.number;
                row.status = 'matched';
                row.formatOk = true;
                row.origDoc = it.docType;
                row.origNum = it.number;
                row.touched = false;
                row.edited = false;
            }
        });
        matchEdits.clear();
        matchApplyFilter.reset();
        matchApplyFilter();
        showNote(`${items.length} baris tersimpan. File revisi siap diunduh.`, true);
        document.getElementById('match-draft').classList.add('hidden');
        scheduleDraftSave();
    } catch (e) {
        // Editan tetap ada di halaman; tawarkan draft CSV kalau mau diselamatkan.
        showNote(`${e.message} Editan masih di halaman ini — klik Simpan revisi lagi, atau unduh draft editan.`, false);
        document.getElementById('match-draft').classList.remove('hidden');
    } finally {
        matchSaving = false;
        setBusy('match-save', false, 'Simpan revisi', 'Simpan revisi');
        syncSaveButton();
    }
});

// Pulihkan cadangan file sebelum revisi terakhir.
document.getElementById('match-restore').addEventListener('click', async () => {
    if (!matchToken) {
        showNote('Token tidak ada — cocokkan ulang dulu.', false);
        return;
    }
    if (!confirm('Pulihkan file revisi ke cadangan terakhir? Perubahan terakhir akan hilang.')) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'match_restore');
    fd.append('token', matchToken);

    setBusy('match-restore', true, 'Pulihkan Cadangan', 'Memulihkan…');
    try {
        const data = await postForm(fd);
        if (data.restored) {
            showNote('File revisi dipulihkan dari cadangan. Unduh file untuk versi yang dipulihkan.', true);
            document.getElementById('match-download').href = 'download.php?token=' + encodeURIComponent(matchToken);
        }
    } catch (e) {
        showNote('Gagal memulihkan cadangan: ' + e.message, false);
    } finally {
        setBusy('match-restore', false, 'Pulihkan Cadangan', 'Memulihkan…');
    }
});

// Ekspor CSV (BOM + ; agar rapi di Excel Indonesia). csvCell() ada di escape.js.
function downloadCsv(rows, filename) {
    const lines = rows.map(row => row.map(csvCell).join(';'));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

const csvStamp = () => new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
const csvStatus = r => ({ matched: 'Cocok', not_found: 'Tidak ketemu', both_empty: 'Kosong' }[r.status] || r.status);

document.getElementById('match-issues').addEventListener('click', () => {
    const bad = matchRows.filter(r => r.status !== 'matched' || r.formatOk === false);
    if (!bad.length) {
        showNote('Tidak ada baris bermasalah.', true);
        return;
    }
    const note = r => r.status !== 'matched'
        ? 'tidak ada di master / data kosong'
        : 'format nomor tidak valid';
    downloadCsv([
        ['RefDesc', 'Nama', 'Dokumen', 'Nomor', 'Status', 'Catatan'],
        ...bad.map(r => [r.refdesc, r.name ?? '', r.docType ?? '', r.number ?? '', csvStatus(r), note(r)]),
    ], `bermasalah-${csvStamp()}.csv`);
});

// Jaring pengaman terakhir: simpan editan sebagai CSV bila server/token mati.
document.getElementById('match-draft').addEventListener('click', () => {
    if (!matchRows.length) return;
    downloadCsv([
        ['RefDesc', 'Nama', 'Dokumen', 'Nomor', 'Status'],
        ...matchRows.map(r => [r.refdesc, r.name ?? '', r.docType ?? '', r.number ?? '', csvStatus(r)]),
    ], `draft-editan-${csvStamp()}.csv`);
});

// Pengaman unduh: editan belum disimpan / masih ada format tidak valid.
document.getElementById('match-download').addEventListener('click', e => {
    if (!matchToken) {
        e.preventDefault();
        return;
    }
    if (matchEdits.size > 0
        && !confirm(`${matchEdits.size} baris editan belum disimpan — file yang diunduh TIDAK berisi editan itu. Tetap unduh versi lama?`)) {
        e.preventDefault();
        return;
    }
    const badFormat = matchRows.filter(r => r.formatOk === false).length;
    if (badFormat > 0
        && !confirm(`${badFormat} baris format nomornya masih tidak valid (lihat filter "Perlu diperiksa"). Tetap unduh?`)) {
        e.preventDefault();
    }
});

document.getElementById('match-run').addEventListener('click', async () => {
    const xmlFile = document.getElementById('match-xml').files[0];
    const masterFile = document.getElementById('match-master').files[0];

    if (!xmlFile) { showError('match-error', 'Pilih file XML faktur dulu.'); return; }
    if (!masterFile) { showError('match-error', 'Pilih Excel master (Sample NIK.xlsx) dulu.'); return; }
    if (matchEdits.size > 0
        && !confirm(`${matchEdits.size} baris editan belum disimpan dan akan dibuang. Lanjut cocokkan ulang?`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'match');
    fd.append('xml', xmlFile);
    fd.append('master', masterFile);

    setBusy('match-run', true, 'Cocokkan dan revisi', 'Memproses…');
    spinner('match-spin', true);
    showError('match-error', null);
    try {
        const data = await postForm(fd);
        const s = data.stats;

        document.getElementById('match-stats').innerHTML = `
            <div class="stat"><b>${s.total}</b> Total faktur</div>
            <div class="stat"><b>${s.updated}</b> Direvisi</div>
            <div class="stat"><b>${s.sanitized}</b> Nomor dibersihkan</div>
            <div class="stat"><b>${s.invalidLeft}</b> Nomor masih bermasalah</div>            <div class="stat"><b>${s.matched}</b> Cocok</div>
            <div class="stat"><b>${s.both_empty}</b> Master kosong</div>
            <div class="stat"><b>${s.not_found}</b> Tidak ada di master</div>
            <div class="stat"><b>${s.invalidFormat}</b> Format tidak valid</div>`;

        const warnBox = document.getElementById('match-warning');
        if (data.warning) {
            warnBox.textContent = data.warning;
            warnBox.classList.remove('hidden');
        } else {
            warnBox.classList.add('hidden');
        }

        matchToken = data.downloadToken;
        document.getElementById('match-download').href = 'download.php?token=' + matchToken;
        matchRows = data.rows;
        matchRows.forEach(r => { r.origDoc = r.docType || ''; r.origNum = r.number || ''; r.touched = false; r.edited = false; });
        matchEdits.clear();
        matchHint.textContent = 'Ada nomor yang salah? Ubah dokumen atau nomor langsung di tabel, lalu klik Simpan revisi. Tanpa edit, file revisi bisa langsung diunduh.';
        document.getElementById('match-draft').classList.add('hidden');
        clearNote();
        matchApplyFilter.reset();
        matchApplyFilter();
        syncSaveButton();
        resultCard('match-result', true);
        scheduleDraftSave();
    } catch (e) {
        resultCard('match-result', false);
        showError('match-error', e.message);
    } finally {
        spinner('match-spin', false);
        setBusy('match-run', false, 'Cocokkan dan revisi', 'Memproses…');
    }
});

// ---------- Draft editan (anti hilang: crash, tutup tab, server mati) ----------
const DRAFT_KEY = 'match-draft';
const DRAFT_MAX_AGE = 3600 * 1000; // 1 jam

function saveDraft() {
    try {
        if (!matchToken || !matchRows.length) return;
        localStorage.setItem(DRAFT_KEY, JSON.stringify({
            token: matchToken,
            rows: matchRows,
            edits: [...matchEdits],
            ts: Date.now(),
        }));
    } catch { /* storage penuh / private mode */ }
}

let draftTimer = null;
function scheduleDraftSave() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(saveDraft, 500);
}

function clearDraft() {
    try { localStorage.removeItem(DRAFT_KEY); } catch { /* noop */ }
}

// Tawarkan pulihkan draft saat halaman dimuat.
(function restoreDraft() {
    let d = null;
    try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch { /* korup */ }
    if (!d || !d.token || !Array.isArray(d.rows) || !d.rows.length) return;
    if (Date.now() - (d.ts || 0) > DRAFT_MAX_AGE) { clearDraft(); return; }
    const n = Array.isArray(d.edits) ? d.edits.length : 0;
    const msg = n > 0
        ? `Ada ${n} baris editan belum disimpan dari sesi sebelumnya. Pulihkan?`
        : 'Ada hasil pencocokan dari sesi sebelumnya. Pulihkan?';
    if (!confirm(msg)) { clearDraft(); return; }
    matchToken = d.token;
    matchRows = d.rows;
    matchRows.forEach(r => {
        if (r.origDoc === undefined) r.origDoc = r.docType || '';
        if (r.origNum === undefined) r.origNum = digitsOnly(r.number);
        if (typeof r.touched !== 'boolean') r.touched = true;
    });
    matchEdits.clear();
    (d.edits || []).forEach(([ref, v]) => matchEdits.set(ref, v));
    document.getElementById('match-download').href = 'download.php?token=' + encodeURIComponent(matchToken);
    document.getElementById('match-xml-info').textContent = 'dipulihkan dari draft (file asli tidak perlu diunggah ulang)';
    resultCard('match-result', true);
    matchApplyFilter.reset();
    matchApplyFilter();
    syncSaveButton();
    // Kartu hasil ada di tab 2 — pindahkan user ke sana, kalau tidak terlihat seperti "tidak terjadi apa-apa".
    const matchTabBtn = document.getElementById('tab-match-btn');
    if (matchTabBtn) activateTab(matchTabBtn);
    showNote(n > 0
        ? `Draft dipulihkan: ${n} baris belum tersimpan ke file. Klik Simpan revisi untuk menerapkan.`
        : 'Hasil pencocokan dipulihkan. File revisi bisa langsung diunduh.', true);
    document.getElementById('match-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
})();

// ---------- Draft editan Tab 1 (anti hilang: crash, tutup tab, server mati) ----------
const CHECK_DRAFT_KEY = 'check-draft';
const CHECK_DRAFT_MAX_AGE = 3600 * 1000; // 1 jam

function saveCheckDraft() {
    try {
        if (!checkRows.length || checkEdits.size === 0) { clearCheckDraft(); return; }
        const tokens = [...new Set(checkRows.map(r => r._token).filter(Boolean))];
        if (!tokens.length) { return; }
        localStorage.setItem(CHECK_DRAFT_KEY, JSON.stringify({
            rows: checkRows,
            edits: [...checkEdits],
            ts: Date.now(),
        }));
    } catch { /* storage penuh / private mode */ }
}

let checkDraftTimer = null;
function scheduleCheckDraftSave() {
    clearTimeout(checkDraftTimer);
    checkDraftTimer = setTimeout(saveCheckDraft, 500);
}

function clearCheckDraft() {
    try { localStorage.removeItem(CHECK_DRAFT_KEY); } catch { /* noop */ }
}

// Tawarkan pulihkan draft Tab 1 saat halaman dimuat (hanya bila ada editan belum tersimpan).
(function restoreCheckDraft() {
    let d = null;
    try { d = JSON.parse(localStorage.getItem(CHECK_DRAFT_KEY) || 'null'); } catch { /* korup */ }
    if (!d || !Array.isArray(d.rows) || !d.rows.length) return;
    if (Date.now() - (d.ts || 0) > CHECK_DRAFT_MAX_AGE) { clearCheckDraft(); return; }
    const n = Array.isArray(d.edits) ? d.edits.length : 0;
    if (n === 0) { clearCheckDraft(); return; }
    if (!confirm(`Ada ${n} baris editan belum disimpan dari sesi sebelumnya (Tab 1 Cek baris). Pulihkan?`)) { clearCheckDraft(); return; }
    checkRows = d.rows;
    checkRowSeq = Math.max(0, ...d.rows.map(r => Number(r._id) || 0));
    checkEdits.clear();
    (d.edits || []).forEach(([id, v]) => checkEdits.set(id, v));
    const tokens = [...new Set(checkRows.map(r => r._token).filter(Boolean))];
    checkToken = tokens.length === 1 ? tokens[0] : '';
    resultCard('check-result', true);
    renderCheckRows();
    syncCheckSave();
    const checkTabBtn = document.getElementById('tab-check-btn');
    if (checkTabBtn) activateTab(checkTabBtn);
    const note = document.getElementById('check-note');
    note.textContent = `Draft dipulihkan: ${n} baris belum tersimpan. Klik Simpan perbaikan untuk menerapkan.`;
    note.classList.remove('hidden');
    document.getElementById('check-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
})();

// Peringatan sebelum tutup/reload saat masih ada editan (bisa dilewati saat crash).
window.addEventListener('beforeunload', e => {
    if (matchEdits.size > 0 || checkEdits.size > 0) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// ---------- TAB 3: convert ----------
bindFileInfo('convert-xml', 'convert-xml-info');

document.getElementById('convert-run').addEventListener('click', async () => {
    const files = document.getElementById('convert-xml').files;
    if (!files || files.length === 0) { showError('convert-error', 'Pilih file XML faktur dulu.'); return; }

    const isBatch = files.length > 1;
    const fd = new FormData();
    fd.append('action', isBatch ? 'batch_convert' : 'convert');
    if (isBatch) {
        for (let i = 0; i < files.length; i++) {
            fd.append('xml[]', files[i]);
        }
    } else {
        fd.append('xml', files[0]);
    }

    setBusy('convert-run', true, 'Konversi ke Excel', 'Memproses…');
    spinner('convert-spin', true);
    showError('convert-error', null);
    try {
        const data = await postForm(fd);
        const dl = document.getElementById('convert-download');
        const statsEl = document.getElementById('convert-stats');
        if (isBatch) {
            const rows = (data.results || []).map(r => {
                if (r.error) {
                    return `<li><b>${escapeHtml(r.file)}</b> — <span class="pill bad">${escapeHtml(r.error)}</span></li>`;
                }
                return `<li><b>${escapeHtml(r.file)}</b> — ${r.stats.invoices} faktur / ${r.stats.items} barang — <a class="btn" href="download.php?token=${encodeURIComponent(r.token)}" download>Unduh</a></li>`;
            });
            statsEl.innerHTML = `<ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px;">${rows.join('')}</ul>`;
            dl.classList.add('hidden');
        } else {
            statsEl.innerHTML = `
                <div class="stat"><b>${data.stats.invoices}</b> Faktur</div>
                <div class="stat"><b>${data.stats.items}</b> Baris barang</div>`;
            dl.href = 'download.php?token=' + data.downloadToken;
            dl.classList.remove('hidden');
        }
        resultCard('convert-result', true);
    } catch (e) {
        resultCard('convert-result', false);
        showError('convert-error', e.message);
    } finally {
        spinner('convert-spin', false);
        setBusy('convert-run', false, 'Konversi ke Excel', 'Memproses…');
    }
});

// ---------- TAB 4: TAKEOUT BY INVOICE ----------
let takeoutRows = [];
let takeoutToken = '';
let takeoutSourceToken = ''; // token file asli di tmp (untuk ekspor)
let takeoutInvoices = '';    // daftar invoice yang dicari (untuk dikirim saat ekspor)

const takeoutRowText = r => `${r.invoice ?? ''} ${r.refdesc ?? ''}`;
const renderTakeoutTable = bindTableFilter(
    'takeout-search', null, 'takeout-count',
    rows => fillTable('takeout-table', rows, r => [
        `<td class="mono">${escapeHtml(r.invoice)}</td>`,
        `<td class="mono">${escapeHtml(r.refdesc)}</td>`,
        `<td class="mono">${escapeHtml(r.start)}</td>`,
        `<td class="mono">${escapeHtml(r.end ?? '—')}</td>`,
        `<td>${escapeHtml(r.docType ?? '—')}</td>`,
    ]),
    () => takeoutRows,
    takeoutRowText
);

bindFileInfo('takeout-xml', 'takeout-xml-info');

document.getElementById('takeout-run').addEventListener('click', async () => {
    const xmlFile = document.getElementById('takeout-xml').files[0];
    const invoices = document.getElementById('takeout-invoices').value.trim();

    if (!xmlFile) { showError('takeout-error', 'Pilih file XML faktur dulu.'); return; }
    if (!invoices) { showError('takeout-error', 'Masukkan minimal satu nomor invoice.'); return; }

    const fd = new FormData();
    fd.append('action', 'takeout_invoice');
    fd.append('xml', xmlFile);
    fd.append('invoices', invoices);

    setBusy('takeout-run', true, 'Cari Invoice', 'Mencari…');
    spinner('takeout-spin', true);
    showError('takeout-error', null);
    document.getElementById('takeout-result').classList.add('hidden');
    document.getElementById('takeout-download').classList.add('hidden');
    document.getElementById('takeout-notfound').classList.add('hidden');
    document.getElementById('takeout-note').classList.add('hidden');

    try {
        const data = await postForm(fd);
        takeoutSourceToken = data.downloadToken || ''; // token file asli untuk ekspor
        takeoutInvoices = invoices;                   // simpan daftar invoice untuk ekspor
        takeoutRows = data.found.map(f => ({
            invoice: f.invoice,
            refdesc: f.refdesc,
            start: f.start,
            end: f.end,
            docType: f.docType,
        }));
        takeoutToken = ''; // takeout hanya mencari, tidak ada download token langsung

        const exportBtn = document.getElementById('takeout-export');
        exportBtn.disabled = takeoutRows.length === 0;

        const sEl = document.getElementById('takeout-stats');
        sEl.innerHTML = `
            <div class="stat"><b>${data.requested}</b> Nomor dicari</div>
            <div class="stat"><b>${data.foundCount}</b> Ditemukan</div>
            <div class="stat"><b>${data.invoiceCount}</b> Total faktur dalam file</div>
        `;

        if (data.notFound && data.notFound.length) {
            const notFoundBox = document.getElementById('takeout-notfound');
            notFoundBox.textContent = 'Tidak ditemukan: ' + data.notFound.join(', ');
            notFoundBox.classList.remove('hidden');
        }

        renderTakeoutTable();
        const totalProb = takeoutRows.length;
        const noteBox = document.getElementById('takeout-note');
        noteBox.textContent = `${totalProb} dari ${data.invoiceCount} faktur cocok dengan nomor invoice yang dicari.`;
        noteBox.classList.remove('hidden');
        document.getElementById('takeout-count').textContent = `Menampilkan ${takeoutRows.length} dari ${takeoutRows.length} baris`;
        document.getElementById('takeout-result').classList.remove('hidden');
        const takeoutTabBtn = document.getElementById('tab-takeout-btn');
        if (takeoutTabBtn) activateTab(takeoutTabBtn);
        document.getElementById('takeout-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        document.getElementById('takeout-result').classList.add('hidden');
        showError('takeout-error', e.message);
    } finally {
        spinner('takeout-spin', false);
        setBusy('takeout-run', false, 'Cari Invoice', 'Mencari…');
    }
});

// Tombol Ekspor XML Takeout — buat file XML baru hanya dengan faktur yang cocok.
document.getElementById('takeout-export').addEventListener('click', async () => {
    if (!takeoutSourceToken) {
        showError('takeout-error', 'Lakukan pencarian takeout dulu.');
        return;
    }
    if (!takeoutInvoices) {
        showError('takeout-error', 'Daftar invoice kosong. Cari ulang.');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'takeout_export');
    fd.append('token', takeoutSourceToken);
    fd.append('invoices', takeoutInvoices);

    setBusy('takeout-export', true, 'Ekspor XML Takeout', 'Mengekspor…');
    spinner('takeout-spin', true);
    showError('takeout-error', null);
    document.getElementById('takeout-download').classList.add('hidden');

    try {
        const data = await postForm(fd);
        takeoutToken = data.downloadToken;
        const dl = document.getElementById('takeout-download');
        dl.href = `download.php?token=${encodeURIComponent(takeoutToken)}`;
        dl.classList.remove('hidden');
        // Ekspor sekaligus unduh otomatis; tombol unduh tetap ditampilkan sebagai cadangan.
        dl.click();
        showNote(`Ekspor selesai: ${data.exported} faktur diekspor ke XML takeout. File otomatis diunduh.`, true);
    } catch (e) {
        showError('takeout-error', e.message);
    } finally {
        spinner('takeout-spin', false);
        setBusy('takeout-export', false, 'Ekspor XML Takeout', 'Ekspor XML Takeout');
    }
});
