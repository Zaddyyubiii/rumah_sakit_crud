<?php
// File: auth/logout.php
session_start();

// 1. Kosongkan array session
$_SESSION = [];

// 2. Hapus Cookie Session di Browser (PENTING BIAR GAK MENTAL BALIK)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Hancurkan sesi di server
session_destroy();

// 4. Pastikan tidak ada output HTML sebelum header
// Redirect ke halaman login
header("Location: login.php");
exit;
?>