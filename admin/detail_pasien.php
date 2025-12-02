<?php
// File: admin/detail_pasien.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

$id_pasien = $_GET['id'] ?? '';
if (empty($id_pasien)) { header("Location: dashboard.php?page=pasien"); exit; }

// 1. Ambil Biodata Pasien
$stmt = $conn->prepare("SELECT * FROM PASIEN WHERE ID_Pasien = ?");
$stmt->execute([$id_pasien]);
$pasien = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pasien) die("Pasien tidak ditemukan.");

// 2. Ambil History Rekam Medis
$stmt_rm = $conn->prepare("SELECT rm.*, tm.Nama_Tenaga_Medis AS nama_dokter 
                           FROM REKAM_MEDIS rm 
                           JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis 
                           WHERE rm.ID_Pasien = ? 
                           ORDER BY rm.Tanggal_Catatan DESC");
$stmt_rm->execute([$id_pasien]);
$history_rm = $stmt_rm->fetchAll(PDO::FETCH_ASSOC);

// 3. Ambil History Tagihan (Invoice)
$stmt_bill = $conn->prepare("SELECT * FROM TAGIHAN WHERE ID_Pasien = ? ORDER BY Tanggal_Tagihan DESC");
$stmt_bill->execute([$id_pasien]);
$history_bill = $stmt_bill->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pasien - <?= $pasien['nama'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">

<div class="container mt-5 mb-5">
    <a href="dashboard.php?page=pasien" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-user-circle"></i> Detail Pasien</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr><td width="150">ID Pasien</td><td>: <strong><?= $pasien['id_pasien'] ?></strong></td></tr>
                        <tr><td>Nama Lengkap</td><td>: <?= $pasien['nama'] ?></td></tr>
                        <tr><td>Tanggal Lahir</td><td>: <?= date('d F Y', strtotime($pasien['tanggal_lahir'])) ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr><td width="150">Alamat</td><td>: <?= $pasien['alamat'] ?></td></tr>
                        <tr><td>No. Telepon</td><td>: <?= $pasien['nomor_telepon'] ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-file-medical-alt"></i> Riwayat Rekam Medis</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Tgl</th><th>Dokter</th><th>Diagnosis</th></tr></thead>
                        <tbody>
                            <?php foreach($history_rm as $rm): ?>
                            <tr>
                                <td><?= date('d/m/y', strtotime($rm['tanggal_catatan'])) ?></td>
                                <td><?= $rm['nama_dokter'] ?></td>
                                <td><?= $rm['diagnosis'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($history_rm)): ?><tr><td colspan="3" class="text-center text-muted">Belum ada riwayat.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Tagihan & Invoice</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>No Tagihan</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <?php foreach($history_bill as $bill): ?>
                            <tr>
                                <td><?= $bill['id_tagihan'] ?></td>
                                <td>Rp <?= number_format($bill['total_biaya'], 0, ',', '.') ?></td>
                                <td>
                                    <?php if($bill['status_pembayaran'] == 'Lunas'): ?>
                                        <span class="badge bg-success">Lunas</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Belum</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="cetak_invoice.php?id=<?= $bill['id_tagihan'] ?>" class="btn btn-sm btn-dark" target="_blank">
                                        <i class="fas fa-print"></i> Cetak
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($history_bill)): ?><tr><td colspan="4" class="text-center text-muted">Belum ada tagihan.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</body>
</html>