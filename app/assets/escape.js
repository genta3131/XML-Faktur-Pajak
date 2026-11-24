// escape.js, helper escape HTML & sel CSV (dipakai browser dan test node).
function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Sel CSV: kutip + lindungi formula injection (= + - @) dengan prefiks '.
function csvCell(value) {
    let s = String(value ?? '').replace(/"/g, '""');
    if (/^[=+\-@]/.test(s)) s = "'" + s;
    return '"' + s + '"';
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { escapeHtml, csvCell };
}
