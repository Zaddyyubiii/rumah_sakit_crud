<?php
// File: dokter/dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

// Pastikan yang masuk cuma Dokter
require_role('dokter');

$id_tm    = $_SESSION['id_tenaga_medis'] ?? null;
$username = $_SESSION['username'] ?? 'Dokter';

if (!$id_tm) {
    die("<div style='padding:20px; color:red;'>Error: Akun Anda tidak terhubung dengan Data Tenaga Medis. Hubungi Admin.</div>");
}

// View aktif (Default: rekam_medis)
$view = $_GET['view'] ?? 'rekam_medis';

// Untuk pesan sukses / error
$flash_success = null;
$flash_error   = null;

// ==========================================================
// 0. HANDLE FORM POST (Pasien, Pemeriksaan, Layanan dari menu Layanan)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';

    try {
        // Tambah Pasien baru
        if ($form_type === 'pasien_add') {
            $view = 'pasien';

            $id_pasien      = trim($_POST['id_pasien'] ?? '');
            $nama           = trim($_POST['nama'] ?? '');
            $tanggal_lahir  = $_POST['tanggal_lahir'] ?? '';
            $alamat         = trim($_POST['alamat'] ?? '');
            $nomor_telepon  = trim($_POST['nomor_telepon'] ?? '');

            if ($id_pasien === '' || $nama === '' || $tanggal_lahir === '' || $alamat === '' || $nomor_telepon === '') {
                throw new Exception("Semua field pasien wajib diisi.");
            }

            $sql = "INSERT INTO pasien (id_pasien, nama, tanggal_lahir, alamat, nomor_telepon)
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id_pasien, $nama, $tanggal_lahir, $alamat, $nomor_telepon]);

            $flash_success = "Pasien baru berhasil ditambahkan.";
        }

        // Tambah Jadwal Pemeriksaan
        if ($form_type === 'pemeriksaan_add') {
            $view = 'pemeriksaan';

            $id_pemeriksaan      = trim($_POST['id_pemeriksaan'] ?? '');
            $id_pasien           = $_POST['id_pasien'] ?? '';
            $tanggal_pemeriksaan = $_POST['tanggal_pemeriksaan'] ?? '';
            $waktu_pemeriksaan   = $_POST['waktu_pemeriksaan'] ?? '';
            $ruang_pemeriksaan   = trim($_POST['ruang_pemeriksaan'] ?? '');

            if ($id_pemeriksaan === '' || $id_pasien === '' || $tanggal_pemeriksaan === '' || $waktu_pemeriksaan === '' || $ruang_pemeriksaan === '') {
                throw new Exception("Semua field jadwal pemeriksaan wajib diisi.");
            }

            $sql = "INSERT INTO pemeriksaan (
                        id_pemeriksaan, id_pasien, id_tenaga_medis, 
                        tanggal_pemeriksaan, waktu_pemeriksaan, ruang_pemeriksaan
                    )
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $id_pemeriksaan, $id_pasien, $id_tm,
                $tanggal_pemeriksaan, $waktu_pemeriksaan, $ruang_pemeriksaan
            ]);

            $flash_success = "Jadwal pemeriksaan baru berhasil dibuat.";
        }

        // Tambah / update Detail Pemeriksaan dari menu LAYANAN (bukan dari Isi Hasil)
        if ($form_type === 'detail_pem_add') {
            $view = 'layanan';

            $id_pemeriksaan = $_POST['id_pemeriksaan'] ?? '';
            $id_layanan     = $_POST['id_layanan'] ?? '';
            $konsultasi     = trim($_POST['konsultasi'] ?? '');
            $suntik_vitamin = trim($_POST['suntik_vitamin'] ?? '');

            if ($id_pemeriksaan === '' || $id_layanan === '' || $konsultasi === '') {
                throw new Exception("Pemeriksaan, layanan, dan konsultasi wajib diisi.");
            }

            // pastikan pemeriksaan milik dokter
            $cek = $conn->prepare("SELECT 1 FROM pemeriksaan WHERE id_pemeriksaan = ? AND id_tenaga_medis = ?");
            $cek->execute([$id_pemeriksaan, $id_tm]);
            if (!$cek->fetchColumn()) {
                throw new Exception("Pemeriksaan tidak ditemukan atau bukan milik Anda.");
            }

            // UPSERT ke detail_pemeriksaan
            $sqlDet = "
                INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (id_layanan, id_pemeriksaan)
                DO UPDATE SET
                    konsultasi     = EXCLUDED.konsultasi,
                    suntik_vitamin = EXCLUDED.suntik_vitamin
            ";
            $stmtDet = $conn->prepare($sqlDet);
            $stmtDet->execute([$id_layanan, $id_pemeriksaan, $konsultasi, $suntik_vitamin]);

            $flash_success = "Layanan lanjutan berhasil disimpan untuk pemeriksaan tersebut.";
        }

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
    } catch (PDOException $e) {
        $flash_error = "Terjadi kesalahan database: " . $e->getMessage();
    }
}

// ==========================================================
// 1. LOGIK REKAM MEDIS (view = rekam_medis)
// ==========================================================
$rekam_medis = [];
$stat_rm = ['total_rm' => 0, 'total_pasien_rm' => 0];

if ($view === 'rekam_medis') {
    $sql = "SELECT 
                rm.id_rekam_medis,
                p.nama AS nama_pasien,
                rm.tanggal_catatan,
                rm.diagnosis,
                rm.hasil_pemeriksaan,
                rm.id_pasien
            FROM rekam_medis rm
            JOIN pasien p ON rm.id_pasien = p.id_pasien
            WHERE rm.id_tenaga_medis = ? 
            ORDER BY rm.tanggal_catatan DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $rekam_medis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tentukan status Rawat Jalan / Rawat Inap per rekam medis
    foreach ($rekam_medis as &$rm) {
        // Asumsi: ID Rekam Medis RMxxx berpasangan dengan ID Pemeriksaan PExxx
        $num = preg_replace('/\D/', '', $rm['id_rekam_medis']);
        $num = str_pad($num, 3, '0', STR_PAD_LEFT);
        $id_pem = 'PE' . $num;
        $rm['id_pemeriksaan'] = $id_pem;

        $sqlJR = "SELECT l.id_layanan, l.nama_layanan
                  FROM detail_pemeriksaan dp
                  JOIN layanan l ON l.id_layanan = dp.id_layanan
                  WHERE dp.id_pemeriksaan = ?
                  LIMIT 1";
        $stJR = $conn->prepare($sqlJR);
        $stJR->execute([$id_pem]);
        $rowJR = $stJR->fetch(PDO::FETCH_ASSOC);

        if ($rowJR && (strpos(strtolower($rowJR['nama_layanan']), 'kamar rawat inap') !== false || $rowJR['id_layanan'] === 'L005')) {
            $rm['jenis_rawat'] = 'Rawat Inap';
        } else {
            $rm['jenis_rawat'] = 'Rawat Jalan';
        }
    }
    unset($rm);

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
// 2. LOGIK PASIEN (view = pasien)
// ==========================================================
$pasien = [];
$stat_pasien = ['total_pasien' => 0];

if ($view === 'pasien') {
    $sql = "SELECT 
                p.id_pasien,
                p.nama,
                p.tanggal_lahir,
                p.alamat,
                p.nomor_telepon
            FROM pasien p
            ORDER BY p.nama";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $pasien = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stat_pasien['total_pasien'] = count($pasien);
}

// ==========================================================
// 3. LOGIK PEMERIKSAAN (view = pemeriksaan)
// ==========================================================
$pemeriksaan = [];
$stat_pem = ['total_pem' => 0];
$daftar_pasien_all = [];

if ($view === 'pemeriksaan') {

    $sql = "SELECT 
                pe.id_pemeriksaan,
                p.nama AS nama_pasien,
                pe.tanggal_pemeriksaan,
                pe.waktu_pemeriksaan,
                pe.ruang_pemeriksaan,
                (
                    SELECT COUNT(*) 
                    FROM detail_pemeriksaan dp 
                    WHERE dp.id_pemeriksaan = pe.id_pemeriksaan
                ) AS jumlah_detail
            FROM pemeriksaan pe
            JOIN pasien p ON pe.id_pasien = p.id_pasien
            WHERE pe.id_tenaga_medis = ?
            ORDER BY pe.tanggal_pemeriksaan DESC, pe.waktu_pemeriksaan DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stat_pem['total_pem'] = count($pemeriksaan);

    // Pasien untuk dropdown
    $daftar_pasien_all = $conn->query("SELECT id_pasien, nama FROM pasien ORDER BY nama")->fetchAll(PDO::FETCH_ASSOC);
}

// ==========================================================
// 4. LOGIK LAYANAN (view = layanan)
// ==========================================================
$layanan = [];
$stat_layanan = ['total_jenis' => 0, 'total_pem_layanan' => 0];
$daftar_layanan_all_l = [];
$daftar_pemeriksaan_dokter = [];

if ($view === 'layanan') {

    $sql = "SELECT 
                l.id_layanan,
                l.nama_layanan,
                l.tarif_dasar,
                COUNT(DISTINCT dp.id_pemeriksaan) AS jumlah_pemeriksaan,
                COUNT(DISTINCT p.id_pasien)       AS jumlah_pasien
            FROM detail_pemeriksaan dp
            JOIN pemeriksaan pe ON dp.id_pemeriksaan = pe.id_pemeriksaan
            JOIN layanan l      ON dp.id_layanan = l.id_layanan
            JOIN pasien p       ON pe.id_pasien = p.id_pasien
            WHERE pe.id_tenaga_medis = ?
            GROUP BY l.id_layanan, l.nama_layanan, l.tarif_dasar
            ORDER BY l.nama_layanan";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $layanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stat_layanan['total_jenis'] = count($layanan);

    $totalPem = 0;
    foreach ($layanan as $row) {
        $totalPem += (int)$row['jumlah_pemeriksaan'];
    }
    $stat_layanan['total_pem_layanan'] = $totalPem;

    // Untuk form tambah layanan lanjutan
    $daftar_layanan_all_l = $conn->query("SELECT id_layanan, nama_layanan FROM layanan ORDER BY nama_layanan")->fetchAll(PDO::FETCH_ASSOC);

    $sqlPer = "SELECT 
                    pe.id_pemeriksaan,
                    p.nama AS nama_pasien,
                    pe.tanggal_pemeriksaan
               FROM pemeriksaan pe
               JOIN pasien p ON pe.id_pasien = p.id_pasien
               WHERE pe.id_tenaga_medis = ?
               ORDER BY pe.tanggal_pemeriksaan DESC";
    $stPer = $conn->prepare($sqlPer);
    $stPer->execute([$id_tm]);
    $daftar_pemeriksaan_dokter = $stPer->fetchAll(PDO::FETCH_ASSOC);
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
        
        .sidebar { background: #0d47a1; color: #e3f2fd; padding: 24px 20px; }
        .sidebar h2 { margin-top: 0; font-size: 20px; margin-bottom: 4px; }
        .sidebar .role { font-size: 13px; opacity: 0.85; margin-bottom: 24px; }
        .sidebar a { display: block; text-decoration: none; color: #e3f2fd; padding: 8px 10px; border-radius: 6px; font-size: 14px; margin-bottom: 6px; }
        .sidebar a.active { background: rgba(255,255,255,0.16); font-weight: 600; }
        .sidebar a:hover { background: rgba(255,255,255,0.08); }
        .sidebar a.logout { margin-top: 20px; background: rgba(244, 67, 54, 0.15); color: #ffcdd2; }
        
        .content { padding: 24px 32px; }
        .content-header { margin-bottom: 18px; }
        .content-header h1 { margin: 0; font-size: 22px; color: #263238; }
        .content-header span { font-size: 13px; color: #78909c; }

        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 4px 0; font-size: 15px; }
        .card span { font-size: 13px; color: #607d8b; }
        .card-strong { font-size: 22px; font-weight: 700; color: #1565c0; }

        .section { background: #ffffff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .section-header h2 { margin: 0; font-size: 17px; }
        
        .btn-primary, .btn-secondary { border-radius: 999px; padding: 6px 14px; font-size: 13px; border: none; cursor: pointer; text-decoration: none; display:inline-block;}
        .btn-primary { background: #1976d2; color: white; }
        .btn-primary:hover { background: #1258a3; }
        .btn-secondary { background:#eceff1; color:#37474f; }
        .btn-secondary:hover { background:#d7dde2; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eceff1; text-align: left; }
        th { background: #f5f7fa; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        .empty { font-size: 13px; color: #90a4ae; padding: 8px 0; text-align: center; }

        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; }
        .badge-success { background:#c8e6c9; color:#2e7d32; }
        .badge-warning { background:#fff3cd; color:#f57f17; }

        .alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
        .alert-success { background:#e8f5e9; color:#2e7d32; }
        .alert-error { background:#ffebee; color:#c62828; }

        .form-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:10px 16px; margin-bottom:10px; }
        .form-grid label { font-size:12px; color:#546e7a; display:block; margin-bottom:2px; }
        .form-grid input, .form-grid select, .form-grid textarea {
            width:100%; padding:6px 8px; font-size:13px;
            border-radius:6px; border:1px solid #cfd8dc;
        }
        textarea { resize: vertical; min-height:60px; }
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
        <a href="?view=layanan"     class="<?= $view==='layanan' ? 'active' : '' ?>">Layanan</a>

        <a class="logout" href="../auth/logout.php">Logout</a>
    </aside>

    <main class="content">

        <?php if ($flash_success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <?php if ($view === 'rekam_medis'): ?>
            <!-- REKAM MEDIS -->
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
                            <th>Jenis Rawat</th>
                            <th>Aksi</th>
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
                                <td><?= htmlspecialchars($rm['jenis_rawat']) ?></td>
                                <td>
                                    <a class="btn-secondary" style="padding:3px 10px; font-size:11px; margin-right:4px;"
                                       href="rekam_medis_edit.php?id=<?= urlencode($rm['id_rekam_medis']) ?>">
                                        Edit
                                    </a>
                                    <a class="btn-secondary" style="padding:3px 10px; font-size:11px;"
                                       href="pemeriksaan_detail.php?id=<?= urlencode($rm['id_pemeriksaan']) ?>">
                                        Rawat
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'pasien'): ?>
            <!-- PASIEN -->
            <div class="content-header">
                <h1>Pasien Saya</h1>
                <span>Daftar pasien di sistem.</span>
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
                    <h2>Tambah Pasien Baru</h2>
                </div>
                <form method="post">
                    <input type="hidden" name="form_type" value="pasien_add">
                    <div class="form-grid">
                        <div>
                            <label>ID Pasien</label>
                            <input type="text" name="id_pasien" placeholder="Misal: P011">
                        </div>
                        <div>
                            <label>Nama Lengkap</label>
                            <input type="text" name="nama">
                        </div>
                        <div>
                            <label>Tanggal Lahir</label>
                            <input type="date" name="tanggal_lahir">
                        </div>
                        <div>
                            <label>No. Telepon</label>
                            <input type="text" name="nomor_telepon">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div style="grid-column: 1 / -1;">
                            <label>Alamat</label>
                            <input type="text" name="alamat">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Simpan Pasien</button>
                </form>
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
            <!-- PEMERIKSAAN -->
            <div class="content-header">
                <h1>Jadwal Pemeriksaan</h1>
                <span>Daftar pemeriksaan fisik yang dijadwalkan dan hasilnya.</span>
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
                    <h2>Buat Jadwal Pemeriksaan Baru</h2>
                </div>

                <form method="post">
                    <input type="hidden" name="form_type" value="pemeriksaan_add">
                    <div class="form-grid">
                        <div>
                            <label>ID Pemeriksaan</label>
                            <input type="text" name="id_pemeriksaan" placeholder="Misal: PE011">
                        </div>
                        <div>
                            <label>Pasien</label>
                            <select name="id_pasien">
                                <option value="">-- Pilih Pasien --</option>
                                <?php foreach ($daftar_pasien_all as $ps): ?>
                                    <option value="<?= htmlspecialchars($ps['id_pasien']) ?>">
                                        <?= htmlspecialchars($ps['nama']) ?> (<?= htmlspecialchars($ps['id_pasien']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Tanggal Pemeriksaan</label>
                            <input type="date" name="tanggal_pemeriksaan">
                        </div>
                        <div>
                            <label>Waktu</label>
                            <input type="time" name="waktu_pemeriksaan">
                        </div>
                        <div>
                            <label>Ruang</label>
                            <input type="text" name="ruang_pemeriksaan" placeholder="R-01">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Simpan Jadwal</button>
                </form>
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
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pemeriksaan as $pe): ?>
                            <tr>
                                <td><?= htmlspecialchars($pe['id_pemeriksaan']) ?></td>
                                <td><?= htmlspecialchars($pe['nama_pasien']) ?></td>
                                <td><?= date('d-m-Y', strtotime($pe['tanggal_pemeriksaan'])) ?></td>
                                <td><?= htmlspecialchars($pe['waktu_pemeriksaan']) ?></td>
                                <td><span class="badge" style="background:#e3f2fd; color:#1565c0;"><?= htmlspecialchars($pe['ruang_pemeriksaan']) ?></span></td>
                                <td>
                                    <?php if ($pe['jumlah_detail'] > 0): ?>
                                        <span class="badge badge-success">Selesai</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Belum</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn-primary" style="padding:3px 10px; font-size:11px;"
                                       href="pemeriksaan_detail.php?id=<?= urlencode($pe['id_pemeriksaan']) ?>">
                                        Isi Hasil / Layanan
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'layanan'): ?>
            <!-- LAYANAN -->
            <div class="content-header">
                <h1>Layanan</h1>
                <span>Daftar layanan medis yang pernah Anda gunakan, dan penambahan layanan lanjutan.</span>
            </div>

            <div class="cards">
                <div class="card">
                    <h3>Jenis Layanan</h3>
                    <div class="card-strong"><?= (int)$stat_layanan['total_jenis'] ?></div>
                    <span>Total jenis layanan yang Anda gunakan.</span>
                </div>
                <div class="card">
                    <h3>Total Pemeriksaan</h3>
                    <div class="card-strong"><?= (int)$stat_layanan['total_pem_layanan'] ?></div>
                    <span>Jumlah pemeriksaan yang melibatkan layanan tersebut.</span>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>Tambah Layanan Lanjutan untuk Pemeriksaan</h2>
                </div>
                <form method="post">
                    <input type="hidden" name="form_type" value="detail_pem_add">

                    <div class="form-grid">
                        <div>
                            <label>Pemeriksaan</label>
                            <select name="id_pemeriksaan">
                                <option value="">-- Pilih Pemeriksaan --</option>
                                <?php foreach ($daftar_pemeriksaan_dokter as $pe): ?>
                                    <option value="<?= htmlspecialchars($pe['id_pemeriksaan']) ?>">
                                        <?= htmlspecialchars($pe['id_pemeriksaan']) ?> -
                                        <?= htmlspecialchars($pe['nama_pasien']) ?> (<?= date('d-m-Y', strtotime($pe['tanggal_pemeriksaan'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Layanan Lanjutan</label>
                            <select name="id_layanan">
                                <option value="">-- Pilih Layanan --</option>
                                <?php foreach ($daftar_layanan_all_l as $ly): ?>
                                    <option value="<?= htmlspecialchars($ly['id_layanan']) ?>">
                                        <?= htmlspecialchars($ly['nama_layanan']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Suntik Vitamin</label>
                            <input type="text" name="suntik_vitamin" placeholder="Ya / Tidak / Jenis vitamin">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div style="grid-column:1 / -1;">
                            <label>Konsultasi / Alasan Tambahan Layanan</label>
                            <textarea name="konsultasi" placeholder="Misal: kondisi memburuk, perlu operasi / rawat inap / radiologi."></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Simpan Layanan Lanjutan</button>
                </form>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>Ringkasan Layanan</h2>
                </div>

                <?php if (count($layanan) === 0): ?>
                    <div class="empty">Belum ada layanan yang tercatat pada pemeriksaan Anda.</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>ID Layanan</th>
                            <th>Nama Layanan</th>
                            <th>Tarif Dasar</th>
                            <th>Jumlah Pemeriksaan</th>
                            <th>Pasien Terlayani</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($layanan as $l): ?>
                            <tr>
                                <td><?= htmlspecialchars($l['id_layanan']) ?></td>
                                <td><b><?= htmlspecialchars($l['nama_layanan']) ?></b></td>
                                <td>Rp <?= number_format((float)$l['tarif_dasar'], 0, ',', '.') ?></td>
                                <td><?= (int)$l['jumlah_pemeriksaan'] ?></td>
                                <td><?= (int)$l['jumlah_pasien'] ?></td>
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
