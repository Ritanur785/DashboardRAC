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
                    href="pengkinian_data.php"
                    class="nav-link <?= $currentScript === 'pengkinian_data.php' ? 'active' : '' ?>"
                >
                    Pengkinian Data
                </a>
            </li>

            <li>
                <a
                    href="uji-petik.php"
                    class="nav-link <?= $currentScript === 'uji-petik.php' ? 'active' : '' ?>"
                >
                    Uji Petik
                </a>
            </li>

            <li>
                <a
                    href="nilai-maturitas.php"
                    class="nav-link <?= $currentScript === 'nilai-maturitas.php' ? 'active' : '' ?>"
                >
                    Nilai Maturitas
                </a>
            </li>

            <li>
                <a
                    href="penilaian-resiko.php"
                    class="nav-link <?= $currentScript === 'penilaian-resiko.php' ? 'active' : '' ?>"
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

        </ul>
    </nav>

</aside>