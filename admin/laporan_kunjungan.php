<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

// Filter Tanggal
$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');

$sql = "SELECT rm.*, p.Nama AS nama_pasien, tm.Nama_Tenaga_Medis AS nama_dokter 
        FROM REKAM_MEDIS rm 
        JOIN PASIEN p ON rm.ID_Pasien = p.ID_Pasien 
        JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis
        WHERE rm.Tanggal_Catatan BETWEEN ? AND ?
        ORDER BY rm.Tanggal_Catatan DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Laporan Kunjungan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> @media print { .no-print { display: none !important; } body { background: white; } } </style>
</head>
<body class="bg-light p-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="dashboard.php" class="btn btn-secondary">Kembali</a>
        <form class="d-flex gap-2">
            <input type="date" name="tgl_awal" class="form-control" value="<?= $tgl_awal ?>">
            <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir ?>">
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
        <button onclick="window.print()" class="btn btn-secondary">Cetak</button>
    </div>
    
    <div class="bg-white p-5 rounded shadow-sm">
        <h2 class="text-center mb-2">LAPORAN KUNJUNGAN PASIEN</h2>
        <p class="text-center text-muted mb-4">Periode: <?= date('d M Y', strtotime($tgl_awal)) ?> s/d <?= date('d M Y', strtotime($tgl_akhir)) ?></p>
        
        <table class="table table-bordered table-striped">
            <thead class="table-secondary"><tr><th>Tanggal</th><th>ID RM</th><th>Pasien</th><th>Dokter</th><th>Diagnosis</th></tr></thead>
            <tbody>
                <?php if($total > 0): ?>
                    <?php foreach($data as $row): ?>
                    <tr>
                        <td><?= date('d-m-Y', strtotime($row['tanggal_catatan'])) ?></td>
                        <td><?= $row['id_rekam_medis'] ?></td>
                        <td><?= $row['nama_pasien'] ?></td>
                        <td><?= $row['nama_dokter'] ?></td>
                        <td><?= $row['diagnosis'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center">Tidak ada data kunjungan pada periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="mt-4">
            <strong>Total Kunjungan: <?= $total ?> Pasien</strong>
        </div>
        <div class="text-end mt-5"><p>Jakarta, <?= date('d F Y') ?></p><br><br><b>( Admin RS )</b></div>
    </div>
</body>
</html>