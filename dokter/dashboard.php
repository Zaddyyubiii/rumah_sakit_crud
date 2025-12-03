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

// Ambil nama dokter
$nama_dokter = $username;
try {
    $stNama = $conn->prepare("SELECT nama_tenaga_medis FROM tenaga_medis WHERE id_tenaga_medis = ?");
    $stNama->execute([$id_tm]);
    $row = $stNama->fetch(PDO::FETCH_ASSOC);
    if ($row) $nama_dokter = $row['nama_tenaga_medis'];
} catch (Exception $e) {}

// Helper: generate ID otomatis
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $sql  = "SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    if (!$last) { $num = 1; } 
    else { $num = (int)preg_replace('/\D/', '', $last) + 1; }
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

$view = $_GET['view'] ?? 'rekam_medis';
$flash_success = null;
$flash_error   = null;

// ===================================================================
// HANDLE POST (LOGIKA FLOW BARU)
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';

    try {
        // 1. TAMBAH PASIEN (Sama seperti sebelumnya)
        if ($form_type === 'pasien_add') {
            $view = 'pasien';
            $id_pasien = generateNextId($conn, 'pasien', 'id_pasien', 'P');
            $nama = trim($_POST['nama'] ?? '');
            
            if (empty($nama)) throw new Exception("Nama pasien wajib diisi.");

            $stmt = $conn->prepare("INSERT INTO pasien (id_pasien, nama, tanggal_lahir, alamat, nomor_telepon) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id_pasien, $nama, $_POST['tanggal_lahir'], $_POST['alamat'], $_POST['nomor_telepon']]);
            $flash_success = "Pasien berhasil ditambahkan: $id_pasien";
        }

        // 2. BUAT JADWAL PEMERIKSAAN (Flow: Dokter Input ID RM -> Buat Jadwal)
        if ($form_type === 'pemeriksaan_add') {
            $view = 'pemeriksaan';
            $id_pemeriksaan = generateNextId($conn, 'pemeriksaan', 'id_pemeriksaan', 'PE');
            $id_rm_input    = strtoupper(trim($_POST['id_rekam_medis'] ?? '')); // Dokter input manual ID RM

            if (empty($id_rm_input)) throw new Exception("ID Rekam Medis wajib diisi.");

            // Cek apakah ID RM Valid & Milik Dokter ini (opsional: atau bebas)
            // Disini kita cek apakah RM ada di database
            $rmCheck = $conn->prepare("SELECT id_pasien FROM rekam_medis WHERE id_rekam_medis = ?");
            $rmCheck->execute([$id_rm_input]);
            $rmData = $rmCheck->fetch(PDO::FETCH_ASSOC);

            if (!$rmData) throw new Exception("ID Rekam Medis tidak ditemukan. Pastikan Admin sudah membuatnya.");

            // Cek apakah RM ini sudah punya jadwal? (Prevent double booking)
            $cekDouble = $conn->prepare("SELECT 1 FROM pemeriksaan WHERE id_rekam_medis = ?");
            $cekDouble->execute([$id_rm_input]);
            if($cekDouble->fetchColumn()) throw new Exception("Rekam Medis ini sudah memiliki jadwal pemeriksaan.");

            $sql = "INSERT INTO pemeriksaan (id_pemeriksaan, id_pasien, id_tenaga_medis, tanggal_pemeriksaan, waktu_pemeriksaan, ruang_pemeriksaan, id_rekam_medis)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql)->execute([
                $id_pemeriksaan, 
                $rmData['id_pasien'], 
                $id_tm, 
                $_POST['tanggal_pemeriksaan'], 
                $_POST['waktu_pemeriksaan'], 
                $_POST['ruang_pemeriksaan'], 
                $id_rm_input
            ]);

            $flash_success = "Jadwal pemeriksaan berhasil dibuat ($id_pemeriksaan).";
        }

        // 3. INPUT LAYANAN & AUTO RAWAT INAP (Flow: Input Layanan -> Cek Jenis -> Insert Rawat Inap jika perlu)
        if ($form_type === 'detail_pem_add') {
            $view = 'layanan';
            $id_pemeriksaan = $_POST['id_pemeriksaan'] ?? '';
            $id_layanan     = $_POST['id_layanan'] ?? '';
            $konsultasi     = trim($_POST['konsultasi'] ?? '');
            $suntik_vitamin = ($_POST['suntik_vitamin'] ?? 'Tidak') === 'Ya' ? 'Ya' : 'Tidak';

            if (empty($id_pemeriksaan) || empty($id_layanan)) throw new Exception("Pilih pemeriksaan dan layanan.");

            // A. Simpan ke Detail Pemeriksaan (Record Medis & Tagihan)
            $sqlDet = "INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin)
                       VALUES (?, ?, ?, ?)
                       ON CONFLICT (id_layanan, id_pemeriksaan) DO UPDATE SET konsultasi = EXCLUDED.konsultasi";
            $conn->prepare($sqlDet)->execute([$id_layanan, $id_pemeriksaan, $konsultasi, $suntik_vitamin]);

            // B. LOGIKA OTOMATIS RAWAT INAP
            // Cek apakah layanan yang dipilih mengandung kata "Kamar", "Inap", atau "VIP"
            $cekLay = $conn->prepare("SELECT nama_layanan FROM layanan WHERE id_layanan = ?");
            $cekLay->execute([$id_layanan]);
            $nama_layanan = $cekLay->fetchColumn();

            if (preg_match('/(Kamar|Inap|VIP)/i', $nama_layanan)) {
                // Ambil info pasien dari pemeriksaan
                $qPasien = $conn->prepare("SELECT id_pasien, tanggal_pemeriksaan, id_rekam_medis FROM pemeriksaan WHERE id_pemeriksaan = ?");
                $qPasien->execute([$id_pemeriksaan]);
                $dPasien = $qPasien->fetch(PDO::FETCH_ASSOC);

                // Masukkan ke tabel RAWAT_INAP (Biar Admin bisa lihat)
                // Cek dulu biar ga duplikat
                $cekRI = $conn->prepare("SELECT 1 FROM rawat_inap WHERE id_pasien = ? AND tanggal_keluar IS NULL");
                $cekRI->execute([$dPasien['id_pasien']]);
                
                if (!$cekRI->fetchColumn()) {
                    $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
                    $sqlInap = "INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk, tanggal_keluar) 
                                VALUES (?, ?, ?, ?, NULL)";
                    $conn->prepare($sqlInap)->execute([$id_kamar, $id_layanan, $dPasien['id_pasien'], $dPasien['tanggal_pemeriksaan']]);
                    
                    // Update Status RM jadi Rawat Inap (Opsional, buat visual aja)
                    // (Asumsi di tabel rekam_medis ada kolom 'jenis_rawat' atau sejenisnya, kalau ga ada skip aja)
                    // $conn->prepare("UPDATE rekam_medis SET jenis_rawat='Rawat Inap' WHERE id_rekam_medis=?")->execute([$dPasien['id_rekam_medis']]);
                    
                    $flash_success = "Layanan disimpan. Pasien OTOMATIS masuk daftar Rawat Inap Admin.";
                } else {
                    $flash_success = "Layanan kamar ditambahkan (Pasien sudah terdaftar di Rawat Inap).";
                }
            } else {
                $flash_success = "Layanan Rawat Jalan berhasil disimpan.";
            }
        }

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
    } catch (PDOException $e) {
        $flash_error = "Database Error: " . $e->getMessage();
    }
}

// ===================================================================
// DATA FETCHING
// ===================================================================

// 1. REKAM MEDIS (List untuk Dokter)
$rekam_medis = [];
$stat_rm = ['total' => 0];
if ($view === 'rekam_medis') {
    $sql = "SELECT rm.*, p.nama AS nama_pasien, 
            (SELECT COUNT(*) FROM rawat_inap ri WHERE ri.id_pasien = rm.id_pasien AND ri.tanggal_keluar IS NULL) as is_inap
            FROM rekam_medis rm 
            JOIN pasien p ON rm.id_pasien = p.id_pasien 
            WHERE rm.id_tenaga_medis = ? ORDER BY rm.tanggal_catatan DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $rekam_medis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stat_rm['total'] = count($rekam_medis);
}

// 2. PASIEN
$pasien = [];
if ($view === 'pasien') {
    $pasien = $conn->query("SELECT * FROM pasien ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// 3. PEMERIKSAAN
$pemeriksaan = [];
if ($view === 'pemeriksaan') {
    $sql = "SELECT pe.*, p.nama AS nama_pasien, 
            (SELECT COUNT(*) FROM detail_pemeriksaan dp WHERE dp.id_pemeriksaan = pe.id_pemeriksaan) as is_done
            FROM pemeriksaan pe 
            JOIN pasien p ON pe.id_pasien = p.id_pasien 
            WHERE pe.id_tenaga_medis = ? ORDER BY pe.tanggal_pemeriksaan DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 4. LAYANAN (Dynamic Dropdown Prep)
$layanan_kamar = [];
$layanan_tindakan = [];
$daftar_pemeriksaan = [];
$detail_layanan = [];

if ($view === 'layanan') {
    // Ambil Data Layanan & Pisahkan
    $all_layanan = $conn->query("SELECT * FROM layanan ORDER BY nama_layanan")->fetchAll(PDO::FETCH_ASSOC);
    foreach($all_layanan as $l) {
        if (preg_match('/(Kamar|Inap|VIP)/i', $l['nama_layanan'])) {
            $layanan_kamar[] = $l;
        } else {
            $layanan_tindakan[] = $l;
        }
    }

    // Dropdown Pemeriksaan (Yang belum selesai/semua milik dokter)
    $stmt = $conn->prepare("SELECT pe.id_pemeriksaan, p.nama, pe.tanggal_pemeriksaan 
                            FROM pemeriksaan pe JOIN pasien p ON pe.id_pasien = p.id_pasien 
                            WHERE pe.id_tenaga_medis = ? ORDER BY pe.tanggal_pemeriksaan DESC");
    $stmt->execute([$id_tm]);
    $daftar_pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // List History Layanan
    $stmt = $conn->prepare("SELECT dp.*, l.nama_layanan, pe.tanggal_pemeriksaan, p.nama as nama_pasien 
                            FROM detail_pemeriksaan dp 
                            JOIN layanan l ON dp.id_layanan = l.id_layanan
                            JOIN pemeriksaan pe ON dp.id_pemeriksaan = pe.id_pemeriksaan
                            JOIN pasien p ON pe.id_pasien = p.id_pasien
                            WHERE pe.id_tenaga_medis = ? ORDER BY pe.tanggal_pemeriksaan DESC");
    $stmt->execute([$id_tm]);
    $detail_layanan = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .card { background: #ffffff; border-radius: 12px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card h3 { margin: 0 0 4px 0; font-size: 15px; }
        .card-strong { font-size: 22px; font-weight: 700; color: #1565c0; }
        .section { background: #ffffff; border-radius: 12px; padding: 18px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .section-header h2 { margin: 0; font-size: 17px; margin-bottom: 12px;}
        .btn-primary { background: #1976d2; color: white; padding: 6px 14px; border-radius: 99px; text-decoration: none; border: none; cursor: pointer; display: inline-block;}
        .btn-secondary { background: #eceff1; color: #37474f; padding: 6px 14px; border-radius: 99px; text-decoration: none; border: none; cursor: pointer; display: inline-block;}
        .btn-outline { background: transparent; color:#1976d2; border:1px solid #1976d2; padding: 6px 14px; border-radius: 99px; cursor: pointer;}
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eceff1; text-align: left; }
        th { background: #f5f7fa; font-weight: 600; }
        .empty { font-size: 13px; color: #90a4ae; padding: 8px 0; text-align: center; }
        .badge { padding:2px 8px; border-radius:999px; font-size:11px; }
        .badge-success { background:#c8e6c9; color:#2e7d32; }
        .badge-warning { background:#fff3cd; color:#f57f17; }
        .badge-danger { background:#ffebee; color:#c62828; }
        .alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:16px; }
        .alert-success { background:#e8f5e9; color:#2e7d32; }
        .alert-error { background:#ffebee; color:#c62828; }
        .form-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:10px 16px; margin-bottom:10px; }
        .form-grid label { font-size:12px; color:#546e7a; display:block; margin-bottom:2px; }
        .form-grid input, .form-grid select, .form-grid textarea { width:100%; padding:6px 8px; font-size:13px; border-radius:6px; border:1px solid #cfd8dc; }
        textarea { min-height: 60px; resize: vertical; }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2>Dashboard Dokter</h2>
        <div class="role">Halo, <?= htmlspecialchars($nama_dokter) ?></div>
        <a href="?view=rekam_medis" class="<?= $view==='rekam_medis'?'active':'' ?>">Rekam Medis</a>
        <a href="?view=pasien" class="<?= $view==='pasien'?'active':'' ?>">Pasien Saya</a>
        <a href="?view=pemeriksaan" class="<?= $view==='pemeriksaan'?'active':'' ?>">Jadwal Pemeriksaan</a>
        <a href="?view=layanan" class="<?= $view==='layanan'?'active':'' ?>">Layanan & Tindakan</a>
        <a class="logout" href="../auth/logout.php">Logout</a>
    </aside>

    <main class="content">
        <?php if ($flash_success): ?> <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div> <?php endif; ?>
        <?php if ($flash_error): ?> <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div> <?php endif; ?>

        <?php if ($view === 'rekam_medis'): ?>
            <div class="content-header"><h1>Rekam Medis</h1><span>Daftar rekam medis pasien Anda.</span></div>
            <div class="cards"><div class="card"><h3>Total RM</h3><div class="card-strong"><?= $stat_rm['total'] ?></div></div></div>
            <div class="section">
                <div class="section-header"><h2>Riwayat Rekam Medis</h2></div>
                <?php if (empty($rekam_medis)): ?> <div class="empty">Belum ada data.</div> <?php else: ?>
                <table>
                    <thead><tr><th>ID RM</th><th>Pasien</th><th>Tanggal</th><th>Diagnosis</th><th>Hasil</th><th>Status Saat Ini</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php foreach($rekam_medis as $rm): ?>
                        <tr>
                            <td><?= $rm['id_rekam_medis'] ?></td>
                            <td><b><?= $rm['nama_pasien'] ?></b></td>
                            <td><?= date('d/m/Y', strtotime($rm['tanggal_catatan'])) ?></td>
                            <td style="color:#d32f2f"><?= $rm['diagnosis'] ?></td>
                            <td><?= $rm['hasil_pemeriksaan'] ?></td>
                            <td>
                                <?php if($rm['is_inap'] > 0): ?>
                                    <span class="badge badge-danger">Rawat Inap</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Rawat Jalan/Pulang</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn-secondary" href="rekam_medis_edit.php?id=<?= $rm['id_rekam_medis'] ?>">Edit</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'pasien'): ?>
            <div class="content-header"><h1>Pasien Saya</h1></div>
            <div class="section">
                <div class="section-header"><h2>Daftar Pasien</h2><button class="btn-outline" onclick="document.getElementById('form-pasien').style.display='block'">+ Pasien Baru</button></div>
                <div id="form-pasien" style="display:none; margin-bottom:15px;">
                    <form method="post"><input type="hidden" name="form_type" value="pasien_add">
                        <div class="form-grid">
                            <div><label>Nama</label><input type="text" name="nama" required></div>
                            <div><label>Lahir</label><input type="date" name="tanggal_lahir" required></div>
                            <div><label>Telp</label><input type="text" name="nomor_telepon" required></div>
                        </div>
                        <div class="form-grid"><div><label>Alamat</label><input type="text" name="alamat" required></div></div>
                        <button type="submit" class="btn-primary">Simpan</button>
                    </form>
                </div>
                <table>
                    <thead><tr><th>ID</th><th>Nama</th><th>Alamat</th><th>Telp</th></tr></thead>
                    <tbody><?php foreach($pasien as $p): ?><tr><td><?=$p['id_pasien']?></td><td><?=$p['nama']?></td><td><?=$p['alamat']?></td><td><?=$p['nomor_telepon']?></td></tr><?php endforeach; ?></tbody>
                </table>
            </div>

        <?php elseif ($view === 'pemeriksaan'): ?>
            <div class="content-header"><h1>Jadwal Pemeriksaan</h1><span>Kelola jadwal periksa pasien.</span></div>
            <div class="section">
                <div class="section-header"><h2>Buat Jadwal Baru</h2></div>
                <form method="post">
                    <input type="hidden" name="form_type" value="pemeriksaan_add">
                    <div class="form-grid">
                        <div><label>ID Pemeriksaan</label><input type="text" value="Otomatis" disabled></div>
                        <div>
                            <label>ID Rekam Medis</label>
                            <input type="text" name="id_rekam_medis" placeholder="Contoh: RM001" required>
                            <small style="color:#78909c">Masukkan ID yang diberikan Admin</small>
                        </div>
                        <div><label>Tanggal</label><input type="date" name="tanggal_pemeriksaan" required></div>
                        <div><label>Waktu</label><input type="time" name="waktu_pemeriksaan" required></div>
                        <div><label>Ruang</label><input type="text" name="ruang_pemeriksaan" placeholder="R-01" required></div>
                    </div>
                    <button type="submit" class="btn-primary">Buat Jadwal</button>
                </form>
            </div>
            <div class="section">
                <div class="section-header"><h2>Log Pemeriksaan</h2></div>
                <table>
                    <thead><tr><th>ID</th><th>Pasien</th><th>Jadwal</th><th>Ruang</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php foreach($pemeriksaan as $pe): ?>
                        <tr>
                            <td><?= $pe['id_pemeriksaan'] ?></td>
                            <td><?= $pe['nama_pasien'] ?></td>
                            <td><?= date('d/m/Y', strtotime($pe['tanggal_pemeriksaan'])) ?> - <?= $pe['waktu_pemeriksaan'] ?></td>
                            <td><span class="badge" style="background:#e3f2fd; color:#1565c0"><?= $pe['ruang_pemeriksaan'] ?></span></td>
                            <td><?= $pe['is_done'] > 0 ? '<span class="badge badge-success">Selesai</span>' : '<span class="badge badge-warning">Belum</span>' ?></td>
                            <td><a class="btn-primary" href="pemeriksaan_detail.php?id=<?= $pe['id_pemeriksaan'] ?>">Periksa</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($view === 'layanan'): ?>
            <div class="content-header"><h1>Layanan & Tindakan</h1><span>Input tindakan medis atau rawat inap.</span></div>
            <div class="section">
                <div class="section-header"><h2>Input Layanan</h2></div>
                <form method="post">
                    <input type="hidden" name="form_type" value="detail_pem_add">
                    <div class="form-grid">
                        <div>
                            <label>Pilih Jadwal Pemeriksaan</label>
                            <select name="id_pemeriksaan" required>
                                <option value="">-- Pilih --</option>
                                <?php foreach($daftar_pemeriksaan as $dp): ?>
                                    <option value="<?=$dp['id_pemeriksaan']?>"><?=$dp['id_pemeriksaan']?> - <?=$dp['nama']?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label style="color:#1565c0; font-weight:bold;">Tipe Layanan</label>
                            <select id="pilih_tipe" onchange="updateListLayanan()" style="margin-bottom:8px; border:1px solid #1976d2; background:#e3f2fd;">
                                <option value="">-- Pilih Tipe --</option>
                                <option value="jalan">Tindakan Medis (Rawat Jalan)</option>
                                <option value="inap">Fasilitas Kamar (Rawat Inap)</option>
                            </select>

                            <label>Nama Layanan</label>
                            <select name="id_layanan" id="list_layanan_dinamis" required>
                                <option value="">-- Pilih Tipe Dulu --</option>
                            </select>
                        </div>

                        <div>
                            <label>Suntik Vitamin?</label>
                            <select name="suntik_vitamin">
                                <option value="Tidak">Tidak</option>
                                <option value="Ya">Ya</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div style="grid-column:1/-1"><label>Catatan Konsultasi</label><textarea name="konsultasi" placeholder="Catatan medis..."></textarea></div>
                    </div>
                    <button type="submit" class="btn-primary">Simpan Layanan</button>
                </form>
            </div>
            
            <div class="section">
                <div class="section-header"><h2>History Layanan</h2></div>
                <table>
                    <thead><tr><th>Tgl</th><th>Pasien</th><th>Layanan</th><th>Konsultasi</th><th>Vit</th></tr></thead>
                    <tbody>
                        <?php foreach($detail_layanan as $dl): ?>
                        <tr>
                            <td><?= date('d/m/y', strtotime($dl['tanggal_pemeriksaan'])) ?></td>
                            <td><?= $dl['nama_pasien'] ?></td>
                            <td><b><?= $dl['nama_layanan'] ?></b></td>
                            <td><?= $dl['konsultasi'] ?></td>
                            <td><?= $dl['suntik_vitamin'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
    const dataKamar = <?php echo json_encode(array_values($layanan_kamar ?? [])); ?>;
    const dataTindakan = <?php echo json_encode(array_values($layanan_tindakan ?? [])); ?>;

    function updateListLayanan() {
        const tipe = document.getElementById('pilih_tipe').value;
        const dropdown = document.getElementById('list_layanan_dinamis');
        dropdown.innerHTML = '<option value="">-- Pilih Layanan --</option>';
        
        let data = (tipe === 'inap') ? dataKamar : (tipe === 'jalan' ? dataTindakan : []);
        
        if (data.length > 0) {
            data.forEach(item => {
                let opt = document.createElement('option');
                opt.value = item.id_layanan;
                opt.text = item.nama_layanan;
                dropdown.add(opt);
            });
        } else {
            let opt = document.createElement('option');
            opt.text = (tipe === "") ? "-- Pilih Tipe Dulu --" : "Tidak ada layanan";
            dropdown.add(opt);
        }
    }
</script>
</body>
</html>