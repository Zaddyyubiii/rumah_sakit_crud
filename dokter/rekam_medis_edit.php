<?php
// File: dokter/rekam_medis_edit.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
if (!$id_tm) die("Akses ditolak.");

$id_rm = $_GET['id'] ?? null;
if (!$id_rm) {
    header('Location: dashboard.php?view=rekam_medis');
    exit;
}

$flash_error = null;

// Helper ID Generator
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (int)preg_replace('/\D/', '', $last) + 1 : 1;
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// Ambil Data RM + Pasien
$stmt = $conn->prepare("
    SELECT rm.*, p.nama AS nama_pasien 
    FROM rekam_medis rm 
    JOIN pasien p ON rm.id_pasien = p.id_pasien 
    WHERE rm.id_rekam_medis = ? AND rm.id_tenaga_medis = ?
");
$stmt->execute([$id_rm, $id_tm]);
$rm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rm) die("Data tidak ditemukan.");

// Status saat ini dari kolom rekam_medis (supaya sinkron dengan Admin)
$current_status = $rm['jenis_rawat'] ?? 'Belum Ditentukan';
$allowed_status = ['Belum Ditentukan', 'Rawat Jalan', 'Rawat Inap'];
if (!in_array($current_status, $allowed_status, true)) {
    $current_status = 'Belum Ditentukan';
}

// Cek apakah pasien sedang punya Rawat Inap aktif (untuk sinkron kamar)
$cekRI = $conn->prepare("SELECT 1 FROM rawat_inap WHERE id_pasien = ? AND tanggal_keluar IS NULL");
$cekRI->execute([$rm['id_pasien']]);
$is_currently_inap = (bool)$cekRI->fetchColumn();

// HANDLE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosis  = trim($_POST['diagnosis'] ?? '');
    $hasil      = trim($_POST['hasil_pemeriksaan'] ?? '');
    $status_new = $_POST['jenis_rawat'] ?? $current_status;

    if (!in_array($status_new, $allowed_status, true)) {
        $status_new = $current_status;
    }

    try {
        $conn->beginTransaction();

        // 1. Update Data Rekam Medis + jenis_rawat
        $upd = $conn->prepare("
            UPDATE rekam_medis 
            SET diagnosis = ?, hasil_pemeriksaan = ?, jenis_rawat = ?
            WHERE id_rekam_medis = ?
        ");
        $upd->execute([$diagnosis, $hasil, $status_new, $id_rm]);

        // 2. Sinkronisasi dengan RAWAT_INAP
        // Jika dokter set 'Rawat Inap' dan belum ada rawat inap aktif → buat record baru
        if ($status_new === 'Rawat Inap' && !$is_currently_inap) {

            // Cari layanan kamar default yang mengandung kata "Rawat Inap"
            $lay = $conn->query("
                SELECT id_layanan 
                FROM layanan 
                WHERE nama_layanan ILIKE '%Rawat Inap%' 
                LIMIT 1
            ")->fetchColumn();
            $id_layanan = $lay ?: 'L005'; // fallback

            $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
            $ins = $conn->prepare("
                INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk) 
                VALUES (?, ?, ?, ?)
            ");
            $ins->execute([$id_kamar, $id_layanan, $rm['id_pasien'], $rm['tanggal_catatan']]);

        // Jika dokter set 'Rawat Jalan' atau 'Belum Ditentukan' dan ada rawat inap aktif → check-out
        } elseif ($status_new !== 'Rawat Inap' && $is_currently_inap) {
            $out = $conn->prepare("
                UPDATE rawat_inap 
                SET tanggal_keluar = CURRENT_DATE 
                WHERE id_pasien = ? AND tanggal_keluar IS NULL
            ");
            $out->execute([$rm['id_pasien']]);
        }

        $conn->commit();
        header("Location: dashboard.php?view=rekam_medis&msg=updated");
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $flash_error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Rekam Medis</title>
    <style>
        body{font-family:system-ui;background:#f3f6f9;padding:40px;}
        .box{max-width:600px;margin:0 auto;background:white;padding:30px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.05);}
        label{display:block;margin-bottom:5px;font-weight:500;color:#37474f;}
        input,textarea,select{width:100%;padding:10px;border:1px solid #cfd8dc;border-radius:6px;margin-bottom:15px;box-sizing:border-box;}
        button{background:#1976d2;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;}
        .back{color:#546e7a;text-decoration:none;margin-left:10px;font-size:14px;}
        .info{background:#e3f2fd;color:#0d47a1;padding:10px;border-radius:6px;margin-bottom:20px;font-size:14px;}
        .alert{background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;}
    </style>
</head>
<body>
<div class="box">
    <h2>Edit Rekam Medis</h2>
    <?php if($flash_error): ?><div class="alert"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
    
    <div class="info">
        Pasien: <b><?= htmlspecialchars($rm['nama_pasien']) ?></b><br>
        Tanggal: <?= date('d F Y', strtotime($rm['tanggal_catatan'])) ?><br>
        Status saat ini: <b><?= htmlspecialchars($current_status) ?></b>
    </div>

    <form method="POST">
        <label>Diagnosis</label>
        <input type="text" name="diagnosis" value="<?= htmlspecialchars($rm['diagnosis']) ?>">

        <label>Hasil Pemeriksaan</label>
        <textarea name="hasil_pemeriksaan" rows="4"><?= htmlspecialchars($rm['hasil_pemeriksaan']) ?></textarea>

        <label>Status Rawat (Override Manual)</label>
        <select name="jenis_rawat">
            <option value="Belum Ditentukan" <?= $current_status === 'Belum Ditentukan' ? 'selected' : '' ?>>Belum Ditentukan (ikuti sistem)</option>
            <option value="Rawat Jalan" <?= $current_status === 'Rawat Jalan' ? 'selected' : '' ?>>Rawat Jalan / Pulang</option>
            <option value="Rawat Inap" <?= $current_status === 'Rawat Inap' ? 'selected' : '' ?>>Rawat Inap (Masuk Kamar)</option>
        </select>
        <small style="display:block; margin-top:-10px; margin-bottom:15px; color:#78909c; font-size:12px;">
            Mengubah ke 'Rawat Inap' akan otomatis memasukkan pasien ke daftar Rawat Inap Admin. 
            Mengubah ke 'Rawat Jalan' / 'Belum Ditentukan' akan mengakhiri Rawat Inap aktif.
        </small>

        <button type="submit">Simpan Perubahan</button>
        <a href="dashboard.php?view=rekam_medis" class="back">Batal</a>
    </form>
</div>
</body>
</html>
