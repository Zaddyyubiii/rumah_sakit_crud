<?php
// File: admin/dashboard.php
session_start();
require_once '../includes/auth_check.php';
require_once '../includes/db.php';

require_role('admin');

// ==========================================================
// FUNGSI AUTO NUMBER
// ==========================================================
function autoId($conn, $col, $table, $prefix) {
    $q = $conn->query("SELECT $col FROM $table ORDER BY $col DESC LIMIT 1");
    $last = $q->fetchColumn();
    if (!$last) { $number = 1; } 
    else { $number = (int)substr($last, strlen($prefix)) + 1; }
    return $prefix . str_pad($number, 3, '0', STR_PAD_LEFT);
}

// ==========================================================
// LOGIK STATISTIK
// ==========================================================
$stat_pasien = $conn->query("SELECT COUNT(*) FROM PASIEN")->fetchColumn();
$stat_dokter = $conn->query("SELECT COUNT(*) FROM DOKTER")->fetchColumn();
$stat_perawat = $conn->query("SELECT COUNT(*) FROM PERAWAT")->fetchColumn();
$stat_rm = $conn->query("SELECT COUNT(*) FROM REKAM_MEDIS")->fetchColumn();
$stat_uang = $conn->query("SELECT COALESCE(SUM(Total_Biaya), 0) FROM TAGIHAN WHERE Status_Pembayaran = 'Lunas'")->fetchColumn();

// ==========================================================
// LOGIK CRUD UTAMA
// ==========================================================
$page = isset($_GET['page']) ? $_GET['page'] : 'rekam_medis';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$id_edit = isset($_GET['id']) ? $_GET['id'] : '';
$keyword = isset($_GET['cari']) ? $_GET['cari'] : '';
$data_edit = null;

// --- LOGIK DELETE GLOBAL ---
if ($action == 'delete' && !empty($id_edit)) {
    try {
        if ($page == 'pasien') $stmt = $conn->prepare("DELETE FROM PASIEN WHERE ID_Pasien = ?");
        elseif ($page == 'dokter' || $page == 'perawat') $stmt = $conn->prepare("DELETE FROM TENAGA_MEDIS WHERE ID_Tenaga_Medis = ?");
        elseif ($page == 'rawat_inap') $stmt = $conn->prepare("DELETE FROM RAWAT_INAP WHERE ID_Kamar = ?");
        elseif ($page == 'rekam_medis') $stmt = $conn->prepare("DELETE FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        elseif ($page == 'tagihan') $stmt = $conn->prepare("DELETE FROM TAGIHAN WHERE ID_Tagihan = ?");
        
        if (isset($stmt)) {
            $stmt->execute([$id_edit]);
            header("Location: dashboard.php?page=$page&msg=deleted"); exit();
        }
    } catch (PDOException $e) { $error = "Gagal Hapus: " . $e->getMessage(); }
}

// --- 1. LOGIK REKAM MEDIS ---
if ($page == 'rekam_medis') {
    $list_pasien = $conn->query("SELECT id_pasien, nama FROM PASIEN")->fetchAll(PDO::FETCH_ASSOC);
    $list_dokter = $conn->query("SELECT d.id_tenaga_medis, tm.nama_tenaga_medis FROM DOKTER d JOIN TENAGA_MEDIS tm ON d.id_tenaga_medis = tm.id_tenaga_medis")->fetchAll(PDO::FETCH_ASSOC);
    $list_kamar_layanan = $conn->query("SELECT * FROM LAYANAN WHERE Nama_Layanan ILIKE '%Inap%' OR Nama_Layanan ILIKE '%Kamar%' OR Nama_Layanan ILIKE '%VIP%'")->fetchAll(PDO::FETCH_ASSOC);
    $next_id = autoId($conn, 'id_rekam_medis', 'REKAM_MEDIS', 'RM');

    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (isset($_POST['simpan_rm'])) {
        try {
            $conn->beginTransaction();
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE REKAM_MEDIS SET ID_Pasien=?, ID_Tenaga_Medis=?, Tanggal_Catatan=?, Diagnosis=?, Hasil_Pemeriksaan=? WHERE ID_Rekam_Medis=?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$_POST['id_pasien'], $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil'], $_POST['id_rm']]);
            } else {
                $sql = "INSERT INTO REKAM_MEDIS (ID_Rekam_Medis, ID_Pasien, ID_Tenaga_Medis, Tanggal_Catatan, Diagnosis, Hasil_Pemeriksaan) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$_POST['id_rm'], $_POST['id_pasien'], $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil']]);
            }

            if (!empty($_POST['status_rawat']) && $_POST['status_rawat'] == 'inap' && !empty($_POST['pilih_kamar'])) {
                $id_transaksi_inap = autoId($conn, 'id_kamar', 'RAWAT_INAP', 'RI'); 
                $sql_inap = "INSERT INTO RAWAT_INAP (ID_Kamar, ID_Layanan, Tanggal_Masuk, Tanggal_Keluar) VALUES (?, ?, ?, NULL)";
                $stmt_inap = $conn->prepare($sql_inap);
                $stmt_inap->execute([$id_transaksi_inap, $_POST['pilih_kamar'], $_POST['tanggal']]);
            }
            $conn->commit();
            header("Location: dashboard.php?page=rekam_medis&msg=saved"); exit();
        } catch (PDOException $e) { 
            $conn->rollBack(); $error = "Error: " . $e->getMessage(); 
        }
    }
    
    $sql = "SELECT rm.*, p.Nama AS nama_pasien, tm.Nama_Tenaga_Medis AS nama_dokter,
            (SELECT l.Nama_Layanan FROM TAGIHAN t JOIN DETAIL_TAGIHAN dt ON t.ID_Tagihan = dt.ID_Tagihan JOIN LAYANAN l ON dt.ID_Layanan = l.ID_Layanan WHERE t.ID_Pasien = p.ID_Pasien AND t.Status_Pembayaran = 'Belum Lunas' AND (l.Nama_Layanan ILIKE '%Inap%' OR l.Nama_Layanan ILIKE '%Kamar%') LIMIT 1) AS info_kamar
            FROM REKAM_MEDIS rm 
            JOIN PASIEN p ON rm.ID_Pasien = p.ID_Pasien 
            JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis";
    if (!empty($keyword)) {
        $sql .= " WHERE p.Nama ILIKE ? OR rm.Diagnosis ILIKE ?";
        $sql .= " ORDER BY rm.Tanggal_Catatan DESC";
        $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%", "%$keyword%"]);
    } else {
        $sql .= " ORDER BY rm.Tanggal_Catatan DESC"; $stmt = $conn->query($sql);
    }
    $data_rm = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 2. LOGIK PASIEN ---
if ($page == 'pasien') {
    $next_id = autoId($conn, 'id_pasien', 'PASIEN', 'P');
    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM PASIEN WHERE ID_Pasien = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['simpan_pasien'])) {
        try {
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE PASIEN SET Nama=?, Tanggal_Lahir=?, Alamat=?, Nomor_Telepon=? WHERE ID_Pasien=?";
                $params = [$_POST['nama'], $_POST['tgl_lahir'], $_POST['alamat'], $_POST['no_telp'], $_POST['id_pasien']];
            } else {
                $sql = "INSERT INTO PASIEN (ID_Pasien, Nama, Tanggal_Lahir, Alamat, Nomor_Telepon) VALUES (?, ?, ?, ?, ?)";
                $params = [$_POST['id_pasien'], $_POST['nama'], $_POST['tgl_lahir'], $_POST['alamat'], $_POST['no_telp']];
            }
            $stmt = $conn->prepare($sql); $stmt->execute($params);
            header("Location: dashboard.php?page=pasien&msg=saved"); exit();
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
    $sql = "SELECT p.*, (SELECT COUNT(*) FROM TAGIHAN t WHERE t.ID_Pasien = p.ID_Pasien AND t.Status_Pembayaran = 'Belum Lunas') as tagihan_aktif FROM PASIEN p";
    if (!empty($keyword)) { $sql .= " WHERE p.Nama ILIKE ? OR p.ID_Pasien ILIKE ?"; $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%", "%$keyword%"]); } else { $sql .= " ORDER BY p.ID_Pasien ASC"; $stmt = $conn->query($sql); }
    $data_pasien = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 3. LOGIK DOKTER & PERAWAT ---
if ($page == 'dokter' || $page == 'perawat') {
    $list_dept = $conn->query("SELECT * FROM DEPARTEMEN")->fetchAll(PDO::FETCH_ASSOC);
    $next_id = autoId($conn, 'id_tenaga_medis', 'TENAGA_MEDIS', 'TM');
    
    if ($page == 'dokter') {
        if ($action == 'edit' && !empty($id_edit)) {
            $stmt = $conn->prepare("SELECT tm.*, d.Spesialisasi FROM TENAGA_MEDIS tm JOIN DOKTER d ON tm.ID_Tenaga_Medis = d.ID_Tenaga_Medis WHERE tm.ID_Tenaga_Medis = ?");
            $stmt->execute([$id_edit]);
            $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (isset($_POST['simpan_dokter'])) {
            try {
                $conn->beginTransaction();
                if ($_POST['mode'] == 'update') {
                    $conn->prepare("UPDATE TENAGA_MEDIS SET Nama_Tenaga_Medis=?, ID_Departemen=? WHERE ID_Tenaga_Medis=?")->execute([$_POST['nama'], $_POST['id_dept'], $_POST['id_dokter']]);
                    $conn->prepare("UPDATE DOKTER SET Spesialisasi=? WHERE ID_Tenaga_Medis=?")->execute([$_POST['spesialisasi'], $_POST['id_dokter']]);
                } else {
                    $conn->prepare("INSERT INTO TENAGA_MEDIS (ID_Tenaga_Medis, Nama_Tenaga_Medis, ID_Departemen) VALUES (?, ?, ?)")->execute([$_POST['id_dokter'], $_POST['nama'], $_POST['id_dept']]);
                    $conn->prepare("INSERT INTO DOKTER (ID_Tenaga_Medis, Spesialisasi) VALUES (?, ?)")->execute([$_POST['id_dokter'], $_POST['spesialisasi']]);
                }
                $conn->commit(); header("Location: dashboard.php?page=dokter&msg=saved"); exit();
            } catch (PDOException $e) { $conn->rollBack(); $error = $e->getMessage(); }
        }
        $sql = "SELECT tm.ID_Tenaga_Medis, tm.Nama_Tenaga_Medis, dp.Nama_Departemen, d.Spesialisasi FROM TENAGA_MEDIS tm JOIN DOKTER d ON tm.ID_Tenaga_Medis = d.ID_Tenaga_Medis JOIN DEPARTEMEN dp ON tm.ID_Departemen = dp.ID_Departemen";
        if (!empty($keyword)) { $sql .= " WHERE tm.Nama_Tenaga_Medis ILIKE ?"; $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%"]); } else { $stmt = $conn->query($sql); }
        $data_dokter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    else {
        if ($action == 'edit' && !empty($id_edit)) {
            $stmt = $conn->prepare("SELECT tm.*, p.Shift FROM TENAGA_MEDIS tm JOIN PERAWAT p ON tm.ID_Tenaga_Medis = p.ID_Tenaga_Medis WHERE tm.ID_Tenaga_Medis = ?");
            $stmt->execute([$id_edit]);
            $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (isset($_POST['simpan_perawat'])) {
            try {
                $conn->beginTransaction();
                if ($_POST['mode'] == 'update') {
                    $conn->prepare("UPDATE TENAGA_MEDIS SET Nama_Tenaga_Medis=?, ID_Departemen=? WHERE ID_Tenaga_Medis=?")->execute([$_POST['nama'], $_POST['id_dept'], $_POST['id_perawat']]);
                    $conn->prepare("UPDATE PERAWAT SET Shift=? WHERE ID_Tenaga_Medis=?")->execute([$_POST['shift'], $_POST['id_perawat']]);
                } else {
                    $conn->prepare("INSERT INTO TENAGA_MEDIS (ID_Tenaga_Medis, Nama_Tenaga_Medis, ID_Departemen) VALUES (?, ?, ?)")->execute([$_POST['id_perawat'], $_POST['nama'], $_POST['id_dept']]);
                    $conn->prepare("INSERT INTO PERAWAT (ID_Tenaga_Medis, Shift) VALUES (?, ?)")->execute([$_POST['id_perawat'], $_POST['shift']]);
                }
                $conn->commit(); header("Location: dashboard.php?page=perawat&msg=saved"); exit();
            } catch (PDOException $e) { $conn->rollBack(); $error = $e->getMessage(); }
        }
        $sql = "SELECT tm.*, dp.Nama_Departemen, p.Shift FROM TENAGA_MEDIS tm JOIN PERAWAT p ON tm.ID_Tenaga_Medis = p.ID_Tenaga_Medis JOIN DEPARTEMEN dp ON tm.ID_Departemen = dp.ID_Departemen";
        if (!empty($keyword)) { $sql .= " WHERE tm.Nama_Tenaga_Medis ILIKE ?"; $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%"]); } else { $stmt = $conn->query($sql); }
        $data_perawat = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- 4. LOGIK RAWAT INAP (MODE MONITOR & CHECKOUT) ---
if ($page == 'rawat_inap') {
    // 1. Proses Checkout (Pulangkan Pasien)
    if ($action == 'checkout' && !empty($id_edit)) {
        try {
            $tgl_keluar = date('Y-m-d'); // Hari ini
            $stmt = $conn->prepare("UPDATE RAWAT_INAP SET Tanggal_Keluar = ? WHERE ID_Kamar = ?");
            $stmt->execute([$tgl_keluar, $id_edit]);
            header("Location: dashboard.php?page=rawat_inap&msg=checkout_sukses");
            exit();
        } catch (PDOException $e) { $error = "Gagal checkout: " . $e->getMessage(); }
    }

    // 2. Proses Hapus Data (Kalau salah input)
    if ($action == 'delete' && !empty($id_edit)) {
        $stmt = $conn->prepare("DELETE FROM RAWAT_INAP WHERE ID_Kamar = ?");
        $stmt->execute([$id_edit]);
        header("Location: dashboard.php?page=rawat_inap&msg=deleted");
        exit();
    }

    // 3. Ambil Data
    $sql = "SELECT r.*, l.Nama_Layanan, 
            COALESCE(
                (SELECT p.Nama FROM REKAM_MEDIS rm 
                 JOIN PASIEN p ON rm.ID_Pasien = p.ID_Pasien 
                 WHERE rm.Tanggal_Catatan = r.Tanggal_Masuk LIMIT 1), 
            'Pasien Tanpa RM') as nama_pasien
            FROM RAWAT_INAP r 
            JOIN LAYANAN l ON r.ID_Layanan = l.ID_Layanan";

    if (!empty($keyword)) { 
        $sql .= " WHERE r.ID_Kamar ILIKE ?"; 
        $sql .= " ORDER BY r.Tanggal_Masuk DESC";
        $stmt = $conn->prepare($sql); 
        $stmt->execute(["%$keyword%"]); 
    } else { 
        $sql .= " ORDER BY r.Tanggal_Masuk DESC"; 
        $stmt = $conn->query($sql); 
    }
    $data_inap = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- 5. LOGIK TAGIHAN ---
if ($page == 'tagihan') {
    $list_pasien = $conn->query("SELECT id_pasien, nama FROM PASIEN")->fetchAll(PDO::FETCH_ASSOC);
    $next_id = autoId($conn, 'id_tagihan', 'TAGIHAN', 'T');

    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM TAGIHAN WHERE ID_Tagihan = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['simpan_tagihan'])) {
        try {
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE TAGIHAN SET ID_Pasien=?, Tanggal_Tagihan=?, Total_Biaya=?, Status_Pembayaran=? WHERE ID_Tagihan=?";
                $params = [$_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status'], $_POST['id_tagihan']];
            } else {
                $sql = "INSERT INTO TAGIHAN (ID_Tagihan, ID_Pasien, Tanggal_Tagihan, Total_Biaya, Status_Pembayaran) VALUES (?, ?, ?, ?, ?)";
                $params = [$_POST['id_tagihan'], $_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status']];
            }
            $stmt = $conn->prepare($sql); $stmt->execute($params);
            header("Location: dashboard.php?page=tagihan&msg=saved"); exit();
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
    $sql = "SELECT t.*, p.Nama AS nama_pasien FROM TAGIHAN t JOIN PASIEN p ON t.ID_Pasien = p.ID_Pasien";
    if (!empty($keyword)) { $sql .= " WHERE p.Nama ILIKE ? OR t.ID_Tagihan ILIKE ?"; $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%", "%$keyword%"]); } else { $sql .= " ORDER BY t.Tanggal_Tagihan DESC"; $stmt = $conn->query($sql); }
    $data_tagihan = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin RS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-stat { transition: transform 0.2s; cursor: pointer; }
        .card-stat:hover { transform: translateY(-5px); }
        .truncate-text { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4 shadow">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="fas fa-hospital-alt me-2"></i>Sistem Informasi RS</a>
        <div class="navbar-nav ms-auto">
            <a href="../auth/logout.php" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="container">

    <div class="row mb-4">
        <div class="col-md-2">
            <a href="laporan_pasien.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-primary text-white card-stat">
                    <div class="card-body p-3">
                        <h6 class="mb-0">Pasien</h6>
                        <h3 class="mb-0 fw-bold"><?= $stat_pasien ?></h3>
                        <small class="text-white-50">Detail &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="laporan_dokter.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-success text-white card-stat">
                    <div class="card-body p-3">
                        <h6 class="mb-0">Dokter</h6>
                        <h3 class="mb-0 fw-bold"><?= $stat_dokter ?></h3>
                        <small class="text-white-50">Detail &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-2">
            <a href="laporan_perawat.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-info text-white card-stat">
                    <div class="card-body p-3">
                        <h6 class="mb-0">Perawat</h6>
                        <h3 class="mb-0 fw-bold"><?= $stat_perawat ?></h3>
                        <small class="text-white-50">Detail &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="laporan_kunjungan.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-secondary text-white card-stat">
                    <div class="card-body p-3">
                        <h6 class="mb-0">Kunjungan (RM)</h6>
                        <h3 class="mb-0 fw-bold"><?= $stat_rm ?></h3>
                        <small class="text-white-50">Laporan &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="laporan_keuangan.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-warning text-dark card-stat">
                    <div class="card-body p-3">
                        <h6 class="mb-0">Pendapatan</h6>
                        <h4 class="mb-0 fw-bold">Rp <?= number_format($stat_uang, 0, ',', '.') ?></h4>
                        <small class="text-dark-50">Keuangan &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $page == 'rekam_medis' ? 'active' : '' ?>" href="?page=rekam_medis"><i class="fas fa-file-medical"></i> Rekam Medis</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'pasien' ? 'active' : '' ?>" href="?page=pasien"><i class="fas fa-user-injured"></i> Pasien</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'dokter' ? 'active' : '' ?>" href="?page=dokter"><i class="fas fa-user-doctor"></i> Dokter</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'perawat' ? 'active' : '' ?>" href="?page=perawat"><i class="fas fa-user-nurse"></i> Perawat</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'rawat_inap' ? 'active' : '' ?>" href="?page=rawat_inap"><i class="fas fa-bed"></i> Rawat Inap</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'tagihan' ? 'active' : '' ?>" href="?page=tagihan"><i class="fas fa-cash-register"></i> Kasir</a></li>
    </ul>

    <form method="GET" class="row g-2 mb-4">
        <input type="hidden" name="page" value="<?= $page ?>">
        <div class="col-auto"><input type="text" name="cari" class="form-control" placeholder="Cari data..." value="<?= htmlspecialchars($keyword) ?>"></div>
        <div class="col-auto"><button type="submit" class="btn btn-primary">Cari</button> <?php if(!empty($keyword)): ?> <a href="?page=<?= $page ?>" class="btn btn-secondary">Reset</a> <?php endif; ?></div>
    </form>

    <?php if(isset($error)): ?> <div class="alert alert-danger"><?= $error ?></div> <?php endif; ?>
    <?php if(isset($_GET['msg'])): ?> <div class="alert alert-success">Aksi berhasil dilakukan!</div> <?php endif; ?>

    <?php if ($page == 'rekam_medis'): ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-dark text-white"><?= $data_edit ? 'Edit Data' : 'Input Data' ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2">
                            <label>ID RM (Otomatis)</label>
                            <input type="text" name="id_rm" class="form-control bg-light" value="<?= $data_edit['id_rekam_medis'] ?? $next_id ?>" readonly>
                        </div>
                        <div class="mb-2"><label>Pasien</label><select name="id_pasien" class="form-select" required><?php foreach($list_pasien as $p): ?> <option value="<?=$p['id_pasien']?>" <?= ($data_edit && $data_edit['id_pasien'] == $p['id_pasien']) ? 'selected' : '' ?>><?=$p['nama']?></option> <?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Dokter</label><select name="id_dokter" class="form-select" required><?php foreach($list_dokter as $d): ?> <option value="<?=$d['id_tenaga_medis']?>" <?= ($data_edit && $data_edit['id_tenaga_medis'] == $d['id_tenaga_medis']) ? 'selected' : '' ?>><?=$d['nama_tenaga_medis']?></option> <?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_catatan'] ?? date('Y-m-d') ?>" required></div>
                        <div class="mb-2"><label>Diagnosis</label><input type="text" name="diagnosis" class="form-control" value="<?= $data_edit['diagnosis'] ?? '' ?>" required></div>
                        <div class="mb-3"><label>Hasil</label><textarea name="hasil" class="form-control" required><?= $data_edit['hasil_pemeriksaan'] ?? '' ?></textarea></div>
                        
                        <div class="mb-3 p-3 bg-light border rounded">
                            <label class="fw-bold mb-2">Tindakan Lanjutan</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status_rawat" id="jalan" value="jalan" checked onclick="toggleKamar(false)">
                                <label class="form-check-label" for="jalan">Rawat Jalan / Pulang</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="status_rawat" id="inap" value="inap" onclick="toggleKamar(true)">
                                <label class="form-check-label" for="inap">Rawat Inap</label>
                            </div>
                            <div id="pilihan_kamar" class="mt-2" style="display:none;">
                                <label>Pilih Kelas / Kamar:</label>
                                <select name="pilih_kamar" class="form-select mt-1">
                                    <option value="">-- Pilih Kamar --</option>
                                    <?php foreach($list_kamar_layanan as $k): ?>
                                        <option value="<?= $k['id_layanan'] ?>"><?= $k['nama_layanan'] ?> - Rp <?= number_format($k['tarif_dasar'],0) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted text-danger">*Otomatis membuat data Rawat Inap.</small>
                            </div>
                        </div>

                        <button type="submit" name="simpan_rm" class="btn btn-primary w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                        <?php if($data_edit): ?> <a href="?page=rekam_medis" class="btn btn-secondary w-100 mt-2">Batal</a> <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>Pasien</th><th>Dokter</th><th>Tanggal</th><th>Status</th><th>Hasil</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_rm as $r): ?>
                    <tr>
                        <td><b><?=$r['nama_pasien']?></b></td><td><?=$r['nama_dokter']?></td><td><?= date('d-m-Y', strtotime($r['tanggal_catatan'])) ?></td>
                        <td>
                            <?php if(!empty($r['info_kamar'])): ?>
                                <span class="badge bg-danger">Inap: <?= $r['info_kamar'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-success">Rawat Jalan</span>
                            <?php endif; ?>
                        </td>
                        <td><div class="truncate-text"><?= htmlspecialchars($r['hasil_pemeriksaan']) ?></div></td>
                        <td>
                            <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#modalDetail" data-id="<?= $r['id_rekam_medis'] ?>" data-pasien="<?= $r['nama_pasien'] ?>" data-dokter="<?= $r['nama_dokter'] ?>" data-tgl="<?= date('d F Y', strtotime($r['tanggal_catatan'])) ?>" data-diag="<?= $r['diagnosis'] ?>" data-hasil="<?= $r['hasil_pemeriksaan'] ?>" data-status="<?= !empty($r['info_kamar']) ? 'Rawat Inap ('.$r['info_kamar'].')' : 'Rawat Jalan' ?>"><i class="fas fa-eye"></i></button>
                            <a href="?page=rekam_medis&action=edit&id=<?=$r['id_rekam_medis']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=rekam_medis&action=delete&id=<?=$r['id_rekam_medis']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($page == 'pasien'): ?>
        <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-warning text-dark">Form Pasien</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID (Otomatis)</label><input type="text" name="id_pasien" class="form-control bg-light" value="<?= $data_edit['id_pasien'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Nama</label><input type="text" name="nama" class="form-control" value="<?= $data_edit['nama'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Lahir</label><input type="date" name="tgl_lahir" class="form-control" value="<?= $data_edit['tanggal_lahir'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Alamat</label><input type="text" name="alamat" class="form-control" value="<?= $data_edit['alamat'] ?? '' ?>" required></div>
                        <div class="mb-3"><label>Telp</label><input type="text" name="no_telp" class="form-control" value="<?= $data_edit['nomor_telepon'] ?? '' ?>" required></div>
                        <button type="submit" name="simpan_pasien" class="btn btn-warning w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Alamat</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_pasien as $p): ?>
                    <tr>
                        <td><?=$p['id_pasien']?></td><td><?=$p['nama']?></td><td><?=$p['alamat']?></td>
                        <td><?= $p['tagihan_aktif'] > 0 ? '<span class="badge bg-danger">Dalam Perawatan</span>' : '<span class="badge bg-success">Pulang</span>' ?></td>
                        <td>
                            <a href="detail_pasien.php?id=<?=$p['id_pasien']?>" class="btn btn-info btn-sm text-white"><i class="fas fa-file-alt"></i> Detail</a>
                            <a href="?page=pasien&action=edit&id=<?=$p['id_pasien']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=pasien&action=delete&id=<?=$p['id_pasien']?>" onclick="return confirm('Yakin hapus?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($page == 'perawat'): ?>
        <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-info text-dark">Form Perawat</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID (Otomatis)</label><input type="text" name="id_perawat" class="form-control bg-light" value="<?= $data_edit['id_tenaga_medis'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Nama Perawat</label><input type="text" name="nama" class="form-control" value="<?= $data_edit['nama_tenaga_medis'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Departemen</label><select name="id_dept" class="form-select" required><?php foreach($list_dept as $dp): ?><option value="<?=$dp['id_departemen']?>" <?= ($data_edit && $data_edit['id_departemen'] == $dp['id_departemen']) ? 'selected' : '' ?>><?=$dp['nama_departemen']?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label>Shift</label><select name="shift" class="form-select" required><option value="Pagi" <?= ($data_edit && $data_edit['shift'] == 'Pagi') ? 'selected' : '' ?>>Pagi</option><option value="Siang" <?= ($data_edit && $data_edit['shift'] == 'Siang') ? 'selected' : '' ?>>Siang</option><option value="Malam" <?= ($data_edit && $data_edit['shift'] == 'Malam') ? 'selected' : '' ?>>Malam</option></select></div>
                        <button type="submit" name="simpan_perawat" class="btn btn-info w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Dept</th><th>Shift</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_perawat as $p): ?>
                    <tr><td><?=$p['id_tenaga_medis']?></td><td><?=$p['nama_tenaga_medis']?></td><td><?=$p['nama_departemen']?></td><td><span class="badge bg-secondary"><?=$p['shift']?></span></td><td><a href="?page=perawat&action=edit&id=<?=$p['id_tenaga_medis']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a> <a href="?page=perawat&action=delete&id=<?=$p['id_tenaga_medis']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($page == 'dokter'): ?>
        <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-success text-white">Form Dokter</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID (Otomatis)</label><input type="text" name="id_dokter" class="form-control bg-light" value="<?= $data_edit['id_tenaga_medis'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Nama</label><input type="text" name="nama" class="form-control" value="<?= $data_edit['nama_tenaga_medis'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Departemen</label><select name="id_dept" class="form-select" required><?php foreach($list_dept as $dp): ?><option value="<?=$dp['id_departemen']?>" <?= ($data_edit && $data_edit['id_departemen'] == $dp['id_departemen']) ? 'selected' : '' ?>><?=$dp['nama_departemen']?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label>Spesialisasi</label><input type="text" name="spesialisasi" class="form-control" value="<?= $data_edit['spesialisasi'] ?? '' ?>" required></div>
                        <button type="submit" name="simpan_dokter" class="btn btn-success w-100">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Dept</th><th>Spesialisasi</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_dokter as $d): ?>
                    <tr><td><?=$d['id_tenaga_medis']?></td><td><?=$d['nama_tenaga_medis']?></td><td><?=$d['nama_departemen']?></td><td><?=$d['spesialisasi']?></td><td><a href="?page=dokter&action=edit&id=<?=$d['id_tenaga_medis']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a> <a href="?page=dokter&action=delete&id=<?=$d['id_tenaga_medis']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

   <?php if ($page == 'rawat_inap'): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card shadow mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-procedures me-2"></i>Monitor Bangsal & Checkout</h5>
                        <small class="text-white-50">Data masuk otomatis dari Rekam Medis</small>
                    </div>
                    <div class="card-body">
                        
                        <div class="mb-3">
                            <span class="badge bg-danger me-2">Merah = Sedang Dirawat</span>
                            <span class="badge bg-success">Hijau = Sudah Pulang</span>
                        </div>

                        <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-secondary">
                                <tr>
                                    <th>ID Kamar</th>
                                    <th>Nama Pasien</th>
                                    <th>Layanan / Kelas</th>
                                    <th>Tgl Masuk</th>
                                    <th>Tgl Keluar</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($data_inap as $r): ?>
                                <?php 
                                    // Cek apakah pasien masih dirawat (tanggal keluar kosong)
                                    $sedang_dirawat = empty($r['tanggal_keluar']); 
                                    // Kalau sedang dirawat, barisnya dikasih warna kuning tipis
                                    $bg_row = $sedang_dirawat ? 'table-warning' : ''; 
                                ?>
                                <tr class="<?= $bg_row ?>">
                                    <td class="fw-bold"><?= $r['id_kamar'] ?></td>
                                    <td><?= htmlspecialchars($r['nama_pasien']) ?></td>
                                    <td><?= $r['nama_layanan'] ?></td>
                                    <td><?= date('d-m-Y', strtotime($r['tanggal_masuk'])) ?></td>
                                    <td>
                                        <?= $sedang_dirawat ? '-' : date('d-m-Y', strtotime($r['tanggal_keluar'])) ?>
                                    </td>
                                    <td>
                                        <?php if($sedang_dirawat): ?>
                                            <span class="badge bg-danger">Sedang Dirawat</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Sudah Pulang</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($sedang_dirawat): ?>
                                            <a href="?page=rawat_inap&action=checkout&id=<?=$r['id_kamar']?>" 
                                               class="btn btn-success btn-sm" 
                                               onclick="return confirm('Pasien sudah boleh pulang (Checkout)? Tanggal keluar akan diisi hari ini.')">
                                               <i class="fas fa-check-circle"></i> Checkout
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled>Selesai</button>
                                        <?php endif; ?>

                                        <a href="?page=rawat_inap&action=delete&id=<?=$r['id_kamar']?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Hapus data kamar ini? History akan hilang.')">
                                           <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($page == 'tagihan'): ?>
        <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-dark text-white">Kasir</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>No Tagihan (Otomatis)</label><input type="text" name="id_tagihan" class="form-control bg-light" value="<?= $data_edit['id_tagihan'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Pasien</label><select name="id_pasien" class="form-select" required><option value="">-- Pilih Pasien --</option><?php foreach($list_pasien as $p): ?><option value="<?=$p['id_pasien']?>" <?= ($data_edit && $data_edit['id_pasien'] == $p['id_pasien']) ? 'selected' : '' ?>><?=$p['nama']?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_tagihan'] ?? date('Y-m-d') ?>" required></div>
                        <div class="mb-2"><label>Biaya</label><input type="number" name="biaya" class="form-control" value="<?= $data_edit['total_biaya'] ?? '' ?>" required></div>
                        <div class="mb-3"><label>Status</label><select name="status" class="form-select" required><option value="Belum Lunas" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Belum Lunas') ? 'selected' : '' ?>>Belum Lunas</option><option value="Lunas" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Lunas') ? 'selected' : '' ?>>Lunas</option><option value="Dicicil" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Dicicil') ? 'selected' : '' ?>>Dicicil</option></select></div>
                        <button type="submit" name="simpan_tagihan" class="btn btn-primary w-100">Simpan Tagihan</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>No</th><th>Pasien</th><th>Tanggal</th><th>Biaya</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_tagihan as $t): ?>
                    <tr><td><?=$t['id_tagihan']?></td><td><?=$t['nama_pasien']?></td><td><?=date('d-m-Y', strtotime($t['tanggal_tagihan']))?></td><td>Rp <?=number_format($t['total_biaya'], 0, ',', '.')?></td><td><span class="badge <?= $t['status_pembayaran']=='Lunas' ? 'bg-success' : 'bg-danger' ?>"><?=$t['status_pembayaran']?></span></td><td><a href="?page=tagihan&action=edit&id=<?=$t['id_tagihan']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a> <a href="?page=tagihan&action=delete&id=<?=$t['id_tagihan']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title">Detail Rekam Medis</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <table class="table table-borderless">
                    <tr><td width="35%">ID Rekam Medis</td><td>: <span id="mdl_id"></span></td></tr>
                    <tr><td>Pasien</td><td>: <strong><span id="mdl_pasien"></span></strong></td></tr>
                    <tr><td>Dokter</td><td>: <span id="mdl_dokter"></span></td></tr>
                    <tr><td>Tanggal</td><td>: <span id="mdl_tgl"></span></td></tr>
                    <tr><td>Status</td><td>: <span id="mdl_status" class="fw-bold"></span></td></tr>
                    <tr><td>Diagnosis</td><td>: <span class="badge bg-warning text-dark" id="mdl_diag"></span></td></tr>
                </table>
                <hr><h6>Hasil Pemeriksaan:</h6><p id="mdl_hasil" class="p-3 bg-light border rounded"></p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleKamar(show) {
        document.getElementById('pilihan_kamar').style.display = show ? 'block' : 'none';
    }

    const modalDetail = document.getElementById('modalDetail');
    if (modalDetail) {
        modalDetail.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('mdl_id').textContent = button.getAttribute('data-id');
            document.getElementById('mdl_pasien').textContent = button.getAttribute('data-pasien');
            document.getElementById('mdl_dokter').textContent = button.getAttribute('data-dokter');
            document.getElementById('mdl_tgl').textContent = button.getAttribute('data-tgl');
            document.getElementById('mdl_diag').textContent = button.getAttribute('data-diag');
            document.getElementById('mdl_hasil').textContent = button.getAttribute('data-hasil');
            document.getElementById('mdl_status').textContent = button.getAttribute('data-status');
        });
    }
</script>
</body>
</html>