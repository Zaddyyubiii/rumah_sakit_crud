<?php
// File: admin/cetak_invoice.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

require_role('admin');

$id_tagihan = $_GET['id'] ?? '';

if (empty($id_tagihan)) die("ID Tagihan tidak ditemukan.");

// 1. Ambil Data Header Tagihan & Pasien
$sql = "SELECT t.*, p.Nama AS nama_pasien, p.Alamat, p.Nomor_Telepon 
        FROM TAGIHAN t 
        JOIN PASIEN p ON t.ID_Pasien = p.ID_Pasien 
        WHERE t.ID_Tagihan = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_tagihan]);
$header = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$header) die("Data tagihan tidak ditemukan.");

// 2. Ambil Detail Item Layanan (Jika ada di tabel DETAIL_TAGIHAN)
// Note: Kalau tabel detail kosong, kita pakai logika layanan rawat inap/jalan manual
$sql_detail = "SELECT dt.*, l.Nama_Layanan, l.Tarif_Dasar 
               FROM DETAIL_TAGIHAN dt 
               JOIN LAYANAN l ON dt.ID_Layanan = l.ID_Layanan 
               WHERE dt.ID_Tagihan = ?";
$stmt_d = $conn->prepare($sql_detail);
$stmt_d->execute([$id_tagihan]);
$items = $stmt_d->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?= $id_tagihan ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #eee; font-family: 'Courier New', Courier, monospace; }
        .invoice-box {
            max-width: 800px; margin: 30px auto; padding: 30px;
            border: 1px solid #eee; box-shadow: 0 0 10px rgba(0, 0, 0, 0.15);
            background: white;
        }
        @media print {
            body { background: white; }
            .invoice-box { box-shadow: none; border: none; margin: 0; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="invoice-box">
    <div class="row mb-4">
        <div class="col-8">
            <h2 class="fw-bold text-primary">RUMAH SAKIT Kelompok 5</h2>
            <p class="mb-0">Jl. Kesehatan No. 99, Jakarta</p>
            <p>Telp: (021) 555-5555</p>
        </div>
        <div class="col-4 text-end">
            <h4 class="fw-bold">INVOICE</h4>
            <p class="mb-0">No: <strong><?= $header['id_tagihan'] ?></strong></p>
            <p>Tgl: <?= date('d M Y', strtotime($header['tanggal_tagihan'])) ?></p>
            <span class="badge <?= $header['status_pembayaran'] == 'Lunas' ? 'bg-success' : 'bg-danger' ?> fs-6">
                <?= strtoupper($header['status_pembayaran']) ?>
            </span>
        </div>
    </div>

    <hr>

    <div class="row mb-4">
        <div class="col-6">
            <h6 class="fw-bold">Ditagihkan Kepada:</h6>
            <p class="mb-0"><strong><?= $header['nama_pasien'] ?></strong></p>
            <p class="mb-0"><?= $header['alamat'] ?></p>
            <p><?= $header['nomor_telepon'] ?></p>
        </div>
    </div>

    <table class="table table-bordered">
        <thead class="table-light">
            <tr>
                <th>Deskripsi Layanan</th>
                <th class="text-center">Jml</th>
                <th class="text-end">Harga Satuan</th>
                <th class="text-end">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) > 0): ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= $item['nama_layanan'] ?></td>
                    <td class="text-center"><?= $item['jumlah'] ?></td>
                    <td class="text-end">Rp <?= number_format($item['tarif_dasar'], 0, ',', '.') ?></td>
                    <td class="text-end">Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3">Biaya Pengobatan & Perawatan (Global)</td>
                    <td class="text-end">Rp <?= number_format($header['total_biaya'], 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>
            
            <tr class="table-group-divider fw-bold">
                <td colspan="3" class="text-end">TOTAL TAGIHAN</td>
                <td class="text-end">Rp <?= number_format($header['total_biaya'], 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <div class="row mt-5">
        <div class="col-6">
            <p>Metode Pembayaran: Transfer / Tunai</p>
            <p class="text-muted small">* Kwitansi ini sah sebagai bukti pembayaran.</p>
        </div>
        <div class="col-6 text-end">
            <p>Jakarta, <?= date('d F Y') ?></p>
            <br><br>
            <p class="fw-bold">( Admin Keuangan )</p>
        </div>
    </div>

    <div class="text-center mt-4 no-print">
        <button onclick="window.print()" class="btn btn-primary btn-lg">🖨️ Cetak Invoice</button>
        <a href="detail_pasien.php?id=<?= $header['id_pasien'] ?>" class="btn btn-secondary btn-lg">Kembali</a>
    </div>
</div>

</body>
</html>