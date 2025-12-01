<?php
// File: C:\xampp\htdocs\rumah_sakit_crud\db.php

$host = "localhost";
$port = "5432";
$dbname = "proyek2"; // Pastikan ini sesuai nama database di DBeaver
$user = "postgres";
$password = "ayubifathan"; // <-- ISI PASSWORDNYA JANGAN LUPA

try {
    // KITA WAJIB PAKAI "new PDO" (bukan pg_connect)
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    
    // Setting error mode biar kalau salah query ketahuan
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // echo "Koneksi Berhasil!"; // (Boleh di-uncomment kalau mau tes doang)
} catch (PDOException $e) {
    // Kalau gagal konek, akan muncul pesan ini
    die("Koneksi Database Gagal: " . $e->getMessage());
}
?>