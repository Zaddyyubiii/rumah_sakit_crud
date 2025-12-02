<?php
// File: dokter/rekam_medis_edit.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
$username = $_SESSION['username'] ?? 'Dokter';

if (!$id_tm) {
    die("Akun Anda tidak terhubung dengan Tenaga Medis.");
}

$id_rm = $_GET['id'] ?? null;
if (!$id_rm) {
    header('Location: dashboard.php?view=rekam_medis');
    exit;
}

$flash_success = null;
$flash_error   = null;

// Kalau form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosis         = trim($_POST['diagnosis'] ?? '');
    $hasil_pemeriksaan = trim($_POST['hasil_pemeriksaan'] ?? '');

    try {
        if ($diagnosis === '' || $hasil_pemeriksaan === '') {
            throw new Exception("Diagnosis dan hasil pemeriksaan wajib diisi.");
        }

        $sql = "UPDATE rekam_medis
                SET diagnosis = ?, hasil_pemeriksaan = ?
                WHERE id_rekam_medis = ? AND id_tenaga_medis = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$diagnosis, $hasil_pemeriksaan, $id_rm, $id_tm]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Rekam medis tidak ditemukan atau bukan milik Anda.");
        }

        // Setelah edit, balik ke dashboard rekam medis
        header('Location: dashboard.php?view=rekam_medis');
        exit;

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
    } catch (PDOException $e) {
        $flash_error = "Kesalahan database: " . $e->getMessage();
    }
}

// Ambil data rekam medis untuk ditampilkan di form
$sql = "SELECT 
            rm.id_rekam_medis,
            rm.id_pasien,
            rm.tanggal_catatan,
            rm.diagnosis,
            rm.hasil_pemeriksaan,
            p.nama AS nama_pasien
        FROM rekam_medis rm
        JOIN pasien p ON rm.id_pasien = p.id_pasien
        WHERE rm.id_rekam_medis = ? AND rm.id_tenaga_medis = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_rm, $id_tm]);
$rm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rm) {
    die("Rekam medis tidak ditemukan atau bukan milik Anda.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Rekam Medis</title>
    <style>
        body { margin:0; font-family:system-ui, sans-serif; background:#f3f6f9; }
        .wrap { max-width:720px; margin:40px auto; background:#fff; padding:24px 28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
        h1 { margin-top:0; font-size:22px; color:#263238; }
        .meta { font-size:13px; color:#607d8b; margin-bottom:14px; }
        label { font-size:13px; color:#455a64; display:block; margin-bottom:4px; }
        input[type=text], textarea {
            width:100%; padding:8px 10px; border-radius:6px;
            border:1px solid #cfd8dc; font-size:13px;
        }
        textarea { min-height:90px; resize:vertical; }
        .row { margin-bottom:12px; }
        .btn-primary, .btn-secondary {
            border-radius:999px; padding:6px 14px; font-size:13px;
            border:none; cursor:pointer; text-decoration:none; display:inline-block;
        }
        .btn-primary { background:#1976d2; color:#fff; }
        .btn-primary:hover { background:#1258a3; }
        .btn-secondary { background:#eceff1; color:#37474f; }
        .btn-secondary:hover { background:#d7dde2; }
        .alert { padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:12px; }
        .alert-error { background:#ffebee; color:#c62828; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Edit Rekam Medis</h1>
    <div class="meta">
        ID: <b><?= htmlspecialchars($rm['id_rekam_medis']) ?></b><br>
        Pasien: <b><?= htmlspecialchars($rm['nama_pasien']) ?></b> (<?= htmlspecialchars($rm['id_pasien']) ?>)<br>
        Tanggal: <?= date('d-m-Y', strtotime($rm['tanggal_catatan'])) ?>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="row">
            <label>Diagnosis</label>
            <input type="text" name="diagnosis" value="<?= htmlspecialchars($rm['diagnosis']) ?>">
        </div>
        <div class="row">
            <label>Hasil Pemeriksaan</label>
            <textarea name="hasil_pemeriksaan"><?= htmlspecialchars($rm['hasil_pemeriksaan']) ?></textarea>
        </div>

        <button type="submit" class="btn-primary">Simpan Perubahan</button>
        <a href="dashboard.php?view=rekam_medis" class="btn-secondary">Kembali ke Rekam Medis</a>
    </form>
</div>
</body>
</html>
