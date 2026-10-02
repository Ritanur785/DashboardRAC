document.addEventListener('DOMContentLoaded', () => {
    const updateModal = document.getElementById('updateModal');
    const createModal = document.getElementById('createModal');
    const bulkModal = document.getElementById('bulkModal');
    const updateForm = document.getElementById('updateProgressForm');
    const createForm = document.getElementById('createAlertForm');
    const bulkForm = document.getElementById('bulkUpdateForm');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const bulkSelectedBadge = document.getElementById('bulkSelectedBadge');
    const bulkSelectedCountText = document.getElementById('bulkSelectedCountText');
    const checkAllAlerts = document.getElementById('checkAllAlerts');
    const toast = document.getElementById('toast');

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3500);
    }

    function openModal(modal) {
        if (modal) modal.classList.add('open');
    }

    function closeModal(modal) {
        if (modal) modal.classList.remove('open');
    }

    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            closeModal(updateModal);
            closeModal(createModal);
            closeModal(bulkModal);
        });
    });

    window.addEventListener('click', (e) => {
        if (e.target === updateModal) closeModal(updateModal);
        if (e.target === createModal) closeModal(createModal);
        if (e.target === bulkModal) closeModal(bulkModal);
    });

    const openCreateBtn = document.getElementById('btnOpenCreateModal');
    if (openCreateBtn) {
        openCreateBtn.addEventListener('click', () => openModal(createModal));
    }

    document.querySelectorAll('.btn-update-progress').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-id');
            if (!id) {
                alert('ID alert tidak valid.');
                return;
            }

            const origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Memuat...';

            try {
                const getUrl = new URL(`api/alerts.php?action=get&id=${encodeURIComponent(id)}`, window.location.href).href;
                const res = await fetch(getUrl);
                if (!res.ok) {
                    throw new Error(`Server merespons status ${res.status}`);
                }

                const result = await res.json();
                if (!result.success || !result.data) {
                    alert(result.error || 'Gagal mengambil data alert.');
                    return;
                }

                const d = result.data;
                const setVal = (elemId, val) => {
                    const el = document.getElementById(elemId);
                    if (el) el.value = (val !== null && val !== undefined) ? val : '';
                };

                setVal('edit_id', d.id);
                const nameEl = document.getElementById('display_nama');
                if (nameEl) nameEl.textContent = `${d.nama_nasabah || '-'} (${d.cif || '-'})`;
                const scenEl = document.getElementById('display_skenario');
                if (scenEl) scenEl.textContent = d.skenario || '-';

                setVal('edit_tgl_tindak_lanjut', d.tgl_tindak_lanjut || '');
                setVal('edit_disposisi_rac', d.disposisi_rac || '');
                setVal('edit_status_tl', (d.status_tl && d.status_tl.toLowerCase() === 'done') ? 'Done' : 'Not Done');

                openModal(updateModal);
            } catch (err) {
                console.error('Update modal error:', err);
                alert('Gagal membuka form update: ' + (err.message || 'Periksa koneksi Anda.'));
            } finally {
                btn.disabled = false;
                btn.textContent = origText;
            }
        });
    });

    if (updateForm) {
        updateForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = updateForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Menyimpan...';
            }

            const formData = new FormData(updateForm);
            try {
                const postUrl = new URL('api/alerts.php?action=update_progress', window.location.href).href;
                const res = await fetch(postUrl, {
                    method: 'POST',
                    body: formData
                });
                if (!res.ok) {
                    throw new Error(`Server merespons status ${res.status}`);
                }

                const result = await res.json();
                if (result.success) {
                    closeModal(updateModal);
                    showToast('Progress tugas berhasil diperbarui.');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    alert(result.error || 'Gagal menyimpan perubahan.');
                }
            } catch (err) {
                console.error('Update submit error:', err);
                alert('Terjadi kesalahan saat menyimpan: ' + (err.message || 'Silakan coba lagi.'));
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Simpan Perubahan';
                }
            }
        });
    }

    if (createForm) {
        createForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = createForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Menambahkan...';

            const formData = new FormData(createForm);
            try {
                const res = await fetch('api/alerts.php?action=create', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();
                if (result.success) {
                    closeModal(createModal);
                    showToast('Alert STR baru berhasil ditambahkan.');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    alert(result.error || 'Gagal menambahkan alert.');
                }
            } catch (err) {
                console.error(err);
                alert('Terjadi kesalahan saat menambahkan.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Tambah Alert';
            }
        });
    }

    // Bulk selection & bulk update logic
    function updateBulkState() {
        const itemCheckboxes = document.querySelectorAll('.alert-check-item');
        const checkedItems = document.querySelectorAll('.alert-check-item:checked');
        const count = checkedItems.length;

        const bulkActionRow = document.getElementById('bulkActionRow');
        if (bulkActionRow) {
            bulkActionRow.style.display = count > 0 ? '' : 'none';
        }

        const bulkBtnText = document.getElementById('bulkBtnText');
        if (bulkBtnText) {
            bulkBtnText.textContent = count > 0 ? `Update Item Terpilih (${count})` : 'Update Item Terpilih';
        }

        if (bulkSelectedBadge) bulkSelectedBadge.textContent = count;
        if (bulkSelectedCountText) bulkSelectedCountText.textContent = count;

        if (bulkActionBar) {
            bulkActionBar.style.display = count > 0 ? 'flex' : 'none';
        }

        if (checkAllAlerts && itemCheckboxes.length > 0) {
            checkAllAlerts.checked = count === itemCheckboxes.length;
            checkAllAlerts.indeterminate = count > 0 && count < itemCheckboxes.length;
        }
    }

    if (checkAllAlerts) {
        checkAllAlerts.addEventListener('change', () => {
            const isChecked = checkAllAlerts.checked;
            document.querySelectorAll('.alert-check-item').forEach(cb => {
                cb.checked = isChecked;
            });
            updateBulkState();
        });
    }

    document.querySelectorAll('.alert-check-item').forEach(cb => {
        cb.addEventListener('change', () => {
            updateBulkState();
        });
    });

    const btnCancelBulk = document.getElementById('btnCancelBulkSelect');
    if (btnCancelBulk) {
        btnCancelBulk.addEventListener('click', () => {
            document.querySelectorAll('.alert-check-item').forEach(cb => cb.checked = false);
            if (checkAllAlerts) checkAllAlerts.checked = false;
            updateBulkState();
        });
    }

    const btnOpenBulk = document.getElementById('btnOpenBulkModal');
    if (btnOpenBulk) {
        btnOpenBulk.addEventListener('click', () => {
            const checkedCount = document.querySelectorAll('.alert-check-item:checked').length;
            if (checkedCount === 0) {
                showToast('Silakan pilih minimal satu alert terlebih dahulu dengan mencentang kotak pada tabel.');
                return;
            }
            openModal(bulkModal);
            const inputDisposisi = document.getElementById('bulk_disposisi_rac');
            const inputTgl = document.getElementById('bulk_tgl_tindak_lanjut');
            const inputStatus = document.getElementById('bulk_status_tl');
            if (inputDisposisi) inputDisposisi.value = '';
            if (inputTgl) inputTgl.value = '';
            if (inputStatus) inputStatus.value = '';
            if (inputDisposisi) inputDisposisi.focus();
        });
    }

    if (bulkForm) {
        bulkForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const checkedBoxes = document.querySelectorAll('.alert-check-item:checked');
            const ids = Array.from(checkedBoxes).map(cb => cb.value);

            if (ids.length === 0) {
                alert('Tidak ada alert yang dipilih.');
                return;
            }

            const disposisiRac = document.getElementById('bulk_disposisi_rac').value.trim();
            const tglTindakLanjut = document.getElementById('bulk_tgl_tindak_lanjut').value.trim();
            const statusTl = document.getElementById('bulk_status_tl').value;

            if (!disposisiRac) {
                alert('Disposisi RAC wajib diisi.');
                return;
            }

            const submitBtn = bulkForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Menerapkan...';
            }

            const formData = new FormData();
            formData.append('ids', JSON.stringify(ids));
            formData.append('disposisi_rac', disposisiRac);
            if (tglTindakLanjut) formData.append('tgl_tindak_lanjut', tglTindakLanjut);
            if (statusTl) formData.append('status_tl', statusTl);

            try {
                const res = await fetch('api/alerts.php?action=bulk_update', {
                    method: 'POST',
                    body: formData
                });
                if (!res.ok) {
                    throw new Error(`Server merespons status ${res.status}`);
                }

                const result = await res.json();
                if (result.success) {
                    closeModal(bulkModal);
                    showToast(result.message || 'Disposisi serentak berhasil disimpan.');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    alert(result.error || 'Gagal menyimpan disposisi serentak.');
                }
            } catch (err) {
                console.error('Bulk update error:', err);
                alert('Terjadi kesalahan saat menyimpan disposisi serentak: ' + (err.message || 'Silakan coba lagi.'));
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Terapkan Disposisi Serentak';
                }
            }
        });
    }
});

function openResetModal() {
    const modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'flex';
}

function closeResetModal() {
    const modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'none';
}

async function executeResetAlerts() {
    const btn = document.getElementById('btnConfirmReset');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Menghapus...';
    }

    try {
        const response = await fetch('api/alerts.php?action=clear', { method: 'POST' });
        const result = await response.json();
        if (!result.success) {
            alert(result.error || result.message || 'Gagal mereset data alert.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat mereset seluruh data alert.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Ya, Hapus Semua';
        }
    }
}

async function deleteSelectedAlerts() {
    const checked = Array.from(document.querySelectorAll('.alert-check-item:checked')).map(cb => cb.value);
    if (!checked.length) {
        alert('Pilih setidaknya satu alert yang ingin dihapus.');
        return;
    }

    if (!confirm('Apakah Anda yakin ingin menghapus ' + checked.length + ' alert terpilih? Data akan dihapus secara permanen.')) {
        return;
    }

    try {
        const response = await fetch('api/alerts.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: checked })
        });
        const result = await response.json();
        if (!result.success) {
            alert(result.error || result.message || 'Gagal menghapus data terpilih.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat menghapus data terpilih.');
    }
}

async function deleteSingleAlert(id) {
    if (!id) return;
    if (!confirm('Apakah Anda yakin ingin menghapus data alert ini secara permanen?')) {
        return;
    }

    try {
        const response = await fetch('api/alerts.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: [id] })
        });
        const result = await response.json();
        if (!result.success) {
            alert(result.error || result.message || 'Gagal menghapus data alert.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat menghapus data.');
    }
}

