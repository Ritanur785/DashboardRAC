<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'amlo_dashboard');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $rootPdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $dbDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
        
        ensureSchemaExists($pdo);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die("Database connection failed: " . htmlspecialchars($e->getMessage()));
    }
}

function ensureSchemaExists(PDO $pdo): void {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'str_alerts'");
    if ($tableCheck->rowCount() === 0) {
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        }
    }

    $pepCheck = $pdo->query("SHOW TABLES LIKE 'pep_alerts'");
    if ($pepCheck->rowCount() === 0) {
        $pepMigration = __DIR__ . '/../database/migrations/create_pep_alerts.sql';
        if (file_exists($pepMigration)) {
            $sql = file_get_contents($pepMigration);
            $pdo->exec($sql);
        }
    }

    $badDataCheck = $pdo->query("SHOW TABLES LIKE 'bad_data'");
    if ($badDataCheck->rowCount() === 0) {
        $badDataMigration = __DIR__ . '/../database/migrations/create_bad_data.sql';
        if (file_exists($badDataMigration)) {
            $sql = file_get_contents($badDataMigration);
            $pdo->exec($sql);
        }
    } else {
        $cols = $pdo->query("DESCRIBE bad_data")->fetchAll(PDO::FETCH_COLUMN);
        $newCols = [
            'no_urut'            => "VARCHAR(50) NULL DEFAULT NULL AFTER id",
            'total_cif'          => "VARCHAR(50) NULL DEFAULT NULL",
            'total'              => "VARCHAR(50) NULL DEFAULT NULL",
            'reguler'            => "VARCHAR(50) NULL DEFAULT NULL",
            'kerjasama'          => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_prev'      => "VARCHAR(50) NULL DEFAULT NULL",
            'persentase_prev'    => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_curr'      => "VARCHAR(50) NULL DEFAULT NULL",
            'persentase_curr'    => "VARCHAR(50) NULL DEFAULT NULL",
            'perbaikan_bad_data' => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_baru'      => "VARCHAR(50) NULL DEFAULT NULL",
            'keterangan'         => "TEXT NULL DEFAULT NULL",
            'tgl_prev'           => "VARCHAR(50) NULL DEFAULT NULL",
            'tgl_curr'           => "VARCHAR(50) NULL DEFAULT NULL",
        ];
        foreach ($newCols as $c => $def) {
            if (!in_array($c, $cols, true)) {
                $pdo->exec("ALTER TABLE bad_data ADD COLUMN `{$c}` {$def}");
            }
        }
    }

    $racCheck = $pdo->query("SHOW TABLES LIKE 'data_rac'");
    if ($racCheck->rowCount() === 0) {
        $racMigration = __DIR__ . '/../database/migrations/create_data_rac.sql';
        if (file_exists($racMigration)) {
            $sql = file_get_contents($racMigration);
            $pdo->exec($sql);
        }
    }
}
