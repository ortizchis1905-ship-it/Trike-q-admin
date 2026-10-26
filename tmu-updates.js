(function () {
    'use strict';
    const cleanCell = value => {
        let text = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
        if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    };
    document.querySelectorAll('[data-csv-table]').forEach(button => button.addEventListener('click', () => {
        const table = document.getElementById(button.dataset.csvTable);
        if (!table || !table.tHead || !table.tBodies.length) { alert('There are no records to export.'); return; }
        const headings = Array.from(table.tHead.rows[0].cells);
        const indexes = headings.map((cell, index) => cell.hasAttribute('data-no-export') ? -1 : index).filter(i => i >= 0);
        const rows = Array.from(table.tBodies[0].rows).filter(row => !row.hidden && getComputedStyle(row).display !== 'none' && row.cells.length === headings.length && !row.querySelector('td[colspan]'));
        if (!rows.length) { alert('No records match the current filters.'); return; }
        const csv = [indexes.map(i => cleanCell(headings[i].textContent)).join(','), ...rows.map(row => indexes.map(i => cleanCell(row.cells[i].dataset.csvValue ?? row.cells[i].textContent)).join(','))].join('\r\n');
        const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = (button.dataset.csvName || 'records') + '-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }));
    const orgSearch = document.getElementById('tqOrgSearch');
    if (orgSearch) orgSearch.addEventListener('input', () => {
        const term = orgSearch.value.trim().toLowerCase(); let count = 0;
        document.querySelectorAll('#tqOrgTable tbody tr[data-org-row]').forEach(row => {
            row.hidden = !row.textContent.toLowerCase().includes(term); if (!row.hidden) count++;
        });
        const result = document.getElementById('tqOrgCount'); if (result) result.textContent = count + ' matching organizations';
    });
    const photoLinks = document.querySelectorAll('[data-tq-photo]');
    if (photoLinks.length) {
        const dialog = document.createElement('dialog'); dialog.className = 'tq-photo-dialog';
        dialog.innerHTML = '<header><h2></h2><button type="button">Close</button></header><img alt=""><p class="tq-photo-error" hidden>Photo unavailable. Refresh or check whether a photo has been uploaded.</p>';
        document.body.appendChild(dialog);
        const image = dialog.querySelector('img'), title = dialog.querySelector('h2'), error = dialog.querySelector('p');
        const close = () => { dialog.close(); image.removeAttribute('src'); };
        dialog.querySelector('button').addEventListener('click', close);
        dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if(event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close(); } });
        image.addEventListener('error', () => { image.hidden = true; error.hidden = false; });
        photoLinks.forEach(link => link.addEventListener('click', event => {
            if (typeof dialog.showModal !== 'function') return;
            event.preventDefault(); error.hidden = true; image.hidden = false;
            title.textContent = link.dataset.photoTitle || 'Photo'; image.alt = title.textContent; image.src = link.href; dialog.showModal();
        }));
    }
})();