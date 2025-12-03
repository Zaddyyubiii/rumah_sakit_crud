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
$stat_pasien  = $conn->query("SELECT COUNT(*) FROM PASIEN")->fetchColumn();
$stat_dokter  = $conn->query("SELECT COUNT(*) FROM DOKTER")->fetchColumn();
$stat_perawat = $conn->query("SELECT COUNT(*) FROM PERAWAT")->fetchColumn();
$stat_rm      = $conn->query("SELECT COUNT(*) FROM REKAM_MEDIS")->fetchColumn();
$stat_uang    = $conn->query("SELECT COALESCE(SUM(Total_Biaya), 0) FROM TAGIHAN WHERE Status_Pembayaran = 'Lunas'")->fetchColumn();

// ==========================================================
// LOGIK CRUD UTAMA
// ==========================================================
$page    = isset($_GET['page']) ? $_GET['page'] : 'rekam_medis';
$action  = isset($_GET['action']) ? $_GET['action'] : '';
$id_edit = isset($_GET['id']) ? $_GET['id'] : '';
$keyword = isset($_GET['cari']) ? $_GET['cari'] : '';
$data_edit = null;

// --- LOGIK DELETE GLOBAL ---
if ($action == 'delete' && !empty($id_edit)) {
    try {
        $conn->beginTransaction(); 
        
        if ($page == 'pasien') {
            // Hapus anak-anaknya dulu (Jaga-jaga DB belum cascade)
            $conn->prepare("DELETE FROM TAGIHAN WHERE ID_Pasien = ?")->execute([$id_edit]);
            $conn->prepare("DELETE FROM REKAM_MEDIS WHERE ID_Pasien = ?")->execute([$id_edit]);
            $conn->prepare("DELETE FROM RAWAT_INAP WHERE ID_Pasien = ?")->execute([$id_edit]);
            $conn->prepare("DELETE FROM PEMERIKSAAN WHERE ID_Pasien = ?")->execute([$id_edit]);
            $stmt = $conn->prepare("DELETE FROM PASIEN WHERE ID_Pasien = ?");
        } 
        elseif ($page == 'dokter' || $page == 'perawat') $stmt = $conn->prepare("DELETE FROM TENAGA_MEDIS WHERE ID_Tenaga_Medis = ?");
        elseif ($page == 'rawat_inap') $stmt = $conn->prepare("DELETE FROM RAWAT_INAP WHERE ID_Kamar = ?");
        
        // [FIX] Hapus Rekam Medis (Hapus Pemeriksaan dulu biar gak error Foreign Key)
        elseif ($page == 'rekam_medis') {
            $conn->prepare("DELETE FROM PEMERIKSAAN WHERE ID_Rekam_Medis = ?")->execute([$id_edit]);
            $stmt = $conn->prepare("DELETE FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        }
        
        elseif ($page == 'tagihan') {
            $conn->prepare("DELETE FROM DETAIL_TAGIHAN WHERE ID_Tagihan = ?")->execute([$id_edit]);
            $stmt = $conn->prepare("DELETE FROM TAGIHAN WHERE ID_Tagihan = ?");
        }
        
        if (isset($stmt)) {
            $stmt->execute([$id_edit]);
            $conn->commit();
            header("Location: dashboard.php?page=$page&msg=deleted"); exit();
        }
    } catch (PDOException $e) { 
        $conn->rollBack();
        $error = "Gagal Hapus: " . $e->getMessage(); 
    }
}

// --- 1. LOGIK REKAM MEDIS ---
if ($page == 'rekam_medis') {
    $list_pasien = $conn->query("SELECT id_pasien, nama FROM PASIEN")->fetchAll(PDO::FETCH_ASSOC);
    $list_dokter = $conn->query("SELECT d.id_tenaga_medis, tm.nama_tenaga_medis FROM DOKTER d JOIN TENAGA_MEDIS tm ON d.id_tenaga_medis = tm.id_tenaga_medis")->fetchAll(PDO::FETCH_ASSOC);
    $list_kamar_layanan = $conn->query("SELECT * FROM LAYANAN WHERE Nama_Layanan ILIKE '%Inap%' OR Nama_Layanan ILIKE '%Kamar%' OR Nama_Layanan ILIKE '%VIP%'")->fetchAll(PDO::FETCH_ASSOC);
    $list_poli = $conn->query("SELECT rj.ID_Poli, l.Nama_Layanan FROM RAWAT_JALAN rj JOIN LAYANAN l ON rj.ID_Layanan = l.ID_Layanan")->fetchAll(PDO::FETCH_ASSOC);

    $next_id = autoId($conn, 'id_rekam_medis', 'REKAM_MEDIS', 'RM');

    // --- FITUR TAGIH RAWAT JALAN (Tombol di tabel RM) ---
    if ($action == 'tagih_jalan' && !empty($id_edit)) {
        try {
            $conn->beginTransaction();
            // 1. Ambil Data Rekam Medis
            $q_rm = $conn->prepare("SELECT * FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
            $q_rm->execute([$id_edit]);
            $rm_data = $q_rm->fetch(PDO::FETCH_ASSOC);

            if ($rm_data) {
                $id_pasien = $rm_data['id_pasien'];
                $tgl = $rm_data['tanggal_catatan'];
                $grand_total = 0;
                $list_items = [];

                // 2. Hitung Biaya Poli (Jika ada ID_Poli)
                if (!empty($rm_data['id_poli'])) {
                    $q_poli = $conn->prepare("SELECT l.ID_Layanan, l.Tarif_Dasar FROM RAWAT_JALAN rj JOIN LAYANAN l ON rj.ID_Layanan = l.ID_Layanan WHERE rj.ID_Poli = ?");
                    $q_poli->execute([$rm_data['id_poli']]);
                    $poli = $q_poli->fetch(PDO::FETCH_ASSOC);
                    if ($poli) {
                        $grand_total += $poli['tarif_dasar'];
                        $list_items[] = ['id' => $poli['id_layanan'], 'harga' => $poli['tarif_dasar']];
                    }
                }

                // 3. Hitung Biaya Tindakan Dokter (Dari tabel Detail Pemeriksaan)
                // Cari pemeriksaan yang terhubung dengan RM ini
                $sql_jasa = "SELECT dp.ID_Layanan, l.Tarif_Dasar 
                             FROM PEMERIKSAAN pe
                             JOIN DETAIL_PEMERIKSAAN dp ON pe.ID_Pemeriksaan = dp.ID_Pemeriksaan
                             JOIN LAYANAN l ON dp.ID_Layanan = l.ID_Layanan
                             WHERE pe.ID_Rekam_Medis = ?";
                $q_jasa = $conn->prepare($sql_jasa);
                $q_jasa->execute([$id_edit]);
                $jasas = $q_jasa->fetchAll(PDO::FETCH_ASSOC);

                foreach($jasas as $j) {
                    $grand_total += $j['tarif_dasar'];
                    $list_items[] = ['id' => $j['id_layanan'], 'harga' => $j['tarif_dasar']];
                }

                // 4. Buat Tagihan
                $id_tag = 'TJ' . date('ymd') . rand(10, 99); 
                $conn->prepare("INSERT INTO TAGIHAN (ID_Tagihan, ID_Pasien, Tanggal_Tagihan, Total_Biaya, Status_Pembayaran) VALUES (?, ?, ?, ?, 'Belum Lunas')")
                     ->execute([$id_tag, $id_pasien, $tgl, $grand_total]);

                // 5. Masukkan Detail
                $ins_det = $conn->prepare("INSERT INTO DETAIL_TAGIHAN (ID_Tagihan, ID_Layanan, Jumlah, Subtotal) VALUES (?, ?, 1, ?)");
                foreach($list_items as $item) {
                    try { $ins_det->execute([$id_tag, $item['id'], $item['harga']]); } catch (Exception $x) {}
                }

                $conn->commit();
                header("Location: dashboard.php?page=rekam_medis&msg=tagihan_created"); exit();
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Gagal buat tagihan: " . $e->getMessage();
        }
    }

    if ($action == 'edit' && !empty($id_edit)) {
        $stmt = $conn->prepare("SELECT * FROM REKAM_MEDIS WHERE ID_Rekam_Medis = ?");
        $stmt->execute([$id_edit]);
        $data_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // --- PROSES SIMPAN REKAM MEDIS ---
    if (isset($_POST['simpan_rm'])) {
        try {
            $conn->beginTransaction();
            $id_pasien_fix = $_POST['id_pasien']; 

            // [FLOW BARU] Admin CUMA simpan data RM (Pasien & Dokter), jadwal urusan dokter nanti.
            if ($_POST['mode'] == 'update') {
                $sql = "UPDATE REKAM_MEDIS SET ID_Pasien=?, ID_Tenaga_Medis=?, Tanggal_Catatan=?, Diagnosis=?, Hasil_Pemeriksaan=?, ID_Poli=NULL WHERE ID_Rekam_Medis=?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id_pasien_fix, $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil'], $_POST['id_rm']]);
            } else {
                $sql = "INSERT INTO REKAM_MEDIS (ID_Rekam_Medis, ID_Pasien, ID_Tenaga_Medis, Tanggal_Catatan, Diagnosis, Hasil_Pemeriksaan, ID_Poli) VALUES (?, ?, ?, ?, ?, ?, NULL)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$_POST['id_rm'], $id_pasien_fix, $_POST['id_dokter'], $_POST['tanggal'], $_POST['diagnosis'], $_POST['hasil']]);
            }

            // TIDAK ADA INSERT KE PEMERIKSAAN (Dihapus sesuai flow baru)

            $conn->commit();
            header("Location: dashboard.php?page=rekam_medis&msg=saved"); exit();
        } catch (PDOException $e) { 
            $conn->rollBack(); $error = "Error Simpan: " . $e->getMessage(); 
        }
    }
    
    $sql = "SELECT rm.*, p.Nama AS nama_pasien, tm.Nama_Tenaga_Medis AS nama_dokter,
            l_poli.Nama_Layanan AS nama_poli,
            (SELECT l.Nama_Layanan FROM RAWAT_INAP ri 
             JOIN LAYANAN l ON ri.ID_Layanan = l.ID_Layanan 
             WHERE ri.ID_Pasien = rm.ID_Pasien AND ri.Tanggal_Masuk = rm.Tanggal_Catatan LIMIT 1) AS info_kamar,
             (SELECT COUNT(*) FROM TAGIHAN t WHERE t.ID_Pasien = rm.ID_Pasien AND t.Tanggal_Tagihan = rm.Tanggal_Catatan) AS sudah_ditagih
            FROM REKAM_MEDIS rm 
            JOIN PASIEN p ON rm.ID_Pasien = p.ID_Pasien 
            JOIN TENAGA_MEDIS tm ON rm.ID_Tenaga_Medis = tm.ID_Tenaga_Medis
            LEFT JOIN RAWAT_JALAN rj ON rm.ID_Poli = rj.ID_Poli
            LEFT JOIN LAYANAN l_poli ON rj.ID_Layanan = l_poli.ID_Layanan";

    if (!empty($keyword)) {
        $sql .= " WHERE p.Nama ILIKE ? OR rm.Diagnosis ILIKE ?";
        $stmt = $conn->prepare($sql . " ORDER BY rm.Tanggal_Catatan DESC");
        $stmt->execute(["%$keyword%", "%$keyword%"]);
    } else {
        $stmt = $conn->query($sql . " ORDER BY rm.Tanggal_Catatan DESC");
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
    
       $sql = "SELECT p.*, 
            (SELECT COUNT(*) FROM TAGIHAN t 
             WHERE t.ID_Pasien = p.ID_Pasien 
             AND t.Status_Pembayaran = 'Belum Lunas') as is_belum_bayar,

            -- jenis_rawat dari rekam medis TERBARU pasien
            (SELECT rm.jenis_rawat
             FROM REKAM_MEDIS rm
             WHERE rm.ID_Pasien = p.ID_Pasien
             ORDER BY rm.Tanggal_Catatan DESC, rm.ID_Rekam_Medis DESC
             LIMIT 1) AS last_jenis_rawat
            FROM PASIEN p";

    
    if (!empty($keyword)) { $sql .= " WHERE p.Nama ILIKE ? OR p.ID_Pasien ILIKE ?"; $stmt = $conn->prepare($sql); $stmt->execute(["%$keyword%", "%$keyword%"]); } 
    else { $stmt = $conn->query($sql . " ORDER BY p.ID_Pasien ASC"); }
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

// --- 4. LOGIK RAWAT INAP ---
if ($page == 'rawat_inap') {
    if ($action == 'checkout' && !empty($id_edit)) {
        try {
            $conn->beginTransaction();
            $tgl_keluar = date('Y-m-d');

            // 1. Ambil Data Kamar & Pasien
            $sql_cek = "SELECT r.*, l.Tarif_Dasar, l.ID_Layanan, 
                        COALESCE(r.ID_Pasien, 
                            (SELECT rm.ID_Pasien FROM REKAM_MEDIS rm WHERE rm.Tanggal_Catatan = r.Tanggal_Masuk LIMIT 1)
                        ) as id_pasien_fix
                        FROM RAWAT_INAP r 
                        JOIN LAYANAN l ON r.ID_Layanan = l.ID_Layanan 
                        WHERE r.ID_Kamar = ?";
            
            $stmt_cek = $conn->prepare($sql_cek);
            $stmt_cek->execute([$id_edit]);
            $data_inap = $stmt_cek->fetch(PDO::FETCH_ASSOC);

            if ($data_inap && !empty($data_inap['id_pasien_fix'])) {
                // Hitung
                $masuk = new DateTime($data_inap['tanggal_masuk']);
                $keluar = new DateTime($tgl_keluar);
                $durasi = $keluar->diff($masuk)->days;
                if ($durasi == 0) $durasi = 1;
                $total_biaya = $durasi * $data_inap['tarif_dasar'];

                // Update Keluar
                $stmt = $conn->prepare("UPDATE RAWAT_INAP SET Tanggal_Keluar = ? WHERE ID_Kamar = ?");
                $stmt->execute([$tgl_keluar, $id_edit]);

                // Buat Tagihan (ID LEBIH PENDEK BIAR AMAN)
                $id_tag = 'T' . date('ymd') . rand(10, 99); 
                
                $stmt_bill = $conn->prepare("INSERT INTO TAGIHAN (ID_Tagihan, ID_Pasien, Tanggal_Tagihan, Total_Biaya, Status_Pembayaran) VALUES (?, ?, ?, ?, 'Belum Lunas')");
                
                if (!$stmt_bill->execute([$id_tag, $data_inap['id_pasien_fix'], $tgl_keluar, $total_biaya])) {
                    throw new Exception("Gagal buat tagihan: " . implode(" ", $stmt_bill->errorInfo()));
                }

                // Insert Detail
                try {
                    $conn->prepare("INSERT INTO DETAIL_TAGIHAN (ID_Tagihan, ID_Layanan, Jumlah, Subtotal) VALUES (?, ?, ?, ?)")
                         ->execute([$id_tag, $data_inap['id_layanan'], $durasi, $total_biaya]);
                } catch (Exception $x) {}

                $conn->commit();
                header("Location: dashboard.php?page=rawat_inap&msg=checkout_sukses"); 
                exit();

            } else {
                throw new Exception("Data Pasien tidak ditemukan.");
            }

        } catch (Exception $e) { 
            $conn->rollBack(); 
            // Tampilkan error spesifik
            die("Error Checkout: " . $e->getMessage());
        }
    }

    if ($action == 'delete' && !empty($id_edit)) {
        $stmt = $conn->prepare("DELETE FROM RAWAT_INAP WHERE ID_Kamar = ?");
        $stmt->execute([$id_edit]);
        header("Location: dashboard.php?page=rawat_inap&msg=deleted"); exit();
    }

    $sql = "SELECT r.*, l.Nama_Layanan, COALESCE(p.Nama, 'Pasien Tanpa RM') as nama_pasien FROM RAWAT_INAP r JOIN LAYANAN l ON r.ID_Layanan = l.ID_Layanan LEFT JOIN PASIEN p ON r.ID_Pasien = p.ID_Pasien";
    if (!empty($keyword)) { $sql .= " WHERE r.ID_Kamar ILIKE ? OR p.Nama ILIKE ?"; $stmt = $conn->prepare($sql . " ORDER BY r.Tanggal_Masuk DESC"); $stmt->execute(["%$keyword%", "%$keyword%"]); } else { $stmt = $conn->query($sql . " ORDER BY r.Tanggal_Masuk DESC"); }
    $data_inap = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// --- LOGIKA UPDATE / PINDAH KAMAR ---
    if (isset($_POST['update_inap'])) {
        try {
            $tgl_keluar = !empty($_POST['tgl_keluar']) ? $_POST['tgl_keluar'] : null;
            
            // Logika Tukar Kepala: Update ID_Kamar (Primary Key) berdasarkan ID Lama
            $stmt = $conn->prepare("UPDATE RAWAT_INAP SET ID_Kamar=?, ID_Layanan=?, Tanggal_Masuk=?, Tanggal_Keluar=? WHERE ID_Kamar=?");
            
            $stmt->execute([
                $_POST['id_kamar_baru'], // 1. Set ID Baru
                $_POST['id_layanan'], 
                $_POST['tgl_masuk'], 
                $tgl_keluar, 
                $_POST['id_kamar_lama']  // 2. Cari berdasarkan ID Lama
            ]);
            
            header("Location: dashboard.php?page=rawat_inap&msg=updated"); 
            exit();
        } catch(PDOException $e) { 
            $error = "Gagal Update (ID Kamar mungkin sudah terpakai): " . $e->getMessage(); 
        }
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
            if ($_POST['mode'] == 'update') { $conn->prepare("UPDATE TAGIHAN SET ID_Pasien=?, Tanggal_Tagihan=?, Total_Biaya=?, Status_Pembayaran=? WHERE ID_Tagihan=?")->execute([$_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status'], $_POST['id_tagihan']]); }
            else { $conn->prepare("INSERT INTO TAGIHAN (ID_Tagihan, ID_Pasien, Tanggal_Tagihan, Total_Biaya, Status_Pembayaran) VALUES (?, ?, ?, ?, ?)")->execute([$_POST['id_tagihan'], $_POST['id_pasien'], $_POST['tanggal'], $_POST['biaya'], $_POST['status']]); }
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
        <div class="col-md-2"><a href="laporan_pasien.php" class="text-decoration-none"><div class="card shadow border-0 bg-primary text-white card-stat"><div class="card-body p-3"><h6 class="mb-0">Pasien</h6><h3 class="mb-0 fw-bold"><?= $stat_pasien ?></h3></div></div></a></div>
        <div class="col-md-2"><a href="laporan_dokter.php" class="text-decoration-none"><div class="card shadow border-0 bg-success text-white card-stat"><div class="card-body p-3"><h6 class="mb-0">Dokter</h6><h3 class="mb-0 fw-bold"><?= $stat_dokter ?></h3></div></div></a></div>
        <div class="col-md-2"><a href="laporan_perawat.php" class="text-decoration-none"><div class="card shadow border-0 bg-info text-white card-stat"><div class="card-body p-3"><h6 class="mb-0">Perawat</h6><h3 class="mb-0 fw-bold"><?= $stat_perawat ?></h3></div></div></a></div>
        <div class="col-md-3"><a href="laporan_kunjungan.php" class="text-decoration-none"><div class="card shadow border-0 bg-secondary text-white card-stat"><div class="card-body p-3"><h6 class="mb-0">Kunjungan (RM)</h6><h3 class="mb-0 fw-bold"><?= $stat_rm ?></h3></div></div></a></div>
        <div class="col-md-3"><a href="laporan_keuangan.php" class="text-decoration-none"><div class="card shadow border-0 bg-warning text-dark card-stat"><div class="card-body p-3"><h6 class="mb-0">Pendapatan</h6><h4 class="mb-0 fw-bold">Rp <?= number_format($stat_uang, 0, ',', '.') ?></h4></div></div></a></div>
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
                        <div class="mb-2"><label>ID RM (Otomatis)</label><input type="text" name="id_rm" class="form-control bg-light" value="<?= $data_edit['id_rekam_medis'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Pasien</label><select name="id_pasien" class="form-select" required><?php foreach($list_pasien as $p): ?> <option value="<?=$p['id_pasien']?>" <?= ($data_edit && $data_edit['id_pasien'] == $p['id_pasien']) ? 'selected' : '' ?>><?=$p['nama']?></option> <?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Dokter</label><select name="id_dokter" class="form-select" required><?php foreach($list_dokter as $d): ?> <option value="<?=$d['id_tenaga_medis']?>" <?= ($data_edit && $data_edit['id_tenaga_medis'] == $d['id_tenaga_medis']) ? 'selected' : '' ?>><?=$d['nama_tenaga_medis']?></option> <?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_catatan'] ?? date('Y-m-d') ?>" required></div>
                        <div class="mb-2">
                            <label>Diagnosis <small class="text-muted">(Opsional)</small></label>
                            <input type="text" name="diagnosis" class="form-control" value="<?= $data_edit['diagnosis'] ?? '' ?>">
                        </div>
                        <div class="mb-3">
                            <label>Hasil <small class="text-muted">(Opsional)</small></label>
                            <textarea name="hasil" class="form-control"><?= $data_edit['hasil_pemeriksaan'] ?? '' ?></textarea>
                        </div>

                        <button type="submit" name="simpan_rm" class="btn btn-primary w-100"><?= $data_edit ? 'Update' : 'Simpan' ?></button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <table class="table table-bordered bg-white table-striped">
                <thead class="table-dark"><tr><th>Pasien</th><th>Dokter</th><th>Tgl</th><th>Status</th><th>Hasil</th><th>Aksi</th></tr></thead>
                <tbody>
                    <?php foreach($data_rm as $r): ?>
                        <?php
    $jenis = $r['jenis_rawat'] ?? 'Belum Ditentukan';
    if ($jenis === 'Rawat Inap')      $status_text = 'Rawat Inap';
    elseif ($jenis === 'Rawat Jalan') $status_text = 'Rawat Jalan';
    else                              $status_text = 'Belum Ditentukan';
?>

                    <tr>
                        <td><b><?=$r['nama_pasien']?></b></td>
                        <td><?=$r['nama_dokter']?></td>
                        <td><?= date('d-m-Y', strtotime($r['tanggal_catatan'])) ?></td>
                        
                                       <td>
                    <?php
                        // pakai jenis_rawat dari tabel REKAM_MEDIS
                        $jenis = $r['jenis_rawat'] ?? 'Belum Ditentukan';

                        if ($jenis === 'Rawat Inap') {
                            if (!empty($r['info_kamar'])) {
                                echo '<span class="badge bg-danger">Inap: ' . htmlspecialchars($r['info_kamar']) . '</span>';
                            } else {
                                echo '<span class="badge bg-danger">Rawat Inap</span>';
                            }
                        } elseif ($jenis === 'Rawat Jalan') {
                            if (!empty($r['nama_poli'])) {
                                echo '<span class="badge bg-success">Jalan: ' . htmlspecialchars($r['nama_poli']) . '</span>';
                            } else {
                                echo '<span class="badge bg-success">Rawat Jalan</span>';
                            }
                        } else {
                            echo '<span class="badge bg-secondary">Belum Ditentukan</span>';
                        }
                    ?>
                </td>
                        
                        <td><div class="truncate-text"><?= htmlspecialchars($r['hasil_pemeriksaan']) ?></div></td>
                        
                        <td>
                            <button type="button" class="btn btn-info btn-sm text-white" 
        data-bs-toggle="modal" data-bs-target="#modalDetail" 
        data-id="<?= $r['id_rekam_medis'] ?>" 
        data-pasien="<?= $r['nama_pasien'] ?>" 
        data-dokter="<?= $r['nama_dokter'] ?>" 
        data-tgl="<?= date('d F Y', strtotime($r['tanggal_catatan'])) ?>" 
        data-diag="<?= $r['diagnosis'] ?>" 
        data-hasil="<?= $r['hasil_pemeriksaan'] ?>" 
        data-status="<?= htmlspecialchars($status_text) ?>">
    <i class="fas fa-eye"></i>
</button>

                            
                            <a href="?page=rekam_medis&action=edit&id=<?=$r['id_rekam_medis']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?page=rekam_medis&action=delete&id=<?=$r['id_rekam_medis']?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                            
                            <?php if($r['sudah_ditagih'] == 0 && empty($r['info_kamar'])): ?>
                                <a href="?page=rekam_medis&action=tagih_jalan&id=<?=$r['id_rekam_medis']?>" class="btn btn-success btn-sm" onclick="return confirm('Buat Tagihan Rawat Jalan?')"><i class="fas fa-file-invoice-dollar"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
                <table class="table table-bordered bg-white table-striped">
                    <thead class="table-dark"><tr><th>ID</th><th>Nama</th><th>Alamat</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php foreach($data_pasien as $p): ?>
                        <tr>
                            <td><?=$p['id_pasien']?></td><td><?=$p['nama']?></td><td><?=$p['alamat']?></td>
                            <td>
    <?php
        $last = $p['last_jenis_rawat'] ?? null;

        if ($last === 'Rawat Inap') {
            // RM terbaru rawat inap
            echo '<span class="badge bg-danger">Sedang Dirawat</span>';
        } elseif ($p['is_belum_bayar'] > 0) {
            // ada tagihan belum lunas
            echo '<span class="badge bg-warning text-dark">Belum Bayar</span>';
        } elseif ($last === 'Rawat Jalan') {
            // ini pengganti "Pulang" khusus rawat jalan
            echo '<span class="badge bg-success">Rawat Jalan</span>';
        } else {
            // belum ada rm / rm terakhir belum ditentukan
            echo '<span class="badge bg-secondary">Terdaftar</span>';
        }
    ?>
</td>

                            <td>
                                <a href="detail_pasien.php?id=<?=$p['id_pasien']?>" class="btn btn-info btn-sm text-white"><i class="fas fa-file-alt"></i> Detail</a>
                                <a href="?page=pasien&action=edit&id=<?=$p['id_pasien']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
                                <a href="?page=pasien&action=delete&id=<?=$p['id_pasien']?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                        <div class="mb-3"><label>Spesialis</label><input type="text" name="spesialisasi" class="form-control" value="<?= $data_edit['spesialisasi'] ?? '' ?>" required></div>
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

    <?php if ($page == 'rawat_inap'): ?>
    <div class="row">
        <?php if($data_edit): ?>
        <div class="col-md-4">
            <div class="card shadow mb-3 border-warning">
                <div class="card-header bg-warning">Edit Kamar</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="id_kamar_lama" value="<?= $data_edit['id_kamar'] ?>">
                        
                        <div class="mb-2">
                            <label>ID Kamar (Bisa Pindah)</label>
                            <input type="text" name="id_kamar_baru" class="form-control" value="<?= $data_edit['id_kamar'] ?>" required>
                        </div>

                        <div class="mb-2">
                            <label>Layanan / Kelas</label>
                            <select name="id_layanan" class="form-select">
                                <?php foreach($list_kamar_layanan as $l): ?>
                                    <option value="<?=$l['id_layanan']?>" <?= $data_edit['id_layanan']==$l['id_layanan']?'selected':''?>>
                                        <?=$l['nama_layanan']?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2"><label>Masuk</label><input type="date" name="tgl_masuk" class="form-control" value="<?= $data_edit['tanggal_masuk'] ?>"></div>
                        
                        <button type="submit" name="update_inap" class="btn btn-primary w-100">Update</button>
                        <a href="?page=rawat_inap" class="btn btn-secondary w-100 mt-1">Batal</a>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $data_edit ? '8' : '12' ?>">
            <div class="card shadow"><div class="card-header bg-dark text-white">Data Pasien Rawat Inap</div><div class="card-body">
                <table class="table table-bordered table-hover">
                    <thead class="table-secondary"><tr><th>ID</th><th>Pasien</th><th>Layanan</th><th>Masuk</th><th>Keluar</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                        <?php foreach($data_inap as $r): ?>
                        <?php $is_active = empty($r['tanggal_keluar']); $bg = $is_active ? 'table-warning' : ''; ?>
                        <tr class="<?= $bg ?>"><td><?=$r['id_kamar']?></td><td><?=$r['nama_pasien']?></td><td><?=$r['nama_layanan']?></td><td><?=date('d-m-Y', strtotime($r['tanggal_masuk']))?></td><td><?=$r['tanggal_keluar']?date('d-m-Y', strtotime($r['tanggal_keluar'])):'-'?></td><td><?= $is_active ? '<span class="badge bg-danger">Dirawat</span>' : '<span class="badge bg-success">Pulang</span>' ?></td>
                        <td>
                            <?php if($is_active): ?>
                                <a href="?page=rawat_inap&action=checkout&id=<?=$r['id_kamar']?>" class="btn btn-success btn-sm" onclick="return confirm('Checkout pasien ini? Tagihan akan otomatis dibuat.')">Checkout</a>
                            <?php endif; ?>
                            <a href="?page=rawat_inap&action=delete&id=<?=$r['id_kamar']?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')">Hapus</a>
                        </td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($page == 'tagihan'): ?>
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow"><div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $data_edit ? 'update' : 'insert' ?>">
                        <div class="mb-2"><label>ID</label><input type="text" name="id_tagihan" class="form-control" value="<?= $data_edit['id_tagihan'] ?? $next_id ?>" readonly></div>
                        <div class="mb-2"><label>Pasien</label><select name="id_pasien" class="form-select"><?php foreach($list_pasien as $p): ?><option value="<?=$p['id_pasien']?>"><?=$p['nama']?></option><?php endforeach; ?></select></div>
                        <div class="mb-2"><label>Tanggal</label><input type="date" name="tanggal" class="form-control" value="<?= $data_edit['tanggal_tagihan'] ?? date('Y-m-d') ?>"></div>
                        <div class="mb-2"><label>Biaya</label><input type="number" name="biaya" class="form-control" value="<?= $data_edit['total_biaya'] ?? '' ?>"></div>
                        <div class="mb-2"><label>Status</label><select name="status" class="form-select"><option value="Belum Lunas">Belum Lunas</option><option value="Lunas">Lunas</option></select></div>
                        <button type="submit" name="simpan_tagihan" class="btn btn-primary w-100">Simpan</button>
                    </form>
                </div></div>
            </div>
            <div class="col-md-8">
                <table class="table table-bordered bg-white"><thead class="table-dark"><tr><th>ID</th><th>Pasien</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody><?php foreach($data_tagihan as $t): ?><tr><td><?=$t['id_tagihan']?></td><td><?=$t['nama_pasien']?></td><td>Rp <?=number_format($t['total_biaya'])?></td><td><?=$t['status_pembayaran']?></td><td><a href="?page=tagihan&action=edit&id=<?=$t['id_tagihan']?>" class="btn btn-warning btn-sm">Edit</a> <a href="?page=tagihan&action=delete&id=<?=$t['id_tagihan']?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus?')">Hapus</a></td></tr><?php endforeach; ?></tbody></table>
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
        document.getElementById('pilihan_poli').style.display = !show ? 'block' : 'none';
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