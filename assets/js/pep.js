document.addEventListener('DOMContentLoaded', () => {
    const updateModal = document.getElementById('updateModal');
    const bulkModal = document.getElementById('bulkModal');
    const updateForm = document.getElementById('updateProgressForm');
    const bulkForm = document.getElementById('bulkUpdateForm');
    const checkAllAlerts = document.getElementById('checkAllAlerts');
    const bulkActionRow = document.getElementById('bulkActionRow');
    const bulkBtnText = document.getElementById('bulkBtnText');
    const bulkSelectedCountText = document.getElementById('bulkSelectedCountText');
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
            closeModal(bulkModal);
        });
    });

    // Close when clicking modal backdrop
    [updateModal, bulkModal].forEach(modal => {
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeModal(modal);
                }
            });
        }
    });

    // Escape key to close modal
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal(updateModal);
            closeModal(bulkModal);
        }
    });

    // Bulk selection state
    function updateBulkState() {
        const itemCheckboxes = document.querySelectorAll('.alert-check-item');
        const checkedItems = document.querySelectorAll('.alert-check-item:checked');
        const count = checkedItems.length;

        if (bulkActionRow) {
            bulkActionRow.style.display = count > 0 ? '' : 'none';
        }

        if (bulkBtnText) {
            bulkBtnText.textContent = count > 0 ? `Update Item Terpilih (${count})` : 'Update Item Terpilih';
        }

        if (bulkSelectedCountText) {
            bulkSelectedCountText.textContent = count;
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

    const btnOpenBulk = document.getElementById('btnOpenBulkModal');
    if (btnOpenBulk) {
        btnOpenBulk.addEventListener('click', () => {
            const checkedCount = document.querySelectorAll('.alert-check-item:checked').length;
            if (checkedCount === 0) {
                showToast('Silakan pilih minimal satu item PEP terlebih dahulu.');
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

    // Individual update modal
    document.querySelectorAll('.btn-update-progress').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            try {
                const res = await fetch(`api/pep.php?action=get&id=${encodeURIComponent(id)}`);
                const result = await res.json();
                if (result.success && result.data) {
                    const data = result.data;
                    document.getElementById('edit_id').value = data.id;
                    document.getElementById('display_nama').textContent = data.nama_lengkap || '-';
                    document.getElementById('display_cif').textContent = `CIF: ${data.cif || '-'} | NIK: ${data.nik || '-'}`;
                    document.getElementById('edit_disposisi_rac').value = data.disposisi_rac || '';
                    document.getElementById('edit_tgl_tindak_lanjut').value = data.tgl_tindak_lanjut || '';
                    document.getElementById('edit_status_tl').value = (data.status_tl && data.status_tl.toLowerCase() === 'done') ? 'Done' : 'Not Done';

                    openModal(updateModal);
                    document.getElementById('edit_disposisi_rac').focus();
                } else {
                    showToast(result.error || 'Gagal mengambil data PEP.');
                }
            } catch (err) {
                console.error(err);
                showToast('Terjadi kesalahan jaringan.');
            }
        });
    });

    // Individual progress submit
    if (updateForm) {
        updateForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('btnSubmitProgress');
            if (submitBtn) submitBtn.disabled = true;

            const formData = new FormData(updateForm);
            try {
                const res = await fetch('api/pep.php?action=update_progress', {
                    method: 'POST',
                    body: formData,
                });
                const result = await res.json();
                if (result.success) {
                    closeModal(updateModal);
                    showToast(result.message || 'Progress PEP berhasil disimpan.');
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showToast(result.error || 'Gagal menyimpan pembaruan.');
                }
            } catch (err) {
                console.error(err);
                showToast('Terjadi kesalahan jaringan saat menyimpan.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Bulk update submit
    if (bulkForm) {
        bulkForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const checkedBoxes = document.querySelectorAll('.alert-check-item:checked');
            const ids = Array.from(checkedBoxes).map(cb => cb.value);

            if (ids.length === 0) {
                showToast('Tidak ada item PEP yang dipilih.');
                return;
            }

            const submitBtn = document.getElementById('btnSubmitBulk');
            if (submitBtn) submitBtn.disabled = true;

            const formData = new FormData(bulkForm);
            ids.forEach(id => formData.append('ids[]', id));

            try {
                const res = await fetch('api/pep.php?action=bulk_update', {
                    method: 'POST',
                    body: formData,
                });
                const result = await res.json();
                if (result.success) {
                    closeModal(bulkModal);
                    showToast(result.message || 'Disposisi serentak berhasil diterapkan.');
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    showToast(result.error || 'Gagal memperbarui item PEP.');
                }
            } catch (err) {
                console.error(err);
                showToast('Terjadi kesalahan jaringan saat memperbarui serentak.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }
});
