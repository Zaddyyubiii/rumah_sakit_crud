<?php
// File: dokter/pemeriksaan_detail.php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

require_role('dokter');

$id_tm    = $_SESSION['id_tenaga_medis'] ?? null;
$username = $_SESSION['username'] ?? 'Dokter';

if (!$id_tm) {
    die("Akun Anda tidak terhubung dengan Tenaga Medis.");
}

$id_pem = $_GET['id'] ?? null;
if (!$id_pem) {
    header('Location: dashboard.php?view=pemeriksaan');
    exit;
}

$flash_error = null;

// ------------------------------------------------------------------
// DATA PEMERIKSAAN
// ------------------------------------------------------------------
$sql = "SELECT 
            pe.id_pemeriksaan,
            pe.id_pasien,
            pe.tanggal_pemeriksaan,
            pe.waktu_pemeriksaan,
            pe.ruang_pemeriksaan,
            p.nama AS nama_pasien
        FROM pemeriksaan pe
        JOIN pasien p ON pe.id_pasien = p.id_pasien
        WHERE pe.id_pemeriksaan = ? AND pe.id_tenaga_medis = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$id_pem, $id_tm]);
$pem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pem) {
    die("Pemeriksaan tidak ditemukan atau bukan milik Anda.");
}

// ID layanan rawat inap (kamar)
$LAYANAN_INAP_IDS = ['L005', 'L006']; // Kamar Rawat Inap Kelas 1 & VIP

// ------------------------------------------------------------------
// AMBIL SEMUA LAYANAN & PISAH RAWAT JALAN / INAP
// ------------------------------------------------------------------
$daftar_layanan_raw = $conn->query("
    SELECT id_layanan, nama_layanan 
    FROM layanan 
    ORDER BY nama_layanan
")->fetchAll(PDO::FETCH_ASSOC);

$layanan_jalan = [];
$layanan_inap  = [];

foreach ($daftar_layanan_raw as $l) {
    $id_l = trim($l['id_layanan']); // PENTING: buang spasi CHAR(10)
    $row  = [
        'id_layanan'   => $id_l,
        'nama_layanan' => $l['nama_layanan']
    ];

    if (in_array($id_l, $LAYANAN_INAP_IDS, true)) {
        $layanan_inap[] = $row;
    } else {
        $layanan_jalan[] = $row;
    }
}

// ------------------------------------------------------------------
// TENTUKAN JENIS RAWAT AWAL (DARI LAYANAN YANG SUDAH ADA, JIKA ADA)
// ------------------------------------------------------------------
$sqlJR = "SELECT l.id_layanan
          FROM detail_pemeriksaan dp
          JOIN layanan l ON l.id_layanan = dp.id_layanan
          WHERE dp.id_pemeriksaan = ?
          LIMIT 1";
$stJR = $conn->prepare($sqlJR);
$stJR->execute([$id_pem]);
$rowJR = $stJR->fetch(PDO::FETCH_ASSOC);

$jenis_rawat_awal = 'Rawat Jalan';
if ($rowJR) {
    $id_l_jr = trim($rowJR['id_layanan']);  // buang spasi lagi
    if (in_array($id_l_jr, $LAYANAN_INAP_IDS, true)) {
        $jenis_rawat_awal = 'Rawat Inap';
    }
}

// ------------------------------------------------------------------
// HANDLE SUBMIT
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_layanan     = trim($_POST['id_layanan'] ?? ''); // just in case
    $diagnosis      = trim($_POST['diagnosis'] ?? '');
    $konsultasi     = trim($_POST['konsultasi'] ?? '');
    $suntik_vitamin = $_POST['suntik_vitamin'] ?? 'Tidak';
    $suntik_vitamin = ($suntik_vitamin === 'Ya') ? 'Ya' : 'Tidak';
    $jenis_rawat    = $_POST['jenis_rawat'] ?? 'Rawat Jalan';

    try {
        if (!in_array($jenis_rawat, ['Rawat Jalan','Rawat Inap'], true)) {
            $jenis_rawat = 'Rawat Jalan';
        }

        if ($id_layanan === '' || $diagnosis === '' || $konsultasi === '') {
            throw new Exception("Layanan, diagnosis, dan hasil/konsultasi wajib diisi.");
        }

        // Safety: kalau Rawat Inap tapi layanan bukan kamar, paksa ke L005
        if ($jenis_rawat === 'Rawat Inap' && !in_array($id_layanan, $LAYANAN_INAP_IDS, true)) {
            $id_layanan = 'L005';
        }

        // 1) UPSERT ke detail_pemeriksaan
        $sqlDet = "
            INSERT INTO detail_pemeriksaan (id_layanan, id_pemeriksaan, konsultasi, suntik_vitamin)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (id_layanan, id_pemeriksaan)
            DO UPDATE SET
                konsultasi     = EXCLUDED.konsultasi,
                suntik_vitamin = EXCLUDED.suntik_vitamin
        ";
        $stDet = $conn->prepare($sqlDet);
        $stDet->execute([$id_layanan, $id_pem, $konsultasi, $suntik_vitamin]);

        // 2) UPSERT ke rekam_medis
        $id_pasien   = $pem['id_pasien'];
        $tgl_catatan = $pem['tanggal_pemeriksaan'];

        $num  = preg_replace('/\D/', '', $id_pem);
        $num  = str_pad($num, 3, '0', STR_PAD_LEFT);
        $rm_id = 'RM' . $num;

        $sqlRM = "
            INSERT INTO rekam_medis 
                (id_rekam_medis, id_pasien, id_tenaga_medis, tanggal_catatan, diagnosis, hasil_pemeriksaan)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (id_rekam_medis)
            DO UPDATE SET
                id_pasien         = EXCLUDED.id_pasien,
                id_tenaga_medis   = EXCLUDED.id_tenaga_medis,
                tanggal_catatan   = EXCLUDED.tanggal_catatan,
                diagnosis         = EXCLUDED.diagnosis,
                hasil_pemeriksaan = EXCLUDED.hasil_pemeriksaan
        ";
        $stRM = $conn->prepare($sqlRM);
        $stRM->execute([$rm_id, $id_pasien, $id_tm, $tgl_catatan, $diagnosis, $konsultasi]);

        header('Location: dashboard.php?view=pemeriksaan');
        exit;

    } catch (Exception $e) {
        $flash_error = $e->getMessage();
    } catch (PDOException $e) {
        $flash_error = "Kesalahan database: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Isi Hasil Pemeriksaan</title>
    <style>
        body { margin:0; font-family:system-ui, sans-serif; background:#f3f6f9; }
        .wrap { max-width:760px; margin:40px auto; background:#fff; padding:24px 28px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
        h1 { margin-top:0; font-size:22px; color:#263238; }
        .meta { font-size:13px; color:#607d8b; margin-bottom:14px; }
        label { font-size:13px; color:#455a64; display:block; margin-bottom:4px; }
        input[type=text], select, textarea {
            width:100%; padding:8px 10px; border-radius:6px;
            border:1px solid #cfd8dc; font-size:13px;
        }
        textarea { min-height:90px; resize:vertical; }
        .row { margin-bottom:12px; }
        .btn-primary, .btn-secondary {
            border-radius:999px; padding:6px 14px; font-size:13px;
            border:none; cursor:pointer; text-decoration:none; display:inline-block;
        }
        .btn-primary { background:#1976d2; color:#fff; }
        .btn-primary:hover { background:#1258a3; }
        .btn-secondary { background:#eceff1; color:#37474f; }
        .btn-secondary:hover { background:#d7dde2; }
        .alert { padding:8px 12px; border-radius:6px; font-size:13px; margin-bottom:12px; }
        .alert-error { background:#ffebee; color:#c62828; }
        .radio-inline {
            display:inline-flex;
            align-items:center;
            gap:6px;
            font-size:13px;
            margin-right:12px;
        }
        .hidden { display:none; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Isi Hasil Pemeriksaan</h1>
    <div class="meta">
        ID Pemeriksaan: <b><?= htmlspecialchars($pem['id_pemeriksaan']) ?></b><br>
        Pasien: <b><?= htmlspecialchars($pem['nama_pasien']) ?></b> (<?= htmlspecialchars($pem['id_pasien']) ?>)<br>
        Tanggal: <?= date('d-m-Y', strtotime($pem['tanggal_pemeriksaan'])) ?>,
        Waktu: <?= htmlspecialchars($pem['waktu_pemeriksaan']) ?><br>
        Ruang: <?= htmlspecialchars($pem['ruang_pemeriksaan']) ?>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="row">
            <label>Jenis Perawatan</label>
            <select name="jenis_rawat" id="jenis_rawat">
                <option value="Rawat Jalan" <?= $jenis_rawat_awal === 'Rawat Jalan' ? 'selected' : '' ?>>Rawat Jalan</option>
                <option value="Rawat Inap" <?= $jenis_rawat_awal === 'Rawat Inap' ? 'selected' : '' ?>>Rawat Inap</option>
            </select>
        </div>

        <!-- hidden yang dikirim ke PHP -->
        <input type="hidden" name="id_layanan" id="id_layanan_hidden">

        <div class="row">
            <label>Layanan</label>

            <!-- Dropdown RAWAT JALAN -->
            <select id="select_layanan_jalan">
                <option value="">-- Pilih Layanan --</option>
                <?php foreach ($layanan_jalan as $l): ?>
                    <option value="<?= htmlspecialchars($l['id_layanan']) ?>">
                        <?= htmlspecialchars($l['nama_layanan']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Dropdown RAWAT INAP -->
            <select id="select_layanan_inap" class="hidden">
                <option value="">-- Pilih Layanan --</option>
                <?php foreach ($layanan_inap as $l): ?>
                    <option value="<?= htmlspecialchars($l['id_layanan']) ?>">
                        <?= htmlspecialchars($l['nama_layanan']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row">
            <label>Suntik Vitamin</label>
            <div>
                <label class="radio-inline">
                    <input type="radio" name="suntik_vitamin" value="Ya"> Ya
                </label>
                <label class="radio-inline">
                    <input type="radio" name="suntik_vitamin" value="Tidak" checked> Tidak
                </label>
            </div>
        </div>

        <div class="row">
            <label>Diagnosis</label>
            <input type="text" name="diagnosis" placeholder="Misal: Flu Biasa, Pneumonia, dsb.">
        </div>
        <div class="row">
            <label>Hasil / Konsultasi</label>
            <textarea name="konsultasi" placeholder="Catatan hasil pemeriksaan, saran, tindakan lanjutan, dll."></textarea>
        </div>

        <button type="submit" class="btn-primary">Simpan Hasil Pemeriksaan</button>
        <a href="dashboard.php?view=pemeriksaan" class="btn-secondary">Kembali ke Jadwal</a>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const jenisSelect = document.getElementById('jenis_rawat');
    const jalanSelect = document.getElementById('select_layanan_jalan');
    const inapSelect  = document.getElementById('select_layanan_inap');
    const hiddenInput = document.getElementById('id_layanan_hidden');

    if (!jenisSelect || !jalanSelect || !inapSelect || !hiddenInput) return;

    function syncHiddenFromActive() {
        const isInap = (jenisSelect.value === 'Rawat Inap');
        const activeSelect = isInap ? inapSelect : jalanSelect;
        hiddenInput.value = activeSelect.value || '';
    }

    function updateUI() {
        const isInap = (jenisSelect.value === 'Rawat Inap');

        if (isInap) {
            jalanSelect.classList.add('hidden');
            inapSelect.classList.remove('hidden');
        } else {
            inapSelect.classList.add('hidden');
            jalanSelect.classList.remove('hidden');
        }
        syncHiddenFromActive();
    }

    jenisSelect.addEventListener('change', updateUI);
    jalanSelect.addEventListener('change', syncHiddenFromActive);
    inapSelect.addEventListener('change', syncHiddenFromActive);

    // Init pertama
    updateUI();
});
</script>
</body>
</html>
