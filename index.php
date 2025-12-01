<?php
// File: index.php (Di folder root rumah_sakit_crud)

// PERUBAHAN 1: Path ke db.php masuk ke folder includes
include 'includes/db.php'; 

// --- Query tidak berubah ---
$query = "SELECT 
            rm.id_rekam_medis, 
            p.nama AS nama_pasien, 
            d.spesialisasi, 
            tm.nama_tenaga_medis AS nama_dokter,
            rm.diagnosis, 
            rm.hasil_pemeriksaan, 
            rm.tanggal_catatan
          FROM REKAM_MEDIS rm
          JOIN PASIEN p ON rm.id_pasien = p.id_pasien
          JOIN TENAGA_MEDIS tm ON rm.id_tenaga_medis = tm.id_tenaga_medis
          JOIN DOKTER d ON tm.id_tenaga_medis = d.id_tenaga_medis
          ORDER BY rm.id_rekam_medis ASC";

$stmt = $conn->prepare($query);
$stmt->execute();
$hasil = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sistem RS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="container bg-white p-4 rounded shadow">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-primary">🏥 Data Rekam Medis</h2>
            
            <a href="admin/tambah.php" class="btn btn-success">+ Tambah Data Baru</a>
        </div>
        
        <table class="table table-bordered table-striped">
            <thead class="table-dark">
                <tr>
                    <th>ID RM</th>
                    <th>Nama Pasien</th>
                    <th>Dokter</th>
                    <th>Spesialis</th>
                    <th>Diagnosis</th>
                    <th>Hasil Periksa</th>
                    <th>Tanggal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($hasil as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['id_rekam_medis']) ?></td>
                    <td><b><?= htmlspecialchars($row['nama_pasien']) ?></b></td>
                    <td><?= htmlspecialchars($row['nama_dokter']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($row['spesialisasi']) ?></span></td>
                    <td><?= htmlspecialchars($row['diagnosis']) ?></td>
                    <td><?= htmlspecialchars($row['hasil_pemeriksaan']) ?></td>
                    <td><?= date('d-m-Y', strtotime($row['tanggal_catatan'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>