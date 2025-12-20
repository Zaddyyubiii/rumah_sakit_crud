<?php
// File: dokter/pemeriksaan_detail.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
if (!$id_tm) {
    die("Akun Anda tidak terhubung dengan Tenaga Medis.");
}

$id_pemeriksaan = $_GET['id'] ?? null;
if (!$id_pemeriksaan) {
    header('Location: dashboard.php?view=pemeriksaan');
    exit;
}

$flash_error = null;

// ======================================================
// AMBIL DATA PEMERIKSAAN + RM + PASIEN
// ======================================================
$sql = "
    SELECT
        pe.id_pemeriksaan,
        pe.tanggal_pemeriksaan,
        pe.waktu_pemeriksaan,
        pe.ruang_pemeriksaan,
        pe.diagnosis,
        pe.hasil_pemeriksaan,
        rm.id_rekam_medis,
        p.id_pasien,
        p.nama AS nama_pasien
    FROM pemeriksaan pe
    JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
    JOIN pasien p ON rm.id_pasien = p.id_pasien
    WHERE pe.id_pemeriksaan = ?
      AND pe.id_tenaga_medis = ?
";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_pemeriksaan, $id_tm]);
$detail = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$detail) {
    die("Pemeriksaan tidak ditemukan atau bukan milik Anda.");
}

// ======================================================
// HANDLE POST → UPDATE DIAGNOSIS & HASIL (KE PEMERIKSAAN)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $hasil     = trim($_POST['hasil_pemeriksaan'] ?? '');

        $upd = $conn->prepare("
            UPDATE pemeriksaan
            SET diagnosis = ?, hasil_pemeriksaan = ?
            WHERE id_pemeriksaan = ?
              AND id_tenaga_medis = ?
        ");
        $upd->execute([
            $diagnosis,
            $hasil,
            $id_pemeriksaan,
            $id_tm
        ]);

        header("Location: dashboard.php?view=pemeriksaan&msg=done");
        exit;

    } catch (Exception $e) {
        $flash_error = "Gagal menyimpan data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pemeriksaan</title>
    <style>
        body { font-family: system-ui; background:#f3f6f9; padding:20px; }
        .container {
            max-width:800px;
            margin:auto;
            background:#fff;
            padding:30px;
            border-radius:12px;
            box-shadow:0 4px 10px rgba(0,0,0,.05);
        }
        h1 { margin-top:0; color:#1565c0; }
        .meta {
            background:#e3f2fd;
            padding:15px;
            border-radius:8px;
            font-size:14px;
            margin-bottom:20px;
        }
        label { font-size:13px; display:block; margin-bottom:5px; }
        input, textarea {
            width:100%;
            padding:8px;
            border:1px solid #cfd8dc;
            border-radius:6px;
            font-size:13px;
        }
        textarea { min-height:80px; }
        .btn {
            padding:10px 20px;
            border:none;
            border-radius:99px;
            cursor:pointer;
            font-size:14px;
        }
        .btn-primary { background:#1976d2; color:#fff; width:100%; }
        .btn-secondary { background:#eceff1; color:#37474f; text-decoration:none; }
        .alert {
            padding:10px;
            border-radius:6px;
            margin-bottom:15px;
            font-size:13px;
        }
        .alert-error { background:#ffebee; color:#c62828; }
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h1>Detail Pemeriksaan</h1>
        <a href="dashboard.php?view=pemeriksaan" class="btn btn-secondary">← Kembali</a>
    </div>

    <?php if(isset($_GET['msg'])): ?>
        <div class="alert" style="background:#e8f5e9;color:#2e7d32;">
            Data pemeriksaan berhasil disimpan.
        </div>
    <?php endif; ?>

    <?php if($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <div class="meta">
        <b>Pasien:</b> <?= htmlspecialchars($detail['nama_pasien']) ?> (<?= $detail['id_pasien'] ?>)<br>
        <b>ID Rekam Medis:</b> <?= htmlspecialchars($detail['id_rekam_medis']) ?><br>
        <b>Tanggal:</b> <?= date('d F Y', strtotime($detail['tanggal_pemeriksaan'])) ?><br>
        <b>Waktu:</b> <?= htmlspecialchars($detail['waktu_pemeriksaan']) ?><br>
        <b>Ruangan:</b> <?= htmlspecialchars($detail['ruang_pemeriksaan']) ?>
    </div>

    <form method="post">
        <label>Diagnosis</label>
        <input type="text" name="diagnosis"
               value="<?= htmlspecialchars($detail['diagnosis'] ?? '') ?>"
               placeholder="Contoh: ISPA, Demam Berdarah">

        <label style="margin-top:15px;">Hasil Pemeriksaan</label>
        <textarea name="hasil_pemeriksaan"
                  placeholder="Catatan hasil pemeriksaan fisik..."><?= htmlspecialchars($detail['hasil_pemeriksaan'] ?? '') ?></textarea>

        <button type="submit" class="btn btn-primary" style="margin-top:20px;">
            Simpan Hasil Pemeriksaan
        </button>
    </form>
</div>

</body>
</html>
