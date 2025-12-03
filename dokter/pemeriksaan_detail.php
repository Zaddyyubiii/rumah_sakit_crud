<?php
// File: dokter/pemeriksaan_detail.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
if (!$id_tm) die("Akun Anda tidak terhubung dengan Tenaga Medis.");

$id_pemeriksaan = $_GET['id'] ?? null;
if (!$id_pemeriksaan) {
    header('Location: dashboard.php?view=pemeriksaan');
    exit;
}

$flash_success = null;
$flash_error   = null;

// Helper ID
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (int)preg_replace('/\D/', '', $last) + 1 : 1;
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// 1. Ambil Data Pemeriksaan & Pasien
// 1. Ambil Data Pemeriksaan & Pasien
$sql = "SELECT 
            pe.*,
            p.id_pasien, 
            p.nama AS nama_pasien,
            rm.id_rekam_medis,
            rm.diagnosis,
            rm.hasil_pemeriksaan
        FROM pemeriksaan pe
        JOIN pasien p ON pe.id_pasien = p.id_pasien
        LEFT JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
        WHERE pe.id_pemeriksaan = ? AND pe.id_tenaga_medis = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_pemeriksaan, $id_tm]);
$detail = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$detail) die("Pemeriksaan tidak ditemukan atau bukan milik Anda.");

// ==========================================================
// HANDLE POST (Update Diagnosis Saja, tanpa layanan)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        $diagnosis = $_POST['diagnosis'] ?? '';
        $hasil     = $_POST['hasil_pemeriksaan'] ?? '';

        // Update ke REKAM_MEDIS (kalau ada id_rekam_medis-nya)
        if (!empty($detail['id_rekam_medis'])) {
            $updRM = $conn->prepare("
                UPDATE rekam_medis 
                SET diagnosis = ?, hasil_pemeriksaan = ?
                WHERE id_rekam_medis = ?
            ");
            $updRM->execute([$diagnosis, $hasil, $detail['id_rekam_medis']]);
        }

        $conn->commit();

        // Setelah simpan: BALIK ke tab Jadwal Pemeriksaan
        header("Location: dashboard.php?view=pemeriksaan&msg=done");
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $flash_error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pemeriksaan</title>
    <style>
        body { font-family: system-ui; background: #f3f6f9; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #1565c0; font-size: 24px; }
        .meta { margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; font-size: 14px; color: #0d47a1; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: #455a64; font-size: 13px; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        textarea { height: 80px; resize: vertical; }
        .btn { padding: 10px 20px; border: none; border-radius: 99px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1976d2; color: white; }
        .btn-secondary { background: #eceff1; color: #37474f; margin-right: 10px; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 6px; font-size: 13px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; }
        .alert-error { background: #ffebee; color: #c62828; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Proses Pemeriksaan</h1>
        <a href="dashboard.php?view=pemeriksaan" class="btn btn-secondary">Kembali</a>
    </div>

    <?php if(isset($_GET['msg'])): ?> <div class="alert alert-success">Data berhasil disimpan!</div> <?php endif; ?>
    <?php if($flash_error): ?> <div class="alert alert-error"><?= $flash_error ?></div> <?php endif; ?>

    <div class="meta">
    <strong>Pasien:</strong> <?= htmlspecialchars($detail['nama_pasien']) ?> (ID: <?= $detail['id_pasien'] ?>)<br>
    <strong>No. RM:</strong> <?= htmlspecialchars($detail['id_rekam_medis'] ?? '-') ?><br>
    <strong>Jadwal:</strong> <?= date('d F Y', strtotime($detail['tanggal_pemeriksaan'])) ?> - <?= $detail['waktu_pemeriksaan'] ?><br>
    <strong>Ruangan:</strong> <?= $detail['ruang_pemeriksaan'] ?>
    </div>


    <form method="POST">
        <div style="background: #fafafa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #eee;">
            <h3 style="margin-top:0; font-size:16px;">1. Hasil Diagnosis</h3>
            <div class="form-group">
                <label>Diagnosis Dokter</label>
                <input type="text" name="diagnosis" value="<?= htmlspecialchars($detail['diagnosis'] ?? '') ?>" placeholder="Contoh: Demam Berdarah, Flu Ringan...">
            </div>
            <div class="form-group">
                <label>Catatan Pemeriksaan</label>
                <textarea name="hasil_pemeriksaan" placeholder="Detail hasil pemeriksaan fisik..."><?= htmlspecialchars($detail['hasil_pemeriksaan'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">SIMPAN HASIL PEMERIKSAAN</button>
    </form>
</div>
</body>
</html>