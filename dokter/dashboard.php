<?php
// File: dokter/dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

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
// HANDLE POST
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';

    try {

        // 0. UPDATE STATUS RAWAT LANGSUNG DARI TABEL RM
        if ($form_type === 'rm_status_update') {
            $view = 'rekam_medis';

            $id_rm       = $_POST['id_rekam_medis'] ?? null;
            $status_new  = $_POST['jenis_rawat'] ?? null;
            $allowed     = ['Belum Ditentukan','Rawat Jalan','Rawat Inap'];

            if (!$id_rm || !in_array($status_new, $allowed, true)) {
                throw new Exception("Data status tidak valid.");
            }

            // Ambil data RM milik dokter ini
            $stmt = $conn->prepare("
                SELECT * FROM rekam_medis 
                WHERE id_rekam_medis = ? AND id_tenaga_medis = ?
            ");
            $stmt->execute([$id_rm, $id_tm]);
            $rm = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rm) {
                throw new Exception("Rekam medis tidak ditemukan.");
            }

            // Cek rawat inap aktif
            $cekRI = $conn->prepare("SELECT 1 FROM rawat_inap WHERE id_pasien = ? AND tanggal_keluar IS NULL");
            $cekRI->execute([$rm['id_pasien']]);
            $is_currently_inap = (bool)$cekRI->fetchColumn();

            $conn->beginTransaction();

            // Update kolom jenis_rawat di rekam_medis
            $upd = $conn->prepare("
                UPDATE rekam_medis 
                SET jenis_rawat = ? 
                WHERE id_rekam_medis = ?
            ");
            $upd->execute([$status_new, $id_rm]);

            // Sinkronisasi dengan rawat_inap
            if ($status_new === 'Rawat Inap' && !$is_currently_inap) {
                // cari layanan kamar default
                $lay = $conn->query("
                    SELECT id_layanan 
                    FROM layanan 
                    WHERE nama_layanan ILIKE '%Rawat Inap%'
                    LIMIT 1
                ")->fetchColumn();
                $id_layanan = $lay ?: 'L005';

                $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
                $ins = $conn->prepare("
                    INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk)
                    VALUES (?, ?, ?, ?)
                ");
                $ins->execute([$id_kamar, $id_layanan, $rm['id_pasien'], $rm['tanggal_catatan']]);
            }
            elseif ($status_new !== 'Rawat Inap' && $is_currently_inap) {
                // check-out rawat inap
                $out = $conn->prepare("
                    UPDATE rawat_inap 
                    SET tanggal_keluar = CURRENT_DATE 
                    WHERE id_pasien = ? AND tanggal_keluar IS NULL
                ");
                $out->execute([$rm['id_pasien']]);
            }

            $conn->commit();
            $flash_success = "Status rawat berhasil diperbarui.";
        }

        // 1. TAMBAH PASIEN
        if ($form_type === 'pasien_add') {
            $view = 'pasien';
            $id_pasien = generateNextId($conn, 'pasien', 'id_pasien', 'P');
            $nama = trim($_POST['nama'] ?? '');
            
            if (empty($nama)) throw new Exception("Nama pasien wajib diisi.");

            $stmt = $conn->prepare("INSERT INTO pasien (id_pasien, nama, tanggal_lahir, alamat, nomor_telepon) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id_pasien, $nama, $_POST['tanggal_lahir'], $_POST['alamat'], $_POST['nomor_telepon']]);
            $flash_success = "Pasien berhasil ditambahkan: $id_pasien";
        }

        // 2. BUAT JADWAL PEMERIKSAAN
        if ($form_type === 'pemeriksaan_add') {
            $view = 'pemeriksaan';
            $id_pemeriksaan = generateNextId($conn, 'pemeriksaan', 'id_pemeriksaan', 'PE');
            $id_rm_input    = strtoupper(trim($_POST['id_rekam_medis'] ?? ''));

            if (empty($id_rm_input)) throw new Exception("ID Rekam Medis wajib diisi.");

            $rmCheck = $conn->prepare("SELECT id_pasien FROM rekam_medis WHERE id_rekam_medis = ?");
            $rmCheck->execute([$id_rm_input]);
            $rmData = $rmCheck->fetch(PDO::FETCH_ASSOC);

            if (!$rmData) throw new Exception("ID Rekam Medis tidak ditemukan. Pastikan Admin sudah membuatnya.");

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

         // 3. INPUT LAYANAN & AUTO RAWAT INAP + UPDATE JENIS_RAWAT DI RM
    if ($form_type === 'detail_pem_add') {
        $view = 'layanan';

        $id_pemeriksaan = $_POST['id_pemeriksaan'] ?? '';
        $id_layanan     = $_POST['id_layanan'] ?? '';
        $konsultasi     = trim($_POST['konsultasi'] ?? '');
        $suntik_vitamin = ($_POST['suntik_vitamin'] ?? 'Tidak') === 'Ya' ? 'Ya' : 'Tidak';

        if (empty($id_pemeriksaan) || empty($id_layanan)) {
            throw new Exception("Pilih pemeriksaan dan layanan.");
        }

            // Ambil info pemeriksaan: pasien, tanggal, dan ID RM
    $qPem = $conn->prepare("
        SELECT id_pasien, tanggal_pemeriksaan, id_rekam_medis
        FROM pemeriksaan
        WHERE id_pemeriksaan = ?
    ");
    $qPem->execute([$id_pemeriksaan]);
    $infoPem = $qPem->fetch(PDO::FETCH_ASSOC);
    if (!$infoPem) {
        throw new Exception("Data pemeriksaan tidak ditemukan.");
    }

    $id_pasien_layanan = $infoPem['id_pasien'];
    $tgl_layanan       = $infoPem['tanggal_pemeriksaan'];

        // Mulai transaksi biar aman
        $conn->beginTransaction();

        // A. Simpan ke DETAIL_PEMERIKSAAN
        $sqlDet = "INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin)
                   VALUES (?, ?, ?, ?)
                   ON CONFLICT (id_layanan, id_pemeriksaan)
                   DO UPDATE SET konsultasi = EXCLUDED.konsultasi,
                                 suntik_vitamin = EXCLUDED.suntik_vitamin";
        $conn->prepare($sqlDet)->execute([
            $id_layanan,
            $id_pemeriksaan,
            $konsultasi,
            $suntik_vitamin
        ]);

        // B. Ambil info pasien dari PEMERIKSAAN
        $qPasien = $conn->prepare("
            SELECT pe.id_pasien, pe.tanggal_pemeriksaan
            FROM pemeriksaan pe
            WHERE pe.id_pemeriksaan = ?
              AND pe.id_tenaga_medis = ?
        ");
        $qPasien->execute([$id_pemeriksaan, $id_tm]);
        $dPasien = $qPasien->fetch(PDO::FETCH_ASSOC);

        if (!$dPasien) {
            throw new Exception("Data pemeriksaan tidak ditemukan atau bukan milik Anda.");
        }

        // C. Ambil REKAM MEDIS TERBARU milik pasien ini
        $cariRM = $conn->prepare("
            SELECT id_rekam_medis
            FROM rekam_medis
            WHERE id_pasien = ?
            ORDER BY tanggal_catatan DESC, id_rekam_medis DESC
            LIMIT 1
        ");
        $cariRM->execute([$dPasien['id_pasien']]);
        $id_rm_target = $cariRM->fetchColumn();

        // Kalau pasien belum punya RM sama sekali, ya sudah: kita cuma simpan layanan
        // (secara normal, harusnya selalu punya, karena RM dibuat admin dulu)
        // ---------------------------------------------------------------
        // D. Cek jenis layanan (kamar / non kamar)
        // ---------------------------------------------------------------
        $cekLay = $conn->prepare("SELECT nama_layanan FROM layanan WHERE id_layanan = ?");
        $cekLay->execute([$id_layanan]);
        $nama_layanan = $cekLay->fetchColumn();

        // Flag: layanan kamar/rawat inap
        $is_kamar = preg_match('/(Kamar|Inap|VIP)/i', $nama_layanan);

        if ($id_rm_target) {
            if ($is_kamar) {
                // Rawat Inap
                $conn->prepare("UPDATE rekam_medis SET jenis_rawat = 'Rawat Inap'
                                WHERE id_rekam_medis = ?")
                     ->execute([$id_rm_target]);
            } else {
                // Rawat Jalan / Pulang
                $conn->prepare("UPDATE rekam_medis SET jenis_rawat = 'Rawat Jalan'
                                WHERE id_rekam_medis = ?")
                     ->execute([$id_rm_target]);
            }
        }

        // ---------------------------------------------------------------
        // E. (Opsional) logika RAWAT INAP: tetap boleh, tapi TIDAK mengubah RM lain
        // ---------------------------------------------------------------
        if ($is_kamar) {
            // Cek apakah sudah ada rawat inap aktif
            $cekRI = $conn->prepare("
                SELECT 1 FROM rawat_inap
                WHERE id_pasien = ?
                  AND tanggal_keluar IS NULL
            ");
            $cekRI->execute([$dPasien['id_pasien']]);

            if (!$cekRI->fetchColumn()) {
                // Buat ID Kamar baru
                $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');

                $sqlInap = "INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk, tanggal_keluar)
                            VALUES (?, ?, ?, ?, NULL)";
                $conn->prepare($sqlInap)->execute([
                    $id_kamar,
                    $id_layanan,
                    $dPasien['id_pasien'],
                    $dPasien['tanggal_pemeriksaan']
                ]);

                $flash_success = "Layanan disimpan. Pasien OTOMATIS masuk daftar Rawat Inap Admin.";
            } else {
                $flash_success = "Layanan kamar ditambahkan (pasien sudah terdaftar di Rawat Inap).";
            }
        } else {
            $flash_success = "Layanan Rawat Jalan berhasil disimpan.";
        }

                // ---------------------------------------------------------------
        // F. TAGIHAN RAWAT JALAN OTOMATIS (bukan layanan kamar)
        // ---------------------------------------------------------------
        if (!$is_kamar) {
            // 1. Ambil tarif dasar layanan
            $qTarif = $conn->prepare("SELECT tarif_dasar FROM layanan WHERE id_layanan = ?");
            $qTarif->execute([$id_layanan]);
            $tarif = (int)$qTarif->fetchColumn();

            if ($tarif > 0) {

                // 2. Cari tagihan aktif (Belum Lunas) utk pasien + tanggal pemeriksaan ini
                $qTag = $conn->prepare("
                    SELECT id_tagihan, total_biaya
                    FROM tagihan
                    WHERE id_pasien = ?
                      AND tanggal_tagihan = ?
                      AND status_pembayaran = 'Belum Lunas'
                    LIMIT 1
                ");
                $qTag->execute([
                    $dPasien['id_pasien'],
                    $dPasien['tanggal_pemeriksaan']
                ]);
                $tag = $qTag->fetch(PDO::FETCH_ASSOC);

                if ($tag) {
                    // 3a. Kalau tagihan sudah ada → update total_biaya
                    $id_tagihan = $tag['id_tagihan'];
                    $total_baru = $tag['total_biaya'] + $tarif;

                    $conn->prepare("
                        UPDATE tagihan 
                        SET total_biaya = ?
                        WHERE id_tagihan = ?
                    ")->execute([$total_baru, $id_tagihan]);

                } else {
                    // 3b. Kalau belum ada → buat tagihan baru
                    // Pola ID sama seperti di admin: TJ + yymmdd + 2 digit random
                    $id_tagihan = 'TJ' . date('ymd') . rand(10, 99);

                    $conn->prepare("
                        INSERT INTO tagihan 
                            (id_tagihan, id_pasien, tanggal_tagihan, total_biaya, status_pembayaran)
                        VALUES (?, ?, ?, ?, 'Belum Lunas')
                    ")->execute([
                        $id_tagihan,
                        $dPasien['id_pasien'],
                        $dPasien['tanggal_pemeriksaan'],
                        $tarif
                    ]);
                }

                // 4. Tambahkan DETAIL_TAGIHAN untuk layanan ini
                $insDetTag = $conn->prepare("
                    INSERT INTO detail_tagihan (id_tagihan, id_layanan, jumlah, subtotal)
                    VALUES (?, ?, 1, ?)
                ");

                try {
                    $insDetTag->execute([$id_tagihan, $id_layanan, $tarif]);
                } catch (Exception $e) {
                    // Kalau kombinasi id_tagihan + id_layanan sudah ada,
                    // update jumlah dan subtotal saja
                    $conn->prepare("
                        UPDATE detail_tagihan
                        SET jumlah = jumlah + 1,
                            subtotal = subtotal + ?
                        WHERE id_tagihan = ? AND id_layanan = ?
                    ")->execute([$tarif, $id_tagihan, $id_layanan]);
                }

                // Ubah pesan sukses dikit biar jelas sudah bikin tagihan
                $flash_success = "Layanan Rawat Jalan disimpan & tagihan otomatis dibuat/diupdate.";
            }
        }


        $conn->commit();
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

// 1. REKAM MEDIS
$rekam_medis = [];
$stat_rm = ['total' => 0];
if ($view === 'rekam_medis') {
    $sql = "SELECT rm.*, p.nama AS nama_pasien
            FROM rekam_medis rm 
            JOIN pasien p ON rm.id_pasien = p.id_pasien 
            WHERE rm.id_tenaga_medis = ?
            ORDER BY rm.id_rekam_medis ASC";

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
    $sql = "SELECT 
                pe.*,
                p.nama AS nama_pasien,
                rm.diagnosis,
                rm.hasil_pemeriksaan
            FROM pemeriksaan pe
            JOIN pasien p ON pe.id_pasien = p.id_pasien
            LEFT JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
            WHERE pe.id_tenaga_medis = ?
            ORDER BY pe.id_pemeriksaan ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id_tm]);
    $pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// 4. LAYANAN
$layanan_kamar = [];
$layanan_tindakan = [];
$daftar_pemeriksaan = [];
$detail_layanan = [];

if ($view === 'layanan') {
    $all_layanan = $conn->query("SELECT * FROM layanan ORDER BY nama_layanan")->fetchAll(PDO::FETCH_ASSOC);
    foreach($all_layanan as $l) {
        if (preg_match('/(Kamar|Inap|VIP)/i', $l['nama_layanan'])) {
            $layanan_kamar[] = $l;
        } else {
            $layanan_tindakan[] = $l;
        }
    }

    $stmt = $conn->prepare("SELECT pe.id_pemeriksaan, p.nama, pe.tanggal_pemeriksaan
    FROM pemeriksaan pe 
    JOIN pasien p ON pe.id_pasien = p.id_pasien
    WHERE pe.id_tenaga_medis = ?
    ORDER BY pe.id_pemeriksaan ASC");

    $stmt->execute([$id_tm]);
    $daftar_pemeriksaan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT dp.*, l.nama_layanan, pe.tanggal_pemeriksaan, p.nama as nama_pasien
    FROM detail_pemeriksaan dp
    JOIN layanan l ON dp.id_layanan = l.id_layanan
    JOIN pemeriksaan pe ON dp.id_pemeriksaan = pe.id_pemeriksaan
    JOIN pasien p ON pe.id_pasien = p.id_pasien
    WHERE pe.id_tenaga_medis = ?
    ORDER BY pe.id_pemeriksaan ASC");

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
        .badge-neutral { background:#eceff1; color:#546e7a; }
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
                       <?php foreach($rekam_medis as $rm): 
    // Status murni per RM, tidak dipaksa ikut rawat_inap pasien lain
    $jenis = $rm['jenis_rawat'] ?? 'Belum Ditentukan';
?>

                        <tr>
                            <td><?= htmlspecialchars($rm['id_rekam_medis']) ?></td>
                            <td><b><?= htmlspecialchars($rm['nama_pasien']) ?></b></td>
                            <td><?= date('d/m/Y', strtotime($rm['tanggal_catatan'])) ?></td>
                            <td style="color:#d32f2f"><?= htmlspecialchars($rm['diagnosis']) ?></td>
                            <td><?= htmlspecialchars($rm['hasil_pemeriksaan']) ?></td>
                            <td>
    <?php
        // warna dropdown sesuai status
        $styleSelect = "font-size:11px;padding:3px 6px;border-radius:999px;border:1px solid;";
        if ($jenis === 'Rawat Inap') {
            $styleSelect .= "background:#ffebee;color:#c62828;border-color:#ffcdd2;";
        } elseif ($jenis === 'Rawat Jalan') {
            $styleSelect .= "background:#c8e6c9;color:#2e7d32;border-color:#a5d6a7;";
        } else { // Belum Ditentukan
            $styleSelect .= "background:#eceff1;color:#546e7a;border-color:#cfd8dc;";
        }
    ?>
    <?php
    // Ambil jenis_rawat dari database untuk baris RM ini
    $jenis = $rm['jenis_rawat'] ?? 'Belum Ditentukan';
    ?>

    <form method="post" style="margin:0;">
        <input type="hidden" name="form_type" value="rm_status_update">
        <input type="hidden" name="id_rekam_medis" value="<?= htmlspecialchars($rm['id_rekam_medis']) ?>">
        <select name="jenis_rawat" onchange="this.form.submit()" style="<?= $styleSelect ?>">
            <option value="Belum Ditentukan" <?= $jenis==='Belum Ditentukan'?'selected':''; ?>>Belum Ditentukan</option>
            <option value="Rawat Jalan" <?= $jenis==='Rawat Jalan'?'selected':''; ?>>Rawat Jalan/Pulang</option>
            <option value="Rawat Inap" <?= $jenis==='Rawat Inap'?'selected':''; ?>>Rawat Inap</option>
        </select>
    </form>
</td>

                            <td><a class="btn-secondary" href="rekam_medis_edit.php?id=<?= htmlspecialchars($rm['id_rekam_medis']) ?>">Edit</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'pasien'): ?>
            <div class="content-header"><h1>Pasien Saya</h1></div>
            <div class="section">
                <div class="section-header">
                    <h2>Daftar Pasien</h2>
                    <button class="btn-outline" onclick="document.getElementById('form-pasien').style.display='block'">+ Pasien Baru</button>
                </div>
                <div id="form-pasien" style="display:none; margin-bottom:15px;">
                    <form method="post">
                        <input type="hidden" name="form_type" value="pasien_add">
                        <div class="form-grid">
                            <div><label>Nama</label><input type="text" name="nama" required></div>
                            <div><label>Lahir</label><input type="date" name="tanggal_lahir" required></div>
                            <div><label>Telp</label><input type="text" name="nomor_telepon" required></div>
                        </div>
                        <div class="form-grid">
                            <div><label>Alamat</label><input type="text" name="alamat" required></div>
                        </div>
                        <button type="submit" class="btn-primary">Simpan</button>
                    </form>
                </div>
                <table>
                    <thead><tr><th>ID</th><th>Nama</th><th>Alamat</th><th>Telp</th></tr></thead>
                    <tbody>
                        <?php foreach($pasien as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['id_pasien']) ?></td>
                            <td><?= htmlspecialchars($p['nama']) ?></td>
                            <td><?= htmlspecialchars($p['alamat']) ?></td>
                            <td><?= htmlspecialchars($p['nomor_telepon']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
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
                            <td><?= htmlspecialchars($pe['id_pemeriksaan']) ?></td>
                            <td><?= htmlspecialchars($pe['nama_pasien']) ?></td>
                            <td><?= date('d/m/Y', strtotime($pe['tanggal_pemeriksaan'])) ?> - <?= htmlspecialchars($pe['waktu_pemeriksaan']) ?></td>
                            <td><span class="badge" style="background:#e3f2fd; color:#1565c0"><?= htmlspecialchars($pe['ruang_pemeriksaan']) ?></span></td>
                            <td>
    <?php
        // dianggap "Selesai" kalau diagnosis ATAU hasil_pemeriksaan sudah diisi
        $sudah_diisi = !empty($pe['diagnosis']) || !empty($pe['hasil_pemeriksaan']);

        if ($sudah_diisi) {
            echo '<span class="badge badge-success">Selesai</span>';
        } else {
            echo '<span class="badge badge-warning">Belum</span>';
        }
    ?>
</td>

                            <td><a class="btn-primary" href="pemeriksaan_detail.php?id=<?= htmlspecialchars($pe['id_pemeriksaan']) ?>">Periksa</a></td>
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
                            <td><?= htmlspecialchars($dl['nama_pasien']) ?></td>
                            <td><b><?= htmlspecialchars($dl['nama_layanan']) ?></b></td>
                            <td><?= htmlspecialchars($dl['konsultasi']) ?></td>
                            <td><?= htmlspecialchars($dl['suntik_vitamin']) ?></td>
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
        if (!dropdown) return;

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
