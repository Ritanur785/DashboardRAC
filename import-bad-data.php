<?php
declare(strict_types=1);

$pageTitle = 'Import Data Bad Data';

require_once __DIR__ . '/config/database.php';

$pdo = getDbConnection();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <style>
        .import-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
        }

        .import-title h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }

        .import-title p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .import-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .import-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-radius: 6px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            white-space: nowrap;
        }

        .import-btn-template {
            background: #ffffff;
            color: #334155;
            border: 1px solid #dbe3ed;
        }

        .import-btn-template:hover {
            background: #f8fafc;
        }

        .import-btn-page {
            background: #087df5;
            color: #ffffff;
            border: 1px solid #087df5;
        }

        .import-btn-page:hover {
            background: #066bd2;
        }

        .import-info {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 19px;
            margin-bottom: 24px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            color: #1e40af;
            font-size: 13px;
            line-height: 1.6;
        }

        .import-info-icon {
            width: 22px;
            height: 22px;
            flex: 0 0 22px;
            border: 2px solid #2563eb;
            border-radius: 50%;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            margin-top: 1px;
        }

        .import-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .import-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        .import-card h2 {
            margin: 0 0 8px;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        .import-card-description {
            margin: 0 0 18px;
            font-size: 13px;
            line-height: 1.5;
            color: #64748b;
        }

        .file-drop-zone {
            width: 100%;
            min-height: 120px;
            border: 2px dashed #cbd5e1;
            border-radius: 7px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
            box-sizing: border-box;
            padding: 20px;
        }

        .file-drop-zone:hover,
        .file-drop-zone.dragover {
            border-color: #2563eb;
            background: #f0f7ff;
        }

        .file-drop-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .file-drop-main {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
        }

        .file-drop-size {
            font-size: 12px;
            color: #64748b;
        }

        .selected-file {
            margin-top: 12px;
            padding: 10px 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            font-size: 13px;
            color: #1e40af;
            display: none;
            word-break: break-all;
        }

        .import-main-btn {
            width: 100%;
            height: 42px;
            margin-top: auto;
            padding-top: 10px;
            border: 0;
            border-radius: 6px;
            background: #087df5;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .import-main-btn:hover {
            background: #066bd2;
        }

        .import-main-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        .paste-textarea {
            width: 100%;
            height: 140px;
            resize: vertical;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            line-height: 1.5;
            color: #1e293b;
            outline: none;
            margin-bottom: 16px;
        }

        .paste-textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.12);
        }

        .supported-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px 24px;
        }

        .supported-title {
            margin: 0 0 12px;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .supported-content {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px 16px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            line-height: 1.6;
            color: #334155;
            overflow-x: auto;
            white-space: normal;
        }

        .import-toast {
            position: fixed;
            right: 25px;
            bottom: 25px;
            min-width: 280px;
            max-width: 440px;
            padding: 14px 18px;
            border-radius: 8px;
            color: #ffffff;
            font-size: 13px;
            line-height: 1.5;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
            z-index: 99999;
            display: none;
        }

        .import-toast.success {
            background: #15803d;
        }

        .import-toast.error {
            background: #b91c1c;
        }

        @media (max-width: 900px) {
            .import-methods {
                grid-template-columns: 1fr;
            }

            .import-header {
                flex-direction: column;
            }

            .import-actions {
                width: 100%;
            }
        }
    </style>

    <div class="import-page">
        <div class="import-header">
            <div class="import-title">
                <h1>Import Data Bad Data</h1>
                <p>Unggah berkas Excel (.xlsx), CSV, atau tempel data teks dengan pemisah Pipe (|), Koma (,), atau Tab</p>
            </div>
            <div class="import-actions">
                <a href="template_import_bad_data.csv" class="import-btn import-btn-template" download>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Unduh Template CSV
                </a>
                <a href="bad-data.php" class="import-btn import-btn-page">
                    Ke Halaman Bad Data
                </a>
            </div>
        </div>

        <div class="import-info">
            <div class="import-info-icon">i</div>
            <div>
                <strong>Ketentuan Pembaruan Otomatis:</strong> Data yang diimpor akan dicocokkan berdasarkan
                <strong>Branch Office</strong>. Jika data cabang sudah ada sebelumnya, baris tersebut akan diperbarui
                dengan data periode terkini sehingga rekapan Bad Data selalu menggunakan data hasil import terakhir.
            </div>
        </div>

        <div class="import-methods">
            <div class="import-card">
                <h2>Metode 1: Unggah Berkas (Excel / CSV)</h2>
                <p class="import-card-description">
                    Mendukung berkas <strong>.xlsx</strong>, <strong>.csv</strong> (pemisah Pipe, Koma, atau Titik Koma), dan <strong>.txt</strong>.
                </p>

                <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,.txt" style="display:none;">

                <div class="file-drop-zone" id="fileDropZone">
                    <div class="file-drop-content">
                        <div class="file-drop-main">Klik untuk memilih berkas atau seret berkas ke sini</div>
                        <div class="file-drop-size">Maksimal 15MB per unggahan</div>
                    </div>
                </div>

                <div class="selected-file" id="selectedFile"></div>

                <button type="button" class="import-main-btn" id="btnImportFile" style="margin-top:16px;">
                    Mulai Import Berkas
                </button>
            </div>

            <div class="import-card">
                <h2>Metode 2: Salin & Tempel Teks</h2>
                <p class="import-card-description">
                    Salin baris header dan data dari Excel atau format Pipe teks Anda, lalu tempel pada kolom berikut:
                </p>

                <textarea
                    id="rawData"
                    class="paste-textarea"
                    placeholder='"NO"|"BRANCH OFFICE"|"BAD DATA (26 SEP 2026)"|"TOTAL CIF"|"TOTAL"|"REGULER"|"KERJASAMA"|"%BAD DATA"|"BAD DATA (27 SEP 2026)"|"TOTAL CIF"|"TOTAL"|"REGULER"|"KERJASAMA"|"%BAD DATA"|"PERBAIKAN BAD DATA"|"BAD DATA BARU"|"KETERANGAN"&#10;"1"|"KC JAKARTA KOTA"|"120"|"2500"|"2500"|"2300"|"200"|"4.8%"|"110"|"2510"|"2510"|"2310"|"200"|"4.38%"|"15"|"5"|"Tindak lanjut selesai"'
                ></textarea>

                <button type="button" class="import-main-btn" id="btnImportText">
                    Import dari Teks yang Ditempel
                </button>
            </div>
        </div>

        <div class="supported-card">
            <h2 class="supported-title">Format Kolom yang Didukung (17 Kolom):</h2>
            <div class="supported-content" style="font-weight:600;">
                NO | BRANCH OFFICE | BAD DATA | TOTAL CIF | TOTAL | REGULER | KERJASAMA | %BAD DATA | BAD DATA | TOTAL CIF | TOTAL | REGULER | KERJASAMA | %BAD DATA | PERBAIKAN BAD DATA | BAD DATA BARU | KETERANGAN
            </div>
            <div style="margin-top:12px;font-size:12.5px;color:#475569;line-height:1.7;">
                <strong>Rincian Format Kolom:</strong><br>
                1. <strong>Identitas:</strong> <code>NO</code> | <code>BRANCH OFFICE</code><br>
                2. <strong>Periode Awal (cth: 26 Sep 2026):</strong> <code>BAD DATA</code> | <code>TOTAL CIF</code> | <code>TOTAL</code> | <code>REGULER</code> | <code>KERJASAMA</code> | <code>%BAD DATA</code><br>
                3. <strong>Periode Pembanding (cth: 27 Sep 2026):</strong> <code>BAD DATA</code> | <code>TOTAL CIF</code> | <code>TOTAL</code> | <code>REGULER</code> | <code>KERJASAMA</code> | <code>%BAD DATA</code><br>
                4. <strong>Mutasi & Keterangan:</strong> <code>PERBAIKAN BAD DATA</code> | <code>BAD DATA BARU</code> | <code>KETERANGAN</code>
            </div>
        </div>
    </div>

    <div id="importToast" class="import-toast"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const fileInput = document.getElementById('fileInput');
            const fileDropZone = document.getElementById('fileDropZone');
            const selectedFile = document.getElementById('selectedFile');
            const btnImportFile = document.getElementById('btnImportFile');
            const btnImportText = document.getElementById('btnImportText');
            const rawData = document.getElementById('rawData');
            const toast = document.getElementById('importToast');

            function showToast(message, type = 'success') {
                toast.textContent = message;
                toast.className = 'import-toast ' + type;
                toast.style.display = 'block';

                setTimeout(function () {
                    toast.style.display = 'none';
                }, 4000);
            }

            fileDropZone.addEventListener('click', function () {
                fileInput.click();
            });

            fileInput.addEventListener('change', function () {
                if (!fileInput.files || !fileInput.files.length) {
                    return;
                }
                showSelectedFile(fileInput.files[0]);
            });

            function showSelectedFile(file) {
                const maxSize = 15 * 1024 * 1024;
                if (file.size > maxSize) {
                    showToast('Ukuran berkas maksimal 15MB.', 'error');
                    fileInput.value = '';
                    selectedFile.style.display = 'none';
                    return;
                }

                selectedFile.textContent = 'Berkas dipilih: ' + file.name;
                selectedFile.style.display = 'block';
            }

            fileDropZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                fileDropZone.classList.add('dragover');
            });

            fileDropZone.addEventListener('dragleave', function () {
                fileDropZone.classList.remove('dragover');
            });

            fileDropZone.addEventListener('drop', function (e) {
                e.preventDefault();
                fileDropZone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (!files || !files.length) {
                    return;
                }
                fileInput.files = files;
                showSelectedFile(files[0]);
            });

            btnImportFile.addEventListener('click', async function () {
                if (!fileInput.files || !fileInput.files.length) {
                    showToast('Silakan pilih berkas terlebih dahulu.', 'error');
                    return;
                }

                const file = fileInput.files[0];
                const formData = new FormData();
                formData.append('file', file);

                btnImportFile.disabled = true;
                btnImportFile.textContent = 'Sedang memproses...';

                try {
                    const response = await fetch('api/bad-data.php?action=import', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();
                    if (!result.success) {
                        throw new Error(result.message || 'Import gagal.');
                    }

                    showToast(result.message || 'Import berhasil.', 'success');
                    setTimeout(function () {
                        window.location.href = 'bad-data.php';
                    }, 1200);
                } catch (error) {
                    showToast(error.message || 'Terjadi kesalahan saat import.', 'error');
                } finally {
                    btnImportFile.disabled = false;
                    btnImportFile.textContent = 'Mulai Import Berkas';
                }
            });

            btnImportText.addEventListener('click', async function () {
                const data = rawData.value.trim();
                if (!data) {
                    showToast('Silakan tempel data terlebih dahulu.', 'error');
                    return;
                }

                btnImportText.disabled = true;
                btnImportText.textContent = 'Sedang memproses...';

                try {
                    const formData = new FormData();
                    formData.append('raw_data', data);

                    const response = await fetch('api/bad-data.php?action=import', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();
                    if (!result.success) {
                        throw new Error(result.message || 'Import gagal.');
                    }

                    showToast(result.message || 'Import berhasil.', 'success');
                    setTimeout(function () {
                        window.location.href = 'bad-data.php';
                    }, 1200);
                } catch (error) {
                    showToast(error.message || 'Terjadi kesalahan saat import.', 'error');
                } finally {
                    btnImportText.disabled = false;
                    btnImportText.textContent = 'Import dari Teks yang Ditempel';
                }
            });
        });
    </script>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
