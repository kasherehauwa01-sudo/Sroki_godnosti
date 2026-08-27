(function () {
    'use strict';
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character]));
    /** Общий диалог выбора партий для вкладок «Уведомления» и «События». */
    window.openBatchExportSelection = function ({ batches, onDownload }) {
        document.querySelector('#batchExportSelectionDialog')?.remove();
        const items = (batches || []).map((batch) => ({ ...batch, id: Number(batch.id) })).filter((batch) => batch.id > 0);
        const selectedIds = new Set(items.map((batch) => batch.id));
        const dialog = document.createElement('dialog');
        dialog.id = 'batchExportSelectionDialog'; dialog.className = 'modal batch-export-selection-dialog';
        dialog.innerHTML = `<div class="card form modal-card batch-export-selection-card"><div class="modal-heading"><h2>Выберите товары для экспорта</h2><button class="icon-button batch-export-close" type="button" aria-label="Закрыть">×</button></div><label class="checkbox-row"><input class="batch-export-select-all" type="checkbox" checked> Выбрать все</label><input class="batch-export-search" type="search" placeholder="Поиск по артикулу, коду или наименованию" aria-label="Поиск товаров"><div class="batch-export-list"></div><p class="batch-export-count"></p><p class="field-error batch-export-error" role="alert"></p><div class="modal-actions"><button class="ghost-button batch-export-cancel" type="button">Отмена</button><button class="primary batch-export-download" type="button">Скачать XLS</button></div></div>`;
        document.body.append(dialog);
        const list = dialog.querySelector('.batch-export-list'), selectAll = dialog.querySelector('.batch-export-select-all'), download = dialog.querySelector('.batch-export-download'), count = dialog.querySelector('.batch-export-count'), error = dialog.querySelector('.batch-export-error');
        const close = () => { dialog.close(); dialog.remove(); };
        const updateState = () => { selectAll.checked = items.length > 0 && selectedIds.size === items.length; selectAll.indeterminate = selectedIds.size > 0 && selectedIds.size < items.length; download.disabled = selectedIds.size === 0; count.textContent = `Выбрано: ${selectedIds.size} из ${items.length}`; };
        const render = () => {
            const query = dialog.querySelector('.batch-export-search').value.trim().toLocaleLowerCase('ru-RU');
            const visible = items.filter((batch) => [batch.article, batch.code, batch.name].some((value) => String(value || '').toLocaleLowerCase('ru-RU').includes(query)));
            list.innerHTML = visible.map((batch) => `<label class="batch-export-row"><input type="checkbox" data-batch-id="${batch.id}" ${selectedIds.has(batch.id) ? 'checked' : ''}><span>${escapeHtml(batch.article || '—')} | ${escapeHtml(batch.code || '—')} | ${escapeHtml(batch.name || '—')} | ${escapeHtml(batch.expiry_date || batch.expiryDate || '—')}</span></label>`).join('') || '<p class="subtitle">Товары не найдены.</p>';
            list.querySelectorAll('[data-batch-id]').forEach((checkbox) => checkbox.addEventListener('change', () => { const id = Number(checkbox.dataset.batchId); checkbox.checked ? selectedIds.add(id) : selectedIds.delete(id); updateState(); })); updateState();
        };
        selectAll.addEventListener('change', () => { selectAll.checked ? items.forEach((batch) => selectedIds.add(batch.id)) : selectedIds.clear(); render(); });
        dialog.querySelector('.batch-export-search').addEventListener('input', render); dialog.querySelector('.batch-export-close').addEventListener('click', close); dialog.querySelector('.batch-export-cancel').addEventListener('click', close);
        download.addEventListener('click', async () => { if (!selectedIds.size) return; download.disabled = true; error.textContent = ''; try { await onDownload([...selectedIds]); close(); } catch (downloadError) { error.textContent = downloadError.message || 'Не удалось скачать файл'; download.disabled = false; } });
        render(); dialog.showModal();
    };
})();
