<?php
declare(strict_types=1);

$pageTitle = 'Batch Import Folder & ZIP';

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

        .import-btn-outline {
            background: #ffffff;
            color: #334155;
            border: 1px solid #dbe3ed;
        }

        .import-btn-outline:hover {
            background: #f8fafc;
        }

        .import-btn-primary {
            background: #0284c7;
            color: #ffffff;
            border: 1px solid #0284c7;
        }

        .import-btn-primary:hover {
            background: #0369a1;
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

        /* TABS */
        .batch-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0;
        }

        .batch-tab-btn {
            background: none;
            border: none;
            outline: none;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: color 0.2s, border-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .batch-tab-btn.active {
            color: #0284c7;
            border-bottom-color: #0284c7;
        }

        .batch-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .file-drop-zone {
            width: 100%;
            min-height: 140px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
            box-sizing: border-box;
            padding: 24px;
        }

        .file-drop-zone.dragover {
            border-color: #0284c7;
            background: #eff6ff;
        }

        .file-drop-main {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .file-drop-size {
            font-size: 12px;
            color: #64748b;
        }

        .batch-file-list {
            margin-top: 16px;
            max-height: 180px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            display: none;
        }

        .batch-file-item {
            padding: 8px 14px;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
        }

        .batch-file-item:last-child {
            border-bottom: none;
        }

        .batch-file-item-name {
            font-weight: 500;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .batch-file-item-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
        }

        .btn-start-batch {
            width: 100%;
            padding: 12px 20px;
            background: #0284c7;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 18px;
            transition: background 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-start-batch:hover:not(:disabled) {
            background: #0369a1;
        }

        .btn-start-batch:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        /* PROGRESS & LOGS */
        .batch-progress-card {
            display: none;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
        }

        .progress-bar-bg {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: #0284c7;
            transition: width 0.3s ease;
        }

        .terminal-log {
            background: #0f172a;
            color: #f8fafc;
            border-radius: 6px;
            padding: 12px 16px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            line-height: 1.6;
            max-height: 140px;
            overflow-y: auto;
        }

        /* RESULTS */
        .batch-results-card {
            display: none;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .results-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .result-metric {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px;
            text-align: center;
        }

        .result-metric-val {
            font-size: 22px;
            font-weight: 700;
            color: #0284c7;
        }

        .result-metric-lbl {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .result-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 14px;
        }

        .result-table th {
            background: #f1f5f9;
            color: #334155;
            padding: 10px 12px;
            font-weight: 600;
            text-align: left;
            border-bottom: 1px solid #cbd5e1;
        }

        .result-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        .status-pill {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-pill.success {
            background: #dcfce7;
            color: #15803d;
        }

        .status-pill.partial {
            background: #fef3c7;
            color: #b45309;
        }
    </style>

    <div class="import-header">
        <div class="import-title">
            <h1>Batch Import Folder / ZIP Excel</h1>
            <p>Unggah satu berkas ZIP atau pilih folder komputer yang berisi berkas Excel seluruh Kanwil untuk diimpor sekaligus ke 5 modul utama.</p>
        </div>
        <div class="import-actions">
            <a href="bad-data.php" class="import-btn import-btn-outline">Bad Data</a>
            <a href="Pengkinian_data.php" class="import-btn import-btn-outline">Pengkinian Data</a>
            <a href="uji-petik.php" class="import-btn import-btn-outline">Uji Petik</a>
            <a href="nilai-maturitas.php" class="import-btn import-btn-outline">Nilai Maturitas</a>
            <a href="penilaian-resiko.php" class="import-btn import-btn-outline">Penilaian Resiko</a>
        </div>
    </div>

    <div class="import-info">
        <div class="import-info-icon">i</div>
        <div>
            <strong>Otomatis 5 Modul Sekaligus:</strong>
            Sistem cerdas akan otomatis membedah tiap lembar kerja (*worksheet*) di dalam masing-masing berkas Excel Kanwil:
            <strong>Bad Data</strong>, <strong>Pengkinian Data</strong>, <strong>Uji Petik</strong>, <strong>Nilai Maturitas</strong>, dan <strong>Penilaian Resiko</strong>.
            Catatan kaki/footnote otomatis dibersihkan, alias nama kantor cabang (seperti BO Pringsewu & KC Pringsewu) disatukan, dan rating diselaraskan.
        </div>
    </div>

    <!-- TABS -->
    <div class="batch-tabs">
        <button type="button" class="batch-tab-btn active" id="tabBtnZip" onclick="switchBatchMode('zip')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
            Metode 1: Unggah Arsip ZIP (.zip)
        </button>
        <button type="button" class="batch-tab-btn" id="tabBtnFolder" onclick="switchBatchMode('folder')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
            </svg>
            Metode 2: Pilih Folder Komputer
        </button>
        <button type="button" class="batch-tab-btn" id="tabBtnMulti" onclick="switchBatchMode('multi')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Metode 3: Pilih Banyak Berkas (Ctrl+A)
        </button>
    </div>

    <!-- MAIN UPLOAD CARD -->
    <div class="batch-card" id="batchUploadCard">
        <!-- Input ZIP -->
        <input type="file" id="inputZip" accept=".zip" style="display:none;">

        <!-- Input Folder (HTML5 webkitdirectory) -->
        <input type="file" id="inputFolder" webkitdirectory directory multiple style="display:none;">

        <!-- Input Multi Excel -->
        <input type="file" id="inputMulti" accept=".xlsx,.xls" multiple style="display:none;">

        <div class="file-drop-zone" id="batchDropZone">
            <div id="dropZoneContent">
                <div class="file-drop-main" id="dropZoneTitle">Klik untuk memilih berkas ZIP folder atau seret ke sini</div>
                <div class="file-drop-size" id="dropZoneSub">Contoh: Moneva Update 24 September.zip (Maksimal 64MB)</div>
            </div>
        </div>

        <div class="batch-file-list" id="batchFileList"></div>

        <button type="button" class="btn-start-batch" id="btnStartBatch" disabled onclick="executeBatchImport()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="5 3 19 12 5 21 5 3"></polygon>
            </svg>
            Mulai Sinkronisasi Seluruh Berkas Kanwil
        </button>
    </div>

    <!-- PROGRESS CARD -->
    <div class="batch-progress-card" id="batchProgressCard">
        <div class="progress-header">
            <span id="progressStatusText">Sedang mempersiapkan impor...</span>
            <span id="progressPercentText">0%</span>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progressBarFill"></div>
        </div>
        <div class="terminal-log" id="terminalLog">
            <div>[SISTEM] Menghubungkan ke API Batch Import...</div>
        </div>
    </div>

    <!-- RESULTS CARD -->
    <div class="batch-results-card" id="batchResultsCard">
        <h3 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#0f172a;">Ringkasan Hasil Import Batch</h3>

        <div class="results-summary-grid">
            <div class="result-metric">
                <div class="result-metric-val" id="resTotalFiles">0</div>
                <div class="result-metric-lbl">Berkas Diproses</div>
            </div>
            <div class="result-metric">
                <div class="result-metric-val" id="resBadData">0</div>
                <div class="result-metric-lbl">Baris Bad Data</div>
            </div>
            <div class="result-metric">
                <div class="result-metric-val" id="resPengkinian">0</div>
                <div class="result-metric-lbl">Pengkinian Data</div>
            </div>
            <div class="result-metric">
                <div class="result-metric-val" id="resUjiPetik">0</div>
                <div class="result-metric-lbl">Baris Uji Petik</div>
            </div>
            <div class="result-metric">
                <div class="result-metric-val" id="resMaturitas">0</div>
                <div class="result-metric-lbl">Nilai Maturitas</div>
            </div>
            <div class="result-metric">
                <div class="result-metric-val" id="resResiko">0</div>
                <div class="result-metric-lbl">Penilaian Resiko</div>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="result-table">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>NAMA BERKAS EXCEL</th>
                        <th>REGIONAL OFFICE</th>
                        <th style="text-align:center;">BAD DATA</th>
                        <th style="text-align:center;">PENGKINIAN</th>
                        <th style="text-align:center;">UJI PETIK</th>
                        <th style="text-align:center;">MATURITAS</th>
                        <th style="text-align:center;">RISIKO</th>
                        <th style="text-align:center;">STATUS</th>
                    </tr>
                </thead>
                <tbody id="resultTableBody"></tbody>
            </table>
        </div>
    </div>
</main>

<script>
let currentMode = 'zip'; // 'zip' | 'folder' | 'multi'
let selectedZipFile = null;
let selectedExcelFiles = [];

function switchBatchMode(mode) {
    currentMode = mode;
    document.querySelectorAll('.batch-tab-btn').forEach(btn => btn.classList.remove('active'));
    
    const title = document.getElementById('dropZoneTitle');
    const sub = document.getElementById('dropZoneSub');
    resetSelection();

    if (mode === 'zip') {
        document.getElementById('tabBtnZip').classList.add('active');
        title.textContent = 'Klik untuk memilih berkas ZIP folder atau seret ke sini';
        sub.textContent = 'Contoh: Moneva Update 24 September.zip (Maksimal 64MB)';
    } else if (mode === 'folder') {
        document.getElementById('tabBtnFolder').classList.add('active');
        title.textContent = 'Klik untuk memilih FOLDER komputer yang berisi berkas Excel';
        sub.textContent = 'Pilih folder di laptop Anda, seluruh berkas .xlsx di dalamnya akan otomatis terbaca.';
    } else if (mode === 'multi') {
        document.getElementById('tabBtnMulti').classList.add('active');
        title.textContent = 'Klik atau seret BANYAK BERKAS Excel (.xlsx) sekaligus ke sini';
        sub.textContent = 'Tekan Ctrl + A pada folder untuk memilih semua berkas Excel lalu seret ke sini.';
    }
}

function resetSelection() {
    selectedZipFile = null;
    selectedExcelFiles = [];
    document.getElementById('inputZip').value = '';
    document.getElementById('inputFolder').value = '';
    document.getElementById('inputMulti').value = '';
    document.getElementById('batchFileList').style.display = 'none';
    document.getElementById('batchFileList').innerHTML = '';
    document.getElementById('btnStartBatch').disabled = true;
}

document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('batchDropZone');
    const inputZip = document.getElementById('inputZip');
    const inputFolder = document.getElementById('inputFolder');
    const inputMulti = document.getElementById('inputMulti');

    dropZone.addEventListener('click', function () {
        if (currentMode === 'zip') inputZip.click();
        else if (currentMode === 'folder') inputFolder.click();
        else if (currentMode === 'multi') inputMulti.click();
    });

    inputZip.addEventListener('change', function () {
        if (this.files && this.files.length) {
            handleZipSelected(this.files[0]);
        }
    });

    inputFolder.addEventListener('change', function () {
        if (this.files && this.files.length) {
            handleExcelFilesSelected(Array.from(this.files));
        }
    });

    inputMulti.addEventListener('change', function () {
        if (this.files && this.files.length) {
            handleExcelFilesSelected(Array.from(this.files));
        }
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', function () {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = Array.from(e.dataTransfer.files || []);
        if (!files.length) return;

        if (files.length === 1 && files[0].name.toLowerCase().endsWith('.zip')) {
            switchBatchMode('zip');
            handleZipSelected(files[0]);
        } else {
            const excelFiles = files.filter(f => {
                const ext = f.name.toLowerCase();
                return (ext.endsWith('.xlsx') || ext.endsWith('.xls')) && !f.name.startsWith('~$');
            });
            if (excelFiles.length > 0) {
                switchBatchMode('multi');
                handleExcelFilesSelected(excelFiles);
            } else {
                alert('Silakan pilih berkas ZIP atau berkas Excel (.xlsx).');
            }
        }
    });
});

function handleZipSelected(file) {
    selectedZipFile = file;
    selectedExcelFiles = [];
    const fileList = document.getElementById('batchFileList');
    fileList.style.display = 'block';
    fileList.innerHTML = `
        <div class="batch-file-item">
            <span class="batch-file-item-name">📦 ${escapeHtml(file.name)}</span>
            <span class="batch-file-item-badge">${(file.size / (1024 * 1024)).toFixed(2)} MB</span>
        </div>
    `;
    document.getElementById('btnStartBatch').disabled = false;
}

function handleExcelFilesSelected(files) {
    selectedZipFile = null;
    selectedExcelFiles = files.filter(f => {
        const ext = f.name.toLowerCase();
        return (ext.endsWith('.xlsx') || ext.endsWith('.xls')) && !f.name.startsWith('~$');
    });

    if (selectedExcelFiles.length === 0) {
        alert('Tidak ditemukan berkas Excel (.xlsx) yang valid.');
        resetSelection();
        return;
    }

    const fileList = document.getElementById('batchFileList');
    fileList.style.display = 'block';
    let html = `<div style="padding:8px 14px;background:#f8fafc;font-size:12px;font-weight:600;color:#475569;border-bottom:1px solid #e2e8f0;">Ditemukan ${selectedExcelFiles.length} Berkas Excel:</div>`;
    selectedExcelFiles.forEach(f => {
        html += `
            <div class="batch-file-item">
                <span class="batch-file-item-name">📊 ${escapeHtml(f.name)}</span>
                <span class="batch-file-item-badge">${(f.size / 1024).toFixed(1)} KB</span>
            </div>
        `;
    });
    fileList.innerHTML = html;
    document.getElementById('btnStartBatch').disabled = false;
}

async function executeBatchImport() {
    const btn = document.getElementById('btnStartBatch');
    btn.disabled = true;

    const progressCard = document.getElementById('batchProgressCard');
    const progressBar = document.getElementById('progressBarFill');
    const progressStatus = document.getElementById('progressStatusText');
    const progressPercent = document.getElementById('progressPercentText');
    const logBox = document.getElementById('terminalLog');
    const resultsCard = document.getElementById('batchResultsCard');

    progressCard.style.display = 'block';
    resultsCard.style.display = 'none';
    progressBar.style.width = '20%';
    progressPercent.textContent = '20%';
    progressStatus.textContent = 'Mengunggah berkas ke server...';

    logBox.innerHTML = `<div>[SISTEM] Mulai mengirim data ke API...</div>`;

    const formData = new FormData();
    if (selectedZipFile) {
        formData.append('zip_file', selectedZipFile);
        appendLog(`[INFO] Mengirim arsip ZIP: ${selectedZipFile.name} (${(selectedZipFile.size / 1024).toFixed(0)} KB)`);
    } else if (selectedExcelFiles.length > 0) {
        selectedExcelFiles.forEach(file => {
            formData.append('excel_files[]', file);
        });
        appendLog(`[INFO] Mengirim ${selectedExcelFiles.length} berkas Excel ke server...`);
    } else {
        alert('Silakan pilih berkas terlebih dahulu.');
        btn.disabled = false;
        return;
    }

    progressBar.style.width = '50%';
    progressPercent.textContent = '50%';
    progressStatus.textContent = 'Server sedang memproses seluruh berkas dan sheet...';
    appendLog('[PROSES] Server sedang mengekstrak lembar kerja dan memperbarui 5 tabel...');

    try {
        const response = await fetch('api/batch-import.php', {
            method: 'POST',
            body: formData
        });

        progressBar.style.width = '85%';
        progressPercent.textContent = '85%';

        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Gagal memproses berkas.');
        }

        progressBar.style.width = '100%';
        progressPercent.textContent = '100%';
        progressStatus.textContent = 'Sinkronisasi selesai dengan sukses!';
        appendLog(`[SUKSES] ${result.message}`);
        appendLog(`[HASIL] Total baris diperbarui di seluruh modul: ${result.data.total_rows_saved}`);

        renderResults(result.data);
    } catch (err) {
        progressBar.style.width = '100%';
        progressBar.style.background = '#ef4444';
        progressStatus.textContent = 'Terjadi kesalahan saat memproses.';
        appendLog(`[ERROR] ${err.message}`);
        alert('Gagal impor batch: ' + err.message);
    } finally {
        btn.disabled = false;
    }
}

function appendLog(msg) {
    const logBox = document.getElementById('terminalLog');
    const line = document.createElement('div');
    line.textContent = msg;
    logBox.appendChild(line);
    logBox.scrollTop = logBox.scrollHeight;
}

function renderResults(data) {
    document.getElementById('batchResultsCard').style.display = 'block';
    document.getElementById('resTotalFiles').textContent = data.total_files || 0;
    document.getElementById('resBadData').textContent = data.modules_breakdown.bad_data || 0;
    document.getElementById('resPengkinian').textContent = data.modules_breakdown.pengkinian_data || 0;
    document.getElementById('resUjiPetik').textContent = data.modules_breakdown.uji_petik || 0;
    document.getElementById('resMaturitas').textContent = data.modules_breakdown.nilai_maturitas || 0;
    document.getElementById('resResiko').textContent = data.modules_breakdown.penilaian_resiko || 0;

    const tbody = document.getElementById('resultTableBody');
    tbody.innerHTML = '';

    (data.files || []).forEach((item, idx) => {
        const m = item.modules || {};
        const isSuccess = item.status === 'success';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><strong>${escapeHtml(item.filename)}</strong></td>
            <td>${escapeHtml(item.region || '-')}</td>
            <td style="text-align:center;">${m.bad_data ?? 0}</td>
            <td style="text-align:center;">${m.pengkinian_data ?? 0}</td>
            <td style="text-align:center;">${m.uji_petik ?? 0}</td>
            <td style="text-align:center;">${m.nilai_maturitas ?? 0}</td>
            <td style="text-align:center;">${m.penilaian_resiko ?? 0}</td>
            <td style="text-align:center;">
                <span class="status-pill ${isSuccess ? 'success' : 'partial'}">
                    ${isSuccess ? 'Berhasil' : 'Peringatan'}
                </span>
            </td>
        `;
        tbody.appendChild(tr);
    });

    // Smooth scroll down to results
    document.getElementById('batchResultsCard').scrollIntoView({ behavior: 'smooth' });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
