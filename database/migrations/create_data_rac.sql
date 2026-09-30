CREATE TABLE IF NOT EXISTS `data_rac` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `region` VARCHAR(50) NOT NULL,
    `team` VARCHAR(50) NOT NULL,
    `pn` VARCHAR(50) DEFAULT NULL,
    `nama` VARCHAR(150) NOT NULL,
    `telepon` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_rac_region` (`region`),
    INDEX `idx_rac_team` (`team`),
    INDEX `idx_rac_nama` (`nama`),
    INDEX `idx_rac_pn` (`pn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
