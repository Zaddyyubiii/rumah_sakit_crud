<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
$id_rm = $_GET['id'] ?? null;

if (!$id_tm || !$id_rm) {
    die("Akses tidak valid.");
}

/* ================================
   1. DATA HEADER REKAM MEDIS
   ================================ */
$stmt = $conn->prepare("
    SELECT rm.*, p.nama AS nama_pasien
    FROM rekam_medis rm
    JOIN pasien p ON rm.id_pasien = p.id_pasien
    WHERE rm.id_rekam_medis = ?
      AND rm.id_tenaga_medis = ?
");
$stmt->execute([$id_rm, $id_tm]);
$rm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rm) {
    die("Rekam medis tidak ditemukan atau bukan milik Anda.");
}

/* ================================
   2. LOG PEMERIKSAAN + MULTI LAYANAN
   ================================ */
$stmt = $conn->prepare("
    SELECT
        pe.id_pemeriksaan,
        pe.tanggal_pemeriksaan,
        pe.waktu_pemeriksaan,
        pe.diagnosis,
        pe.hasil_pemeriksaan,
        COALESCE(
            STRING_AGG(l.nama_layanan, ', ' ORDER BY l.nama_layanan),
            '-'
        ) AS daftar_layanan
    FROM pemeriksaan pe
    LEFT JOIN detail_pemeriksaan dp
           ON pe.id_pemeriksaan = dp.id_pemeriksaan
    LEFT JOIN layanan l
           ON dp.id_layanan = l.id_layanan
    WHERE pe.id_rekam_medis = ?
    GROUP BY pe.id_pemeriksaan
    ORDER BY pe.tanggal_pemeriksaan DESC, pe.waktu_pemeriksaan DESC
");
$stmt->execute([$id_rm]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Detail Rekam Medis</title>
<style>
    body { font-family: system-ui, sans-serif; background:#f4f6f8; margin:0; padding:24px; }
    .container { max-width:1100px; margin:auto; }
    .card { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:20px; }
    h1 { margin:0 0 6px 0; font-size:22px; }
    h2 { margin:0; font-size:18px; }
    .muted { color:#607d8b; font-size:13px; }
    .badge { padding:4px 10px; border-radius:999px; font-size:12px; }
    .badge-jalan { background:#c8e6c9; color:#2e7d32; }
    .badge-inap { background:#ffcdd2; color:#c62828; }
    .badge-netral { background:#eceff1; color:#546e7a; }
    table { width:100%; border-collapse:collapse; font-size:14px; }
    th, td { padding:10px 8px; border-bottom:1px solid #eee; vertical-align:top; }
    th { background:#f5f7fa; text-align:left; }
    .btn { padding:6px 14px; border-radius:999px; font-size:13px; text-decoration:none; display:inline-block; }
    .btn-primary { background:#1976d2; color:#fff; }
    .btn-secondary { background:#eceff1; color:#37474f; }
    .empty { text-align:center; color:#90a4ae; padding:20px 0; }
</style>
</head>
<body>

<div class="container">

    <!-- HEADER RM -->
    <div class="card">
        <h1>Rekam Medis <?= htmlspecialchars($rm['id_rekam_medis']) ?></h1>
        <div class="muted">
            Pasien: <b><?= htmlspecialchars($rm['nama_pasien']) ?></b><br>
            Tanggal Dibuat: <?= date('d/m/Y', strtotime($rm['tanggal_catatan'])) ?>
        </div>
        <div style="margin-top:10px;">
            <?php
                $jr = $rm['jenis_rawat'] ?? 'Belum Ditentukan';
                if ($jr === 'Rawat Inap') {
                    echo '<span class="badge badge-inap">Rawat Inap</span>';
                } elseif ($jr === 'Rawat Jalan') {
                    echo '<span class="badge badge-jalan">Rawat Jalan</span>';
                } else {
                    echo '<span class="badge badge-netral">Belum Ditentukan</span>';
                }
            ?>
        </div>
    </div>

    <!-- LOG PEMERIKSAAN -->
    <div class="card">
        <h2>Log Pemeriksaan</h2>

        <?php if (empty($logs)): ?>
            <div class="empty">Belum ada pemeriksaan untuk rekam medis ini.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Layanan / Tindakan</th>
                        <th>Diagnosis</th>
                        <th>Hasil Pemeriksaan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($log['tanggal_pemeriksaan'])) ?></td>
                        <td><?= htmlspecialchars($log['waktu_pemeriksaan']) ?></td>
                        <td><?= htmlspecialchars($log['daftar_layanan']) ?></td>
                        <td><?= nl2br(htmlspecialchars($log['diagnosis'] ?? '-')) ?></td>
                        <td><?= nl2br(htmlspecialchars($log['hasil_pemeriksaan'] ?? '-')) ?></td>
                        <td>
                            <a class="btn btn-secondary"
                               href="pemeriksaan_detail.php?id=<?= htmlspecialchars($log['id_pemeriksaan']) ?>">
                               Edit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <a class="btn btn-secondary" href="dashboard.php?view=rekam_medis">← Kembali</a>

</div>

</body>
</html>
