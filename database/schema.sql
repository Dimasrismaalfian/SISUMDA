-- =====================================================================
-- Skema database Dashboard Program BASARNAS (MySQL 5.7+ / 8.x / MariaDB 10.3+)
-- Kolom extra_json menampung isian tambahan yang tidak punya kolom sendiri
-- (kolom dinamis Uji Periodik, field turunan, dsb.) agar tidak ada data hilang.
-- Kolom "urut" menjaga urutan penyimpanan baris (pengganti rowid SQLite).
-- Tabel pendukung (users, login_attempts, wa_*, acc_notif, sync_meta, blob_chunk) dibuat
-- otomatis oleh aplikasi saat pertama dipakai.
-- =====================================================================
CREATE TABLE ref_jenis_kegiatan (
  jenis VARCHAR(100) NOT NULL PRIMARY KEY,
  label LONGTEXT NOT NULL,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE anggota (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama LONGTEXT,
  nip LONGTEXT,
  jabatan LONGTEXT,
  unit LONGTEXT,
  whatsapp LONGTEXT,
  email LONGTEXT,
  kompetensi LONGTEXT,
  spesialisasi LONGTEXT,
  sertifikasi LONGTEXT,
  status LONGTEXT CHECK (status IS NULL OR status IN ('Aktif','Nonaktif')),
  keterangan LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE surat_masuk (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nomor_surat LONGTEXT,
  asal_surat LONGTEXT,
  tanggal_surat LONGTEXT,
  tanggal_diterima LONGTEXT,
  perihal LONGTEXT,
  jenis VARCHAR(100),
  jenis_label LONGTEXT,
  tanggal_kegiatan LONGTEXT,
  waktu LONGTEXT,
  lokasi LONGTEXT,
  penanggung_jawab LONGTEXT,
  no_hp LONGTEXT,
  jumlah_dibutuhkan BIGINT,
  deskripsi LONGTEXT,
  file_name LONGTEXT,
  file_size BIGINT,
  file_data LONGTEXT,
  status LONGTEXT CHECK (status IS NULL OR status IN ('Menunggu Persetujuan','Disetujui','Ditolak','Selesai')),
  keterangan_persetujuan LONGTEXT,
  kegiatan_id VARCHAR(100),
  dibuat LONGTEXT,
  diperbarui LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
  FOREIGN KEY (jenis) REFERENCES ref_jenis_kegiatan(jenis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE surat_masuk_acc (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  surat_id VARCHAR(100) NOT NULL,
  role LONGTEXT NOT NULL,
  waktu LONGTEXT,
  FOREIGN KEY (surat_id) REFERENCES surat_masuk(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE kegiatan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nomor LONGTEXT,
  jenis VARCHAR(100),
  jenis_label LONGTEXT,
  nama LONGTEXT,
  tanggal VARCHAR(100),
  tanggal_selesai LONGTEXT,
  waktu LONGTEXT,
  lokasi LONGTEXT,
  penanggung_jawab LONGTEXT,
  no_hp LONGTEXT,
  deskripsi LONGTEXT,
  jumlah_dibutuhkan BIGINT,
  status_persetujuan VARCHAR(100) CHECK (status_persetujuan IS NULL OR status_persetujuan IN ('Menunggu Persetujuan','Disetujui','Ditolak')),
  keterangan_persetujuan LONGTEXT,
  status_pelaksanaan VARCHAR(100) CHECK (status_pelaksanaan IS NULL OR status_pelaksanaan IN ('Draft','Dalam Pelaksanaan','Selesai')),
  status_wa LONGTEXT,
  dokumentasi LONGTEXT,
  surat_id VARCHAR(100),
  linked_record_id LONGTEXT,
  lampiran_name LONGTEXT,
  lampiran_size BIGINT,
  lampiran_data LONGTEXT,
  dibuat LONGTEXT,
  diperbarui LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
  FOREIGN KEY (jenis) REFERENCES ref_jenis_kegiatan(jenis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE kegiatan_anggota (
  kegiatan_id VARCHAR(100) NOT NULL,
  anggota_id VARCHAR(100) NOT NULL,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
  PRIMARY KEY (kegiatan_id, anggota_id),
  FOREIGN KEY (kegiatan_id) REFERENCES kegiatan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE kegiatan_wa_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kegiatan_id VARCHAR(100) NOT NULL,
  waktu LONGTEXT,
  jumlah BIGINT,
  FOREIGN KEY (kegiatan_id) REFERENCES kegiatan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE kegiatan_file (
  fid VARCHAR(100) NOT NULL PRIMARY KEY,
  kegiatan_id VARCHAR(100) NOT NULL,
  kind VARCHAR(100) NOT NULL CHECK (kind IN ('sprin','bukti','hasil')),
  name LONGTEXT,
  size BIGINT,
  mime LONGTEXT,
  uploaded LONGTEXT,
  oleh LONGTEXT,
  laporan BIGINT DEFAULT 0,
  path LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
  FOREIGN KEY (kegiatan_id) REFERENCES kegiatan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE sgts (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_sekolah LONGTEXT,
  alamat_sekolah LONGTEXT,
  tanggal LONGTEXT,
  waktu LONGTEXT,
  jumlah_peserta BIGINT,
  materi LONGTEXT,
  penanggung_jawab LONGTEXT,
  narasumber LONGTEXT,
  anggota_bertugas LONGTEXT,
  keterangan LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE bimtek (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_bimtek LONGTEXT,
  jenis_bimtek LONGTEXT,
  materi LONGTEXT,
  tanggal LONGTEXT,
  waktu LONGTEXT,
  lokasi LONGTEXT,
  penyelenggara LONGTEXT,
  narasumber LONGTEXT,
  peserta LONGTEXT,
  jumlah_peserta BIGINT,
  petugas LONGTEXT,
  keterangan LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE potensi (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_potensi LONGTEXT,
  kategori LONGTEXT,
  nama_kontak LONGTEXT,
  nomor_kontak LONGTEXT,
  alamat LONGTEXT,
  wilayah LONGTEXT,
  kompetensi LONGTEXT,
  jumlah_tersertifikasi BIGINT,
  jumlah_belum_sertifikasi BIGINT,
  status LONGTEXT,
  alut_sapras LONGTEXT,
  keterangan LONGTEXT,
  dokumentasi LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE narasumber (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama LONGTEXT,
  instansi LONGTEXT,
  jabatan LONGTEXT,
  keahlian LONGTEXT,
  nomor_kontak LONGTEXT,
  email LONGTEXT,
  materi LONGTEXT,
  kegiatan_terkait LONGTEXT,
  status LONGTEXT,
  keterangan LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE diklat (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_diklat LONGTEXT,
  jenis_diklat LONGTEXT,
  materi LONGTEXT,
  tanggal_mulai LONGTEXT,
  tanggal_selesai LONGTEXT,
  lokasi LONGTEXT,
  penyelenggara LONGTEXT,
  instruktur LONGTEXT,
  peserta LONGTEXT,
  jumlah_peserta BIGINT,
  anggota_bertugas LONGTEXT,
  sertifikat LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE rescuer (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  anggota_id VARCHAR(100),
  jabatan LONGTEXT,
  kompetensi LONGTEXT,
  spesialisasi LONGTEXT,
  sertifikasi LONGTEXT,
  masa_berlaku LONGTEXT,
  status LONGTEXT,
  riwayat LONGTEXT,
  keterangan LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE pembinaan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_kegiatan LONGTEXT,
  jenis_pembinaan LONGTEXT,
  sasaran LONGTEXT,
  tanggal LONGTEXT,
  lokasi LONGTEXT,
  materi LONGTEXT,
  pembina LONGTEXT,
  peserta LONGTEXT,
  anggota_bertugas LONGTEXT,
  hasil LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE pemberdayaan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_kegiatan LONGTEXT,
  sasaran LONGTEXT,
  lokasi LONGTEXT,
  tanggal LONGTEXT,
  jumlah_peserta BIGINT,
  materi LONGTEXT,
  narasumber LONGTEXT,
  petugas LONGTEXT,
  hasil LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE uji_periodik (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_uji LONGTEXT,
  jenis_uji LONGTEXT,
  materi LONGTEXT,
  tanggal_mulai LONGTEXT,
  tanggal_selesai LONGTEXT,
  lokasi LONGTEXT,
  penyelenggara LONGTEXT,
  penguji LONGTEXT,
  peserta LONGTEXT,
  jumlah_peserta BIGINT,
  anggota_bertugas LONGTEXT,
  sertifikat LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE uji_pemeliharaan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_peralatan LONGTEXT,
  nomor_inventaris LONGTEXT,
  jenis_peralatan LONGTEXT,
  tanggal_pemeriksaan LONGTEXT,
  kondisi LONGTEXT,
  jenis_pemeliharaan LONGTEXT,
  petugas LONGTEXT,
  hasil LONGTEXT,
  tindakan LONGTEXT,
  jadwal_berikutnya LONGTEXT,
  keterangan LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE lainnya (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_kegiatan LONGTEXT,
  jenis_kegiatan LONGTEXT,
  tanggal LONGTEXT,
  waktu LONGTEXT,
  lokasi LONGTEXT,
  penyelenggara LONGTEXT,
  penanggung_jawab LONGTEXT,
  peserta LONGTEXT,
  jumlah_peserta BIGINT,
  anggota_bertugas LONGTEXT,
  keterangan LONGTEXT,
  dokumentasi LONGTEXT,
  status LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE peralatan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_barang LONGTEXT,
  kode_rak LONGTEXT,
  lokasi LONGTEXT,
  kategori LONGTEXT,
  jumlah_baik BIGINT,
  jumlah_rusak BIGINT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE kendaraan (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_kendaraan LONGTEXT,
  sapras LONGTEXT,
  jenis_kendaraan LONGTEXT,
  nomor_polisi LONGTEXT,
  merk_tipe LONGTEXT,
  kondisi LONGTEXT,
  lokasi LONGTEXT,
  tanggal LONGTEXT,
  km_saat_ini DOUBLE,
  bbm DOUBLE,
  endurance DOUBLE,
  speed DOUBLE,
  `range` DOUBLE,
  konsumsi_bbm DOUBLE,
  cadangan_bbm DOUBLE,
  persen_bbm DOUBLE,
  kecepatan_angin DOUBLE,
  arah_angin LONGTEXT,
  keterangan LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE peminjaman (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  nama_peminjam LONGTEXT,
  jenis_barang LONGTEXT,
  nama_barang LONGTEXT,
  jumlah BIGINT,
  tanggal LONGTEXT,
  tanggal_kembali_rencana LONGTEXT,
  tanggal_kembali LONGTEXT,
  keperluan LONGTEXT,
  penanggung_jawab LONGTEXT,
  status LONGTEXT,
  keterangan LONGTEXT,
  dari_kegiatan_id LONGTEXT,
  tahun_input BIGINT,
  bulan_input BIGINT,
  tanggal_input LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE peserta_kegiatan (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  modul VARCHAR(100) NOT NULL CHECK (modul IN ('diklat','uji_periodik')),
  record_id VARCHAR(100) NOT NULL,
  anggota_id VARCHAR(100),
  nama LONGTEXT,
  jabatan LONGTEXT,
  jk LONGTEXT,
  data_json LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE uji_kategori (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  kategori VARCHAR(100),
  uji_id VARCHAR(100),
  anggota_id VARCHAR(100),
  nama LONGTEXT,
  jabatan LONGTEXT,
  jk LONGTEXT,
  rata_rata DOUBLE,
  predikat LONGTEXT,
  dibuat LONGTEXT,
  diperbarui LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE uji_imt (
  uji_id VARCHAR(100) NOT NULL,
  anggota_id VARCHAR(100) NOT NULL,
  jawaban_json LONGTEXT,
  usia DOUBLE,
  tb DOUBLE,
  bb DOUBLE,
  imt DOUBLE,
  kategori LONGTEXT,
  td DOUBLE,
  nadi DOUBLE,
  tanggal LONGTEXT,
  diperbarui LONGTEXT,
  extra_json LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
  PRIMARY KEY (uji_id, anggota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE app_state (
  `key` VARCHAR(191) NOT NULL PRIMARY KEY,
  value LONGTEXT,
  urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE INDEX idx_kegiatan_tanggal ON kegiatan(tanggal);

CREATE INDEX idx_kegiatan_status ON kegiatan(status_persetujuan, status_pelaksanaan);

CREATE INDEX idx_kegiatan_anggota ON kegiatan_anggota(anggota_id);

CREATE INDEX idx_peserta ON peserta_kegiatan(modul, record_id);

CREATE INDEX idx_uji_kat ON uji_kategori(uji_id, kategori);

CREATE VIEW v_potensi AS SELECT *, COALESCE(jumlah_tersertifikasi,0)+COALESCE(jumlah_belum_sertifikasi,0) AS jumlah_total FROM potensi;

CREATE VIEW v_rescuer AS SELECT a.nama AS nama_anggota, a.nip, r.* FROM rescuer r JOIN anggota a ON a.id=r.anggota_id;

CREATE VIEW v_peralatan AS SELECT p.*, COALESCE(jumlah_baik,0)+COALESCE(jumlah_rusak,0) AS jumlah,
  GREATEST(0, COALESCE(jumlah_baik,0)+COALESCE(jumlah_rusak,0) - COALESCE((SELECT SUM(COALESCE(m.jumlah,1)) FROM peminjaman m
   WHERE m.jenis_barang='Peralatan' AND lower(trim(m.nama_barang))=lower(trim(p.nama_barang)) AND m.status<>'Dikembalikan' AND m.tanggal_kembali IS NULL),0)) AS tersedia
FROM peralatan p;

CREATE VIEW v_kegiatan_anggota AS SELECT ka.kegiatan_id, k.nama AS kegiatan, k.jenis, a.id AS anggota_id, a.nama
FROM kegiatan_anggota ka JOIN kegiatan k ON k.id=ka.kegiatan_id JOIN anggota a ON a.id=ka.anggota_id;

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('sgts','Sar Go To School');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('bimtek','Bimtek');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('potensi','Potensi');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('narasumber','Narasumber');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('diklat','Diklat');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('rescuer','Rescuer');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('pembinaan','Pembinaan Tenaga SAR');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('pemberdayaan','Pemberdayaan Masyarakat');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('uji_periodik','Uji Periodik');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('uji_pemeliharaan','Pemeliharaan Sumda');

INSERT INTO ref_jenis_kegiatan (jenis,label) VALUES ('lainnya','Lainnya');
