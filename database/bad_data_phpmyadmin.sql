-- ==============================================================
-- Script SQL Tabel bad_data untuk phpMyAdmin
-- Database: amlo_dashboard
-- Catatan: Tabel dibuat bersih (tanpa dummy data), 
-- seluruh data akan masuk melalui fitur Import Excel/CSV.
-- ==============================================================

CREATE DATABASE IF NOT EXISTS `amlo_dashboard` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `amlo_dashboard`;

DROP TABLE IF EXISTS `bad_data`;

CREATE TABLE `bad_data` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `no_urut` VARCHAR(50) DEFAULT NULL,
    `branch` VARCHAR(150) NOT NULL,
    `pic_rac` VARCHAR(150) DEFAULT NULL,
    `total_cif` VARCHAR(50) DEFAULT NULL,
    `total` VARCHAR(50) DEFAULT NULL,
    `reguler` VARCHAR(50) DEFAULT NULL,
    `kerjasama` VARCHAR(50) DEFAULT NULL,
    `bad_data_prev` VARCHAR(50) DEFAULT NULL,
    `persentase_prev` VARCHAR(50) DEFAULT NULL,
    `bad_data_curr` VARCHAR(50) DEFAULT NULL,
    `persentase_curr` VARCHAR(50) DEFAULT NULL,
    `persentase` VARCHAR(50) DEFAULT NULL,
    `average` VARCHAR(50) DEFAULT NULL,
    `perbaikan_bad_data` VARCHAR(50) DEFAULT NULL,
    `bad_data_baru` VARCHAR(50) DEFAULT NULL,
    `keterangan` TEXT DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Open',
    `month` VARCHAR(50) DEFAULT NULL,
    `region` VARCHAR(150) DEFAULT NULL,
    `tgl_prev` VARCHAR(50) DEFAULT NULL,
    `tgl_curr` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bad_data_branch` (`branch`),
    INDEX `idx_bad_data_month` (`month`),
    INDEX `idx_bad_data_region` (`region`),
    INDEX `idx_bad_data_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
