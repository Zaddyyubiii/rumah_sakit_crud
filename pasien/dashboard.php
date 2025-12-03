<?php
// File: pasien/dashboard.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

require_role('pasien');

// ==========================================================
// AMBIL ID_PASIEN DARI SESSION / USERS
// ==========================================================
$id_pasien = $_SESSION['id_pasien'] ?? ($_SESSION['user']['id_pasien'] ?? null);

if (!$id_pasien && isset($_SESSION['user_id'])) {
    // fallback: cari dari tabel USERS
    $stmt = $conn->prepare("SELECT id_pasien FROM USERS WHERE id_user = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $id_pasien = $stmt->fetchColumn();
}

if (!$id_pasien) {
    die('ID pasien tidak ditemukan di sesi. Pastikan login pasien menyimpan id_pasien di session.');
}

// ==========================================================
// STATISTIK ATAS
// ==========================================================
$stat_rm = $conn->prepare("SELECT COUNT(*) FROM REKAM_MEDIS WHERE ID_Pasien = ?");
$stat_rm->execute([$id_pasien]);
$stat_rm = $stat_rm->fetchColumn();

$stat_periksa = $conn->prepare("SELECT COUNT(*) FROM PEMERIKSAAN WHERE ID_Pasien = ?");
$stat_periksa->execute([$id_pasien]);
$stat_periksa = $stat_periksa->fetchColumn();

$stat_layanan = $conn->prepare("
    SELECT COALESCE(COUNT(*),0) 
    FROM DETAIL_PEMERIKSAAN dp
    JOIN PEMERIKSAAN p ON p.ID_Pemeriksaan = dp.ID_Pemeriksaan
    WHERE p.ID_Pasien = ?
");
$stat_layanan->execute([$id_pasien]);
$stat_layanan = $stat_layanan->fetchColumn();

$stat_tagihan_aktif = $conn->prepare("
    SELECT COALESCE(SUM(Total_Biaya),0)
    FROM TAGIHAN
    WHERE ID_Pasien = ?
      AND Status_Pembayaran IN ('Belum Lunas', 'Dicicil')
");
$stat_tagihan_aktif->execute([$id_pasien]);
$stat_tagihan_aktif = $stat_tagihan_aktif->fetchColumn();

// ==========================================================
// DATA PROFIL PASIEN
// ==========================================================
$stmt = $conn->prepare("SELECT * FROM PASIEN WHERE ID_Pasien = ?");
$stmt->execute([$id_pasien]);
$profil = $stmt->fetch(PDO::FETCH_ASSOC);

// ==========================================================
// FILTER CARI GLOBAL + TAB AKTIF
// ==========================================================
$keyword    = isset($_GET['q']) ? trim($_GET['q']) : '';
$activeTab  = $_GET['tab'] ?? 'rekam_medis'; // rekam_medis | pemeriksaan | layanan | tagihan
$keywordSql = '%' . $keyword . '%';

// ==========================================================
// DATA REKAM MEDIS
// ==========================================================
$sql_rm = "
    SELECT rm.*, 
           tm.Nama_Tenaga_Medis AS nama_dokter,
           (SELECT l.Nama_Layanan
            FROM RAWAT_INAP ri
            JOIN LAYANAN l ON ri.ID_Layanan = l.ID_Layanan
            WHERE ri.ID_Pasien = rm.ID_Pasien
              AND ri.Tanggal_Masuk = rm.Tanggal_Catatan
            LIMIT 1) AS info_kamar
    FROM REKAM_MEDIS rm
    LEFT JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis
    WHERE rm.ID_Pasien = :id_pasien
";
$params_rm = [':id_pasien' => $id_pasien];

if ($keyword !== '') {
    $sql_rm .= " AND (
        rm.Diagnosis ILIKE :kw
        OR rm.Hasil_Pemeriksaan ILIKE :kw
        OR tm.Nama_Tenaga_Medis ILIKE :kw
    )";
    $params_rm[':kw'] = $keywordSql;
}
$sql_rm .= " ORDER BY rm.Tanggal_Catatan DESC";

$stmt = $conn->prepare($sql_rm);
$stmt->execute($params_rm);
$data_rm = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================================
// DATA PEMERIKSAAN (JADWAL / RIWAYAT)
// ==========================================================
$sql_pem = "
    SELECT p.*, 
           tm.Nama_Tenaga_Medis AS nama_dokter,
           COALESCE(COUNT(dp.ID_Layanan),0) AS jml_layanan
    FROM PEMERIKSAAN p
    LEFT JOIN TENAGA_MEDIS tm ON p.ID_Tenaga_Medis = tm.ID_Tenaga_Medis
    LEFT JOIN DETAIL_PEMERIKSAAN dp ON p.ID_Pemeriksaan = dp.ID_Pemeriksaan
    WHERE p.ID_Pasien = :id_pasien
";
$params_pem = [':id_pasien' => $id_pasien];

if ($keyword !== '') {
    $sql_pem .= " AND (
        tm.Nama_Tenaga_Medis ILIKE :kw
        OR p.Ruang_Pemeriksaan ILIKE :kw
    )";
    $params_pem[':kw'] = $keywordSql;
}

$sql_pem .= "
    GROUP BY p.ID_Pemeriksaan, tm.Nama_Tenaga_Medis
    ORDER BY p.Tanggal_Pemeriksaan DESC, p.Waktu_Pemeriksaan DESC
";

$stmt = $conn->prepare($sql_pem);
$stmt->execute($params_pem);
$data_pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================================
// DATA LAYANAN LANJUTAN
// ==========================================================
$sql_layanan = "
    SELECT l.Nama_Layanan, dp.Konsultasi, dp.Suntik_Vitamin,
           p.Tanggal_Pemeriksaan, p.Waktu_Pemeriksaan
    FROM DETAIL_PEMERIKSAAN dp
    JOIN LAYANAN l ON dp.ID_Layanan = l.ID_Layanan
    JOIN PEMERIKSAAN p ON dp.ID_Pemeriksaan = p.ID_Pemeriksaan
    WHERE p.ID_Pasien = :id_pasien
";
$params_layanan = [':id_pasien' => $id_pasien];

if ($keyword !== '') {
    $sql_layanan .= " AND (
        l.Nama_Layanan ILIKE :kw
        OR dp.Konsultasi ILIKE :kw
        OR dp.Suntik_Vitamin ILIKE :kw
    )";
    $params_layanan[':kw'] = $keywordSql;
}
$sql_layanan .= " ORDER BY p.Tanggal_Pemeriksaan DESC, p.Waktu_Pemeriksaan DESC";

$stmt = $conn->prepare($sql_layanan);
$stmt->execute($params_layanan);
$data_layanan = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================================
// DATA TAGIHAN PASIEN
// ==========================================================
$sql_tag = "
    SELECT * 
    FROM TAGIHAN 
    WHERE ID_Pasien = :id_pasien
";
$params_tag = [':id_pasien' => $id_pasien];

// kalau ada keyword, dan keyword-nya BUKAN kata umum seperti "rp"/"tagihan"
if ($keyword !== '' && !in_array($keywordLower, ['rp', 'tagihan', 'bayar'], true)) {
    $sql_tag .= " AND (
        ID_Tagihan ILIKE :kw
        OR Status_Pembayaran ILIKE :kw";

    $params_tag[':kw'] = $keywordSql;

    // kalau keyword ada angkanya (misal: 400) → cocokkan ke total_biaya
    if ($keywordDigits !== '') {
        $sql_tag .= " OR CAST(Total_Biaya AS TEXT) ILIKE :kw_num";
        $params_tag[':kw_num'] = '%' . $keywordDigits . '%';
    }

    $sql_tag .= ")";
}

$sql_tag .= " ORDER BY Tanggal_Tagihan DESC";

$stmt = $conn->prepare($sql_tag);
$stmt->execute($params_tag);
$data_tagihan = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==========================================================
// AUTO-SWITCH TAB KALAU TAB AKTIF KOSONG TAPI TAB LAIN ADA HASIL
// ==========================================================
$resultsCount = [
    'rekam_medis'  => count($data_rm),
    'pemeriksaan'  => count($data_pemeriksaan),
    'layanan'      => count($data_layanan),
    'tagihan'      => count($data_tagihan),
];

if ($keyword !== '') {
    if (isset($resultsCount[$activeTab]) && $resultsCount[$activeTab] === 0) {
        foreach (['rekam_medis','pemeriksaan','layanan','tagihan'] as $tabName) {
            if ($resultsCount[$tabName] > 0) {
                $activeTab = $tabName;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Pasien</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color:#f5f7fb; }
        .card-stat {
            border: none;
            border-radius: 18px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        }
        .card-stat h3 { font-size: 1.8rem; }
        .section-title{
            font-weight:600;
            font-size:1.1rem;
        }
        .table thead th{
            background:#f0f2f8;
        }
        .badge-soft {
            background: #e8f5ff;
            color:#0d6efd;
        }

        /* shortcut style */
        .btn-quick {
            border-radius: 999px;
            border:1px solid #a855f7;
            color:#6b21a8;
            background-color:#faf5ff;
        }
        .btn-quick:hover{
            background-color:#e9d5ff;
            color:#581c87;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mb-4 shadow" style="background:linear-gradient(90deg,#7c3aed,#9333ea);">
    <div class="container">
        <a class="navbar-brand" href="#">
            <i class="fas fa-hospital-user me-2"></i>Portal Pasien
        </a>
        <div class="navbar-text text-white ms-auto">
            <?php if($profil): ?>
                Halo, <strong><?= htmlspecialchars($profil['nama']) ?></strong>
            <?php endif; ?>
            <a href="../auth/logout.php" class="btn btn-outline-light btn-sm ms-3">
                <i class="fas fa-sign-out-alt"></i> Keluar
            </a>
        </div>
    </div>
</nav>

<div class="container mb-5">

    <!-- STAT KECIL DI ATAS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card card-stat bg-white">
                <div class="card-body">
                    <div class="small text-muted">Rekam Medis</div>
                    <h3 class="mb-1"><?= (int)$stat_rm ?></h3>
                    <div class="text-muted small">Total kunjungan yang tercatat</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card card-stat bg-white">
                <div class="card-body">
                    <div class="small text-muted">Pemeriksaan</div>
                    <h3 class="mb-1"><?= (int)$stat_periksa ?></h3>
                    <div class="text-muted small">Jadwal / riwayat pemeriksaan</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card card-stat bg-white">
                <div class="card-body">
                    <div class="small text-muted">Layanan Lanjutan</div>
                    <h3 class="mb-1"><?= (int)$stat_layanan ?></h3>
                    <div class="text-muted small">Kamar / tindakan tambahan</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card card-stat bg-white">
                <div class="card-body">
                    <div class="small text-muted">Tagihan Aktif</div>
                    <h3 class="mb-1">Rp <?= number_format($stat_tagihan_aktif, 0, ',', '.') ?></h3>
                    <div class="text-muted small">Belum lunas / masih dicicil</div>
                </div>
            </div>
        </div>
    </div>

    <!-- PROFIL + PINTASAN CEPAT -->
    <div class="row mb-4">
        <!-- Profil -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <span class="section-title"><i class="fas fa-id-card me-2"></i>Profil Saya</span>
                </div>
                <div class="card-body">
                    <?php if($profil): ?>
                        <div class="mb-2"><strong>Nama</strong><br><?= htmlspecialchars($profil['nama']) ?></div>
                        <div class="mb-2"><strong>Tanggal Lahir</strong><br><?= date('d-m-Y', strtotime($profil['tanggal_lahir'])) ?></div>
                        <div class="mb-2"><strong>Alamat</strong><br><?= htmlspecialchars($profil['alamat']) ?></div>
                        <div class="mb-2"><strong>No. Telepon</strong><br><?= htmlspecialchars($profil['nomor_telepon']) ?></div>
                        <div class="text-muted small mt-2">
                            Jika data di atas tidak sesuai, hubungi petugas pendaftaran.
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Data profil tidak ditemukan.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pintasan Cepat -->
        <div class="col-md-6 mt-3 mt-md-0">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <span class="section-title"><i class="fas fa-bolt me-2"></i>Pintasan Cepat</span>
                </div>
                <div class="card-body">
                    <button type="button"
                            class="btn btn-quick w-100 text-start mb-2 quick-shortcut"
                            data-quick-tab="rekam_medis">
                        <i class="fas fa-notes-medical me-2"></i>Lihat Rekam Medis
                    </button>
                    <button type="button"
                            class="btn btn-quick w-100 text-start mb-2 quick-shortcut"
                            data-quick-tab="pemeriksaan">
                        <i class="fas fa-stethoscope me-2"></i>Riwayat Pemeriksaan
                    </button>
                    <button type="button"
                            class="btn btn-quick w-100 text-start mb-2 quick-shortcut"
                            data-quick-tab="layanan">
                        <i class="fas fa-syringe me-2"></i>Layanan Lanjutan
                    </button>
                    <button type="button"
                            class="btn btn-quick w-100 text-start quick-shortcut"
                            data-quick-tab="tagihan">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Lihat Tagihan
                    </button>

                    <div class="text-muted small mt-3">
                        Gunakan tombol di atas untuk langsung lompat ke bagian yang Anda butuhkan.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH BAR GLOBAL -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-center">
                        <div class="col-md-10">
                            <input type="text" name="q" class="form-control"
                                   placeholder="Cari rekam medis, pemeriksaan, layanan, atau tagihan..."
                                   value="<?= htmlspecialchars($keyword) ?>">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="fas fa-search me-1"></i>Cari
                            </button>
                        </div>
                        <input type="hidden" name="tab" id="searchTabInput" value="<?= htmlspecialchars($activeTab) ?>">
                    </form>
                    <?php if ($keyword !== ''): ?>
                        <div class="text-muted small mt-2">
                            Menampilkan hasil dengan kata kunci: <strong><?= htmlspecialchars($keyword) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB KONTEN -->
    <ul class="nav nav-tabs mb-3" id="patientTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $activeTab==='rekam_medis' ? 'active' : '' ?>"
                    id="rm-tab" data-bs-toggle="tab" data-bs-target="#rm" type="button"
                    data-tab-target="rekam_medis">
                Rekam Medis
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab==='pemeriksaan' ? 'active' : '' ?>"
                    id="periksa-tab" data-bs-toggle="tab" data-bs-target="#periksa" type="button"
                    data-tab-target="pemeriksaan">
                Pemeriksaan
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab==='layanan' ? 'active' : '' ?>"
                    id="layanan-tab" data-bs-toggle="tab" data-bs-target="#layanan" type="button"
                    data-tab-target="layanan">
                Layanan Lanjutan
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $activeTab==='tagihan' ? 'active' : '' ?>"
                    id="tagihan-tab" data-bs-toggle="tab" data-bs-target="#tagihan" type="button"
                    data-tab-target="tagihan">
                Tagihan Saya
            </button>
        </li>
    </ul>

    <div class="tab-content mb-5" id="patientTabContent">

        <!-- TAB REKAM MEDIS -->
        <div class="tab-pane fade <?= $activeTab==='rekam_medis' ? 'show active' : '' ?>" id="rm" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <span class="section-title">Riwayat Rekam Medis</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Dokter</th>
                                    <th>Diagnosis</th>
                                    <th>Hasil</th>
                                    <th>Status Rawat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($data_rm) == 0): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada rekam medis.</td></tr>
                                <?php else: ?>
                                    <?php foreach($data_rm as $r): ?>
                                        <tr>
                                            <td><?= date('d-m-Y', strtotime($r['tanggal_catatan'])) ?></td>
                                            <td><?= htmlspecialchars($r['nama_dokter'] ?: '-') ?></td>
                                            <td><span class="text-danger"><?= htmlspecialchars($r['diagnosis']) ?></span></td>
                                            <td><?= htmlspecialchars($r['hasil_pemeriksaan']) ?></td>
                                            <td>
                                                <?php if (!empty($r['info_kamar'])): ?>
                                                    <span class="badge bg-danger">
                                                        Rawat Inap (<?= htmlspecialchars($r['info_kamar']) ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Rawat Jalan</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB PEMERIKSAAN -->
        <div class="tab-pane fade <?= $activeTab==='pemeriksaan' ? 'show active' : '' ?>" id="periksa" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <span class="section-title">Jadwal & Riwayat Pemeriksaan</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Waktu</th>
                                    <th>Dokter</th>
                                    <th>Ruang</th>
                                    <th>Layanan Tambahan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($data_pemeriksaan) == 0): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada jadwal / riwayat pemeriksaan.</td></tr>
                                <?php else: ?>
                                    <?php foreach($data_pemeriksaan as $p): ?>
                                        <tr>
                                            <td><?= date('d-m-Y', strtotime($p['tanggal_pemeriksaan'])) ?></td>
                                            <td><?= substr($p['waktu_pemeriksaan'], 0, 5) ?></td>
                                            <td><?= htmlspecialchars($p['nama_dokter'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($p['ruang_pemeriksaan']) ?></td>
                                            <td>
                                                <?php if($p['jml_layanan'] > 0): ?>
                                                    <span class="badge badge-soft"><?= $p['jml_layanan'] ?> layanan</span>
                                                <?php else: ?>
                                                    <span class="text-muted small">Tidak ada</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 text-muted small">
                        * Baris paling atas adalah pemeriksaan yang paling baru.
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB LAYANAN LANJUTAN -->
        <div class="tab-pane fade <?= $activeTab==='layanan' ? 'show active' : '' ?>" id="layanan" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <span class="section-title">Layanan Lanjutan yang Pernah Diterima</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Waktu</th>
                                    <th>Layanan</th>
                                    <th>Konsultasi / Catatan</th>
                                    <th>Suntik Vitamin</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($data_layanan) == 0): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada layanan lanjutan.</td></tr>
                                <?php else: ?>
                                    <?php foreach($data_layanan as $l): ?>
                                        <tr>
                                            <td><?= date('d-m-Y', strtotime($l['tanggal_pemeriksaan'])) ?></td>
                                            <td><?= substr($l['waktu_pemeriksaan'], 0, 5) ?></td>
                                            <td><?= htmlspecialchars($l['nama_layanan']) ?></td>
                                            <td><?= htmlspecialchars($l['konsultasi']) ?></td>
                                            <td><?= htmlspecialchars($l['suntik_vitamin'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 text-muted small">
                        Informasi ini biasanya diisi oleh dokter setelah pemeriksaan.
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB TAGIHAN -->
        <div class="tab-pane fade <?= $activeTab==='tagihan' ? 'show active' : '' ?>" id="tagihan" role="tabpanel">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="section-title mb-2">Ringkasan Tagihan</div>
                            <p class="mb-1 text-muted small">Tagihan aktif (belum lunas / dicicil):</p>
                            <h3 class="mb-3 text-danger">Rp <?= number_format($stat_tagihan_aktif, 0, ',', '.') ?></h3>
                            <p class="text-muted small mb-0">
                                Untuk proses pembayaran, silakan datang ke loket kasir rumah sakit.
                                Simpan nomor tagihan yang tertera pada tabel di samping.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-8 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <span class="section-title">Daftar Tagihan</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                        <tr>
                                            <th>No Tagihan</th>
                                            <th>Tanggal</th>
                                            <th>Jumlah</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($data_tagihan) == 0): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-3">Belum ada tagihan.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($data_tagihan as $t): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($t['id_tagihan']) ?></td>
                                                    <td><?= date('d-m-Y', strtotime($t['tanggal_tagihan'])) ?></td>
                                                    <td>Rp <?= number_format($t['total_biaya'], 0, ',', '.') ?></td>
                                                    <td>
                                                        <?php if ($t['status_pembayaran'] == 'Lunas'): ?>
                                                            <span class="badge bg-success">Lunas</span>
                                                        <?php elseif ($t['status_pembayaran'] == 'Dicicil'): ?>
                                                            <span class="badge bg-warning text-dark">Dicicil</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Belum Lunas</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="p-3 text-muted small">
                                Jika Anda merasa ada kesalahan pada jumlah tagihan, silakan konfirmasi
                                ke bagian administrasi rumah sakit.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div> <!-- /tab-content -->
</div> <!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabInput = document.getElementById('searchTabInput');

    // update hidden "tab" saat nav tab di-klik
    if (tabInput) {
        document.querySelectorAll('[data-tab-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabInput.value = this.getAttribute('data-tab-target');
            });
        });
    }

    // === PINTASAN CEPAT ===
    document.querySelectorAll('.quick-shortcut').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-quick-tab');
            if (!targetTab) return;

            const navBtn = document.querySelector('.nav-link[data-tab-target="' + targetTab + '"]');
            if (!navBtn) return;

            const tab = new bootstrap.Tab(navBtn);
            tab.show();

            if (tabInput) {
                tabInput.value = targetTab;
            }

            const tabContainer = document.getElementById('patientTab');
            if (tabContainer) {
                tabContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});
</script>
</body>
</html>
