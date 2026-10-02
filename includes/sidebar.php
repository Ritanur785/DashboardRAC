<?php
declare(strict_types=1);

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>

<aside class="sidebar">

    <div class="brand-header">
        <h1>RAC Monitoring Dashboard</h1>
        <div class="brand-subtitle">Kantor Pusat</div>
    </div>

    <nav>
        <div class="nav-group-title">
            Menu Utama
        </div>

        <ul class="nav-menu">

            <li>
                <a
                    href="beranda.php"
                    class="nav-link <?= $currentScript === 'beranda.php' ? 'active' : '' ?>"
                >
                    Beranda
                </a>
            </li>

            <li>
                <a
                    href="todo-harian.php"
                    class="nav-link <?= in_array(
                        $currentScript,
                        ['todo-harian.php', 'index.php', ''],
                        true
                    ) ? 'active' : '' ?>"
                >
                    STR Alert
                </a>
            </li>

            <li>
                <a
                    href="pep.php"
                    class="nav-link <?= in_array($currentScript, ['pep.php', 'import-pep.php'], true) ? 'active' : '' ?>"
                >
                    PEP
                </a>
            </li>

            <li>
                <a
                    href="bad-data.php"
                    class="nav-link <?= in_array($currentScript, ['bad-data.php', 'import-bad-data.php'], true) ? 'active' : '' ?>"
                >
                    Bad Data
                </a>
            </li>

            <li>
                <a
                    href="Pengkinian_data.php"
                    class="nav-link <?= in_array(strtolower($currentScript), ['pengkinian_data.php', 'import-pengkinian-data.php'], true) ? 'active' : '' ?>"
                >
                    Pengkinian Data
                </a>
            </li>

            <li>
                <a
                    href="uji-petik.php"
                    class="nav-link <?= in_array($currentScript, ['uji-petik.php', 'import-uji-petik.php'], true) ? 'active' : '' ?>"
                >
                    Uji Petik
                </a>
            </li>

            <li>
                <a
                    href="nilai-maturitas.php"
                    class="nav-link <?= in_array($currentScript, ['nilai-maturitas.php', 'import-nilai-maturitas.php'], true) ? 'active' : '' ?>"
                >
                    Nilai Maturitas
                </a>
            </li>

            <li>
                <a
                    href="penilaian-resiko.php"
                    class="nav-link <?= in_array($currentScript, ['penilaian-resiko.php', 'import-penilaian-resiko.php'], true) ? 'active' : '' ?>"
                >
                    Penilaian Resiko
                </a>
            </li>

            <li>
                <a
                    href="data_rac.php"
                    class="nav-link <?= $currentScript === 'data_rac.php' ? 'active' : '' ?>"
                >
                    Data RAC
                </a>
            </li>

            <li style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;">
                <a
                    href="import-batch.php"
                    class="nav-link <?= $currentScript === 'import-batch.php' ? 'active' : '' ?>"
                    style="display:flex;align-items:center;justify-content:space-between;"
                >
                    <span style="display:flex;align-items:center;gap:6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                        </svg>
                        Import Folder / ZIP
                    </span>
                    <span style="background:#0284c7;color:#ffffff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;text-transform:uppercase;letter-spacing:0.5px;">Batch</span>
                </a>
            </li>

        </ul>
    </nav>


</aside>