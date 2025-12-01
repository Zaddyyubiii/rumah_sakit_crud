<?php
session_start();
require_once '../includes/db.php'; // Pastikan path ini benar mengarah ke db.php

// 1. Ambil data dari Form Login
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Fungsi helper untuk redirect dengan pesan error
function redirect_error($message) {
    header("Location: login.php?error=" . urlencode($message));
    exit;
}

// 2. Validasi Input Kosong
if (empty($username) || empty($password)) {
    redirect_error("Username dan Password wajib diisi!");
}

try {
    // 3. Query Database
    // PENTING: Di PostgreSQL, nama tabel dan kolom biasanya otomatis jadi huruf kecil (lowercase).
    // Jadi kita pakai 'users' bukan 'USERS', dan 'username' bukan 'Username'.
    $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Cek apakah User Ditemukan?
    if (!$user) {
        redirect_error("Username tidak terdaftar.");
    }

    // 5. Cek Password (Plain Text sesuai database Mas)
    // Kita bandingkan inputan user dengan data di kolom 'password_hash'
    if ($password !== $user['password_hash']) {
        redirect_error("Password salah.");
    }

    // 6. Login Berhasil! Simpan data penting ke SESSION
    $_SESSION['is_login']        = true;
    $_SESSION['user_id']         = $user['id_user'];
    $_SESSION['username']        = $user['username'];
    $_SESSION['role']            = $user['role'];
    
    // Simpan ID Pasien / Tenaga Medis (berguna buat query nanti)
    $_SESSION['id_pasien']       = $user['id_pasien']; 
    $_SESSION['id_tenaga_medis'] = $user['id_tenaga_medis'];

    // 7. Arahkan User sesuai Role-nya
    switch ($user['role']) {
        case 'admin':
            header("Location: ../admin/dashboard.php");
            break;
        case 'dokter':
            // Pastikan file dashboard dokter sudah ada, kalau belum arahkan ke index dulu gapapa
            header("Location: ../dokter/dashboard.php"); 
            break;
        case 'pasien':
            // Pastikan file dashboard pasien sudah ada
            header("Location: ../pasien/dashboard.php");
            break;
        default:
            redirect_error("Role user tidak dikenali.");
    }
    exit;

} catch (PDOException $e) {
    // 8. Error Handling Database (Penyebab error header tadi)
    // Kita bungkus pesan errornya biar url-nya tidak rusak
    $pesan_sistem = "Gagal Konek: " . $e->getMessage();
    redirect_error($pesan_sistem);
}
?>