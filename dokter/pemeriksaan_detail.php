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

$flash_success = null;
$flash_error   = null;

// Ambil data pemeriksaan + pasien + RM
$sql = "
    SELECT 
        pe.id_pemeriksaan,
        pe.tanggal_pemeriksaan,
        pe.waktu_pemeriksaan,
        pe.ruang_pemeriksaan,
        pe.id_rekam_medis,
        p.id_pasien,
        p.nama AS nama_pasien,
        rm.tanggal_catatan,
        rm.diagnosis,
        rm.hasil_pemeriksaan
    FROM pemeriksaan pe
    JOIN pasien p      ON pe.id_pasien = p.id_pasien
    LEFT JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
    WHERE pe.id_pemeriksaan = ? AND pe.id_tenaga_medis = ?
";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_pemeriksaan, $id_tm]);
$detail = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$detail) {
    die("Pemeriksaan tidak ditemukan atau bukan milik Anda.");
}

if (!$detail['id_rekam_medis']) {
    die("Pemeriksaan ini belum dihubungkan dengan Rekam Medis. Buat / perbaiki dari jadwal terlebih dahulu.");
}

$id_rm = $detail['id_rekam_medis'];

// Ambil daftar layanan (untuk pilihan layanan lanjutan)
$daftar_layanan = $conn->query("
    SELECT id_layanan, nama_layanan
    FROM layanan
    ORDER BY nama_layanan
")->fetchAll(PDO::FETCH_ASSOC);

// Ambil detail_layanan yang sudah ada untuk pemeriksaan ini
$detail_layanan = $conn->prepare("
    SELECT dp.id_layanan, l.nama_layanan, dp.konsultasi, dp.suntik_vitamin
    FROM detail_pemeriksaan dp
    JOIN layanan l ON dp.id_layanan = l.id_layanan
    WHERE dp.id_pemeriksaan = ?
");
$detail_layanan->execute([$id_pemeriksaan]);
$detail_layanan = $detail_layanan->fetchAll(PDO::FETCH_ASSOC);

// =============================
// HANDLE POST
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosis         = trim($_POST['diagnosis'] ?? '');
    $hasil_pemeriksaan = trim($_POST['hasil_pemeriksaan'] ?? '');
    $jenis_rawat       = $_POST['jenis_rawat'] ?? 'Belum Ditentukan';

    $id_layanan_pilih  = $_POST['id_layanan'] ?? '';
    $konsultasi        = trim($_POST['konsultasi'] ?? '');
    $suntik_vitamin    = $_POST['suntik_vitamin'] ?? 'Tidak';
    $suntik_vitamin    = ($suntik_vitamin === 'Ya') ? 'Ya' : 'Tidak';

    try {
        if ($diagnosis === '' || $hasil_pemeriksaan === '') {
            throw new Exception("Diagnosis dan hasil pemeriksaan wajib diisi.");
        }

        // 1) Update rekam medis (diagnosis & hasil)
        $upRm = $conn->prepare("
            UPDATE rekam_medis
            SET diagnosis = ?, hasil_pemeriksaan = ?
            WHERE id_rekam_medis = ? AND id_tenaga_medis = ?
        ");
        $upRm->execute([$diagnosis, $hasil_pemeriksaan, $id_rm, $id_tm]);

        if ($upRm->rowCount() === 0) {
            throw new Exception("Rekam Medis tidak ditemukan atau bukan milik Anda.");
        }

        // 2) Kelola jenis rawat via tabel RAWAT_INAP
        if (!in_array($jenis_rawat, ['Belum Ditentukan', 'Rawat Jalan', 'Rawat Inap'], true)) {
            $jenis_rawat = 'Belum Ditentukan';
        }

        // Ambil info RM (pasien + tanggal)
        $stInfo = $conn->prepare("
            SELECT id_pasien, tanggal_catatan
            FROM rekam_medis
            WHERE id_rekam_medis = ?
        ");
        $stInfo->execute([$id_rm]);
        $rmInfo = $stInfo->fetch(PDO::FETCH_ASSOC);

        if ($rmInfo) {
            $id_pasien_rm = $rmInfo['id_pasien'];
            $tgl_rm       = $rmInfo['tanggal_catatan'];

            if ($jenis_rawat === 'Rawat Inap') {
                // Cari ID layanan rawat inap
                $id_layanan_inap = $conn->query("
                    SELECT id_layanan
                    FROM layanan
                    WHERE nama_layanan ILIKE '%rawat inap%'
                    LIMIT 1
                ")->fetchColumn();

                if (!$id_layanan_inap) {
                    $id_layanan_inap = 'L005'; // fallback kalau tidak ada
                }

                // Cek sudah ada rawat inap aktif?
                $cekRI = $conn->prepare("
                    SELECT id_kamar
                    FROM rawat_inap
                    WHERE id_pasien = ?
                      AND tanggal_masuk = ?
                      AND tanggal_keluar IS NULL
                    LIMIT 1
                ");
                $cekRI->execute([$id_pasien_rm, $tgl_rm]);
                $id_kamar = $cekRI->fetchColumn();

                if (!$id_kamar) {
                    $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
                    $insRI = $conn->prepare("
                        INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk, tanggal_keluar)
                        VALUES (?, ?, ?, ?, NULL)
                    ");
                    $insRI->execute([$id_kamar, $id_layanan_inap, $id_pasien_rm, $tgl_rm]);
                }
            } elseif ($jenis_rawat === 'Rawat Jalan') {
                // Tutup rawat inap aktif di tanggal itu (kalau ada)
                $updRI = $conn->prepare("
                    UPDATE rawat_inap
                    SET tanggal_keluar = COALESCE(tanggal_keluar, CURRENT_DATE)
                    WHERE id_pasien = ?
                      AND tanggal_masuk = ?
                      AND tanggal_keluar IS NULL
                ");
                $updRI->execute([$id_pasien_rm, $tgl_rm]);
            }
        }

        // 3) Jika dokter mengisi layanan tambahan, simpan ke DETAIL_PEMERIKSAAN
        if ($id_layanan_pilih !== '' && $konsultasi !== '') {
            $sqlDet = "
                INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (id_layanan, id_pemeriksaan)
                DO UPDATE SET
                    konsultasi     = EXCLUDED.konsultasi,
                    suntik_vitamin = EXCLUDED.suntik_vitamin
            ";
            $stDet = $conn->prepare($sqlDet);
            $stDet->execute([$id_layanan_pilih, $id_pemeriksaan, $konsultasi, $suntik_vitamin]);
        }

        $flash_success = "Hasil pemeriksaan dan layanan berhasil disimpan.";
        // Refresh data di halaman
        header("Location: pemeriksaan_detail.php?id=" . urlencode($id_pemeriksaan));
        exit;

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
    } catch (PDOException $e) {
        $flash_error = "Kesalahan database: " . $e->getMessage();
    }
}

// Ambil ulang detail_layanan (kalau barusan error, kita tetap pakai query atas)
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Isi Hasil Pemeriksaan</title>
    <style>
        body { margin:0; font-family:system-ui, sans-serif; background:#f3f6f9; }
        .wrap { max-width:900px; margin:40px auto; background:#fff; padding:24px 28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
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
        .alert-success { background:#e8f5e9; color:#2e7d32; }

        table { width:100%; border-collapse:collapse; font-size:13px; margin-top:16px; }
        th, td { padding:6px 6px; border-bottom:1px solid #eceff1; text-align:left; }
        th { background:#f5f7fa; font-weight:600; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Isi Hasil Pemeriksaan</h1>
    <div class="meta">
        Pemeriksaan: <b><?= htmlspecialchars($detail['id_pemeriksaan']) ?></b><br>
        Pasien: <b><?= htmlspecialchars($detail['nama_pasien']) ?></b> (<?= htmlspecialchars($detail['id_pasien']) ?>)<br>
        Tanggal: <?= date('d-m-Y', strtotime($detail['tanggal_pemeriksaan'])) ?>,
        Waktu: <?= substr($detail['waktu_pemeriksaan'], 0, 5) ?><br>
        Ruang: <?= htmlspecialchars($detail['ruang_pemeriksaan']) ?><br>
        Rekam Medis yang dipakai: <b><?= htmlspecialchars($detail['id_rekam_medis']) ?></b>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="row">
            <label>Diagnosis</label>
            <input type="text" name="diagnosis" value="<?= htmlspecialchars($detail['diagnosis'] ?? '') ?>">
        </div>
        <div class="row">
            <label>Hasil Pemeriksaan</label>
            <textarea name="hasil_pemeriksaan"><?= htmlspecialchars($detail['hasil_pemeriksaan'] ?? '') ?></textarea>
        </div>
        <div class="row">
            <label>Jenis Rawat</label>
            <select name="jenis_rawat">
                <option value="Belum Ditentukan">Belum Ditentukan</option>
                <option value="Rawat Jalan">Rawat Jalan</option>
                <option value="Rawat Inap">Rawat Inap</option>
            </select>
        </div>

        <hr style="margin:18px 0; border:none; border-top:1px solid #eceff1;">

        <h3 style="margin-top:0; font-size:15px;">Layanan Lanjutan</h3>
        <div class="row">
            <label>Layanan</label>
            <select name="id_layanan">
                <option value="">-- Pilih Layanan --</option>
                <?php foreach ($daftar_layanan as $ly): ?>
                    <option value="<?= htmlspecialchars($ly['id_layanan']) ?>">
                        <?= htmlspecialchars($ly['nama_layanan']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="row">
            <label>Suntik Vitamin</label>
            <label style="font-size:12px; margin-right:8px;">
                <input type="radio" name="suntik_vitamin" value="Ya"> Ya
            </label>
            <label style="font-size:12px;">
                <input type="radio" name="suntik_vitamin" value="Tidak" checked> Tidak
            </label>
        </div>
        <div class="row">
            <label>Konsultasi / Alasan Tambahan Layanan</label>
            <textarea name="konsultasi"
                      placeholder="Misal: kondisi memburuk, perlu operasi / rawat inap / radiologi."></textarea>
        </div>

        <button type="submit" class="btn-primary">Simpan Hasil</button>
        <a href="dashboard.php?view=pemeriksaan" class="btn-secondary">Kembali ke Jadwal Pemeriksaan</a>
    </form>

    <?php if (count($detail_layanan) > 0): ?>
        <h3 style="margin-top:24px; font-size:15px;">Layanan yang Sudah Dicatat</h3>
        <table>
            <thead>
            <tr>
                <th>Layanan</th>
                <th>Konsultasi</th>
                <th>Suntik Vitamin</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($detail_layanan as $dl): ?>
                <tr>
                    <td><?= htmlspecialchars($dl['nama_layanan']) ?></td>
                    <td><?= htmlspecialchars($dl['konsultasi']) ?></td>
                    <td><?= htmlspecialchars($dl['suntik_vitamin']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>
</body>
</html>
