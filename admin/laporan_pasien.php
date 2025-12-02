<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';
require_role('admin');

$data = $conn->query("SELECT * FROM PASIEN ORDER BY Nama ASC")->fetchAll(PDO::FETCH_ASSOC);
$total = count($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Laporan Pasien</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> @media print { .no-print { display: none !important; } body { background: white; } } </style>
</head>
<body class="bg-light p-4">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <a href="dashboard.php" class="btn btn-secondary">Kembali</a>
        <button onclick="window.print()" class="btn btn-primary">Cetak Laporan</button>
    </div>
    
    <div class="bg-white p-5 rounded shadow-sm">
        <h2 class="text-center mb-4">LAPORAN DATA PASIEN</h2>
        <p>Total Terdaftar: <strong><?= $total ?> Pasien</strong></p>
        <table class="table table-bordered table-striped">
            <thead class="table-dark"><tr><th>ID</th><th>Nama Lengkap</th><th>Tgl Lahir</th><th>Alamat</th><th>No Telp</th></tr></thead>
            <tbody>
                <?php foreach($data as $row): ?>
                <tr>
                    <td><?= $row['id_pasien'] ?></td>
                    <td><?= $row['nama'] ?></td>
                    <td><?= date('d-m-Y', strtotime($row['tanggal_lahir'])) ?></td>
                    <td><?= $row['alamat'] ?></td>
                    <td><?= $row['nomor_telepon'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-end mt-5"><p>Jakarta, <?= date('d F Y') ?></p><br><br><b>( Admin RS )</b></div>
    </div>
</body>
</html>