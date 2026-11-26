# Dashboard Program BASARNAS (PHP + MySQL)

## Isi paket
| File / folder | Fungsi |
|---|---|
| `index.php` | Halaman dashboard (antarmuka) |
| `api.php` | API simpan/muat data dan upload file |
| `config_db.php` | **Pengaturan koneksi MySQL** (host, port, nama database, user, kata sandi) |
| `koneksi.php` | Pembuat koneksi PDO MySQL bersama + definisi tabel pendukung |
| `lib.php` | Pustaka database (membuat tabel otomatis, simpan/muat) |
| `migrasi_sqlite_ke_mysql.php` | Skrip terminal sekali pakai: memindahkan data lama dari SQLite (`data/*.db`) ke MySQL |
| `login.php` | Halaman Masuk & Daftar (pengguna membuat ID dan kata sandi sendiri) |
| `logout.php` | Keluar dari akun |
| `kelola_akun.php` | Halaman Kelola Akun (khusus Admin Utama): tambah akun, reset kata sandi, ubah nama & peran, hapus akun |
| `buat_admin.php` | Skrip terminal untuk membuat / mengatur ulang akun Admin Utama (tidak bisa dibuka lewat web) |
| `notif.php` | Notifikasi lonceng di header (khusus Admin & Admin Utama): membandingkan data lama vs baru saat disimpan, mencatat siapa yang mengubah (WA Bot / Admin / Anggota), status "sudah dibaca" per akun. Tabel `notifikasi` & `notifikasi_baca` dibuat otomatis |
| `auth.php` | Pustaka login: sesi, hash kata sandi, CSRF, pembatasan percobaan gagal, pengaturan pendaftaran |
| `database/schema.sql` | Skema tabel (MySQL/MariaDB, InnoDB, utf8mb4) |
| `database/seed.json` | Data awal: 151 anggota, 4 kegiatan, contoh data tiap modul |
| `database/basarnas.sql` | Dump lengkap skema + data awal (opsional: `mysql -u USER -p NAMA_DB < database/basarnas.sql`) |
| `data/pengaturan_wa.json` | Dibuat otomatis saat Admin Utama menyimpan menu **Pengaturan** (No WA pimpinan, API Key WA, API website, No WA ACC peminjaman, No WA perantara/inputan). Nilainya menimpa bawaan di `wa_config.php`; hapus file ini untuk kembali ke bawaan |
| `u.php` | Halaman upload lampiran dari tautan yang dikirim bot WA (tanpa login, token 8 karakter, 1 foto/PDF per tautan) |
| `wa_inti.php` | Inti input WA/formulir: spesifikasi tiap modul, parser, validasi, pencatatan, teks balasan |
| `wa_lampiran.php` | Pustaka lampiran WA: token upload, kompres foto, simpan file ke data |
| `data/` | Dibuat/diisi otomatis: `uploads/` (file Sprin, bukti, hasil kegiatan), `wa_log.txt`, `wa.lock`. Database sekarang di MySQL, bukan di folder ini. (`basarnas.db` / `auth.db` bila ada = sisa data SQLite lama, hanya dipakai skrip migrasi) |
| `.htaccess`, `.user.ini` | Proteksi folder & batas upload (Apache / PHP-FPM) |

## Kebutuhan
- PHP 8.0 atau lebih baru dengan ekstensi **pdo_mysql** (aktif secara bawaan di XAMPP/Laragon/hosting umum)
- **MySQL 5.7+ / 8.x atau MariaDB 10.3+** (InnoDB). Lampiran PDF disimpan di database; nilai besar otomatis dipecah ke tabel `blob_chunk`, sehingga bawaan XAMPP (`max_allowed_packet` 1M) sudah cukup. Menaikkan `max_allowed_packet` (mis. 16M di `my.ini`) tetap membuat penyimpanan sedikit lebih cepat
- Folder `data/` dapat ditulis oleh web server
- Koneksi internet saat membuka dashboard (pustaka Tailwind, SheetJS, ExcelJS, jsPDF, pdf.js dimuat dari CDN)

## Cara menjalankan
**Cepat (tanpa Apache):**
```
cd basarnas-dashboard
php -S localhost:8000
```
lalu buka http://localhost:8000

**XAMPP / Laragon / Apache:** salin folder `basarnas-dashboard` ke `htdocs` (atau `www`), buka `http://localhost/basarnas-dashboard/`.

**Hosting / Linux:** unggah semua file, lalu pastikan folder `data` dapat ditulis:
```
chmod -R 775 data
chown -R www-data:www-data data
```

**Menyiapkan MySQL (sekali saja):**
1. Buat database kosong, mis. lewat phpMyAdmin atau terminal:
   ```
   CREATE DATABASE basarnas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'basarnas'@'localhost' IDENTIFIED BY 'sandi_kuat';
   GRANT ALL PRIVILEGES ON basarnas.* TO 'basarnas'@'localhost';
   ```
2. Isi `config_db.php` (host, port, name, user, pass), atau gunakan environment variable `BASARNAS_DB_HOST`, `BASARNAS_DB_PORT`, `BASARNAS_DB_NAME`, `BASARNAS_DB_USER`, `BASARNAS_DB_PASS` (didahulukan dari file).
3. Buka dashboard: semua tabel + data awal dibuat otomatis saat halaman pertama kali dibuka. Lalu buat Admin Utama: `php buat_admin.php`.

**Memindahkan data lama dari SQLite:** letakkan `basarnas.db` dan `auth.db` lama di folder `data/`, isi `config_db.php`, lalu jalankan `php migrasi_sqlite_ke_mysql.php` (butuh ekstensi pdo_sqlite hanya untuk langkah ini). Skrip menolak menimpa MySQL yang sudah berisi data kecuali ditambah `--timpa`. File SQLite tidak diubah. Folder `data/uploads/` tetap dipakai apa adanya.

## Catatan penting
- **Login & akun.** Dashboard dan API hanya bisa dibuka setelah login. Pengguna mendaftar sendiri di tab "Daftar" (ID 3–32 karakter huruf/angka/`.`/`_`/`-`, kata sandi minimal 8 karakter). Kata sandi disimpan sebagai hash di tabel `users` (MySQL), bukan teks biasa. Setelah 5 kali salah, ID+IP dikunci 10 menit; sesi berakhir setelah 8 jam tanpa aktivitas.
- **Pengaturan pendaftaran** ada di bagian atas `auth.php`: `REGISTRASI_TERBUKA` (false = tutup pendaftaran), `KODE_PENDAFTARAN` (isi kode agar hanya yang tahu kode bisa mendaftar), `SESI_TIMEOUT`.
- **Akun Admin Utama awal (otomatis).** Saat dashboard pertama kali dibuka dan belum ada Admin Utama, sistem membuat satu akun: ID `adminutama` (kata sandi awal diberikan terpisah oleh pembuat paket). Segera login lalu ganti kata sandi lewat **Kelola Akun**. Pembuatan hanya sekali (penanda `data/.admin_awal_dibuat`). Admin Utama dapat mengelola semua modul, ACC, import Excel, dan semua akun.
- **Admin Utama.** Akun dengan peran `admin_utama` memiliki akses penuh: peran "Admin Utama" di pojok kanan atas (otomatis terpilih) dapat melakukan semua fitur — ACC surat menggantikan seluruh pejabat, menyetujui kegiatan, upload/hapus Sprin, mengelola data — serta membuka **Kelola Akun** (tombol di header atau `kelola_akun.php`). Pendaftaran mandiri selalu menghasilkan akun biasa. Sistem menolak menghapus/mencabut Admin Utama terakhir.
- **Membuat / reset Admin Utama (terminal):** `php buat_admin.php` (ID `adminutama`, sandi acak) · `php buat_admin.php IDANDA` · `php buat_admin.php IDANDA "sandi baru"`. Bila ID sudah ada, kata sandinya diganti dan perannya dijadikan Admin Utama — jalan keluar jika semua Admin Utama lupa sandi.
- **Lupa kata sandi (akun biasa):** Admin Utama dapat mengatur ulang lewat Kelola Akun. Cara manual lama: belum ada fitur reset mandiri. Hapus baris akun di tabel `users` (mis. lewat phpMyAdmin), lalu pengguna mendaftar ulang dengan ID yang sama.
- **Peran (KKS/KSD/KSO/KSB/Admin/Anggota)** dipilih saat mendaftar (tab "Daftar") dan tersimpan di akun; pilihan di header sudah dihapus. Peran dibaca dari database setiap membuka dashboard. Admin Utama dapat mengubah peran akun lain di Kelola Akun. Karena pendaftar memilih sendiri, isi `KODE_PENDAFTARAN` di `auth.php` bila peran berwenang (mis. KKS/Admin) tidak boleh dipilih sembarang orang. Satu nama lengkap hanya boleh memiliki satu akun (tanpa membedakan huruf besar/kecil).
- Jika dipasang di internet, gunakan HTTPS agar kata sandi tidak terkirim polos.
- **Nginx:** `.htaccess` tidak berlaku. Tambahkan aturan agar folder `data/` dan `database/` serta file pustaka/konfigurasi tidak bisa diakses langsung, mis. `location ~ ^/(data|database)/ { deny all; }` dan `location ~ ^/(lib|auth|koneksi|config_db|buat_admin|migrasi_sqlite_ke_mysql|wa_config|wa_inti|wa_lampiran|wa_acc|wa_siaga|wa_pengingat)\.php$ { deny all; }`.
- **Cadangan data:** dua bagian — ekspor database (`mysqldump -u USER -p NAMA_DB > cadangan.sql`) **dan** salin folder `data/` (berisi file upload).
- **Mengulang dari awal:** hentikan penggunaan, hapus (`DROP`) semua tabel di database MySQL (atau buat database baru dan arahkan `config_db.php` ke sana), lalu buka kembali halaman; tabel dan data awal dibuat ulang. Tabel akun (`users`) ikut terhapus bila semua tabel dihapus.
- **Pembaruan otomatis (tanpa refresh).** Browser menanyakan nomor revisi ke `api.php?action=sync` setiap 4 detik (berhenti saat tab tidak terlihat). Jika pengguna/perangkat lain menyimpan perubahan, data dan tampilan diperbarui sendiri. Jika Anda sedang membuka form/modal, mengetik, atau ada perubahan yang belum tersimpan, pembaruan ditunda sampai selesai. Polling tidak memperpanjang waktu sesi login. Interval diubah lewat `SYNC_INTERVAL` di `index.php`.
- Penyimpanan bersifat satu snapshot (data terakhir yang disimpan menimpa sebelumnya). Cocok untuk satu pengguna / bergantian; jika beberapa orang mengedit bersamaan, perubahan orang yang menyimpan lebih dulu bisa tertimpa.
- Batas upload diatur di `.htaccess` / `.user.ini` (file 25MB, total data kiriman 128MB). Jika hosting mengabaikan file ini, naikkan `upload_max_filesize` dan `post_max_size` di `php.ini`.
- Jika muncul "MySQL server has gone away" saat menyimpan: pastikan memakai versi ini (nilai besar dipecah otomatis); bila tetap terjadi, naikkan `max_allowed_packet` di `my.ini` XAMPP lalu restart MySQL.
- Nilai yang tidak cocok dengan kolom angka (mis. teks "sekitar 50" pada kolom jumlah) tidak ditolak: disimpan di kolom `extra_json` dan dimuat kembali apa adanya, sama seperti perilaku sebelumnya.

## Laporan kesiapan aset via WhatsApp -> Pengecekan Siaga Rutin
File: `wa_siaga.php` (dipanggil dari `wa_webhook.php`, tanpa kata kunci). Kirim laporan format bebas ke nomor bot, contoh:
```
Selamat Siang Pimpinan
Izin melaporkan perkembangan terkini mengenai kesiapan aset ...
Minggu, 01 November 2026

1. KN SAR GANESHA (Dermaga Kade Tg. Priok)
* Endurance: 65,4 jam
* Speed: 12 knot
* Range : 784,8 NM
* BBM Rutin: 55,3%
* Reserve Fuel : 8%

* Kecepatan angin: 3 knot
* Arah angin: Tenggara
```
- **Dikenali** bila ada butir bernomor + baris `* Kunci: nilai`, dan ada kata "kesiapan/siaga/melaporkan/laporan" atau kunci khas (Endurance, Speed, Range, BBM, KM). Perintah biasa (KENDARAAN, ACC, dst.) tidak terpengaruh.
- **Jenis pengecekan otomatis per butir:** Endurance/Speed/Range atau nama kapal/RB/RBB/RIB = *Sarana - Sapras Laut*; KM/ganti oli atau nama mobil/truk/motor = *Sarana - Sapras Darat*; Jumlah/Baik/Rusak/Hilang = *Peralatan*. Satu laporan campuran menghasilkan satu riwayat per jenis.
- **Tanggal** diambil dari laporan (`01 November 2026`, `1 Nov 2026`, `01/11/2026`); tanpa tanggal = hari ini. Jam = saat pesan diterima. Pemeriksa = nama pengirim.
- **Aset belum ada di dashboard dibuat otomatis** di modul Kendaraan / Peralatan (ID baru KDR-/PRL-); yang sudah ada dicocokkan lewat nama (abaikan titik/spasi/huruf besar) lalu diperbarui (angka > 0 menggantikan nilai modul, sama seperti tombol Simpan di dashboard).
- `Endurance: 40 jam 36 menit` -> 40,6 jam. Kecepatan/arah angin (isian di luar butir) diterapkan ke semua aset laut dan disimpan juga di riwayat (`cuaca`, termasuk gelombang / muka air bila disertakan).
- `Reserve Fuel: 8%` dan isian lain tanpa kolom sendiri (mis. Air bersih) masuk ke *Ket* aset. Kolom "Cadangan BBM" di modul berisi liter, jadi hanya terisi bila ditulis dengan satuan liter. Butir `* -` dicatat sebagai "tidak ada data" (aset tetap dibuat/dicek, tanpa angka).
- Pengirim harus nomor terdaftar (aturan webhook yang sama). Bot membalas ringkasan + ID riwayat (SGA-xxx).

## Pembinaan Tenaga SAR — tab Absensi
- Tab **Absensi** ada di samping tab Samapta. Nama anggota yang dipilih di Samapta otomatis masuk ke Absensi (kehadiran awal: Hadir).
- Absensi juga bisa ditambah manual lewat tombol "+ Tambah Absensi".
- Data disimpan di kunci `pembinaan_absensi` (tabel `app_state`), tidak perlu mengubah skema database.

---

## Input data lewat WhatsApp

Pesan WhatsApp ke nomor **087891918976** otomatis dicatat ke dashboard (gateway: Fonnte).
Modul yang bisa diinput: **Kegiatan/Tugas, Persuratan, Pembinaan Tenaga SAR, Peralatan, Kendaraan, Peminjaman, Pengajuan**.

### Pemasangan
1. Unggah semua file (termasuk `wa_webhook.php` dan `wa_config.php`). Server wajib memakai HTTPS agar Fonnte dapat memanggil webhook.
2. Di Fonnte, buka Device 087891918976 → *Webhook* dan isi:
   `https://DOMAIN-ANDA/wa_webhook.php?key=<WA_WEBHOOK_KEY>` (nilai kunci ada di `wa_config.php`).
3. Isi **Nomor WhatsApp** tiap orang di menu *Daftar Anggota*. Hanya nomor yang terdaftar (atau tercantum di `WA_NOMOR_DIIZINKAN`) yang bisa menginput.
4. Uji: buka alamat webhook di browser → tampil `OK`. Lalu kirim `BANTUAN` dari nomor terdaftar.

Token dan kunci ada di `wa_config.php` (dilindungi `.htaccess`). Ganti `WA_WEBHOOK_KEY` bila bocor. Log pesan: `data/wa_log.txt`.

### Format pesan
Baris pertama = kata kunci, lalu satu data per baris dengan format `Nama: isi`. Ketik `FORMAT <modul>` untuk contoh.

| Kata kunci | Modul | Wajib |
|---|---|---|
| `KEGIATAN` | Kegiatan/Tugas (Jenis: Sar Go To School, Bimtek, Diklat, dll.; otomatis tercatat juga di modul sesuai Jenis) | Nama |
| `SURAT MASUK` / `SURAT KELUAR` | Persuratan | Nomor, Perihal / Nomor, Tujuan, Perihal |
| `PEMBINAAN` (`PEMBINAAN SAMAPTA`) | Pembinaan Tenaga SAR | Nama |
| `PERALATAN` | Peralatan | Nama |
| `KENDARAAN` | Kendaraan | Nama |
| `PINJAM` | Peminjaman (stok peralatan diperiksa) | Peminjam, Barang |
| `PENGAJUAN KEBUTUHAN` / `PENGAJUAN PEMELIHARAAN` | Pengajuan | Barang |

Kegiatan, Surat Masuk, dan Pengajuan masuk dengan status *Menunggu Persetujuan*; ACC tetap dilakukan di dashboard oleh pimpinan. Bot membalas dengan ID data atau alasan penolakan.

### Balasan bot, lampiran, dan perintah Selesai
Setelah data tersimpan, bot membalas seperti ini (contoh Kegiatan):

```
✅ Data tersimpan di *Kegiatan/Tugas*.
Kegiatan/Tugas: Uji Kompetensi BSG
...
🔖 *Simpan ID ini: KGT-044*
Kalau kegiatan ini sudah selesai dilaksanakan, balas dengan "Selesai KGT-044".

📷 Upload Lampiran PDF:
https://DOMAIN/u.php/ABCD2345
Maksimal 1 foto/file. Foto otomatis dikompres agar hemat penyimpanan.
Atau langsung kirim foto/PDF-nya di chat ini (pesan terpisah, tanpa teks) dalam 60 menit.
```

- **Tautan upload** (`u.php/KODE`): berlaku `WA_UPLOAD_LINK_HARI` hari, sekali pakai (maks. 1 foto/file, JPG/PNG/PDF, `WA_UPLOAD_MAKS_MB` MB). Foto dikecilkan di browser (maks. 1600 px) dan sekali lagi di server bila ekstensi PHP `gd` aktif. Alamat dasar tautan otomatis dari alamat yang dipakai Fonnte memanggil webhook; paksa lewat `WA_BASE_URL` di `wa_config.php` bila perlu (mis. `https://domain/kansar`).
- **Kirim langsung di chat WA**: foto/PDF tanpa teks, dalam `WA_UPLOAD_JENDELA_MENIT` menit sejak data tersimpan, masuk ke data terakhir yang disimpan nomor pengirim (yang belum punya lampiran). Server mengunduh file dari alamat `url` yang dikirim Fonnte (butuh PHP `curl` atau `allow_url_fopen`).
- Lampiran tampil di halaman detail data (Kegiatan, Surat Masuk, Pembinaan, Peralatan, Kendaraan, Peminjaman, Pengajuan) dan di kolom File Surat Keluar; tombol Lihat / Unduh / Hapus (Hapus untuk non-Anggota). Data di database: kolom `lampiran_files`.
- **`Selesai <ID>`** (khusus Kegiatan/Tugas): menandai pelaksanaan *Selesai*. Hanya bila kegiatan sudah *Disetujui*, dan hanya oleh pengaju via WA, penanggung jawab, anggota bertugas, atau nomor di `WA_NOMOR_DIIZINKAN`. `Selesai KEG-4` dianggap sama dengan `Selesai KGT-004`.
- Tautan upload tidak memakai login: siapa pun yang memegang tautan bisa mengirim 1 file untuk data itu. Karena itu masa berlaku dan sekali pakai dibatasi.
- **Nginx:** pastikan `u.php/KODE` diteruskan ke PHP (`try_files $uri $uri/ /index.php?$args;` tidak cukup; pakai `location ~ \.php(/|$) { fastcgi_split_path_info ^(.+\.php)(/.+)$; ... }`) dan blokir `wa_lampiran.php` seperti `lib.php`.

### Formulir lewat tautan (FORM)
Ketik `FORM KEGIATAN` (atau `FORM SURAT MASUK`, `FORM SURAT KELUAR`, `FORM PEMBINAAN`, `FORM PERALATAN`, `FORM KENDARAAN`, `FORM PINJAM`, `FORM PENGAJUAN`) ke nomor bot. Bot membalas tautan `u.php/KODE` berisi formulir kosong; isian, pilihan, dan validasinya sama dengan format pesan WA. Lampiran (1 foto/PDF) opsional di halaman yang sama. Setelah dikirim, data tercatat (status sama seperti input WA) dan bot mengirim konfirmasi berisi ID ke pengirim. Tautan sekali pakai, berlaku `WA_UPLOAD_LINK_HARI` hari, dan hanya dibuat untuk nomor yang terdaftar. Ketik `FORM` saja untuk daftar pilihan.

**Pengembalian lewat WA:** `KEMBALI` (daftar pinjaman aktif Anda), `KEMBALI PMJ-003` (satu atau beberapa ID, mis. `KEMBALI PMJ-003 PMJ-004`), `KEMBALI PMJ-003 1` (sebagian: 1 unit; sisanya tetap dipinjam), `KEMBALI SEMUA`. Hanya peminjam, penanggung jawab, pengirim data, nomor di `WA_NOMOR_DIIZINKAN`, atau pimpinan yang dapat mengembalikan. Status menjadi *Dikembalikan* dan stok *Tersedia* di modul Peralatan otomatis pulih. Di tabel Peralatan, kolom *Keterangan Pinjam* menampilkan peminjam dan jumlah yang sedang dipinjam.

**Bukti pengembalian (foto/file):** setiap kali `KEMBALI` berhasil, balasan WhatsApp menyertakan tautan upload `u.php/<TOKEN>` untuk tiap data yang dikembalikan (pengembalian sebagian: tautan melekat pada data yang dikembalikan). Pengirim cukup membuka tautan, memilih 1 foto (JPG/PNG, otomatis dikompres) atau PDF, lalu kirim. File tersimpan di `data/uploads` dan muncul di detail Peminjaman dashboard dengan label *Bukti Pengembalian* (tombol Lihat / Unduh). Satu tautan = satu file, berlaku `WA_UPLOAD_LINK_HARI` hari (bawaan 7); ukuran maksimum `WA_UPLOAD_MAKS_MB`.

**ACC / Tolak Peminjaman lewat WhatsApp (penyetuju: Firman):**
- Peminjaman baru (dari chat `PINJAM`, `FORM PINJAM`, atau tombol *+ Tambah* di dashboard) berstatus **Menunggu**. Stok Peralatan langsung dicadangkan agar tidak terpinjam ganda.
- Bot mengirim permintaan ke nomor penyetuju peminjaman (`WA_NOMOR_APPROVER_PINJAM` di `wa_config.php`, saat ini 0851-5515-9030 / Firman). Satu formulir berisi beberapa barang = satu pesan.
- Firman membalas: `ACC PMJ-012` (status jadi **Dipinjam**), `TOLAK PMJ-012 alasan penolakan` (status jadi **Ditolak**, alasan wajib), beberapa sekaligus `ACC PMJ-012 PMJ-013`, atau `MENUNGGU` untuk daftar. Nomor ini hanya berwenang untuk Peminjaman; nomor pimpinan (`WA_NOMOR_APPROVER`) tidak berwenang untuk Peminjaman.
- Peminjam otomatis menerima notifikasi WhatsApp: *DISETUJUI* (status Dipinjam) atau *DITOLAK* beserta alasan dan siapa yang memutuskan. Stok pulih otomatis bila ditolak.
- **Keputusan pertama menang:** setelah di-ACC/ditolak di WhatsApp, tombol ACC/Tolak di dashboard hilang dan perubahan dari halaman dashboard yang belum diperbarui ditolak server (dan sebaliknya: ACC/TOLAK di WhatsApp untuk data yang sudah diputuskan di dashboard dijawab "sudah final"). Di dashboard, ACC/Tolak tersedia untuk KKS/KSO/KSD/Admin/Admin Utama selama masih Menunggu.
- Peminjaman yang ditolak bisa diperbaiki lewat *Ubah Data* lalu disimpan = diajukan ulang (permintaan baru ke Firman). `KEMBALI` hanya berlaku untuk peminjaman yang sudah Dipinjam. Data lama dan hasil Import Excel dianggap sudah disetujui (tidak diminta ACC).

**Peminjaman:** mengirim `PINJAM` saja (tanpa isian) langsung dibalas tautan formulir peminjaman, sama seperti `FORM PINJAM`. `PINJAM` yang disertai isian tetap tercatat langsung lewat pesan.

### Kirim Info Tugas + PDF Sprin ke anggota (tombol di detail Kegiatan)
Tombol **Kirim Info Tugas via WhatsApp** (muncul setelah kegiatan Disetujui, anggota dipilih, dan Sprin diupload) mengirim pesan WA ke nomor WhatsApp tiap anggota bertugas, beserta file PDF Sprin sebagai lampiran, lewat Fonnte (`api.php?action=wa_tugas`). Format pesan:
```
*NOTIFIKASI PENUGASAN*

Nama Kegiatan: ...
Tanggal Kegiatan: ...
Lokasi: ...
Jam: ... WIB
Anggota Yang Bertugas:
1. ...
```
Syarat: kolom **Nomor WhatsApp** anggota terisi di Daftar Anggota, ekstensi PHP `curl` aktif, dan device Fonnte terhubung. Anggota tanpa nomor / yang gagal dilaporkan setelah pengiriman. Template diubah di fungsi `wa_pesan_tugas()` (`wa_inti.php`).

**Dua pesan saat tombol kirim ditekan:** (1) info tugas + PDF Sprin, (2) *Link Update Kegiatan* (`u.php/<TOKEN>`: upload foto/PDF bukti penugasan, boleh berkali-kali → status *Dalam Pelaksanaan*), Setelah anggota mengisi update kegiatan (upload bukti), **otomatis dikirim** pesan ke-3: *Link Upload Laporan* (upload PDF maks. 25MB atau isi link, sekali pakai → status *Selesai*). Tautan dibuat per anggota (tercatat di kolom "oleh"), berlaku sampai 30 hari setelah kegiatan berakhir, dan tautan lama anggota itu dicabut saat info tugas dikirim ulang. Link laporan hanya bisa dipakai setelah kegiatan *Dalam Pelaksanaan* (aturan sama dengan dashboard). Atur alamat dasar tautan lewat `WA_BASE_URL` di `wa_config.php` bila perlu.

## ACC / Tolak lewat WhatsApp (nomor pimpinan 087879545233)

File: `wa_acc.php` (logika), pengaturan di `wa_config.php` (`WA_NOMOR_APPROVER`, `WA_APPROVER_PERAN`).
Berlaku untuk **Surat Masuk**, **Kegiatan/Tugas**, dan **Pengajuan** (Kebutuhan/Pemeliharaan).

1. Begitu ada data berstatus *Menunggu Persetujuan* (dari dashboard, WhatsApp, atau formulir tautan), bot mengirim
   **permintaan persetujuan** ke nomor pimpinan: ringkasan data + perintah balasan.
2. Pimpinan membalas dari nomor tersebut:
   - `ACC KGT-045` — menyetujui
   - `TOLAK KGT-045 alasan penolakan` — menolak
   - `MENUNGGU` — daftar data yang menunggu keputusan
   Pengaruhnya sama dengan tombol di dashboard (Surat Masuk di-ACC → Kegiatan terbentuk otomatis; Kegiatan ditolak →
   data modul turunannya dihapus; Pengajuan Pemeliharaan Kendaraan disetujui → kondisi kendaraan ikut berubah).
3. **Keputusan pertama menang.** Data yang sudah di-ACC/ditolak lewat WhatsApp tidak punya tombol ACC/Tolak lagi di
   dashboard; data yang sudah diputuskan di dashboard ditolak bila dibalas ACC/TOLAK lewat WhatsApp. `api.php` juga
   menjaga di sisi server supaya halaman dashboard yang belum diperbarui tidak menimpa keputusan yang sudah final.
4. **Notifikasi ke pengirim**: setiap keputusan (lewat WhatsApp maupun dashboard) dikirim ke pengaju berisi
   *Disetujui/Ditolak oleh siapa*, alasan (bila ditolak), dan jalurnya (WhatsApp/Dashboard). Bila keputusan diambil di
   dashboard, pimpinan juga diberi tahu bahwa balasan WhatsApp tidak berlaku lagi.

Catatan:
- Nomor pengirim ditemukan dari `wa_pengirim` (data yang diinput via WA/formulir) atau dari kecocokan **nama pengaju
  dengan Daftar Anggota** (kolom Nomor WhatsApp). Pengaju yang namanya/nomornya tidak ada di Daftar Anggota tidak
  mendapat notifikasi (tercatat di `data/wa_log.txt`).
- Nama pimpinan yang tercatat diambil dari Daftar Anggota berdasarkan nomor WhatsApp-nya; peran = `WA_APPROVER_PERAN`.
- Saat pertama kali dipasang, data yang sudah ada hanya dicatat sebagai baseline (tabel `acc_notif`): tidak ada
  pesan massal untuk data lama. Gunakan `MENUNGGU` untuk melihat data lama yang masih menunggu.
- Notifikasi dikirim setelah respons selesai (tidak memperlambat dashboard) dan idempoten (tidak terkirim dua kali).
- Pastikan webhook Fonnte aktif (lihat bagian Input data lewat WhatsApp) agar balasan ACC/TOLAK sampai ke server.

## Modul Potensi via WhatsApp (formulir tautan + lampiran file/link)
- Ketik **FORM POTENSI** di WhatsApp: bot membalas tautan formulir (sekali pakai). Isian: Nama (wajib), Kategori, Kontak, No Kontak, Alamat, Wilayah, Kompetensi, Tersertifikasi, Belum Tersertifikasi, Status, Alut & Sapras, Keterangan.
- Lampiran bersifat opsional dan **tidak harus PDF**: pilih 1 foto/PDF **atau** tempel **link** (Google Drive, YouTube, dll.). Hanya boleh salah satu (maks. 1 lampiran per tautan).
- Cukup ketik **POTENSI** saja di WhatsApp: bot langsung membalas tautan formulir (sama seperti PINJAM). Untuk input lewat chat, kirim pesan berawalan **POTENSI** diikuti isian (contoh: ketik *FORMAT POTENSI*). Setelah tersimpan, bot membalas ID + tautan lampiran yang juga menerima file atau link.
- Link lampiran disimpan di `lampiran_files` (type `link`, tanpa file di server), tampil di detail data Potensi dengan tombol **Buka Link**. Hanya http/https yang diterima. Nama Potensi yang sama tidak dicatat dua kali.
- Lampiran berupa link hanya aktif untuk modul Potensi (konstanta `WL_LINK_DBKEYS` di `wa_lampiran.php`).

## Pengingat penugasan otomatis (H-3)
File: `wa_pengingat.php`. Anggota yang bertugas pada kegiatan yang sudah **Disetujui** dan belum **Selesai** otomatis menerima pengingat WhatsApp mulai 3 hari sebelum tanggal pelaksanaan (H-3 sampai hari H), sekali per anggota per tanggal pelaksanaan. Admin tidak perlu mengirim ulang info tugas.
- Isi pengingat: ID, nama, tanggal, jam, lokasi, daftar anggota, dan **tautan upload bukti** (dibuat otomatis bila belum ada / sudah kedaluwarsa).
- Anggota yang ditambahkan belakangan otomatis ikut dikirimi. Jadwal diubah -> pengingat untuk tanggal baru dikirim lagi.
- **Anggota yang diganti/dikeluarkan tidak bisa mengupload**: tautan lamanya (update maupun laporan) ditolak dengan pesan "sudah tidak termasuk anggota yang bertugas". Bila anggota dimasukkan kembali, tautan yang masih berlaku bisa dipakai lagi.
- Pemicu otomatis: setelah respons dashboard/webhook dan saat dashboard terbuka (dibatasi sekali per 10 menit). Agar tetap jalan walau dashboard tidak dibuka, pasang cron: `*/15 * * * * php /path/ke/basarnas-dashboard/wa_pengingat.php`
- Opsional di `wa_config.php`: `define('WA_PENGINGAT_HARI', 3);` untuk mengubah jumlah hari. Pengiriman yang gagal dicoba ulang maksimal 3 kali per anggota. Catatan di `data/wa_log.txt` (awalan `PENGINGAT`).


## Notifikasi perubahan data (lonceng)
- Lonceng muncul di header, di samping nama akun, **hanya untuk peran Admin dan Admin Utama** (dicek ulang di server pada setiap permintaan).
- Dicatat: tambah / ubah / hapus data di semua modul oleh **WA Bot** (pesan WA, ACC/TOLAK via WA, form & upload dari tautan WA), **Admin**, dan **Anggota** (termasuk KKS/KSD/KSO/KSB) lewat dashboard.
- Perubahan buatan sendiri tidak muncul di lonceng akun itu. Status "dibaca" disimpan per akun.
- Jumlah belum dibaca ikut polling sinkronisasi (4 detik; 15 detik di InfinityFree), jadi lonceng bergoyang & angka berubah tanpa refresh.
- Klik notifikasi membuka menu terkait (Kegiatan/Tugas & Surat Masuk langsung membuka detailnya).
- Edit berulang pada data yang sama oleh orang yang sama dalam 5 menit digabung jadi satu notifikasi; impor massal (>10 data) diringkas satu baris. Notifikasi disimpan 60 hari.
- Perubahan otomatis sistem (status kirim WA, pengingat, migrasi data) tidak dicatat.
