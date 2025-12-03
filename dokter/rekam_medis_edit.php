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

/**
 * Helper sederhana untuk generate ID (Kamar Rawat Inap, dsb.)
 * Contoh: generateNextId($conn, 'rawat_inap', 'id_kamar', 'K') -> K001, K002, ...
 */
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $sql  = "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if (!$last) {
        $num = 1;
    } else {
        $num = (int)preg_replace('/\D/', '', $last) + 1;
    }

    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// Ambil data rekam medis untuk ditampilkan di form (dan dipakai logika rawat inap)
$sql = "SELECT 
            rm.id_rekam_medis,
            rm.id_pasien,
            rm.tanggal_catatan,
            rm.diagnosis,
            rm.hasil_pemeriksaan,
            rm.jenis_rawat,
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

// Kalau form disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosis         = trim($_POST['diagnosis'] ?? '');
    $hasil_pemeriksaan = trim($_POST['hasil_pemeriksaan'] ?? '');
    $jenis_rawat_post  = $_POST['jenis_rawat'] ?? 'Belum Ditentukan';

    try {
        if ($diagnosis === '' || $hasil_pemeriksaan === '') {
            throw new Exception("Diagnosis dan hasil pemeriksaan wajib diisi.");
        }

        // Normalisasi jenis rawat
        $allowedJenis = ['Belum Ditentukan', 'Rawat Jalan', 'Rawat Inap'];
        if (!in_array($jenis_rawat_post, $allowedJenis, true)) {
            $jenis_rawat_post = 'Belum Ditentukan';
        }

        // Ambil info penting dari rekam medis (sudah ada di $rm)
        $id_pasien = $rm['id_pasien'];
        $tgl       = $rm['tanggal_catatan'];

        // Update rekam_medis (diagnosis, hasil, jenis_rawat)
        $sqlUp = "UPDATE rekam_medis
                  SET diagnosis = ?, 
                      hasil_pemeriksaan = ?,
                      jenis_rawat = ?
                  WHERE id_rekam_medis = ? AND id_tenaga_medis = ?";
        $stmtUp = $conn->prepare($sqlUp);
        $stmtUp->execute([$diagnosis, $hasil_pemeriksaan, $jenis_rawat_post, $id_rm, $id_tm]);

        if ($stmtUp->rowCount() === 0) {
            throw new Exception("Rekam medis tidak ditemukan atau bukan milik Anda.");
        }

        // === Sinkronisasi dengan tabel RAWAT_INAP ===
        if ($jenis_rawat_post === 'Rawat Inap') {
            // Cari ID layanan kamar rawat inap
            $id_layanan_inap = $conn->query("
                SELECT id_layanan 
                FROM layanan 
                WHERE nama_layanan ILIKE '%rawat inap%' 
                LIMIT 1
            ")->fetchColumn();
            if (!$id_layanan_inap) {
                $id_layanan_inap = 'L005'; // fallback kalau tidak ada
            }

            // Cek apakah sudah ada rawat inap aktif untuk pasien & tanggal ini
            $cek = $conn->prepare("
                SELECT id_kamar 
                FROM rawat_inap 
                WHERE id_pasien = ? 
                  AND tanggal_masuk = ? 
                  AND tanggal_keluar IS NULL
                LIMIT 1
            ");
            $cek->execute([$id_pasien, $tgl]);
            $id_kamar = $cek->fetchColumn();

            if (!$id_kamar) {
                // Buat ID kamar baru (K001, K002, ...)
                $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
                $ins = $conn->prepare("
                    INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk, tanggal_keluar)
                    VALUES (?, ?, ?, ?, NULL)
                ");
                $ins->execute([$id_kamar, $id_layanan_inap, $id_pasien, $tgl]);
            }

        } elseif ($jenis_rawat_post === 'Rawat Jalan') {
            // Tutup rawat inap (kalau ada) untuk tanggal tersebut
            $upd = $conn->prepare("
                UPDATE rawat_inap 
                SET tanggal_keluar = COALESCE(tanggal_keluar, CURRENT_DATE)
                WHERE id_pasien = ?
                  AND tanggal_masuk = ?
                  AND tanggal_keluar IS NULL
            ");
            $upd->execute([$id_pasien, $tgl]);

        } else {
            // Belum Ditentukan -> tidak menyentuh rawat_inap
        }

        // Setelah edit, balik ke dashboard rekam medis
        header('Location: dashboard.php?view=rekam_medis');
        exit;

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
        // update data di $rm supaya form tetap isi terakhir yg di-post
        $rm['diagnosis']         = $diagnosis;
        $rm['hasil_pemeriksaan'] = $hasil_pemeriksaan;
        $rm['jenis_rawat']       = $jenis_rawat_post;
    } catch (PDOException $e) {
        $flash_error = "Kesalahan database: " . $e->getMessage();
    }
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
        input[type=text], textarea, select {
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
        .hint { font-size:11px; color:#90a4ae; margin-top:2px; }
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
        <div class="row">
            <label>Jenis Rawat</label>
            <?php $jr = $rm['jenis_rawat'] ?? 'Belum Ditentukan'; ?>
            <select name="jenis_rawat">
                <option value="Belum Ditentukan" <?= $jr==='Belum Ditentukan' ? 'selected' : '' ?>>Belum Ditentukan</option>
                <option value="Rawat Jalan" <?= $jr==='Rawat Jalan' ? 'selected' : '' ?>>Rawat Jalan</option>
                <option value="Rawat Inap" <?= $jr==='Rawat Inap' ? 'selected' : '' ?>>Rawat Inap</option>
            </select>
            <div class="hint">
                Status ini akan disinkronkan dengan data Rawat Inap pasien (jika dipilih Rawat Inap).
            </div>
        </div>

        <button type="submit" class="btn-primary">Simpan Perubahan</button>
        <a href="dashboard.php?view=rekam_medis" class="btn-secondary">Kembali ke Rekam Medis</a>
    </form>
</div>
</body>
</html>
