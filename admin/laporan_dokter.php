<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

$sql = "SELECT tm.*, d.Spesialisasi, dp.Nama_Departemen 
        FROM TENAGA_MEDIS tm 
        JOIN DOKTER d ON tm.ID_Tenaga_Medis = d.ID_Tenaga_Medis 
        JOIN DEPARTEMEN dp ON tm.ID_Departemen = dp.ID_Departemen 
        ORDER BY tm.Nama_Tenaga_Medis ASC";
$data = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$total = count($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Laporan Dokter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> @media print { .no-print { display: none !important; } body { background: white; } } </style>
</head>
<body class="bg-light p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="dashboard.php" class="btn btn-secondary">Kembali</a>
        <button onclick="window.print()" class="btn btn-success">Cetak Laporan</button>
    </div>
    
    <div class="bg-white p-5 rounded shadow-sm">
        <h2 class="text-center mb-4">LAPORAN DATA DOKTER</h2>
        <p>Total Dokter: <strong><?= $total ?> Orang</strong></p>
        <table class="table table-bordered">
            <thead class="table-success"><tr><th>ID</th><th>Nama Dokter</th><th>Departemen / Poli</th><th>Spesialisasi</th></tr></thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?= $row['id_tenaga_medis'] ?></td>
                    <td><?= $row['nama_tenaga_medis'] ?></td>
                    <td><?= $row['nama_departemen'] ?></td>
                    <td><?= $row['spesialisasi'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-end mt-5"><p>Jakarta, <?= date('d F Y') ?></p><br><br><b>( Kepala RS )</b></div>
    </div>
</body>
</html>