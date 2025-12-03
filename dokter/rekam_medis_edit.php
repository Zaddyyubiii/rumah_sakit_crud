<?php
// File: dokter/rekam_medis_edit.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
if (!$id_tm) die("Akses ditolak.");

$id_rm = $_GET['id'] ?? null;
if (!$id_rm) { header('Location: dashboard.php?view=rekam_medis'); exit; }

$flash_error = null;

// Helper ID Generator
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (int)preg_replace('/\D/', '', $last) + 1 : 1;
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// Ambil Data
$stmt = $conn->prepare("SELECT rm.*, p.nama AS nama_pasien 
                        FROM rekam_medis rm JOIN pasien p ON rm.id_pasien = p.id_pasien 
                        WHERE rm.id_rekam_medis = ? AND rm.id_tenaga_medis = ?");
$stmt->execute([$id_rm, $id_tm]);
$rm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rm) die("Data tidak ditemukan.");

// Cek Status Rawat Inap saat ini di DB (Bukan dari kolom rekam_medis, tapi real dari tabel rawat_inap)
$cekRI = $conn->prepare("SELECT 1 FROM rawat_inap WHERE id_pasien = ? AND tanggal_keluar IS NULL");
$cekRI->execute([$rm['id_pasien']]);
$is_currently_inap = $cekRI->fetchColumn(); 
$current_status = $is_currently_inap ? 'Rawat Inap' : 'Rawat Jalan';

// HANDLE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosis  = $_POST['diagnosis'];
    $hasil      = $_POST['hasil_pemeriksaan'];
    $status_new = $_POST['jenis_rawat']; // Rawat Inap / Rawat Jalan

    try {
        $conn->beginTransaction();

        // 1. Update Data Teks RM
        $upd = $conn->prepare("UPDATE rekam_medis SET diagnosis=?, hasil_pemeriksaan=? WHERE id_rekam_medis=?");
        $upd->execute([$diagnosis, $hasil, $id_rm]);

        // 2. LOGIKA SINKRONISASI RAWAT INAP (Manual Override)
        // Jika status diubah jadi RAWAT INAP (dan sebelumnya belum)
        if ($status_new === 'Rawat Inap' && !$is_currently_inap) {
            // Cari Layanan Kamar Default (L005 / sesuaikan database)
            $lay = $conn->query("SELECT id_layanan FROM layanan WHERE nama_layanan ILIKE '%Rawat Inap%' LIMIT 1")->fetchColumn();
            $id_layanan = $lay ? $lay : 'L005'; 

            $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
            $ins = $conn->prepare("INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk) VALUES (?, ?, ?, ?)");
            $ins->execute([$id_kamar, $id_layanan, $rm['id_pasien'], $rm['tanggal_catatan']]);
        }
        // Jika status diubah jadi RAWAT JALAN (dan sebelumnya Inap)
        elseif ($status_new === 'Rawat Jalan' && $is_currently_inap) {
            // Check out paksa (set tanggal keluar hari ini)
            $out = $conn->prepare("UPDATE rawat_inap SET tanggal_keluar = CURRENT_DATE WHERE id_pasien = ? AND tanggal_keluar IS NULL");
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
    <?php if($flash_error): ?><div class="alert"><?=$flash_error?></div><?php endif; ?>
    
    <div class="info">
        Pasien: <b><?= htmlspecialchars($rm['nama_pasien']) ?></b><br>
        Tanggal: <?= date('d F Y', strtotime($rm['tanggal_catatan'])) ?>
    </div>

    <form method="POST">
        <label>Diagnosis</label>
        <input type="text" name="diagnosis" value="<?= htmlspecialchars($rm['diagnosis']) ?>">

        <label>Hasil Pemeriksaan</label>
        <textarea name="hasil_pemeriksaan" rows="4"><?= htmlspecialchars($rm['hasil_pemeriksaan']) ?></textarea>

        <label>Status Rawat (Override Manual)</label>
        <select name="jenis_rawat">
            <option value="Rawat Jalan" <?= $current_status === 'Rawat Jalan' ? 'selected' : '' ?>>Rawat Jalan / Pulang</option>
            <option value="Rawat Inap"  <?= $current_status === 'Rawat Inap'  ? 'selected' : '' ?>>Rawat Inap (Masuk Kamar)</option>
        </select>
        <small style="display:block; margin-top:-10px; margin-bottom:15px; color:#78909c; font-size:12px;">
            Mengubah ke 'Rawat Inap' akan otomatis memasukkan pasien ke antrian kamar Admin.
        </small>

        <button type="submit">Simpan Perubahan</button>
        <a href="dashboard.php?view=rekam_medis" class="back">Batal</a>
    </form>
</div>
</body>
</html>