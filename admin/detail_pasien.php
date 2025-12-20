<?php
// File: admin/detail_pasien.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

$id_pasien = $_GET['id'] ?? '';
if (empty($id_pasien)) {
    header("Location: dashboard.php?page=pasien");
    exit;
}

/* ===============================
   1. BIODATA PASIEN
   =============================== */
$stmt = $conn->prepare("SELECT * FROM pasien WHERE id_pasien = ?");
$stmt->execute([$id_pasien]);
$pasien = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pasien) {
    die("Pasien tidak ditemukan.");
}

/* ===============================
   2. RIWAYAT PEMERIKSAAN (FIX)
   =============================== */
$stmt = $conn->prepare("
    SELECT 
        pe.tanggal_pemeriksaan,
        pe.waktu_pemeriksaan,
        pe.diagnosis,
        pe.hasil_pemeriksaan,
        tm.nama_tenaga_medis AS nama_dokter
    FROM pemeriksaan pe
    JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
    JOIN tenaga_medis tm ON pe.id_tenaga_medis = tm.id_tenaga_medis
    WHERE rm.id_pasien = ?
    ORDER BY pe.tanggal_pemeriksaan DESC, pe.waktu_pemeriksaan DESC
");
$stmt->execute([$id_pasien]);
$riwayat_pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   3. RIWAYAT TAGIHAN (REALTIME)
   =============================== */
$stmt = $conn->prepare("
    SELECT 
        t.id_tagihan,
        t.tanggal_tagihan,
        t.status_pembayaran,
        COALESCE(SUM(dt.jumlah * l.tarif_dasar), 0) AS total_tagihan
    FROM tagihan t
    LEFT JOIN detail_tagihan dt ON t.id_tagihan = dt.id_tagihan
    LEFT JOIN layanan l ON dt.id_layanan = l.id_layanan
    WHERE t.id_pasien = ?
    GROUP BY t.id_tagihan, t.tanggal_tagihan, t.status_pembayaran
    ORDER BY t.tanggal_tagihan DESC
");
$stmt->execute([$id_pasien]);
$history_bill = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pasien - <?= htmlspecialchars($pasien['nama']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">

<div class="container mt-5 mb-5">
    <a href="dashboard.php?page=pasien" class="btn btn-secondary mb-3">
        <i class="fas fa-arrow-left"></i> Kembali
    </a>

    <!-- ================= BIODATA ================= -->
    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-user-circle"></i> Detail Pasien</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr><td width="150">ID Pasien</td><td>: <b><?= htmlspecialchars($pasien['id_pasien']) ?></b></td></tr>
                        <tr><td>Nama</td><td>: <?= htmlspecialchars($pasien['nama']) ?></td></tr>
                        <tr><td>Tgl Lahir</td><td>: <?= date('d F Y', strtotime($pasien['tanggal_lahir'])) ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr><td width="150">Alamat</td><td>: <?= htmlspecialchars($pasien['alamat']) ?></td></tr>
                        <tr><td>No. Telp</td><td>: <?= htmlspecialchars($pasien['nomor_telepon']) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">

        <!-- ================= RIWAYAT PEMERIKSAAN ================= -->
        <div class="col-md-6">
            <div class="card shadow h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-stethoscope"></i> Riwayat Pemeriksaan</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Dokter</th>
                                <th>Diagnosis</th>
                                <th>Hasil</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($riwayat_pemeriksaan)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">
                                    Belum ada pemeriksaan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($riwayat_pemeriksaan as $r): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($r['tanggal_pemeriksaan'])) ?></td>
                                <td><?= htmlspecialchars($r['nama_dokter']) ?></td>
                                <td><?= nl2br(htmlspecialchars($r['diagnosis'] ?? '-')) ?></td>
                                <td><?= nl2br(htmlspecialchars($r['hasil_pemeriksaan'] ?? '-')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= TAGIHAN ================= -->
        <div class="col-md-6">
            <div class="card shadow h-100">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Tagihan & Invoice</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>No Tagihan</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($history_bill)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">
                                    Belum ada tagihan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($history_bill as $bill): ?>
                            <tr>
                                <td><?= htmlspecialchars($bill['id_tagihan']) ?></td>
                                <td>Rp <?= number_format($bill['total_tagihan'], 0, ',', '.') ?></td>
                                <td>
                                    <?php if ($bill['status_pembayaran'] === 'Lunas'): ?>
                                        <span class="badge bg-success">Lunas</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Belum</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="cetak_invoice.php?id=<?= $bill['id_tagihan'] ?>"
                                       class="btn btn-sm btn-dark" target="_blank">
                                        <i class="fas fa-print"></i> Cetak
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>
