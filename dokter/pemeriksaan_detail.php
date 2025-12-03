<?php
// File: dokter/pemeriksaan_detail.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm = $_SESSION['id_tenaga_medis'] ?? null;
if (!$id_tm) die("Akun Anda tidak terhubung dengan Tenaga Medis.");

$id_pemeriksaan = $_GET['id'] ?? null;
if (!$id_pemeriksaan) {
    header('Location: dashboard.php?view=pemeriksaan');
    exit;
}

$flash_success = null;
$flash_error   = null;

// Helper ID
function generateNextId(PDO $conn, string $table, string $column, string $prefix): string {
    $stmt = $conn->prepare("SELECT $column FROM $table WHERE $column LIKE ? ORDER BY $column DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (int)preg_replace('/\D/', '', $last) + 1 : 1;
    return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// 1. Ambil Data Pemeriksaan & Pasien
$sql = "SELECT pe.*, p.id_pasien, p.nama AS nama_pasien, rm.diagnosis, rm.hasil_pemeriksaan
        FROM pemeriksaan pe
        JOIN pasien p ON pe.id_pasien = p.id_pasien
        LEFT JOIN rekam_medis rm ON pe.id_rekam_medis = rm.id_rekam_medis
        WHERE pe.id_pemeriksaan = ? AND pe.id_tenaga_medis = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_pemeriksaan, $id_tm]);
$detail = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$detail) die("Pemeriksaan tidak ditemukan atau bukan milik Anda.");

// 2. Siapkan Data Layanan (Untuk Dropdown Dinamis)
$layanan_kamar = [];
$layanan_tindakan = [];
$all_layanan = $conn->query("SELECT * FROM layanan ORDER BY nama_layanan")->fetchAll(PDO::FETCH_ASSOC);

foreach($all_layanan as $l) {
    if (preg_match('/(Kamar|Inap|VIP)/i', $l['nama_layanan'])) {
        $layanan_kamar[] = $l;
    } else {
        $layanan_tindakan[] = $l;
    }
}

// ==========================================================
// HANDLE POST (Update Diagnosis & Tambah Layanan)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        // A. UPDATE DIAGNOSIS & HASIL (Ke tabel Rekam Medis)
        if (!empty($_POST['diagnosis'])) {
            $updRM = $conn->prepare("UPDATE rekam_medis SET diagnosis=?, hasil_pemeriksaan=? WHERE id_rekam_medis=?");
            $updRM->execute([$_POST['diagnosis'], $_POST['hasil_pemeriksaan'], $detail['id_rekam_medis']]);
        }

        // B. INPUT LAYANAN (Jika diisi)
        if (!empty($_POST['id_layanan'])) {
            $id_layanan = $_POST['id_layanan'];
            $konsultasi = $_POST['konsultasi'] ?? '';
            $suntik_vit = $_POST['suntik_vitamin'] ?? 'Tidak';

            // Masuk ke Detail Pemeriksaan (Tagihan)
            $insDet = $conn->prepare("INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING");
            $insDet->execute([$id_layanan, $id_pemeriksaan, $konsultasi, $suntik_vit]);

            // C. LOGIKA AUTO RAWAT INAP
            // Cek nama layanan
            $cekNama = $conn->prepare("SELECT nama_layanan FROM layanan WHERE id_layanan = ?");
            $cekNama->execute([$id_layanan]);
            $nama_lay = $cekNama->fetchColumn();

            if (preg_match('/(Kamar|Inap|VIP)/i', $nama_lay)) {
                // Cek apakah sudah ada di rawat inap aktif
                $cekRI = $conn->prepare("SELECT 1 FROM rawat_inap WHERE id_pasien = ? AND tanggal_keluar IS NULL");
                $cekRI->execute([$detail['id_pasien']]);
                
                if (!$cekRI->fetchColumn()) {
                    $id_kamar = generateNextId($conn, 'rawat_inap', 'id_kamar', 'K');
                    $insRI = $conn->prepare("INSERT INTO rawat_inap (id_kamar, id_layanan, id_pasien, tanggal_masuk) VALUES (?, ?, ?, ?)");
                    $insRI->execute([$id_kamar, $id_layanan, $detail['id_pasien'], $detail['tanggal_pemeriksaan']]);
                    $flash_success = "Diagnosis & Layanan Kamar disimpan. Pasien masuk daftar Rawat Inap Admin.";
                } else {
                    $flash_success = "Layanan ditambahkan (Pasien sudah berstatus Rawat Inap).";
                }
            } else {
                $flash_success = "Diagnosis & Tindakan berhasil disimpan.";
            }
        } else {
            $flash_success = "Diagnosis berhasil diupdate.";
        }

        $conn->commit();
        // Refresh halaman biar data terupdate
        header("Location: pemeriksaan_detail.php?id=$id_pemeriksaan&msg=ok");
        exit;

    } catch (Exception $e) {
        $conn->rollBack();
        $flash_error = "Error: " . $e->getMessage();
    }
}

// Ambil list layanan yang sudah diinput
$hist_layanan = $conn->prepare("SELECT dp.*, l.nama_layanan FROM detail_pemeriksaan dp JOIN layanan l ON dp.id_layanan = l.id_layanan WHERE dp.id_pemeriksaan = ?");
$hist_layanan->execute([$id_pemeriksaan]);
$history = $hist_layanan->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Pemeriksaan</title>
    <style>
        body { font-family: system-ui; background: #f3f6f9; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #1565c0; font-size: 24px; }
        .meta { margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-radius: 8px; font-size: 14px; color: #0d47a1; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: #455a64; font-size: 13px; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #cfd8dc; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        textarea { height: 80px; resize: vertical; }
        .btn { padding: 10px 20px; border: none; border-radius: 99px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #1976d2; color: white; }
        .btn-secondary { background: #eceff1; color: #37474f; margin-right: 10px; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 6px; font-size: 13px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; }
        .alert-error { background: #ffebee; color: #c62828; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>

<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h1>Proses Pemeriksaan</h1>
        <a href="dashboard.php?view=pemeriksaan" class="btn btn-secondary">Kembali</a>
    </div>

    <?php if(isset($_GET['msg'])): ?> <div class="alert alert-success">Data berhasil disimpan!</div> <?php endif; ?>
    <?php if($flash_error): ?> <div class="alert alert-error"><?= $flash_error ?></div> <?php endif; ?>

    <div class="meta">
        <strong>Pasien:</strong> <?= htmlspecialchars($detail['nama_pasien']) ?> (ID: <?= $detail['id_pasien'] ?>)<br>
        <strong>Jadwal:</strong> <?= date('d F Y', strtotime($detail['tanggal_pemeriksaan'])) ?> - <?= $detail['waktu_pemeriksaan'] ?><br>
        <strong>Ruangan:</strong> <?= $detail['ruang_pemeriksaan'] ?>
    </div>

    <form method="POST">
        <div style="background: #fafafa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #eee;">
            <h3 style="margin-top:0; font-size:16px;">1. Hasil Diagnosis</h3>
            <div class="form-group">
                <label>Diagnosis Dokter</label>
                <input type="text" name="diagnosis" value="<?= htmlspecialchars($detail['diagnosis'] ?? '') ?>" placeholder="Contoh: Demam Berdarah, Flu Ringan...">
            </div>
            <div class="form-group">
                <label>Catatan Pemeriksaan</label>
                <textarea name="hasil_pemeriksaan" placeholder="Detail hasil pemeriksaan fisik..."><?= htmlspecialchars($detail['hasil_pemeriksaan'] ?? '') ?></textarea>
            </div>
        </div>

        <div style="background: #fff8e1; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ffe0b2;">
            <h3 style="margin-top:0; font-size:16px;">2. Tindakan & Layanan (Opsional)</h3>
            <p style="font-size:12px; color:#ef6c00; margin-top:-5px;">Jika memilih <b>Fasilitas Kamar</b>, pasien otomatis masuk status <b>Rawat Inap</b>.</p>
            
            <div class="form-group">
                <label>Tipe Layanan</label>
                <select id="pilih_tipe" onchange="updateListLayanan()" style="border-color:#ffb74d;">
                    <option value="">-- Pilih Tipe Dulu --</option>
                    <option value="jalan">Tindakan Medis / Obat</option>
                    <option value="inap">Fasilitas Kamar (Rawat Inap)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Nama Layanan</label>
                <select name="id_layanan" id="list_layanan_dinamis">
                    <option value="">-- Pilih Tipe Diatas --</option>
                </select>
            </div>

            <div class="form-group">
                <label>Tambahan: Suntik Vitamin?</label>
                <select name="suntik_vitamin">
                    <option value="Tidak">Tidak</option>
                    <option value="Ya">Ya</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Catatan Tindakan</label>
                <input type="text" name="konsultasi" placeholder="Keterangan tambahan untuk tindakan ini...">
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">SIMPAN HASIL PEMERIKSAAN</button>
    </form>

    <?php if(!empty($history)): ?>
    <h3 style="margin-top:30px; font-size:16px; border-top:1px solid #eee; padding-top:20px;">Riwayat Tindakan Hari Ini</h3>
    <table>
        <thead><tr><th>Layanan</th><th>Catatan</th><th>Vitamin</th></tr></thead>
        <tbody>
            <?php foreach($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['nama_layanan']) ?></td>
                <td><?= htmlspecialchars($h['konsultasi']) ?></td>
                <td><?= htmlspecialchars($h['suntik_vitamin']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

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