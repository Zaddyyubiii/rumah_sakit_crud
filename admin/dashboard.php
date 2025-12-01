<?php
// File: admin/dashboard.php
session_start();
include '../includes/db.php';

// ==========================================================
// 0. LOGIK STATISTIK (DASHBOARD ATAS)
// ==========================================================
$stat_pasien = $conn->query("SELECT COUNT(*) FROM PASIEN")->fetchColumn();
$stat_dokter = $conn->query("SELECT COUNT(*) FROM DOKTER")->fetchColumn();
$stat_rm = $conn->query("SELECT COUNT(*) FROM REKAM_MEDIS")->fetchColumn();
// Hitung duit hanya yang statusnya 'Lunas'
$stat_uang = $conn->query("SELECT COALESCE(SUM(Total_Biaya), 0) FROM TAGIHAN WHERE Status_Pembayaran = 'Lunas'")->fetchColumn();


// ==========================================================
// LOGIK CRUD UTAMA
// ==========================================================
$page = isset($_GET['page']) ? $_GET['page'] : 'rekam_medis'; // Default page
$action = isset($_GET['action']) ? $_GET['action'] : '';
$id_edit = isset($_GET['id']) ? $_GET['id'] : '';
$data_edit = null;

// --- 1. LOGIK DELETE GLOBAL ---
if ($action == 'delete' && !empty($id_edit)) {
    try {
        if ($page == 'pasien') {
            $stmt = $conn->prepare("DELETE FROM PASIEN WHERE ID_Pasien = ?");
        } elseif ($page == 'dokter') {
            // Hapus dokter agak tricky karena relasi, kita hapus parent-nya (Tenaga Medis)
            // Pastikan ON DELETE CASCADE aktif di database, atau hapus manual child-nya dulu
            $stmt = $conn->prepare("DELETE FROM TENAGA_MEDIS WHERE ID_Tenaga_Medis = ?");
        } elseif ($page == 'rekam_medis') {
            $stmt = $conn->prepare("DELETE FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        } elseif ($page == 'tagihan') {
            $stmt = $conn->prepare("DELETE FROM TAGIHAN WHERE ID_Tagihan = ?");
        }
        
        if (isset($stmt)) {
            $stmt->execute([$id_edit]);
            header("Location: dashboard.php?page=$page&msg=deleted");
            exit();
        }
    } catch (PDOException $e) {
        $error = "Gagal Hapus (Data sedang dipakai tabel lain): " . $e->getMessage();
    }
}

// --- 2. LOGIK HALAMAN TAGIHAN (BARU) ---
if ($page == 'tagihan') {
    // Ambil list pasien buat dropdown
    $list_pasien = $conn->query("SELECT id_pasien, nama FROM PASIEN")->fetchAll(PDO::FETCH_ASSOC);

    // Ambil data edit jika ada
    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM TAGIHAN WHERE ID_Tagihan = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Proses Simpan Tagihan
    if (isset($_POST['simpan_tagihan'])) {
        try {
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE TAGIHAN SET ID_Pasien=?, Tanggal_Tagihan=?, Total_Biaya=?, Status_Pembayaran=? WHERE ID_Tagihan=?";
                $params = [$_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status'], $_POST['id_tagihan']];
            } else {
                $sql = "INSERT INTO TAGIHAN (ID_Tagihan, ID_Pasien, Tanggal_Tagihan, Total_Biaya, Status_Pembayaran) VALUES (?, ?, ?, ?, ?)";
                $params = [$_POST['id_tagihan'], $_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status']];
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            header("Location: dashboard.php?page=tagihan&msg=saved");
            exit();
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }

    // Tampil Data Tabel Tagihan
    $data_tagihan = $conn->query("SELECT t.*, p.Nama AS nama_pasien 
                                  FROM TAGIHAN t 
                                  JOIN PASIEN p ON t.ID_Pasien = p.ID_Pasien 
                                  ORDER BY t.Tanggal_Tagihan DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// --- 3. LOGIK HALAMAN LAIN (SAMA SEPERTI SEBELUMNYA) ---
if ($page == 'pasien') {
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
                $sql = "INSERT INTO PASIEN (Nama, Tanggal_Lahir, Alamat, Nomor_Telepon, ID_Pasien) VALUES (?, ?, ?, ?, ?)";
                $params = [$_POST['nama'], $_POST['tgl_lahir'], $_POST['alamat'], $_POST['no_telp'], $_POST['id_pasien']];
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            header("Location: dashboard.php?page=pasien&msg=saved");
            exit();
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
    $data_pasien = $conn->query("SELECT * FROM PASIEN ORDER BY ID_Pasien ASC")->fetchAll(PDO::FETCH_ASSOC);
}

if ($page == 'dokter') {
    $list_dept = $conn->query("SELECT * FROM DEPARTEMEN")->fetchAll(PDO::FETCH_ASSOC);
    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT tm.*, d.Spesialisasi FROM TENAGA_MEDIS tm JOIN DOKTER d ON tm.ID_Tenaga_Medis = d.ID_Tenaga_Medis WHERE tm.ID_Tenaga_Medis = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['simpan_dokter'])) {
        try {
            $conn->beginTransaction();
            if ($_POST['mode'] == 'update') {
                $stmt1 = $conn->prepare("UPDATE TENAGA_MEDIS SET Nama_Tenaga_Medis=?, ID_Departemen=? WHERE ID_Tenaga_Medis=?");
                $stmt1->execute([$_POST['nama'], $_POST['id_dept'], $_POST['id_dokter']]);
                $stmt2 = $conn->prepare("UPDATE DOKTER SET Spesialisasi=? WHERE ID_Tenaga_Medis=?");
                $stmt2->execute([$_POST['spesialisasi'], $_POST['id_dokter']]);
            } else {
                $stmt1 = $conn->prepare("INSERT INTO TENAGA_MEDIS (ID_Tenaga_Medis, Nama_Tenaga_Medis, ID_Departemen) VALUES (?, ?, ?)");
                $stmt1->execute([$_POST['id_dokter'], $_POST['nama'], $_POST['id_dept']]);
                $stmt2 = $conn->prepare("INSERT INTO DOKTER (ID_Tenaga_Medis, Spesialisasi) VALUES (?, ?)");
                $stmt2->execute([$_POST['id_dokter'], $_POST['spesialisasi']]);
            }
            $conn->commit();
            header("Location: dashboard.php?page=dokter&msg=saved");
            exit();
        } catch (PDOException $e) { $conn->rollBack(); $error = $e->getMessage(); }
    }
    $data_dokter = $conn->query("SELECT tm.ID_Tenaga_Medis, tm.Nama_Tenaga_Medis, dp.Nama_Departemen, d.Spesialisasi FROM TENAGA_MEDIS tm JOIN DOKTER d ON tm.ID_Tenaga_Medis = d.ID_Tenaga_Medis JOIN DEPARTEMEN dp ON tm.ID_Departemen = dp.ID_Departemen")->fetchAll(PDO::FETCH_ASSOC);
}

if ($page == 'rekam_medis') {
    $list_pasien = $conn->query("SELECT id_pasien, nama FROM PASIEN")->fetchAll(PDO::FETCH_ASSOC);
    $list_dokter = $conn->query("SELECT d.id_tenaga_medis, tm.nama_tenaga_medis FROM DOKTER d JOIN TENAGA_MEDIS tm ON d.id_tenaga_medis = tm.id_tenaga_medis")->fetchAll(PDO::FETCH_ASSOC);
    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (isset($_POST['simpan_rm'])) {
        try {
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE REKAM_MEDIS SET ID_Pasien=?, ID_Tenaga_Medis=?, Tanggal_Catatan=?, Diagnosis=?, Hasil_Pemeriksaan=? WHERE ID_Rekam_Medis=?";
                $params = [$_POST['id_pasien'], $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil'], $_POST['id_rm']];
            } else {
                $sql = "INSERT INTO REKAM_MEDIS (ID_Pasien, ID_Tenaga_Medis, Tanggal_Catatan, Diagnosis, Hasil_Pemeriksaan, ID_Rekam_Medis) VALUES (?, ?, ?, ?, ?, ?)";
                $params = [$_POST['id_pasien'], $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil'], $_POST['id_rm']];
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            header("Location: dashboard.php?page=rekam_medis&msg=saved");
            exit();
        } catch (PDOException $e) { $error = $e->getMessage(); }
    }
    $data_rm = $conn->query("SELECT rm.*, p.Nama AS nama_pasien, tm.Nama_Tenaga_Medis AS nama_dokter FROM REKAM_MEDIS rm JOIN PASIEN p ON rm.ID_Pasien = p.ID_Pasien JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis ORDER BY rm.Tanggal_Catatan DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card-stat { transition: transform 0.2s; cursor: pointer; }
        .card-stat:hover { transform: translateY(-5px); }
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
        <div class="col-md-3">
            <div class="card shadow border-0 bg-primary text-white card-stat">
                <div class="card-body">
                    <h6 class="mb-0">Total Pasien</h6>
                    <h2 class="mb-0 fw-bold"><?= $stat_pasien ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow border-0 bg-success text-white card-stat">
                <div class="card-body">
                    <h6 class="mb-0">Dokter Tersedia</h6>
                    <h2 class="mb-0 fw-bold"><?= $stat_dokter ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow border-0 bg-info text-white card-stat">
                <div class="card-body">
                    <h6 class="mb-0">Total Kunjungan</h6>
                    <h2 class="mb-0 fw-bold"><?= $stat_rm ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <a href="laporan_keuangan.php" class="text-decoration-none">
                <div class="card shadow border-0 bg-warning text-dark card-stat">
                    <div class="card-body">
                        <h6 class="mb-0">Pendapatan RS</h6>
                        <h4 class="mb-0 fw-bold">Rp <?= number_format($stat_uang, 0, ',', '.') ?></h4>
                        <small>Klik untuk rincian &rarr;</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $page == 'rekam_medis' ? 'active' : '' ?>" href="?page=rekam_medis"><i class="fas fa-notes-medical"></i> Rekam Medis</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'pasien' ? 'active' : '' ?>" href="?page=pasien"><i class="fas fa-wheelchair"></i> Pasien</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'dokter' ? 'active' : '' ?>" href="?page=dokter"><i class="fas fa-user-doctor"></i> Dokter</a></li>
        <li class="nav-item"><a class="nav-link <?= $page == 'tagihan' ? 'active' : '' ?>" href="?page=tagihan"><i class="fas fa-file-invoice-dollar"></i> Kasir / Tagihan</a></li>
    </ul>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success">Aksi berhasil dilakukan!</div>
    <?php endif; ?>

    <?php if ($page == 'tagihan'): ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-dark text-white"><?= $data_edit ? 'Edit Tagihan' : 'Buat Tagihan Baru' ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2">
                            <label>No Tagihan</label>
                            <input type="text" name="id_tagihan" class="form-control" placeholder="T00..." value="<?= $data_edit['id_tagihan'] ?? '' ?>" <?= $data_edit ? 'readonly' : '' ?> required>
                        </div>
                        <div class="mb-2">
                            <label>Pasien</label>
                            <select name="id_pasien" class="form-select" required>
                                <option value="">-- Pilih Pasien --</option>
                                <?php foreach($list_pasien as $p): ?>
                                    <option value="<?=$p['id_pasien']?>" <?= ($data_edit && $data_edit['id_pasien'] == $p['id_pasien']) ? 'selected' : '' ?>><?=$p['nama']?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_tagihan'] ?? date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-2">
                            <label>Total Biaya (Rp)</label>
                            <input type="number" name="biaya" class="form-control" placeholder="Contoh: 150000" value="<?= $data_edit['total_biaya'] ?? '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label>Status Pembayaran</label>
                            <select name="status" class="form-select" required>
                                <option value="Belum Lunas" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Belum Lunas') ? 'selected' : '' ?>>Belum Lunas</option>
                                <option value="Lunas" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Lunas') ? 'selected' : '' ?>>Lunas (Paid)</option>
                                <option value="Dicicil" <?= ($data_edit && $data_edit['status_pembayaran'] == 'Dicicil') ? 'selected' : '' ?>>Dicicil</option>
                            </select>
                            <small class="text-muted">*Pilih LUNAS agar masuk statistik.</small>
                        </div>
                        <button type="submit" name="simpan_tagihan" class="btn btn-primary w-100">Simpan Tagihan</button>
                        <?php if($data_edit): ?> <a href="?page=tagihan" class="btn btn-secondary w-100 mt-2">Batal</a> <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>No Tagihan</th><th>Pasien</th><th>Tanggal</th><th>Biaya</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_tagihan as $t): ?>
                    <tr>
                        <td><?=$t['id_tagihan']?></td>
                        <td><?=$t['nama_pasien']?></td>
                        <td><?=date('d-m-Y', strtotime($t['tanggal_tagihan']))?></td>
                        <td class="text-end">Rp <?=number_format($t['total_biaya'], 0, ',', '.')?></td>
                        <td>
                            <?php 
                            $badge = match($t['status_pembayaran']) {
                                'Lunas' => 'bg-success',
                                'Belum Lunas' => 'bg-danger',
                                default => 'bg-warning text-dark'
                            };
                            ?>
                            <span class="badge <?=$badge?>"><?=$t['status_pembayaran']?></span>
                        </td>
                        <td>
                            <a href="?page=tagihan&action=edit&id=<?=$t['id_tagihan']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=tagihan&action=delete&id=<?=$t['id_tagihan']?>" onclick="return confirm('Yakin hapus?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($page == 'rekam_medis'): ?>
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-header bg-dark text-white"><?= $data_edit ? 'Edit Data' : 'Input Data' ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2">
                            <label>ID RM</label>
                            <input type="text" name="id_rm" class="form-control" value="<?= $data_edit['id_rekam_medis'] ?? '' ?>" <?= $data_edit ? 'readonly' : '' ?> required>
                        </div>
                        <div class="mb-2">
                            <label>Pasien</label>
                            <select name="id_pasien" class="form-select" required>
                                <?php foreach($list_pasien as $p): ?>
                                    <option value="<?=$p['id_pasien']?>" <?= ($data_edit && $data_edit['id_pasien'] == $p['id_pasien']) ? 'selected' : '' ?>><?=$p['nama']?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label>Dokter</label>
                            <select name="id_dokter" class="form-select" required>
                                <?php foreach($list_dokter as $d): ?>
                                    <option value="<?=$d['id_tenaga_medis']?>" <?= ($data_edit && $data_edit['id_tenaga_medis'] == $d['id_tenaga_medis']) ? 'selected' : '' ?>><?=$d['nama_tenaga_medis']?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_catatan'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Diagnosis</label><input type="text" name="diagnosis" class="form-control" value="<?= $data_edit['diagnosis'] ?? '' ?>" required></div>
                        <div class="mb-3"><label>Hasil</label><textarea name="hasil" class="form-control" required><?= $data_edit['hasil_pemeriksaan'] ?? '' ?></textarea></div>
                        <button type="submit" name="simpan_rm" class="btn btn-primary w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                        <?php if($data_edit): ?> <a href="?page=rekam_medis" class="btn btn-secondary w-100 mt-2">Batal</a> <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Pasien</th><th>Dokter</th><th>Diagnosis</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_rm as $r): ?>
                    <tr>
                        <td><?=$r['id_rekam_medis']?></td>
                        <td><?=$r['nama_pasien']?></td>
                        <td><?=$r['nama_dokter']?></td>
                        <td><?=$r['diagnosis']?></td>
                        <td>
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
                <div class="card-header bg-warning text-dark"><?= $data_edit ? 'Edit Pasien' : 'Tambah Pasien' ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID</label><input type="text" name="id_pasien" class="form-control" value="<?= $data_edit['id_pasien'] ?? '' ?>" <?= $data_edit ? 'readonly' : '' ?> required></div>
                        <div class="mb-2"><label>Nama</label><input type="text" name="nama" class="form-control" value="<?= $data_edit['nama'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Lahir</label><input type="date" name="tgl_lahir" class="form-control" value="<?= $data_edit['tanggal_lahir'] ?? '' ?>" required></div>
                        <div class="mb-2"><label>Alamat</label><input type="text" name="alamat" class="form-control" value="<?= $data_edit['alamat'] ?? '' ?>" required></div>
                        <div class="mb-3"><label>Telp</label><input type="text" name="no_telp" class="form-control" value="<?= $data_edit['nomor_telepon'] ?? '' ?>" required></div>
                        <button type="submit" name="simpan_pasien" class="btn btn-warning w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                        <?php if($data_edit): ?> <a href="?page=pasien" class="btn btn-secondary w-100 mt-2">Batal</a> <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Alamat</th><th>Telp</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_pasien as $p): ?>
                    <tr>
                        <td><?=$p['id_pasien']?></td>
                        <td><?=$p['nama']?></td>
                        <td><?=$p['alamat']?></td>
                        <td><?=$p['nomor_telepon']?></td>
                        <td>
                            <a href="?page=pasien&action=edit&id=<?=$p['id_pasien']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=pasien&action=delete&id=<?=$p['id_pasien']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
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
                <div class="card-header bg-success text-white"><?= $data_edit ? 'Edit Dokter' : 'Tambah Dokter' ?></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID</label><input type="text" name="id_dokter" class="form-control" value="<?= $data_edit['id_tenaga_medis'] ?? '' ?>" <?= $data_edit ? 'readonly' : '' ?> required></div>
                        <div class="mb-2"><label>Nama</label><input type="text" name="nama" class="form-control" value="<?= $data_edit['nama_tenaga_medis'] ?? '' ?>" required></div>
                        <div class="mb-2">
                            <label>Departemen</label>
                            <select name="id_dept" class="form-select" required>
                                <?php foreach($list_dept as $dp): ?>
                                    <option value="<?=$dp['id_departemen']?>" <?= ($data_edit && $data_edit['id_departemen'] == $dp['id_departemen']) ? 'selected' : '' ?>><?=$dp['nama_departemen']?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><label>Spesialisasi</label><input type="text" name="spesialisasi" class="form-control" value="<?= $data_edit['spesialisasi'] ?? '' ?>" required></div>
                        <button type="submit" name="simpan_dokter" class="btn btn-success w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                        <?php if($data_edit): ?> <a href="?page=dokter" class="btn btn-secondary w-100 mt-2">Batal</a> <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="table-responsive">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Departemen</th><th>Spesialisasi</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_dokter as $d): ?>
                    <tr>
                        <td><?=$d['id_tenaga_medis']?></td>
                        <td><?=$d['nama_tenaga_medis']?></td>
                        <td><?=$d['nama_departemen']?></td>
                        <td><?=$d['spesialisasi']?></td>
                        <td>
                            <a href="?page=dokter&action=edit&id=<?=$d['id_tenaga_medis']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=dokter&action=delete&id=<?=$d['id_tenaga_medis']?>" onclick="return confirm('Yakin?')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>