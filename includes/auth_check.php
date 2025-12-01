<?php
// File: includes/auth_check.php

// Pastikan session sudah start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk memaksa user harus punya role tertentu
function require_role($required_role) {
    // 1. Cek apakah sudah login?
    if (!isset($_SESSION['is_login']) || $_SESSION['is_login'] !== true) {
        header("Location: ../auth/login.php?error=" . urlencode("Silakan login terlebih dahulu."));
        exit;
    }

    // 2. Cek apakah role-nya sesuai?
    if ($_SESSION['role'] !== $required_role) {
        // Kalau dokter coba masuk halaman admin, tendang balik
        echo "<h1>Akses Ditolak!</h1>";
        echo "<p>Anda tidak memiliki izin mengakses halaman ini.</p>";
        echo "<a href='../auth/login.php'>Kembali ke Login</a>";
        exit;
    }
}
?>