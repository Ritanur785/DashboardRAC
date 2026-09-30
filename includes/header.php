<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($pageTitle ?? 'AMLO Dashboard') ?> - Monitoring STR
    </title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

<div class="app-layout">

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">