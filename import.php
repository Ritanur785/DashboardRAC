<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pageTitle = 'Import Data Alert STR';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h2 class="page-title">Import Data Alert STR</h2>
            <div class="page-subtitle">Unggah berkas Excel (.xlsx), CSV, atau tempel data teks secara langsung</div>
        </div>
        <div>
            <a href="template_import_str.csv" download class="btn btn-secondary btn-sm" style="display:inline-flex;gap:6px;">
                Unduh Template CSV
            </a>
            <a href="todo-harian.php" class="btn btn-primary btn-sm" style="margin-left:8px;">
                Ke STR Alert
            </a>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;color:#0f172a;">Metode 1: Unggah File (Excel / CSV)</h3>
            <p style="font-size:12px;color:#64748b;margin-bottom:18px;">
                Mendukung file format <code>.xlsx</code> (Microsoft Excel), <code>.csv</code> (Pemisah Pipe <code>|</code>, Comma <code>,</code>, atau Semicolon <code>;</code>), dan <code>.txt</code>.
            </p>

            <form id="fileUploadForm" enctype="multipart/form-data">
                <div style="border:2px dashed #cbd5e1;border-radius:6px;padding:28px 20px;text-align:center;background:#f8fafc;margin-bottom:16px;cursor:pointer;" id="dropArea">
                    <input type="file" id="fileInput" name="file" accept=".xlsx,.csv,.txt" style="display:none;" required>
                    <div id="fileLabel" style="font-size:13px;color:#334155;font-weight:500;">
                        Klik untuk memilih berkas atau seret berkas ke sini
                    </div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Maksimal 10MB per unggahan</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Mulai Import Berkas</button>
            </form>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;color:#0f172a;">Metode 2: Salin & Tempel Langsung dari Excel</h3>
            <p style="font-size:12px;color:#64748b;margin-bottom:18px;">
                Salin baris header dan data dari Excel atau CSV Anda, lalu tempel langsung pada kolom teks di bawah ini.
            </p>

            <form id="pasteDataForm">
                <div class="form-group" style="margin-bottom:16px;">
                    <textarea name="raw_data" id="raw_data" class="form-control" style="height:140px;font-family:monospace;font-size:11px;line-height:1.4;" placeholder="POSISI|NAMA|CIF|No Rek/ Kartu Kredit|...&#10;2026-03-31|PT SUKA SUKA SAYA P|0|10301005146301|..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Import dari Teks yang Ditempel</button>
            </form>
        </div>
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
        <h4 style="font-size:13px;font-weight:700;margin-bottom:10px;color:#0f172a;">Format Kolom yang Didukung (21 Kolom):</h4>
        <div style="font-size:11px;color:#475569;line-height:1.8;background:#f8fafc;padding:12px;border-radius:4px;border:1px solid #edf2f7;overflow-x:auto;">
            <code>POSISI</code> | <code>NAMA</code> | <code>CIF</code> | <code>"No Rek/ Kartu Kredit"</code> | <code>RESIKO</code> | <code>BRILINK</code> | <code>"STATUS PEKERJA"</code> | <code>"DIGITAL SAVING"</code> | <code>SKENARIO</code> | <code>SCORING</code> | <code>"INFO PARAM"</code> | <code>KATEGORI</code> | <code>"UNIT KERJA"</code> | <code>"KANTOR CABANG"</code> | <code>"KANTOR KANWIL"</code> | <code>"REKOMENDASI UKER"</code> | <code>"REKOMENDASI UKK"</code> | <code>"STATUS UKER"</code> | <code>STATUS</code> | <code>DISPOSISI</code> | <code>"INFO LAINNYA"</code>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropArea = document.getElementById('dropArea');
    const fileInput = document.getElementById('fileInput');
    const fileLabel = document.getElementById('fileLabel');
    const fileForm = document.getElementById('fileUploadForm');
    const pasteForm = document.getElementById('pasteDataForm');

    dropArea.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            fileLabel.textContent = `Berkas dipilih: ${fileInput.files[0].name} (${(fileInput.files[0].size / 1024).toFixed(1)} KB)`;
        }
    });

    dropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropArea.style.borderColor = '#0070f3';
        dropArea.style.backgroundColor = '#eff6ff';
    });

    dropArea.addEventListener('dragleave', () => {
        dropArea.style.borderColor = '#cbd5e1';
        dropArea.style.backgroundColor = '#f8fafc';
    });

    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dropArea.style.borderColor = '#cbd5e1';
        dropArea.style.backgroundColor = '#f8fafc';
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            fileLabel.textContent = `Berkas dipilih: ${fileInput.files[0].name} (${(fileInput.files[0].size / 1024).toFixed(1)} KB)`;
        }
    });

    fileForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!fileInput.files.length) {
            alert('Silakan pilih berkas Excel atau CSV terlebih dahulu.');
            return;
        }

        const submitBtn = fileForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses Berkas...';

        const formData = new FormData(fileForm);
        try {
            const res = await fetch('api/alerts.php?action=import', {
                method: 'POST',
                body: formData
            });
            const result = await res.json();
            if (result.success) {
                alert(result.message || 'Import data berhasil!');
                window.location.href = 'todo-harian.php';
            } else {
                alert(result.error || 'Gagal memproses berkas.');
            }
        } catch (err) {
            console.error(err);
            alert('Terjadi kesalahan saat mengunggah berkas.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Mulai Import Berkas';
        }
    });

    pasteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const rawText = document.getElementById('raw_data').value.trim();
        if (!rawText) {
            alert('Silakan tempel data teks terlebih dahulu.');
            return;
        }

        const submitBtn = pasteForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses Data...';

        const formData = new FormData();
        formData.append('raw_data', rawText);

        try {
            const res = await fetch('api/alerts.php?action=import', {
                method: 'POST',
                body: formData
            });
            const result = await res.json();
            if (result.success) {
                alert(result.message || 'Import data berhasil!');
                window.location.href = 'todo-harian.php';
            } else {
                alert(result.error || 'Gagal memproses data.');
            }
        } catch (err) {
            console.error(err);
            alert('Terjadi kesalahan saat memproses data.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Import dari Teks yang Ditempel';
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
