-- =========================
-- DROP SEMUA TABEL
-- =========================
DROP TABLE IF EXISTS detail_tagihan CASCADE;
DROP TABLE IF EXISTS tagihan CASCADE;
DROP TABLE IF EXISTS detail_pemeriksaan CASCADE;
DROP TABLE IF EXISTS pemeriksaan CASCADE;
DROP TABLE IF EXISTS rawat_inap CASCADE;
DROP TABLE IF EXISTS rekam_medis CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS dokter CASCADE;
DROP TABLE IF EXISTS perawat CASCADE;
DROP TABLE IF EXISTS tenaga_medis CASCADE;
DROP TABLE IF EXISTS layanan CASCADE;
DROP TABLE IF EXISTS pasien CASCADE;
DROP TABLE IF EXISTS departemen CASCADE;

-- =========================
-- CREATE TABLE
-- =========================

CREATE TABLE pasien (
    id_pasien VARCHAR(10) PRIMARY KEY,
    nama VARCHAR(100),
    tanggal_lahir DATE,
    alamat TEXT,
    nomor_telepon VARCHAR(20)
);

CREATE TABLE departemen (
    id_departemen VARCHAR(10) PRIMARY KEY,
    nama_departemen VARCHAR(100)
);

CREATE TABLE tenaga_medis (
    id_tenaga_medis VARCHAR(10) PRIMARY KEY,
    nama_tenaga_medis VARCHAR(100),
    id_departemen VARCHAR(10),
    FOREIGN KEY (id_departemen) REFERENCES departemen(id_departemen)
);

CREATE TABLE dokter (
    id_tenaga_medis VARCHAR(10) PRIMARY KEY,
    spesialisasi VARCHAR(100),
    FOREIGN KEY (id_tenaga_medis) REFERENCES tenaga_medis(id_tenaga_medis)
);

CREATE TABLE perawat (
    id_tenaga_medis VARCHAR(10) PRIMARY KEY,
    shift VARCHAR(20),
    FOREIGN KEY (id_tenaga_medis) REFERENCES tenaga_medis(id_tenaga_medis)
);

CREATE TABLE layanan (
    id_layanan VARCHAR(10) PRIMARY KEY,
    nama_layanan VARCHAR(100),
    tarif_dasar INT,
    jenis_layanan VARCHAR(20)
);

CREATE TABLE rekam_medis (
    id_rekam_medis VARCHAR(10) PRIMARY KEY,
    id_pasien VARCHAR(10),
    id_tenaga_medis VARCHAR(10),
    tanggal_catatan DATE,
    jenis_rawat VARCHAR(20),
    diagnosis TEXT,
    hasil_pemeriksaan TEXT,
    FOREIGN KEY (id_pasien) REFERENCES pasien(id_pasien),
    FOREIGN KEY (id_tenaga_medis) REFERENCES tenaga_medis(id_tenaga_medis)
);

CREATE TABLE pemeriksaan (
    id_pemeriksaan VARCHAR(10) PRIMARY KEY,
    id_rekam_medis VARCHAR(10),
    id_tenaga_medis VARCHAR(10),
    tanggal_pemeriksaan DATE,
    waktu_pemeriksaan TIME,
    ruang_pemeriksaan VARCHAR(50),
    diagnosis TEXT,
    hasil_pemeriksaan TEXT,
    FOREIGN KEY (id_rekam_medis) REFERENCES rekam_medis(id_rekam_medis),
    FOREIGN KEY (id_tenaga_medis) REFERENCES tenaga_medis(id_tenaga_medis)
);

CREATE TABLE detail_pemeriksaan (
    id_pemeriksaan VARCHAR(10),
    id_layanan VARCHAR(10),
    PRIMARY KEY (id_pemeriksaan, id_layanan),
    FOREIGN KEY (id_pemeriksaan) REFERENCES pemeriksaan(id_pemeriksaan),
    FOREIGN KEY (id_layanan) REFERENCES layanan(id_layanan)
);

CREATE TABLE rawat_inap (
    id_kamar VARCHAR(10) PRIMARY KEY,
    tanggal_masuk DATE,
    tanggal_keluar DATE,
    id_pasien VARCHAR(10),
    id_layanan VARCHAR(10),
    FOREIGN KEY (id_pasien) REFERENCES pasien(id_pasien),
    FOREIGN KEY (id_layanan) REFERENCES layanan(id_layanan)
);

CREATE TABLE tagihan (
    id_tagihan VARCHAR(15) PRIMARY KEY,
    tanggal_tagihan DATE,
    total_biaya INT,
    status_pembayaran VARCHAR(20),
    id_pasien VARCHAR(10),
    FOREIGN KEY (id_pasien) REFERENCES pasien(id_pasien)
);

CREATE TABLE detail_tagihan (
    id_tagihan VARCHAR(15),
    id_layanan VARCHAR(10),
    jumlah INT,
    subtotal INT,
    PRIMARY KEY (id_tagihan, id_layanan),
    FOREIGN KEY (id_tagihan) REFERENCES tagihan(id_tagihan),
    FOREIGN KEY (id_layanan) REFERENCES layanan(id_layanan)
);

CREATE TABLE users (
    id_user SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password_hash TEXT,
    role VARCHAR(20),
    id_pasien VARCHAR(10),
    id_tenaga_medis VARCHAR(10),
    FOREIGN KEY (id_pasien) REFERENCES pasien(id_pasien),
    FOREIGN KEY (id_tenaga_medis) REFERENCES tenaga_medis(id_tenaga_medis)
);

-- =========================
-- INSERT DUMMY DATA
-- =========================

INSERT INTO pasien VALUES
('P001','Budi Santoso','1995-05-12','Jakarta','081234567890'),
('P002','Ani Wijaya','1998-03-20','Bandung','082345678901');

INSERT INTO departemen VALUES
('D01','Poli Umum'),
('D02','Rawat Inap');

INSERT INTO tenaga_medis VALUES
('TM01','Dr. Andi','D01'),
('TM02','Perawat Sinta','D02');

INSERT INTO dokter VALUES
('TM01','Dokter Umum');

INSERT INTO perawat VALUES
('TM02','Pagi');

INSERT INTO layanan VALUES
('L001','Konsultasi Dokter',50000,'Rawat Jalan'),
('L002','Suntik Vitamin',75000,'Rawat Jalan'),
('L003','Kamar Inap Standar',250000,'Rawat Inap');

INSERT INTO rekam_medis VALUES
('RM001','P001','TM01','2025-12-19','Rawat Jalan','Flu','Istirahat'),
('RM002','P002','TM01','2025-12-19','Rawat Jalan','Demam','Vitamin');

-- PASSWORD = 123456 (bcrypt)
INSERT INTO users (username,password_hash,role) VALUES
('admin','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin');

INSERT INTO users (username,password_hash,role,id_tenaga_medis) VALUES
('drandi','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','dokter','TM01');

INSERT INTO users (username,password_hash,role,id_pasien) VALUES
('henry','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','pasien','P003');
