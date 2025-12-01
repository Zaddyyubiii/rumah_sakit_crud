<?php
// File: dokter/dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

// Pastikan yang masuk cuma Dokter
require_role('dokter');

$id_tm    = $_SESSION['id_tenaga_medis'] ?? null;
$username = $_SESSION['username'] ?? 'Dokter';

// Cek ID Tenaga Medis (Dokter wajib punya ID ini)
if (!$id_tm) {
    die("<div style='padding:20px; color:red;'>Error: Akun Anda tidak terhubung dengan Data Tenaga Medis. Hubungi Admin.</div>");
}

// View aktif (Default: rekam_medis)
$view = $_GET['view'] ?? 'rekam_medis';

// ==========================================================
// 1. LOGIK REKAM MEDIS
// ==========================================================
$rekam_medis = [];
$stat_rm = ['total_rm' => 0, 'total_pasien_rm' => 0];

if ($view === 'rekam_medis') {
    // Ambil daftar rekam medis buatan dokter ini
    $sql = "SELECT 
                rm.id_rekam_medis,
                p.nama AS nama_pasien,
                rm.tanggal_catatan,
                rm.diagnosis,
                rm.hasil_pemeriksaan
            FROM rekam_medis rm
            JOIN pasien p ON rm.id_pasien = p.id_pasien
            WHERE rm.id_tenaga_medis = ? 
            ORDER BY rm.tanggal_catatan DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $rekam_medis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung Statistik
    $sqlStat = "SELECT 
                    COUNT(*) AS total_rm,
                    COUNT(DISTINCT id_pasien) AS total_pasien_rm
                FROM rekam_medis
                WHERE id_tenaga_medis = ?";
    $stmtStat = $conn->prepare($sqlStat);
    $stmtStat->execute([$id_tm]);
    $stat_rm = $stmtStat->fetch(PDO::FETCH_ASSOC);
}

// ==========================================================
// 2. LOGIK PASIEN
// ==========================================================
$pasien = [];
$stat_pasien = ['total_pasien' => 0];

if ($view === 'pasien') {
    // Ambil pasien yang pernah ditangani dokter ini (Distinct biar gak dobel)
    $sql = "SELECT DISTINCT
                p.id_pasien,
                p.nama,
                p.tanggal_lahir,
                p.alamat,
                p.nomor_telepon
            FROM rekam_medis rm
            JOIN pasien p ON rm.id_pasien = p.id_pasien
            WHERE rm.id_tenaga_medis = ?
            ORDER BY p.nama";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $pasien = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stat_pasien['total_pasien'] = count($pasien);
}

// ==========================================================
// 3. LOGIK PEMERIKSAAN
// ==========================================================
$pemeriksaan = [];
$stat_pem = ['total_pem' => 0];

if ($view === 'pemeriksaan') {
    $sql = "SELECT 
                pe.id_pemeriksaan,
                p.nama AS nama_pasien,
                pe.tanggal_pemeriksaan,
                pe.waktu_pemeriksaan,
                pe.ruang_pemeriksaan
            FROM pemeriksaan pe
            JOIN pasien p ON pe.id_pasien = p.id_pasien
            WHERE pe.id_tenaga_medis = ?
            ORDER BY pe.tanggal_pemeriksaan DESC, pe.waktu_pemeriksaan DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stat_pem['total_pem'] = count($pemeriksaan);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Dokter</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #f3f6f9; }
        .layout { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        
        /* Sidebar Styling */
        .sidebar { background: #0d47a1; color: #e3f2fd; padding: 24px 20px; }
        .sidebar h2 { margin-top: 0; font-size: 20px; margin-bottom: 4px; }
        .sidebar .role { font-size: 13px; opacity: 0.85; margin-bottom: 24px; }
        .sidebar a { display: block; text-decoration: none; color: #e3f2fd; padding: 8px 10px; border-radius: 6px; font-size: 14px; margin-bottom: 6px; }
        .sidebar a.active { background: rgba(255,255,255,0.16); font-weight: 600; }
        .sidebar a:hover { background: rgba(255,255,255,0.08); }
        .sidebar a.logout { margin-top: 20px; background: rgba(244, 67, 54, 0.15); color: #ffcdd2; }
        
        /* Content Styling */
        .content { padding: 24px 32px; }
        .content-header { margin-bottom: 18px; }
        .content-header h1 { margin: 0; font-size: 22px; color: #263238; }
        .content-header span { font-size: 13px; color: #78909c; }

        /* Cards */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 4px 0; font-size: 15px; }
        .card span { font-size: 13px; color: #607d8b; }
        .card-strong { font-size: 22px; font-weight: 700; color: #1565c0; }

        /* Section & Table */
        .section { background: #ffffff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .section-header h2 { margin: 0; font-size: 17px; }
        
        .btn-primary { background: #1976d2; color: white; border-radius: 999px; padding: 6px 14px; font-size: 13px; border: none; cursor: pointer; text-decoration: none;}
        .btn-primary:hover { background: #1258a3; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eceff1; text-align: left; }
        th { background: #f5f7fa; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .empty { font-size: 13px; color: #90a4ae; padding: 8px 0; text-align: center; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2>Dashboard Dokter</h2>
        <div class="role">Halo, <?= htmlspecialchars($username) ?></div>

        <a href="?view=rekam_medis" class="<?= $view==='rekam_medis' ? 'active' : '' ?>">Rekam Medis</a>
        <a href="?view=pasien"      class="<?= $view==='pasien' ? 'active' : '' ?>">Pasien Saya</a>
        <a href="?view=pemeriksaan" class="<?= $view==='pemeriksaan' ? 'active' : '' ?>">Jadwal Pemeriksaan</a>

        <a class="logout" href="../auth/logout.php">Logout</a>
    </aside>

    <main class="content">
        <?php if ($view === 'rekam_medis'): ?>
            <div class="content-header">
                <h1>Rekam Medis</h1>
                <span>Data riwayat kesehatan pasien yang Anda tangani.</span>
            </div>

            <div class="cards">
                <div class="card">
                    <h3>Total Diagnosa</h3>
                    <div class="card-strong"><?= (int)$stat_rm['total_rm'] ?></div>
                    <span>Total rekam medis dibuat.</span>
                </div>
                <div class="card">
                    <h3>Pasien Unik</h3>
                    <div class="card-strong"><?= (int)$stat_rm['total_pasien_rm'] ?></div>
                    <span>Jumlah orang yang berbeda.</span>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>Riwayat Rekam Medis</h2>
                    <button class="btn-primary" onclick="alert('Fitur tambah data ada di Dashboard Admin untuk saat ini.')">+ Input Baru</button>
                </div>

                <?php if (count($rekam_medis) === 0): ?>
                    <div class="empty">Belum ada data rekam medis.</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Pasien</th>
                            <th>Tanggal</th>
                            <th>Diagnosis</th>
                            <th>Hasil</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rekam_medis as $rm): ?>
                            <tr>
                                <td><?= htmlspecialchars($rm['id_rekam_medis']) ?></td>
                                <td><b><?= htmlspecialchars($rm['nama_pasien']) ?></b></td>
                                <td><?= date('d-m-Y', strtotime($rm['tanggal_catatan'])) ?></td>
                                <td><span style="color:#d32f2f; font-weight:500;"><?= htmlspecialchars($rm['diagnosis']) ?></span></td>
                                <td><?= htmlspecialchars($rm['hasil_pemeriksaan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'pasien'): ?>
            <div class="content-header">
                <h1>Pasien Saya</h1>
                <span>Daftar pasien yang pernah berkonsultasi dengan Anda.</span>
            </div>

            <div class="cards">
                <div class="card">
                    <h3>Total Pasien</h3>
                    <div class="card-strong"><?= (int)$stat_pasien['total_pasien'] ?></div>
                    <span>Pasien dalam database Anda.</span>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>Data Pasien</h2>
                </div>
                <?php if (count($pasien) === 0): ?>
                    <div class="empty">Belum ada pasien.</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID Pasien</th>
                            <th>Nama</th>
                            <th>Tanggal Lahir</th>
                            <th>Alamat</th>
                            <th>No. Telepon</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pasien as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars($p['id_pasien']) ?></td>
                                <td><b><?= htmlspecialchars($p['nama']) ?></b></td>
                                <td><?= date('d-m-Y', strtotime($p['tanggal_lahir'])) ?></td>
                                <td><?= htmlspecialchars($p['alamat']) ?></td>
                                <td><?= htmlspecialchars($p['nomor_telepon']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'pemeriksaan'): ?>
            <div class="content-header">
                <h1>Jadwal Pemeriksaan</h1>
                <span>Daftar pemeriksaan fisik yang dijadwalkan.</span>
            </div>

            <div class="cards">
                <div class="card">
                    <h3>Total Pemeriksaan</h3>
                    <div class="card-strong"><?= (int)$stat_pem['total_pem'] ?></div>
                    <span>Total aktivitas pemeriksaan.</span>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>Log Pemeriksaan</h2>
                </div>
                <?php if (count($pemeriksaan) === 0): ?>
                    <div class="empty">Belum ada data pemeriksaan.</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Pasien</th>
                            <th>Tanggal</th>
                            <th>Waktu</th>
                            <th>Ruangan</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pemeriksaan as $pe): ?>
                            <tr>
                                <td><?= htmlspecialchars($pe['id_pemeriksaan']) ?></td>
                                <td><?= htmlspecialchars($pe['nama_pasien']) ?></td>
                                <td><?= date('d-m-Y', strtotime($pe['tanggal_pemeriksaan'])) ?></td>
                                <td><?= htmlspecialchars($pe['waktu_pemeriksaan']) ?></td>
                                <td><span style="background:#e3f2fd; padding:2px 8px; border-radius:4px; color:#1565c0;"><?= htmlspecialchars($pe['ruang_pemeriksaan']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>