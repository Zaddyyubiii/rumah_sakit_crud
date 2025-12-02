<?php
// File: admin/laporan_keuangan.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

// Cek Login Admin
require_role('admin');

// ==========================================
// 1. HITUNG TOTAL PENDAPATAN SEUMUR HIDUP (ALL TIME)
// ==========================================
// Menghitung semua uang yang statusnya 'Lunas' tanpa peduli tanggal
$q_total = $conn->query("SELECT COALESCE(SUM(Total_Biaya), 0) FROM TAGIHAN WHERE Status_Pembayaran = 'Lunas'");
$total_seumur_hidup = $q_total->fetchColumn();

// ==========================================
// 2. LOGIK FILTER LAPORAN PERIODE
// ==========================================
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01'); // Default tgl 1 bulan ini
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d'); // Default hari ini

// Query Data Laporan (Hanya yang LUNAS dan Masuk Range Tanggal)
$query = "SELECT t.*, p.Nama AS nama_pasien 
          FROM TAGIHAN t
          JOIN PASIEN p ON t.ID_Pasien = p.ID_Pasien
          WHERE t.Status_Pembayaran = 'Lunas' 
          AND t.Tanggal_Tagihan BETWEEN ? AND ?
          ORDER BY t.Tanggal_Tagihan DESC";

$stmt = $conn->prepare($query);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$data_laporan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung Total Khusus Periode Ini
$total_periode = 0;
foreach ($data_laporan as $row) {
    $total_periode += $row['total_biaya'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan RS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            body { background-color: white !important; }
        }
    </style>
</head>
<body class="bg-light">

    <div class="container mt-4 mb-5">
        
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
            <h3 class="fw-bold text-primary"><i class="fas fa-file-invoice-dollar"></i> Laporan Keuangan</h3>
        </div>

        <div class="row mb-4 no-print">
            <div class="col-md-12">
                <div class="card bg-success text-white shadow">
                    <div class="card-body text-center p-4">
                        <h5 class="mb-2 text-uppercase opacity-75">Total Pendapatan Rumah Sakit (Keseluruhan)</h5>
                        <h1 class="display-4 fw-bold">Rp <?= number_format($total_seumur_hidup, 0, ',', '.') ?></h1>
                        <p class="mb-0"><small>Akumulasi dari seluruh tagihan berstatus <strong>Lunas</strong> sejak awal berdiri.</small></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow mb-4 no-print">
            <div class="card-header bg-white fw-bold">Filter Laporan Periode</div>
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Dari Tanggal</label>
                        <input type="date" name="tgl_awal" class="form-control" value="<?= $tgl_awal ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Tampilkan Data</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card p-5 shadow-sm bg-white border-0">
            <div class="text-center mb-4">
                <h2 class="fw-bold text-uppercase">Rumah Sakit Kelompok 5</h2>
                <p class="mb-0">Jl. Kesehatan No. 99, Jakarta Selatan</p>
                <p>Telp: (021) 555-9999</p>
                <hr class="my-4">
                <h4 class="fw-bold">LAPORAN PENDAPATAN PERIODE</h4>
                <p class="text-muted">Tanggal: <?= date('d M Y', strtotime($tgl_awal)) ?> s/d <?= date('d M Y', strtotime($tgl_akhir)) ?></p>
            </div>

            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                    <tr>
                        <th width="5%">No</th>
                        <th>No Tagihan</th>
                        <th>Tanggal Bayar</th>
                        <th>Nama Pasien</th>
                        <th class="text-end">Jumlah (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($data_laporan) > 0): ?>
                        <?php $no=1; foreach($data_laporan as $row): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= htmlspecialchars($row['id_tagihan']) ?></td>
                            <td><?= date('d/m/Y', strtotime($row['tanggal_tagihan'])) ?></td>
                            <td><?= htmlspecialchars($row['nama_pasien']) ?></td>
                            <td class="text-end"><?= number_format($row['total_biaya'], 0, ',', '.') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="fw-bold table-active fs-5">
                            <td colspan="4" class="text-end">TOTAL PENDAPATAN PERIODE INI</td>
                            <td class="text-end">Rp <?= number_format($total_periode, 0, ',', '.') ?></td>
                        </tr>

                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">Tidak ada transaksi lunas pada periode tanggal ini.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="row mt-5">
                <div class="col-md-4 offset-md-8 text-center">
                    <p>Jakarta, <?= date('d F Y') ?></p>
                    <br><br><br>
                    <p class="fw-bold text-decoration-underline">( Admin Keuangan )</p>
                </div>
            </div>

        </div>

        <div class="text-center my-5 no-print">
            <button onclick="window.print()" class="btn btn-success btn-lg px-5 shadow"><i class="fas fa-print"></i> Cetak Laporan (PDF)</button>
        </div>

    </div>
</body>
</html>