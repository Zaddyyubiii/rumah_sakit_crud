<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

$sql = "SELECT tm.*, p.Shift, dp.Nama_Departemen 
        FROM TENAGA_MEDIS tm 
        JOIN PERAWAT p ON tm.ID_Tenaga_Medis = p.ID_Tenaga_Medis 
        JOIN DEPARTEMEN dp ON tm.ID_Departemen = dp.ID_Departemen 
        ORDER BY tm.Nama_Tenaga_Medis ASC";
$data = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$total = count($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Laporan Perawat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> @media print { .no-print { display: none !important; } body { background: white; } } </style>
</head>
<body class="bg-light p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="dashboard.php" class="btn btn-secondary">Kembali</a>
        <button onclick="window.print()" class="btn btn-info text-white">Cetak Laporan</button>
    </div>
    
    <div class="bg-white p-5 rounded shadow-sm">
        <h2 class="text-center mb-4">LAPORAN DATA PERAWAT</h2>
        <p>Total Perawat: <strong><?= $total ?> Orang</strong></p>
        <table class="table table-bordered">
            <thead class="table-info"><tr><th>ID</th><th>Nama Perawat</th><th>Departemen</th><th>Shift Jaga</th></tr></thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?= $row['id_tenaga_medis'] ?></td>
                    <td><?= $row['nama_tenaga_medis'] ?></td>
                    <td><?= $row['nama_departemen'] ?></td>
                    <td><?= $row['shift'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-end mt-5"><p>Jakarta, <?= date('d F Y') ?></p><br><br><b>( Kepala Keperawatan )</b></div>
    </div>
</body>
</html>