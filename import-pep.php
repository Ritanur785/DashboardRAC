<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pageTitle = 'Import Data PEP';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h2 class="page-title">Import Data PEP (Politically Exposed Persons)</h2>
            <div class="page-subtitle">Unggah berkas Excel (.xlsx), CSV, atau tempel data teks dengan pemisah Pipe (|), Koma, atau Tab</div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="template_import_pep.csv" download class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Unduh Template CSV
            </a>
            <a href="pep.php" class="btn btn-primary btn-sm">
                Ke Halaman PEP
            </a>
        </div>
    </div>

    <!-- Alert info: duplicate handling rule -->
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px;display:flex;align-items:flex-start;gap:12px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
        </svg>
        <div style="font-size:12.5px;color:#1e40af;line-height:1.5;">
            <strong>Ketentuan Pembaruan Otomatis:</strong> Data yang diimpor akan dicocokkan berdasarkan <strong>Posisi Periode</strong> dan <strong>Nomor CIF</strong>. Jika data sudah ada sebelumnya, seluruh kolom master akan diperbarui dengan data terbaru, sementara kolom <strong>Disposisi RAC, Tanggal TL, dan Status TL tetap dipertahankan</strong> agar catatan tindak lanjut yang sudah ada tidak hilang.
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px;">
        <!-- Metode 1: File Upload -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;color:#0f172a;">Metode 1: Unggah File (Excel / CSV)</h3>
            <p style="font-size:12px;color:#64748b;margin-bottom:18px;">
                Mendukung berkas <code>.xlsx</code> (Microsoft Excel), <code>.csv</code> (Pemisah Pipe <code>|</code>, Comma <code>,</code>, Semicolon <code>;</code>), dan <code>.txt</code>.
            </p>

            <form id="fileUploadForm" enctype="multipart/form-data">
                <div style="border:2px dashed #cbd5e1;border-radius:6px;padding:28px 20px;text-align:center;background:#f8fafc;margin-bottom:16px;cursor:pointer;" id="dropArea">
                    <input type="file" id="fileInput" name="file" accept=".xlsx,.csv,.txt" style="display:none;" required>
                    <div id="fileLabel" style="font-size:13px;color:#334155;font-weight:500;">
                        Klik untuk memilih berkas atau seret berkas ke sini
                    </div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Maksimal 15MB per unggahan</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;" id="btnUploadFile">Mulai Import Berkas</button>
            </form>
        </div>

        <!-- Metode 2: Direct Paste -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
            <h3 style="font-size:15px;font-weight:700;margin-bottom:6px;color:#0f172a;">Metode 2: Salin & Tempel dari Excel / Teks</h3>
            <p style="font-size:12px;color:#64748b;margin-bottom:18px;">
                Salin baris header dan data dari Excel atau format Pipe teks Anda, lalu tempel langsung pada kolom di bawah ini:
            </p>

            <form id="pasteDataForm">
                <div class="form-group" style="margin-bottom:16px;">
                    <textarea name="raw_data" id="raw_data" class="form-control" style="height:140px;font-family:monospace;font-size:11px;line-height:1.4;" placeholder="&quot;POSISI|&quot;&quot;NAMA LENGKAP&quot;&quot;|CIF|&quot;&quot;OPEN DATE&quot;&quot;|NIK|...&quot;&#10;&quot;2026-09-10|&quot;&quot;SEPTO KETOPRAK&quot;&quot;|AGTJB05|...&quot;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;" id="btnPasteSubmit">Import dari Teks yang Ditempel</button>
            </form>
        </div>
    </div>

    <!-- Supported Columns Table Guide -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
        <h4 style="font-size:13px;font-weight:700;margin-bottom:10px;color:#0f172a;">Format Kolom yang Didukung (19 Kolom):</h4>
        <div style="font-size:11px;color:#475569;line-height:2.0;background:#f8fafc;padding:14px;border-radius:4px;border:1px solid #edf2f7;overflow-x:auto;">
            <code>POSISI</code> | <code>NAMA LENGKAP</code> | <code>CIF</code> | <code>OPEN DATE</code> | <code>NIK</code> | <code>TEMPAT LAHIR</code> | <code>TANGGAL LAHIR</code> | <code>JABATAN BRI</code> | <code>INSTANSI BRI</code> | <code>KODE UKER</code> | <code>UNIT KERJA</code> | <code>BRANCH</code> | <code>REGION</code> | <code>FLAG PEP BRI (initial)</code> | <code>FLAG PEP BRI (updated)</code> | <code>JABATAN PPATK</code> | <code>INSTANSI PPATK</code> | <code>ANALISA</code> | <code>STATUS</code>
        </div>
    </div>
</main>

<div id="toast" class="toast"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropArea = document.getElementById('dropArea');
    const fileInput = document.getElementById('fileInput');
    const fileLabel = document.getElementById('fileLabel');
    const fileForm = document.getElementById('fileUploadForm');
    const pasteForm = document.getElementById('pasteDataForm');
    const toast = document.getElementById('toast');

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 4000);
    }

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
            showToast('Silakan pilih berkas Excel atau CSV terlebih dahulu.');
            return;
        }

        const submitBtn = document.getElementById('btnUploadFile');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses Berkas...';

        const formData = new FormData(fileForm);
        try {
            const res = await fetch('api/pep.php?action=import', {
                method: 'POST',
                body: formData
            });
            const result = await res.json();
            if (result.success) {
                alert(result.message || 'Import data PEP berhasil!');
                window.location.href = 'pep.php';
            } else {
                alert('Gagal Import: ' + (result.error || 'Format berkas tidak sesuai.'));
            }
        } catch (err) {
            console.error(err);
            alert('Terjadi kesalahan jaringan saat memproses berkas.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Mulai Import Berkas';
        }
    });

    pasteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const rawText = document.getElementById('raw_data').value.trim();
        if (!rawText) {
            showToast('Silakan tempel data teks terlebih dahulu.');
            return;
        }

        const submitBtn = document.getElementById('btnPasteSubmit');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Memproses Data Teks...';

        const formData = new FormData(pasteForm);
        try {
            const res = await fetch('api/pep.php?action=import', {
                method: 'POST',
                body: formData
            });
            const result = await res.json();
            if (result.success) {
                alert(result.message || 'Import data PEP berhasil!');
                window.location.href = 'pep.php';
            } else {
                alert('Gagal Import: ' + (result.error || 'Format teks tidak sesuai.'));
            }
        } catch (err) {
            console.error(err);
            alert('Terjadi kesalahan jaringan saat memproses data.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Import dari Teks yang Ditempel';
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
