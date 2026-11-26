<?php
/**
 * ACC / TOLAK lewat WhatsApp (Dashboard BASARNAS).
 *
 * Alur:
 *  1. Ada data yang menunggu persetujuan (Surat Masuk, Kegiatan/Tugas, Pengajuan) -> bot mengirim permintaan ACC
 *     ke nomor pimpinan (WA_NOMOR_APPROVER) berisi ringkasan + perintah balasan.
 *  2. Pimpinan membalas "ACC <ID>" atau "TOLAK <ID> alasan" -> keputusan diterapkan (wa_webhook.php).
 *  3. Keputusan PERTAMA (WhatsApp atau dashboard) bersifat final: tombol ACC/Tolak di dashboard hilang untuk data itu,
 *     dan balasan ACC/TOLAK di WhatsApp untuk data yang sudah diputuskan di dashboard ditolak.
 *     api.php juga menjaga di sisi server agar halaman dashboard yang belum diperbarui tidak menimpa keputusan.
 *  4. Setiap keputusan (dari WhatsApp maupun dashboard) diberitahukan ke pengirim/pengaju: sudah di-ACC / ditolak oleh siapa.
 *
 * Pengiriman notifikasi dilakukan oleh acc_sweep() yang idempoten (tabel acc_notif), dipanggil setelah respons
 * dikirim (acc_sweep_nanti) oleh api.php (simpan dashboard), wa_webhook.php dan u.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/wa_inti.php';

/** tipe => [kunci data, kolom status, label, prefix ID]. */
const ACC_TIPE = [
    'surat'     => ['key' => 'surat_masuk',  'status' => 'status',             'label' => 'Surat Masuk',     'prefix' => 'SM'],
    'kegiatan'  => ['key' => 'kegiatan',     'status' => 'status_persetujuan', 'label' => 'Kegiatan/Tugas',  'prefix' => 'KGT'],
    'pengajuan' => ['key' => 'pemeliharaan', 'status' => 'status_persetujuan', 'label' => 'Pengajuan',       'prefix' => 'PGJ'],
    // Peminjaman: penyetuju sendiri (WA_NOMOR_APPROVER_PINJAM). Kolom `status` data = Menunggu -> Dipinjam / Ditolak;
    // `status_persetujuan` menyimpan keputusan (Menunggu Persetujuan / Disetujui / Ditolak) agar penjaga keputusan tetap seragam.
    'peminjaman' => ['key' => 'peminjaman',  'status' => 'status_persetujuan', 'label' => 'Peminjaman',      'prefix' => 'PMJ'],
];
const ACC_MENUNGGU = 'Menunggu Persetujuan';

/* ============================== Utilitas ============================== */

/** Baca/tulis kolom pada baris yang bisa berupa array atau objek (data pengajuan disimpan sebagai objek). */
function acc_g(mixed $r, string $k, mixed $d = ''): mixed
{
    if (is_object($r)) return $r->$k ?? $d;
    return is_array($r) ? ($r[$k] ?? $d) : $d;
}
function acc_s(mixed &$r, string $k, mixed $v): void
{
    if (is_object($r)) $r->$k = $v; else $r[$k] = $v;
}
function acc_unset(mixed &$r, string $k): void
{
    if (is_object($r)) unset($r->$k); else unset($r[$k]);
}

/**
 * Nomor yang berwenang ACC/TOLAK lewat WhatsApp (sudah dinormalisasi 62...).
 * $tipe 'peminjaman' = penyetuju peminjaman (Firman); tipe lain = pimpinan (WA_NOMOR_APPROVER); '' = semua penyetuju.
 */
function acc_approvers(string $tipe = ''): array
{
    $daftar = $tipe === 'peminjaman' ? WA_NOMOR_APPROVER_PINJAM : ($tipe === '' ? array_merge(WA_NOMOR_APPROVER, WA_NOMOR_APPROVER_PINJAM) : WA_NOMOR_APPROVER);
    $o = [];
    foreach ($daftar as $n) { $n = wa_nomor((string)$n); if ($n !== '') $o[$n] = $n; }
    return array_values($o);
}
/** Apakah nomor ini penyetuju (untuk $tipe tertentu; tanpa $tipe = penyetuju jenis apa pun). */
function acc_is_approver(string $nomor, string $tipe = ''): bool { return $nomor !== '' && in_array($nomor, acc_approvers($tipe), true); }
/** Tipe data yang boleh diputuskan oleh nomor ini. */
function acc_tipe_boleh(string $nomor): array
{
    return array_values(array_filter(array_keys(ACC_TIPE), static fn(string $t): bool => acc_is_approver($nomor, $t)));
}

/** Status persetujuan sebuah baris. Pengajuan lama tanpa status dianggap Disetujui (seperti pgjSP() di dashboard). */
function acc_status(string $tipe, mixed $r): string
{
    $s = (string)acc_g($r, ACC_TIPE[$tipe]['status'], '');
    return ($s === '' && in_array($tipe, ['pengajuan', 'peminjaman'], true)) ? 'Disetujui' : $s;
}
/** Pengajuan lama (tanpa status tersimpan) tidak pernah diminta persetujuannya. */
function acc_status_eksplisit(string $tipe, mixed $r): bool { return (string)acc_g($r, ACC_TIPE[$tipe]['status'], '') !== ''; }

/** Semua data yang punya alur persetujuan: [[tipe, indeks, baris], ...]. */
function acc_semua(array $db): array
{
    $o = [];
    foreach (ACC_TIPE as $tipe => $t) {
        $rows = $db[$t['key']] ?? [];
        if (!is_array($rows)) continue;
        foreach ($rows as $i => $r) if ((string)acc_g($r, 'id', '') !== '') $o[] = [$tipe, $i, $r];
    }
    return $o;
}

/** Ubah "kgt 7" / "KGT-007" menjadi [PREFIX, nomor]. */
function acc_parse_id(string $teks): ?array
{
    if (!preg_match('/^[\s:\-#]*([A-Za-z]{2,5})[\s\-]*0*(\d{1,6})\b\s*(.*)$/su', $teks, $m)) return null;
    $pre = strtoupper($m[1]);
    if ($pre === 'KEG') $pre = 'KGT';
    return [$pre, (int)$m[2], trim($m[3])];
}

/** Cari data berdasarkan ID di ketiga modul. Kembalikan [tipe, indeks] atau null. */
function acc_cari(array $db, string $pre, int $no): ?array
{
    foreach (acc_semua($db) as [$tipe, $i, $r]) {
        if (preg_match('/^([A-Za-z]+)-0*(\d+)$/', (string)acc_g($r, 'id', ''), $x) && strtoupper($x[1]) === $pre && (int)$x[2] === $no) return [$tipe, $i];
    }
    return null;
}

/** Identitas pimpinan yang memutuskan: nama dari Daftar Anggota (berdasar nomor WA), peran dari konfigurasi. */
function acc_identitas_approver(array $db, string $nomor, string $namaPush = '', string $tipe = ''): array
{
    $pinjam = $tipe === 'peminjaman' || ($tipe === '' && acc_is_approver($nomor, 'peminjaman') && !acc_is_approver($nomor, 'kegiatan'));
    $nama = '';
    foreach ($db['anggota'] ?? [] as $a) {
        foreach (preg_split('/[,;\/\n]+/', (string)acc_g($a, 'whatsapp', '')) ?: [] as $no) {
            if (wa_nomor($no) === $nomor && $nomor !== '') { $nama = (string)acc_g($a, 'nama', ''); break 2; }
        }
    }
    if ($nama === '' && $pinjam) $nama = WA_NAMA_APPROVER_PINJAM;
    if ($nama === '') $nama = trim($namaPush) !== '' ? trim($namaPush) : $nomor;
    return ['nama' => $nama, 'peran' => $pinjam ? WA_APPROVER_PERAN_PINJAM : WA_APPROVER_PERAN, 'nomor' => $nomor];
}

/** Nomor WA pengirim/pengaju sebuah data: dari wa_pengirim (input via WA) atau dicocokkan lewat nama di Daftar Anggota. */
function acc_pengirim(array $db, mixed $r): array
{
    $oleh = acc_g($r, 'diajukan_oleh', null);
    $nama = trim((string)(is_array($oleh) || is_object($oleh) ? acc_g($oleh, 'nama', '') : (string)$oleh));
    $nomor = wa_nomor((string)acc_g($r, 'wa_pengirim', ''));
    $cari = static function (array $db, string $nm): string {
        $n = wa_norm_nama($nm);
        if ($n === '' || $nm === '-') return '';
        foreach ($db['anggota'] ?? [] as $a) {
            if (wa_norm_nama((string)acc_g($a, 'nama', '')) !== $n) continue;
            foreach (preg_split('/[,;\/\n]+/', (string)acc_g($a, 'whatsapp', '')) ?: [] as $no) if (wa_nomor($no) !== '') return wa_nomor($no);
        }
        return '';
    };
    if ($nomor === '') $nomor = $cari($db, $nama);
    // Peminjaman yang diinput lewat dashboard: bila yang menginput tak punya nomor WA, coba nomor peminjamnya.
    $peminjam = trim((string)acc_g($r, 'nama_peminjam', ''));
    if ($nomor === '' && $peminjam !== '') $nomor = $cari($db, $peminjam);
    if ($nama === '') $nama = $peminjam;
    return [$nama, $nomor];
}

/** Ringkasan isi data untuk pesan WA. */
function acc_ringkas(string $tipe, mixed $r): array
{
    $v = static fn(string $k): string => trim((string)acc_g($r, $k, ''));
    $tgl = static fn(string $k): string => wa_tgl_id($v($k) !== '' ? $v($k) : null);
    $o = [];
    switch ($tipe) {
        case 'kegiatan':
            $o[] = 'Nama: ' . ($v('nama') ?: '-');
            $o[] = 'Jenis: ' . ($v('jenis_label') ?: '-');
            $a = substr($v('tanggal'), 0, 10);
            $b = substr($v('tanggal_selesai'), 0, 10);
            $o[] = 'Tanggal: ' . ($b !== '' && $b !== $a
                ? $tgl('tanggal') . ' s.d. ' . $tgl('tanggal_selesai')
                : $tgl('tanggal') . ' (1 hari)')
                . ($v('waktu') !== '' ? ', pukul ' . $v('waktu') : '');
            $o[] = 'Lokasi: ' . ($v('lokasi') ?: '-');
            $o[] = 'PJ: ' . ($v('penanggung_jawab') ?: '-');
            if ($v('jumlah_dibutuhkan') !== '') $o[] = 'Jumlah anggota: ' . $v('jumlah_dibutuhkan');
            break;
        case 'surat':
            $o[] = 'Nomor: ' . ($v('nomor_surat') ?: '-');
            $o[] = 'Asal: ' . ($v('asal_surat') ?: '-');
            $o[] = 'Perihal: ' . ($v('perihal') ?: '-');
            $o[] = 'Jenis: ' . ($v('jenis_label') ?: '-');
            if ($v('tanggal_kegiatan') !== '') $o[] = 'Tanggal kegiatan: ' . $tgl('tanggal_kegiatan') . ($v('waktu') !== '' ? ' ' . $v('waktu') : '');
            if ($v('lokasi') !== '') $o[] = 'Lokasi: ' . $v('lokasi');
            break;
        case 'peminjaman':
            $o[] = 'Peminjam: ' . ($v('nama_peminjam') ?: '-');
            $o[] = ($v('jenis_barang') ?: 'Barang') . ': ' . ($v('nama_barang') ?: '-') . ' x' . ((int)acc_g($r, 'jumlah', 1) ?: 1);
            $o[] = 'Tanggal pinjam: ' . $tgl('tanggal');
            if ($v('tanggal_kembali_rencana') !== '') $o[] = 'Rencana kembali: ' . $tgl('tanggal_kembali_rencana');
            if ($v('keperluan') !== '') $o[] = 'Keperluan: ' . $v('keperluan');
            if ($v('penanggung_jawab') !== '') $o[] = 'PJ: ' . $v('penanggung_jawab');
            break;
        case 'pengajuan':
            $kat = $v('kategori_pengajuan') ?: 'Pemeliharaan';
            $o[] = 'Kategori: ' . $kat;
            $o[] = ($v('jenis_barang') ?: 'Barang') . ': ' . ($v('nama_barang') ?: '-');
            if ($kat === 'Kebutuhan') {
                if ($v('jumlah_kebutuhan') !== '') $o[] = 'Jumlah: ' . $v('jumlah_kebutuhan');
                if ($v('tingkat_urgensi') !== '') $o[] = 'Urgensi: ' . $v('tingkat_urgensi');
                if ($v('alasan') !== '') $o[] = 'Alasan: ' . $v('alasan');
            } else {
                if ($v('jenis_pemeliharaan') !== '') $o[] = 'Jenis pemeliharaan: ' . $v('jenis_pemeliharaan');
                if ($v('uraian') !== '') $o[] = 'Uraian: ' . $v('uraian');
            }
            break;
    }
    return $o;
}

/** Siapa yang memutuskan (teks) + lewat mana, dibaca dari data yang sudah diputuskan. */
function acc_pemutus(string $tipe, mixed $r): string
{
    $teks = '';
    if ($tipe === 'kegiatan') {
        $p = acc_g($r, 'disetujui_oleh', null);
        $arr = is_array($p) && array_is_list($p) ? $p : ($p ? [$p] : []);
        $t = [];
        foreach ($arr as $x) { $nm = trim((string)acc_g($x, 'nama', '')); $pr = trim((string)acc_g($x, 'peran', '')); if ($nm !== '' || $pr !== '') $t[] = ($nm !== '' ? $nm : '-') . ($pr !== '' ? " ($pr)" : ''); }
        $teks = implode(', ', $t);
    } elseif ($tipe === 'pengajuan' || $tipe === 'peminjaman') {
        $nm = trim((string)acc_g($r, 'acc_nama', '')); $pr = trim((string)acc_g($r, 'acc_oleh', ''));
        $teks = $nm !== '' ? $nm . ($pr !== '' ? " ($pr)" : '') : $pr;
    }
    if ($teks === '') {
        $ket = (string)acc_g($r, 'keterangan_persetujuan', '');
        if (preg_match('/(?:Di-?ACC|Disetujui|Ditolak) oleh (.+?)(?: pada |: |\.$|$)/u', $ket, $m)) $teks = trim($m[1]);
    }
    if ($teks === '' && $tipe === 'surat') {
        $roles = array_map(fn($a) => (string)acc_g($a, 'role', ''), (array)acc_g($r, 'acc', []));
        $teks = implode(', ', array_filter($roles));
    }
    $via = (string)acc_g($r, 'acc_via', '');
    return ($teks !== '' ? $teks : 'pimpinan') . ($via !== '' ? " — via $via" : ' — via Dashboard');
}

/** Alasan penolakan (jika ada) dari data yang ditolak. */
function acc_alasan(string $tipe, mixed $r): string
{
    if (in_array($tipe, ['pengajuan', 'peminjaman'], true) && trim((string)acc_g($r, 'alasan_penolakan', '')) !== '') return trim((string)acc_g($r, 'alasan_penolakan', ''));
    return preg_match('/Ditolak oleh [^:]+: (.+)$/su', (string)acc_g($r, 'keterangan_persetujuan', ''), $m) ? trim($m[1]) : '';
}

/* ============================== Penerapan keputusan (dari WhatsApp) ============================== */

/** Hitung stok Peralatan yang masih tersedia (sama dengan pemeriksaan di accPengajuan() dashboard). */
function acc_sisa_stok(array $db, string $nama, string $exceptId): ?int
{
    $n = wa_norm_nama($nama);
    $alat = null;
    foreach ($db['peralatan'] ?? [] as $a) if (wa_norm_nama((string)acc_g($a, 'nama_barang', '')) === $n) { $alat = $a; break; }
    if (!$alat) return null;
    $dipinjam = 0; $dipelihara = 0;
    foreach ($db['peminjaman'] ?? [] as $p) {
        if (acc_g($p, 'jenis_barang') === 'Peralatan' && wa_norm_nama((string)acc_g($p, 'nama_barang', '')) === $n
            && acc_g($p, 'status') !== 'Dikembalikan' && acc_g($p, 'tanggal_kembali') === '') $dipinjam += (int)acc_g($p, 'jumlah', 1) ?: 1;
    }
    foreach ($db['pemeliharaan'] ?? [] as $p) {
        if ((string)acc_g($p, 'id') === $exceptId) continue;
        if (acc_status('pengajuan', $p) === 'Disetujui' && (acc_g($p, 'kategori_pengajuan') ?: 'Pemeliharaan') === 'Pemeliharaan'
            && acc_g($p, 'jenis_barang') === 'Peralatan' && wa_norm_nama((string)acc_g($p, 'nama_barang', '')) === $n
            && acc_g($p, 'status') === 'Sedang Dikerjakan') $dipelihara += (float)acc_g($p, 'jumlah', 1) ?: 1;
    }
    return (int)floor((float)acc_g($alat, 'jumlah_baik', 0) - $dipinjam - $dipelihara);
}

/** Pengajuan Pemeliharaan Kendaraan yang disetujui mengubah kondisi kendaraan (syncKendaraanDariPemeliharaan di dashboard). */
function acc_sync_kendaraan(array &$db, mixed $r, array $oleh): void
{
    if (acc_g($r, 'jenis_barang') !== 'Kendaraan' || (acc_g($r, 'kategori_pengajuan') ?: 'Pemeliharaan') !== 'Pemeliharaan') return;
    $n = wa_norm_nama((string)acc_g($r, 'nama_barang', ''));
    foreach ($db['kendaraan'] ?? [] as $i => $k) {
        if (wa_norm_nama((string)acc_g($k, 'nama_kendaraan', '')) !== $n) continue;
        $kond = (string)acc_g($k, 'kondisi', '');
        if ($kond === 'Scrap') return;
        $baru = $kond;
        $st = (string)acc_g($r, 'status', '');
        if ($st === 'Sedang Dikerjakan') $baru = 'Unserviceable';
        elseif ($st === 'Selesai') { $ks = (string)acc_g($r, 'kondisi_setelah', ''); $baru = $ks === 'Baik / Layak Pakai' ? 'Serviceable' : ($ks !== '' ? 'Unserviceable' : $kond); }
        if ($baru !== $kond) {
            $db['kendaraan'][$i]['kondisi'] = $baru;
            $db['kendaraan'][$i]['diperbarui_oleh'] = ['nama' => $oleh['nama'], 'peran' => $oleh['peran']];
            $db['kendaraan'][$i]['diperbarui_waktu'] = wa_now_str();
        }
        return;
    }
}

/** Surat Masuk disetujui -> Kegiatan/Tugas terbentuk otomatis (padanan buatKegiatanDariSurat di dashboard). */
function acc_buat_kegiatan_dari_surat(array &$db, mixed $sm, array $oleh, string $ket): string
{
    $rows = wa_rows($db, 'kegiatan');
    $jenis = (string)acc_g($sm, 'jenis', 'lainnya');
    $now = wa_now_str();
    $k = [
        'id' => wa_next_id('KGT', $rows), 'nomor' => str_pad((string)(count($rows) + 1), 3, '0', STR_PAD_LEFT) . '/KGT/' . date('Y'),
        'jenis' => $jenis, 'jenis_label' => (string)acc_g($sm, 'jenis_label', WA_JENIS_KEGIATAN[$jenis] ?? 'Lainnya'),
        'nama' => (string)acc_g($sm, 'perihal', ''), 'tanggal' => (string)acc_g($sm, 'tanggal_kegiatan', ''), 'tanggal_selesai' => (string)acc_g($sm, 'tanggal_kegiatan', ''),
        'waktu' => (string)acc_g($sm, 'waktu', ''), 'lokasi' => (string)acc_g($sm, 'lokasi', ''), 'penanggung_jawab' => (string)acc_g($sm, 'penanggung_jawab', ''),
        'no_hp' => (string)acc_g($sm, 'no_hp', ''), 'deskripsi' => (string)acc_g($sm, 'deskripsi', ''), 'jumlah_dibutuhkan' => max(1, (int)acc_g($sm, 'jumlah_dibutuhkan', 1)),
        'status_persetujuan' => 'Disetujui', 'keterangan_persetujuan' => $ket, 'anggota_ids' => [],
        'status_pelaksanaan' => 'Draft', 'status_wa' => 'Belum Terkirim', 'wa_log' => [], 'dokumentasi' => '',
        'file_pdf' => acc_g($sm, 'file_pdf', null), 'surat_id' => (string)acc_g($sm, 'id', ''),
        'diajukan_oleh' => acc_g($sm, 'diajukan_oleh', null), 'disetujui_oleh' => ['nama' => $oleh['nama'], 'peran' => $oleh['peran']],
        'acc_via' => 'WhatsApp', 'acc_nomor' => $oleh['nomor'], 'dibuat' => $now, 'diperbarui' => $now,
    ];
    if ((string)acc_g($sm, 'wa_pengirim', '') !== '') { $k['wa_pengirim'] = (string)acc_g($sm, 'wa_pengirim', ''); $k['sumber'] = 'WhatsApp'; }
    $linked = wa_autofill($db, $jenis, $k);
    if ($linked) $k['linked_record_id'] = $linked;
    $db['kegiatan'] = array_merge($rows, [$k]);
    return $k['id'];
}

/**
 * Terapkan ACC/TOLAK dari WhatsApp. $db diubah langsung.
 * Kembalikan [berhasil(bool), pesan balasan untuk pimpinan].
 */
function acc_putuskan(array &$db, string $tipe, int $idx, bool $setuju, string $alasan, array $oleh): array
{
    $t = ACC_TIPE[$tipe];
    $r = &$db[$t['key']][$idx];
    $id = (string)acc_g($r, 'id', '');
    $st = acc_status($tipe, $r);
    if ($st !== ACC_MENUNGGU) {
        return [false, "ℹ️ {$t['label']} $id sudah *$st* oleh " . acc_pemutus($tipe, $r) . ".\nKeputusan yang sudah final tidak dapat diubah lagi."];
    }
    $now = wa_now_str();
    $ya = $setuju ? 'Disetujui' : 'Ditolak';
    $alasan = trim($alasan) !== '' ? trim($alasan) : '(tanpa keterangan)';
    $orang = "{$oleh['peran']} ({$oleh['nama']})";
    $extra = '';

    if ($tipe === 'pengajuan' && $setuju
        && (acc_g($r, 'kategori_pengajuan') ?: 'Pemeliharaan') === 'Pemeliharaan' && acc_g($r, 'jenis_barang') === 'Peralatan' && acc_g($r, 'status') === 'Sedang Dikerjakan') {
        $sisa = acc_sisa_stok($db, (string)acc_g($r, 'nama_barang', ''), $id);
        if ($sisa !== null && ((float)acc_g($r, 'jumlah', 1) ?: 1) > $sisa)
            return [false, "⚠️ Pengajuan $id belum bisa di-ACC: stok tersedia hanya " . max(0, $sisa) . ", jumlah unit melebihi stok. Ubah pengajuan di dashboard dulu."];
    }

    acc_s($r, 'acc_via', 'WhatsApp');
    acc_s($r, 'acc_nomor', $oleh['nomor']);
    switch ($tipe) {
        case 'surat':
            acc_s($r, 'status', $ya);
            acc_s($r, 'diperbarui', $now);
            if ($setuju) {
                $acc = (array)acc_g($r, 'acc', []);
                $acc[] = ['role' => $oleh['peran'], 'nama' => $oleh['nama'], 'waktu' => $now];
                acc_s($r, 'acc', $acc);
                $ket = "Di-ACC oleh {$oleh['peran']} ({$oleh['nama']}) pada $now.";
                acc_s($r, 'keterangan_persetujuan', $ket);
                $kid = acc_buat_kegiatan_dari_surat($db, $r, $oleh, "Disetujui (ACC {$oleh['peran']}) berdasarkan Surat Masuk $id nomor " . acc_g($r, 'nomor_surat', '') . '.');
                $r = &$db[$t['key']][$idx]; // $db['kegiatan'] berubah; referensi surat tetap dipegang ulang
                acc_s($r, 'kegiatan_id', $kid);
                $extra = "\nKegiatan/Tugas *$kid* terbentuk otomatis.";
            } else {
                acc_s($r, 'keterangan_persetujuan', "Ditolak oleh {$oleh['peran']} ({$oleh['nama']}): $alasan");
            }
            break;
        case 'kegiatan':
            acc_s($r, 'status_persetujuan', $ya);
            acc_s($r, 'disetujui_oleh', ['nama' => $oleh['nama'], 'peran' => $oleh['peran']]);
            acc_s($r, 'diperbarui', $now);
            if ($setuju) {
                acc_s($r, 'keterangan_persetujuan', "Disetujui oleh {$oleh['peran']} pada $now.");
            } else {
                acc_s($r, 'keterangan_persetujuan', "Ditolak oleh {$oleh['peran']}: $alasan");
                acc_s($r, 'status_pelaksanaan', 'Draft');
                // Data di modul jenis yang terbentuk otomatis dari kegiatan ini ikut dihapus (seperti rejectKegiatan di dashboard).
                $jn = (string)acc_g($r, 'jenis', ''); $lid = (string)acc_g($r, 'linked_record_id', '');
                foreach (array_keys(WA_AUTOFILL) as $mk) {
                    $mr = wa_rows($db, $mk);
                    $baru = array_values(array_filter($mr, fn($x) => !(($mk === $jn && $lid !== '' && acc_g($x, 'id') === $lid) || acc_g($x, 'dari_kegiatan_id') === $id)));
                    if (count($baru) !== count($mr)) $db[$mk] = $baru;
                }
                acc_s($r, 'linked_record_id', '');
            }
            break;
        case 'peminjaman':
            acc_s($r, 'status_persetujuan', $ya);
            acc_s($r, 'status', $setuju ? 'Dipinjam' : 'Ditolak'); // status yang tampil di dashboard
            acc_s($r, 'acc_oleh', $oleh['peran']);
            acc_s($r, 'acc_nama', $oleh['nama']);
            acc_s($r, 'acc_waktu', $now);
            acc_s($r, 'diperbarui_oleh', ['nama' => $oleh['nama'], 'peran' => $oleh['peran']]);
            acc_s($r, 'diperbarui_waktu', $now);
            if ($setuju) {
                acc_s($r, 'keterangan_persetujuan', "Disetujui oleh {$oleh['peran']} ({$oleh['nama']}) pada $now.");
                acc_s($r, 'alasan_penolakan', '');
            } else {
                acc_s($r, 'alasan_penolakan', $alasan);
                acc_s($r, 'keterangan_persetujuan', "Ditolak oleh {$oleh['peran']} ({$oleh['nama']}) pada $now: $alasan");
            }
            break;
        case 'pengajuan':
            acc_s($r, 'status_persetujuan', $ya);
            acc_s($r, 'acc_oleh', $oleh['peran']);
            acc_s($r, 'acc_nama', $oleh['nama']);
            acc_s($r, 'acc_waktu', $now);
            if ($setuju) {
                acc_s($r, 'keterangan_persetujuan', "Disetujui oleh {$oleh['peran']} ({$oleh['nama']}) pada $now.");
                acc_s($r, 'alasan_penolakan', '');
                acc_sync_kendaraan($db, $r, $oleh);
            } else {
                acc_s($r, 'alasan_penolakan', $alasan);
                acc_s($r, 'keterangan_persetujuan', "Ditolak oleh {$oleh['peran']} ({$oleh['nama']}) pada $now: $alasan");
            }
            break;
    }
    return [true, ($setuju ? "✅ *$id DISETUJUI*" : "❌ *$id DITOLAK*") . "\n{$t['label']}: " . preg_replace('/^[^:]+: /u', '', acc_ringkas($tipe, $r)[$tipe === 'peminjaman' ? 1 : 0] ?? '')
        . ($setuju ? '' : "\nAlasan: $alasan") . $extra
        . ($tipe === 'peminjaman' ? ($setuju ? "\nStatus di dashboard: *Dipinjam*" : "\nStatus di dashboard: *Ditolak*") : '')
        . "\n\nPengirim akan diberi tahu. Data ini sudah final: tombol ACC/Tolak di dashboard tidak aktif lagi."];
}

/** Daftar data yang menunggu keputusan (perintah MENUNGGU). */
function acc_daftar_menunggu(array $db, string $nomor = ''): string
{
    $baris = []; $total = 0;
    $boleh = $nomor !== '' ? acc_tipe_boleh($nomor) : array_keys(ACC_TIPE);
    foreach (acc_semua($db) as [$tipe, $i, $r]) {
        if (!in_array($tipe, $boleh, true)) continue;
        if (!acc_status_eksplisit($tipe, $r) || acc_status($tipe, $r) !== ACC_MENUNGGU) continue;
        $total++;
        if (count($baris) >= 15) continue;
        $judul = (string)acc_g($r, $tipe === 'surat' ? 'perihal' : ($tipe === 'kegiatan' ? 'nama' : 'nama_barang'), '');
        if ($tipe === 'peminjaman') $judul .= ' — ' . acc_g($r, 'nama_peminjam', '-');
        $baris[] = '• *' . acc_g($r, 'id') . '* (' . ACC_TIPE[$tipe]['label'] . ') ' . mb_strimwidth($judul, 0, 50, '…');
    }
    if ($total === 0) return '✅ Tidak ada data yang menunggu persetujuan.';
    return "⏳ *Menunggu persetujuan ($total)*\n" . implode("\n", $baris) . ($total > 15 ? "\n… dan " . ($total - 15) . ' lainnya (lihat dashboard)' : '')
        . "\n\nBalas *ACC <ID>* atau *TOLAK <ID> alasan*.";
}

/**
 * Uraikan satu atau beberapa ID Peminjaman di awal teks: "PMJ-005", "PMJ-005 PMJ-006", "PMJ-005, 006", "pmj 5 dan pmj 6 ...".
 * Sisa teks setelah ID terakhir = alasan. Kembalikan [daftar nomor, alasan].
 */
function acc_parse_pmj(string $teks): array
{
    $nos = [];
    $sisa = trim($teks);
    while (true) {
        if (preg_match('/^[\s,;:\-#]*(?:dan\s+|&\s*)?pmj[\s\-]*0*(\d{1,6})\b(.*)$/isu', $sisa, $m) || ($nos && preg_match('/^\s*,\s*0*(\d{1,6})\b(.*)$/su', $sisa, $m))) {
            $nos[] = (int)$m[1];
            $sisa = $m[2];
            continue;
        }
        break;
    }
    return [array_values(array_unique($nos)), trim($sisa, " \t\n\r,;:-")];
}

function acc_bantuan(string $nomor = ''): string
{
    if ($nomor !== '' && acc_tipe_boleh($nomor) === ['peminjaman']) {
        return "🔐 *Perintah penyetuju peminjaman*\n• *ACC PMJ-012* — menyetujui (status jadi *Dipinjam*); beberapa sekaligus: *ACC PMJ-012 PMJ-013*\n• *TOLAK PMJ-012 alasan* — menolak, alasan wajib dicatat (mis. TOLAK PMJ-012 stok untuk kegiatan lain)\n• *MENUNGGU* — daftar peminjaman yang menunggu keputusan\n\nKeputusan pertama (WhatsApp atau dashboard) bersifat final.";
    }
    return "🔐 *Perintah pimpinan*\n• *ACC <ID>* — menyetujui (mis. ACC KGT-045)\n• *TOLAK <ID> alasan* — menolak (mis. TOLAK KGT-045 bentrok jadwal)\n• *MENUNGGU* — daftar data yang menunggu keputusan\n\nKeputusan pertama (WhatsApp atau dashboard) bersifat final.";
}

/* ============================== Notifikasi (idempoten) ============================== */

function acc_pesan_permintaan(array $db, string $tipe, mixed $r, ?PDO $p = null): string
{
    $t = ACC_TIPE[$tipe];
    $id = (string)acc_g($r, 'id');
    [$namaPengaju] = acc_pengirim($db, $r);
    $linkSurat = ($tipe === 'surat' && $p) ? acc_link_surat($p, $id, $r) : '';
    return "🔔 *PERMINTAAN PERSETUJUAN*\n{$t['label']} · ID: *$id*\n" . implode("\n", acc_ringkas($tipe, $r))
        . "\nDiajukan oleh: " . ($namaPengaju !== '' ? $namaPengaju : '-')
        . ($linkSurat !== '' ? "\n\n📄 Lihat surat:\n$linkSurat" : '')
        . "\n\nBalas pesan ini:\n✅ *ACC $id*\n❌ *TOLAK $id alasan penolakan*\n\nKeputusan pertama (WhatsApp atau dashboard) bersifat final; setelah itu tombol ACC/Tolak di sisi lain tidak aktif.";
}

/**
 * Tautan publik (tanpa login) untuk MELIHAT file surat masuk: <alamat dasar>/u.php/<KODE>.
 * File = PDF yang diupload di dashboard (file_pdf) atau lampiran dari WhatsApp (lampiran_files). Satu tautan per surat, dipakai ulang.
 * Kembalikan '' bila surat belum punya file.
 */
function acc_link_surat(PDO $p, string $recId, mixed $r): string
{
    $cmd = '';
    $pdf = acc_g($r, 'file_pdf', null);
    if ($pdf && str_starts_with((string)acc_g($pdf, 'data', ''), 'data:application/pdf')) $cmd = 'pdf';
    else foreach ((array)acc_g($r, 'lampiran_files', []) as $f) { $fid = (string)acc_g($f, 'fid', ''); if ($fid !== '') { $cmd = $fid; break; } }
    if ($cmd === '') return '';
    try {
        wl_tabel($p);
        $cek = $p->prepare("SELECT token FROM wa_upload WHERE jenis = 'lihat_surat' AND rec_id = ? AND cmd = ? LIMIT 1");
        $cek->execute([$recId, $cmd]);
        $ada = $cek->fetchColumn();
        if ($ada) return wl_link((string)$ada);
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $now = time();
        for ($try = 0; $try < 8; $try++) {
            $t = '';
            for ($i = 0; $i < 8; $i++) $t .= $abjad[random_int(0, strlen($abjad) - 1)];
            $ins = $p->prepare('INSERT IGNORE INTO wa_upload (token, db_key, rec_id, modul, judul, nomor, nama, dibuat, kedaluwarsa, jenis, cmd, dipakai) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)');
            $ins->execute([$t, 'surat_masuk', $recId, 'Surat Masuk — Lihat Surat', $recId, '', '', $now, $now + 3650 * 86400, 'lihat_surat', $cmd]);
            if ($ins->rowCount() > 0) return wl_link($t);
        }
    } catch (Throwable $e) { wa_log('LINK LIHAT SURAT GAGAL: ' . $e->getMessage()); }
    return '';
}

/** Permintaan ACC untuk satu atau beberapa peminjaman sekaligus (satu formulir bisa berisi beberapa barang). $items = [[id, baris], ...]. */
function acc_pesan_permintaan_pinjam(array $db, array $items): string
{
    $r0 = $items[0][1];
    [$namaPengaju] = acc_pengirim($db, $r0);
    $o = "🔔 *PERMINTAAN PEMINJAMAN*\nPeminjam: *" . (acc_g($r0, 'nama_peminjam', '') ?: '-') . "*";
    if ($namaPengaju !== '' && wa_norm_nama($namaPengaju) !== wa_norm_nama((string)acc_g($r0, 'nama_peminjam', ''))) $o .= "\nDiajukan oleh: $namaPengaju";
    $o .= "\nTanggal pinjam: " . wa_tgl_id((string)acc_g($r0, 'tanggal', '') ?: null);
    if ((string)acc_g($r0, 'tanggal_kembali_rencana', '') !== '') $o .= "\nRencana kembali: " . wa_tgl_id((string)acc_g($r0, 'tanggal_kembali_rencana'));
    if ((string)acc_g($r0, 'keperluan', '') !== '') $o .= "\nKeperluan: " . acc_g($r0, 'keperluan');
    if ((string)acc_g($r0, 'penanggung_jawab', '') !== '') $o .= "\nPJ: " . acc_g($r0, 'penanggung_jawab');
    $o .= "\n\n*" . count($items) . " barang:*\n";
    foreach ($items as [$id, $r]) $o .= "• *$id* — " . (acc_g($r, 'nama_barang', '-')) . ' x' . ((int)acc_g($r, 'jumlah', 1) ?: 1) . ' (' . (acc_g($r, 'jenis_barang', 'Peralatan')) . ")\n";
    $ids = implode(' ', array_column($items, 0));
    return $o . "\nBalas pesan ini:\n✅ *ACC $ids*\n❌ *TOLAK $ids alasan penolakan*\n\nKeputusan pertama (WhatsApp atau dashboard) bersifat final; setelah itu tombol ACC/Tolak di sisi lain tidak aktif.";
}

function acc_pesan_hasil(string $tipe, mixed $r): string
{
    $t = ACC_TIPE[$tipe];
    $id = (string)acc_g($r, 'id');
    $setuju = acc_status($tipe, $r) === 'Disetujui';
    $alasan = $setuju ? '' : acc_alasan($tipe, $r);
    if ($tipe === 'peminjaman') {
        return ($setuju ? "✅ *PEMINJAMAN DISETUJUI*" : "❌ *PEMINJAMAN DITOLAK*") . "\nID: *$id*\n" . implode("\n", acc_ringkas($tipe, $r))
            . "\nStatus: *" . ($setuju ? 'Dipinjam' : 'Ditolak') . "*\n" . ($setuju ? 'Disetujui' : 'Ditolak') . ' oleh: ' . acc_pemutus($tipe, $r)
            . ($setuju ? "\n\nSetelah barang dikembalikan, balas *Kembali $id*." : "\nAlasan: " . ($alasan !== '' ? $alasan : '(tanpa keterangan)') . "\n\nStok barang dikembalikan ke daftar tersedia.");
    }
    return ($setuju ? "✅ *{$t['label']} DISETUJUI*" : "❌ *{$t['label']} DITOLAK*") . "\nID: *$id*\n" . implode("\n", acc_ringkas($tipe, $r))
        . "\n" . ($setuju ? 'Disetujui' : 'Ditolak') . ' oleh: ' . acc_pemutus($tipe, $r)
        . ($alasan !== '' ? "\nAlasan: $alasan" : '')
        . ($tipe === 'surat' && $setuju && (string)acc_g($r, 'kegiatan_id', '') !== '' ? "\nKegiatan/Tugas terbentuk: " . acc_g($r, 'kegiatan_id') : '');
}

/**
 * Kirim notifikasi yang belum terkirim. Aman dipanggil berulang/bersamaan: tiap peristiwa "diklaim" lewat tabel acc_notif
 * sebelum dikirim. Pemanggilan pertama setelah fitur dipasang hanya mencatat kondisi saat ini (tanpa mengirim apa pun),
 * agar data lama tidak membanjiri WhatsApp.
 */
/**
 * Pengaman terakhir anti-spam: pesan dengan isi yang sama ke nomor yang sama hanya boleh keluar sekali dalam $detik.
 * Atomik di database (INSERT IGNORE + UPDATE bersyarat), jadi aman walau beberapa proses berjalan bersamaan.
 * Berlaku apa pun penyebab pemicunya (kunci peristiwa berubah, data disimpan ulang, dsb.).
 */
function acc_boleh_kirim(?PDO $p, string $nomor, string $isi, int $detik): bool
{
    // Disimpan di berkas (data/wa_terkirim.json) dengan flock, BUKAN di MySQL: tetap bekerja walau tabel acc_notif
    // rusak/tanpa PRIMARY KEY/ter-reset (mis. setelah import ulang atau XAMPP dinyalakan kembali).
    $kunci = md5($nomor . "\n" . $isi);
    $now = time();
    $fh = @fopen(DATA_DIR . '/wa_terkirim.json', 'c+');
    if (!$fh) return false; // tidak bisa memastikan -> lebih baik tidak mengirim daripada spam
    try {
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) $data = [];
        foreach ($data as $k => $v) if (!is_array($v) || ($v[0] ?? 0) < $now - 172800) unset($data[$k]); // buang catatan > 2 hari
        if (isset($data[$kunci]) && ($data[$kunci][0] ?? 0) > $now - $detik) return false;
        $data[$kunci] = [$now];
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        return true;
    } finally {
        flock($fh, LOCK_UN); fclose($fh);
    }
}

/** Perbaiki tabel acc_notif bila tidak punya PRIMARY KEY/UNIQUE (INSERT IGNORE jadi tidak pernah menolak duplikat). */
function acc_notif_perbaiki(PDO $p): void
{
    try {
        $s = $p->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'acc_notif' AND constraint_type IN ('PRIMARY KEY','UNIQUE')");
        if ((int)$s->fetchColumn() > 0) return;
        $p->exec('DROP TABLE IF EXISTS acc_notif_baru');
        $p->exec(preg_replace('/\bacc_notif\b/', 'acc_notif_baru', DB_DDL_ACC_NOTIF, 1));
        $p->exec('INSERT IGNORE INTO acc_notif_baru (kunci, waktu) SELECT kunci, MIN(waktu) FROM acc_notif GROUP BY kunci');
        $p->exec('RENAME TABLE acc_notif TO acc_notif_lama, acc_notif_baru TO acc_notif');
        $p->exec('DROP TABLE acc_notif_lama');
        wa_log('acc_notif DIPERBAIKI: PRIMARY KEY ditambahkan, duplikat dibersihkan');
    } catch (Throwable $e) { wa_log('acc_notif perbaikan GALAT: ' . $e->getMessage()); }
}

const ACC_BATAS_HASIL_PER_SWEEP = 5;

function acc_sweep(): void
{
    // Hanya satu sweep berjalan pada satu waktu (saat XAMPP baru menyala banyak permintaan masuk bersamaan).
    $kunciSweep = @fopen(DATA_DIR . '/acc_sweep.lock', 'c');
    if (!$kunciSweep || !flock($kunciSweep, LOCK_EX | LOCK_NB)) { if ($kunciSweep) fclose($kunciSweep); return; }
    try { acc_sweep_jalan(); } finally { flock($kunciSweep, LOCK_UN); fclose($kunciSweep); }
}

function acc_sweep_jalan(): void
{
    $p = pdo();
    $ada = db_table_exists($p, 'acc_notif');
    db_ensure_table($p, 'acc_notif', DB_DDL_ACC_NOTIF);
    acc_notif_perbaiki($p);
    $baseline = !$ada;
    $db = load_db();
    $klaim = $p->prepare('INSERT IGNORE INTO acc_notif (kunci, waktu) VALUES (?, ?)');
    $cek = $p->prepare("SELECT COUNT(*) FROM acc_notif WHERE kunci LIKE ? ESCAPE '\\\\' AND waktu <> 'baseline'");
    $hasilBaru = 0;
    $infoDashboard = []; // [nomor => [baris, ...]] pemberitahuan keputusan via dashboard
    // Migrasi kunci F -> F2: putaran pertama hanya mencatat keputusan yang sudah ada (tanpa mengirim), agar tidak membanjiri WA.
    $baselineF = (int)$p->query("SELECT COUNT(*) FROM acc_notif WHERE kunci = 'F2|baseline'")->fetchColumn() === 0;
    $grupPmj = []; // permintaan Peminjaman satu formulir (banyak barang) dikirim sebagai satu pesan

    foreach (acc_semua($db) as [$tipe, $i, $r]) {
        $approvers = acc_approvers($tipe);
        if (!acc_status_eksplisit($tipe, $r)) continue;
        $st = acc_status($tipe, $r);
        $id = (string)acc_g($r, 'id');
        $likeId = 'M|' . $tipe . '|' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $id) . '|%';

        if ($st === ACC_MENUNGGU) {
            $stempel = (string)(acc_g($r, 'diajukan_waktu', '') ?: acc_g($r, 'dibuat', '') ?: acc_g($r, 'diperbarui', ''));
            $klaim->execute(['M|' . $tipe . '|' . $id . '|' . $stempel, $baseline ? 'baseline' : date('c')]);
            if ($klaim->rowCount() === 0 || $baseline) continue;
            if ($tipe === 'peminjaman') { // dikelompokkan: peminjam + pengirim + waktu pengajuan (menit) yang sama
                $kg = wa_norm_nama((string)acc_g($r, 'nama_peminjam', '')) . '|' . acc_g($r, 'wa_pengirim', '') . '|' . $stempel;
                $grupPmj[$kg][] = [$id, $r];
                continue;
            }
            $pesan = acc_pesan_permintaan($db, $tipe, $r, $p);
            foreach ($approvers as $no) { if (acc_boleh_kirim($p, $no, $pesan, 600)) wa_kirim($no, $pesan, true); else wa_log("ACC permintaan $id -> $no DITAHAN (pesan sama < 10 menit)"); }
            wa_log("ACC permintaan $id -> " . implode(',', $approvers) . " [kunci stempel=$stempel]");
        } elseif ($st === 'Disetujui' || $st === 'Ditolak') {
            if ($tipe === 'kegiatan' && (string)acc_g($r, 'surat_id', '') !== '') continue; // kegiatan hasil ACC surat: sudah diberitahukan lewat surat
            if (acc_g($r, 'tanpa_acc', false)) continue; // data hasil Import Excel: tidak pernah diminta persetujuan, tidak perlu notifikasi
            // Kunci stabil per (tipe, id, status, pengajuan). Teks keterangan TIDAK dipakai karena memuat jam persetujuan
            // sehingga berubah di setiap penyimpanan dan memicu notifikasi berulang (spam).
            $stempelF = (string)(acc_g($r, 'diajukan_waktu', '') ?: acc_g($r, 'dibuat', ''));
            $klaim->execute(['F2|' . $tipe . '|' . $id . '|' . $st . '|' . $stempelF, ($baseline || $baselineF) ? 'baseline' : date('c')]);
            if ($klaim->rowCount() === 0 || $baseline || $baselineF) continue;
            // Pengaman banjir: bila satu putaran menemukan terlalu banyak keputusan baru (impor/pemulihan data), jangan kirim massal.
            if (++$hasilBaru > ACC_BATAS_HASIL_PER_SWEEP) { wa_log("ACC hasil $id ($st) DITAHAN (anti-spam, > " . ACC_BATAS_HASIL_PER_SWEEP . ' per putaran)'); continue; }
            $viaWa = (string)acc_g($r, 'acc_via', '') === 'WhatsApp';
            $pesan = acc_pesan_hasil($tipe, $r);
            [, $noPengirim] = acc_pengirim($db, $r);
            $dikirim = [];
            if ($noPengirim !== '' && !($viaWa && $noPengirim === wa_nomor((string)acc_g($r, 'acc_nomor', '')))) {
                if (acc_boleh_kirim($p, $noPengirim, $pesan, 21600)) wa_kirim($noPengirim, $pesan, true);
                $dikirim[] = $noPengirim;
            }
            if (!$viaWa) { // diputuskan di dashboard: beri tahu pimpinan yang tadi menerima permintaan (dikumpulkan, dikirim 1 pesan per nomor)
                $cek->execute([$likeId]);
                if ((int)$cek->fetchColumn() > 0) {
                    foreach ($approvers as $no) if (!in_array($no, $dikirim, true))
                        $infoDashboard[$no][] = "• {$id} sudah *" . mb_strtolower($st) . "* oleh " . acc_pemutus($tipe, $r);
                }
            }
            wa_log("ACC hasil $id ($st) [stempel=$stempelF] -> pengirim " . ($noPengirim !== '' ? $noPengirim : 'tidak ada nomor WA'));
        }
    }
    foreach ($grupPmj as $items) {
        $app = acc_approvers('peminjaman');
        $pesan = acc_pesan_permintaan_pinjam($db, $items);
        foreach ($app as $no) { if (acc_boleh_kirim($p, $no, $pesan, 600)) wa_kirim($no, $pesan, true); }
        wa_log('ACC permintaan ' . implode(',', array_column($items, 0)) . ' -> ' . implode(',', $app));
    }
    foreach ($infoDashboard as $no => $baris) { // satu pesan gabungan per nomor, bukan satu pesan per data
        $baris = array_values(array_filter($baris, static fn(string $b): bool => acc_boleh_kirim($p, (string)$no, $b, 21600)));
        if (!$baris) { wa_log('INFO DASHBOARD -> ' . $no . ' DITAHAN (sudah diberitahukan < 6 jam)'); continue; }
        wa_kirim((string)$no, "ℹ️ Sudah diputuskan di dashboard:\n" . implode("\n", $baris) . "\n\nBalasan ACC/TOLAK lewat WhatsApp untuk data di atas tidak berlaku lagi.", true);
        wa_log('INFO DASHBOARD -> ' . $no . ' (' . count($baris) . ' data)');
    }
    if ($baselineF) $klaim->execute(['F2|baseline', date('c')]);
    try { acc_sweep_kembali($p, $db); } catch (Throwable $e) { wa_log('NOTIF KEMBALI GALAT: ' . $e->getMessage()); }
}

/* ============================== Notifikasi pengembalian barang ============================== */

/** Bukti pengembalian (file/link) pertama pada data peminjaman, atau null bila belum ada. */
function acc_bukti_kembali(mixed $r): ?array
{
    $fl = acc_g($r, 'lampiran_files', []);
    foreach (is_array($fl) ? $fl : [] as $f) {
        if (!acc_g($f, 'bukti_kembali', false)) continue;
        $link = (string)acc_g($f, 'link', '');
        $fid = (string)acc_g($f, 'fid', '');
        if ($link === '' && $fid === '') continue;
        return ['fid' => $fid, 'name' => (string)acc_g($f, 'name', 'bukti-pengembalian'), 'link' => $link];
    }
    return null;
}

/** Tautan publik (tanpa login) untuk MELIHAT bukti pengembalian berupa file: <alamat dasar>/u.php/<KODE>. Satu tautan per file, dipakai ulang. */
function acc_link_bukti(PDO $p, string $recId, array $b): string
{
    if ($b['link'] !== '') return $b['link'];
    if ($b['fid'] === '') return '';
    try {
        wl_tabel($p);
        $cek = $p->prepare("SELECT token FROM wa_upload WHERE jenis = 'lihat_bukti' AND rec_id = ? AND cmd = ? LIMIT 1");
        $cek->execute([$recId, $b['fid']]);
        $ada = $cek->fetchColumn();
        if ($ada) return wl_link((string)$ada);
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $now = time();
        for ($try = 0; $try < 8; $try++) {
            $t = '';
            for ($i = 0; $i < 8; $i++) $t .= $abjad[random_int(0, strlen($abjad) - 1)];
            $ins = $p->prepare('INSERT IGNORE INTO wa_upload (token, db_key, rec_id, modul, judul, nomor, nama, dibuat, kedaluwarsa, jenis, cmd, dipakai) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)');
            $ins->execute([$t, 'peminjaman', $recId, 'Peminjaman — Lihat Bukti Pengembalian', $recId, '', '', $now, $now + 3650 * 86400, 'lihat_bukti', $b['fid']]);
            if ($ins->rowCount() > 0) return wl_link($t);
        }
    } catch (Throwable $e) { wa_log('LINK LIHAT BUKTI GAGAL: ' . $e->getMessage()); }
    return '';
}

/** Nomor WA yang menerima notifikasi: peminjam (Daftar Anggota), pengirim data, dan yang mengembalikan lewat WA. */
function acc_nomor_peminjam(array $db, mixed $r): array
{
    $no = [];
    $nm = wa_norm_nama((string)acc_g($r, 'nama_peminjam', ''));
    if ($nm !== '') {
        foreach ($db['anggota'] ?? [] as $a) {
            if (wa_norm_nama((string)acc_g($a, 'nama', '')) !== $nm) continue;
            foreach (preg_split('/[,;\/\n]+/', (string)acc_g($a, 'whatsapp', '')) ?: [] as $n) if (wa_nomor($n) !== '') $no[] = wa_nomor($n);
        }
    }
    $kirim = wa_nomor((string)acc_g($r, 'wa_pengirim', ''));
    if ($kirim !== '') $no[] = $kirim;
    $via = acc_g($r, 'dikembalikan_via_wa', null);
    if ($via) { $n = wa_nomor((string)acc_g($via, 'nomor', '')); if ($n !== '') $no[] = $n; }
    return array_values(array_unique($no));
}

/** Pesan template: ID Peminjam / Nama Barang / Tanggal Dikembalikan / Bukti Pengembalian / CC. Pak Firman. */
function acc_pesan_kembali(array $items, string $judul): string
{
    $o = "📦 *$judul*\n";
    foreach ($items as [$r, $bukti]) {
        $jml = max(1, (int)acc_g($r, 'jumlah', 1));
        $nama = trim((string)acc_g($r, 'nama_peminjam', ''));
        $o .= "\nID Peminjam : " . acc_g($r, 'id', '-') . ($nama !== '' ? " ($nama)" : '')
            . "\nNama Barang : " . acc_g($r, 'nama_barang', '-') . ($jml > 1 ? " x$jml" : '')
            . "\nTanggal Dikembalikan : " . wa_tgl_id((string)acc_g($r, 'tanggal_kembali', ''))
            . "\nBukti Pengembalian : " . $bukti . "\n";
    }
    return $o . "\nCC. Pak " . WA_NAMA_APPROVER_PINJAM;
}

/**
 * Kirim notifikasi WA saat barang pinjaman dikembalikan (dari WhatsApp maupun dashboard) ke peminjam dan Pak Firman,
 * lalu kirim lagi (dengan file bukti terlampir) saat bukti pengembalian masuk. Idempoten lewat tabel acc_notif:
 * kunci K|<ID>|<tgl kembali> (pengembalian) dan B|<ID>|<fid> (bukti). Data lama ditandai "baseline" pada eksekusi pertama
 * supaya riwayat pengembalian sebelumnya tidak dikirim ulang.
 */
function acc_sweep_kembali(PDO $p, array $db): void
{
    $sudahBaseline = (int)$p->query("SELECT COUNT(*) FROM acc_notif WHERE kunci = 'K|baseline'")->fetchColumn() > 0;
    $klaim = $p->prepare('INSERT IGNORE INTO acc_notif (kunci, waktu) VALUES (?, ?)');
    if (!$sudahBaseline) $klaim->execute(['K|baseline', date('c')]);
    $firman = acc_approvers('peminjaman');
    $grup = []; $bukti = [];

    foreach (wa_rows($db, 'peminjaman') as $r) {
        $id = (string)acc_g($r, 'id', '');
        $tgl = (string)acc_g($r, 'tanggal_kembali', '');
        if ($id === '' || $tgl === '' || (string)acc_g($r, 'status', '') !== 'Dikembalikan') continue;
        $b = acc_bukti_kembali($r);
        $kK = 'K|' . $id . '|' . $tgl;
        $kB = $b ? 'B|' . $id . '|' . ($b['fid'] !== '' ? $b['fid'] : md5($b['link'])) : '';
        if (!$sudahBaseline) { $klaim->execute([$kK, 'baseline']); if ($kB !== '') $klaim->execute([$kB, 'baseline']); continue; }

        $klaim->execute([$kK, date('c')]);
        $barang = $klaim->rowCount() > 0;
        $barangBukti = false;
        if ($kB !== '') { $klaim->execute([$kB, date('c')]); $barangBukti = $klaim->rowCount() > 0; }
        if ($barang) $grup[wa_norm_nama((string)acc_g($r, 'nama_peminjam', '')) . '|' . $tgl][] = [$r, $b];
        if ($barangBukti && $b !== null) $bukti[] = [$r, $b, $barang];
    }
    if (!$grup && !$bukti) return;

    $tujuan = static fn(array $r): array => array_values(array_unique(array_filter(array_merge(acc_nomor_peminjam($db, $r), $firman))));
    $teksBukti = static function (?array $b, mixed $r) use ($p): string {
        if ($b === null) return 'Belum diunggah (menyusul)';
        $l = acc_link_bukti($p, (string)acc_g($r, 'id', ''), $b);
        return $l !== '' ? $l : 'Terlampir (pesan berikutnya)';
    };

    foreach ($grup as $items) {
        $pesan = acc_pesan_kembali(array_map(static fn($it) => [$it[0], $teksBukti($it[1], $it[0])], $items), 'BARANG SUDAH DIKEMBALIKAN');
        $no = [];
        foreach ($items as $it) $no = array_merge($no, $tujuan($it[0]));
        foreach (array_unique($no) as $n) { wa_kirim($n, $pesan); usleep(300000); }
        wa_log('NOTIF KEMBALI ' . implode(',', array_map(static fn($it) => (string)acc_g($it[0], 'id'), $items)) . ' -> ' . implode(',', array_unique($no)));
    }
    foreach ($bukti as [$r, $b, $barengPengembalian]) {
        $id = (string)acc_g($r, 'id', '');
        if ($b['link'] !== '') { // link: sudah ikut di pesan pengembalian; kirim terpisah hanya bila bukti masuk belakangan
            if ($barengPengembalian) continue;
            $pesan = acc_pesan_kembali([[$r, $b['link']]], 'BUKTI PENGEMBALIAN DITERIMA');
            foreach ($tujuan($r) as $n) { wa_kirim($n, $pesan); usleep(300000); }
            continue;
        }
        $path = UPLOAD_DIR . '/' . $b['fid'];
        $pesan = $barengPengembalian
            ? "📎 Bukti Pengembalian $id — " . acc_g($r, 'nama_barang', '-') . "\nLihat: " . $teksBukti($b, $r)
            : acc_pesan_kembali([[$r, $teksBukti($b, $r)]], 'BUKTI PENGEMBALIAN DITERIMA');
        foreach ($tujuan($r) as $n) {
            if (is_file($path)) wa_kirim_lampiran($n, $pesan, $path, $b['name']);
            else wa_kirim($n, $pesan . "\n(file bukti tidak ditemukan di server)");
            usleep(300000);
        }
        wa_log("NOTIF BUKTI KEMBALI $id -> " . implode(',', $tujuan($r)));
    }
}

/** Jalankan acc_sweep() setelah respons HTTP selesai dikirim (tidak memperlambat dashboard/webhook). */
function acc_sweep_nanti(): void
{
    static $terdaftar = false;
    if ($terdaftar) return;
    $terdaftar = true;
    ignore_user_abort(true);
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(120);
        try { acc_sweep(); } catch (Throwable $e) { wa_log('ACC SWEEP GALAT: ' . $e->getMessage()); }
        try { require_once __DIR__ . '/wa_pengingat.php'; wa_pengingat_jalankan(); } catch (Throwable $e) { wa_log('PENGINGAT GALAT: ' . $e->getMessage()); }
    });
}

/* ============================== Penjaga keputusan di sisi server ============================== */

/** Kolom persetujuan per tipe yang dilindungi setelah ada keputusan final. */
const ACC_KOLOM = [
    'surat'     => ['status', 'acc', 'keterangan_persetujuan', 'kegiatan_id', 'acc_via', 'acc_nomor'],
    'kegiatan'  => ['status_persetujuan', 'keterangan_persetujuan', 'disetujui_oleh', 'acc_via', 'acc_nomor'],
    'pengajuan' => ['status_persetujuan', 'keterangan_persetujuan', 'alasan_penolakan', 'acc_oleh', 'acc_nama', 'acc_waktu', 'acc_via', 'acc_nomor'],
    'peminjaman' => ['status_persetujuan', 'keterangan_persetujuan', 'alasan_penolakan', 'acc_oleh', 'acc_nama', 'acc_waktu', 'acc_via', 'acc_nomor'],
];

/**
 * Dipanggil api.php sebelum menyimpan snapshot dari dashboard. Data yang di server sudah Disetujui/Ditolak
 * tidak boleh berubah keputusannya oleh halaman dashboard yang belum diperbarui ("keputusan pertama menang").
 * Satu-satunya pengecualian: Pengajuan yang ditolak lalu diperbaiki & diajukan ulang (Ditolak -> Menunggu).
 * $objs = hasil json_decode non-assoc yang dipakai save_db untuk kunci tambahan (pemeliharaan).
 * Kembalikan jumlah data yang dikembalikan ke keputusan server.
 */
function acc_jaga_keputusan(array &$new, ?object $objs, array $old): int
{
    $konflik = 0;
    $suratKonflik = [];
    foreach (ACC_TIPE as $tipe => $t) {
        $key = $t['key'];
        $oldFinal = [];
        foreach ($old[$key] ?? [] as $r) {
            $id = (string)acc_g($r, 'id', '');
            if ($id !== '' && acc_status_eksplisit($tipe, $r) && in_array(acc_status($tipe, $r), ['Disetujui', 'Ditolak'], true)) $oldFinal[$id] = $r;
        }
        if (!$oldFinal) continue;
        $targets = [];
        if (isset($new[$key]) && is_array($new[$key]) && $tipe !== 'pengajuan') $targets[] = &$new[$key];
        if ($tipe === 'pengajuan' && $objs !== null && isset($objs->$key) && is_array($objs->$key)) $targets[] = &$objs->$key;
        foreach ($targets as &$rows) {
            foreach ($rows as &$r) {
                $id = (string)acc_g($r, 'id', '');
                if (!isset($oldFinal[$id])) continue;
                $o = $oldFinal[$id];
                if (in_array($tipe, ['pengajuan', 'peminjaman'], true) && acc_status($tipe, $r) === ACC_MENUNGGU && acc_status($tipe, $o) === 'Ditolak') continue; // diajukan ulang
                $beda = false;
                foreach (ACC_KOLOM[$tipe] as $k) if (json_encode(acc_g($r, $k, null)) !== json_encode(acc_g($o, $k, null))) { $beda = true; break; }
                // Peminjaman: kolom `status` juga dijaga (Ditolak harus tetap Ditolak; yang sudah di-ACC tidak boleh kembali Menunggu/Ditolak).
                // Perubahan Dipinjam -> Dikembalikan/Terlambat pada data yang sudah di-ACC tetap diizinkan.
                $statusMundur = false;
                if ($tipe === 'peminjaman') {
                    $sn = (string)acc_g($r, 'status', ''); $so = acc_status($tipe, $o);
                    $statusMundur = ($so === 'Ditolak' && $sn !== 'Ditolak') || ($so === 'Disetujui' && in_array($sn, ['Menunggu', 'Ditolak'], true));
                }
                if (!$beda && !$statusMundur) continue;
                foreach (ACC_KOLOM[$tipe] as $k) {
                    $has = is_object($o) ? property_exists($o, $k) : array_key_exists($k, $o);
                    if ($has) acc_s($r, $k, acc_g($o, $k, null)); else acc_unset($r, $k);
                }
                if ($tipe === 'peminjaman') acc_s($r, 'status', $statusMundur && acc_status($tipe, $o) === 'Ditolak' ? 'Ditolak' : ((string)acc_g($o, 'status', '') ?: 'Dipinjam'));
                if ($tipe === 'kegiatan' && acc_status($tipe, $o) === 'Ditolak') acc_s($r, 'status_pelaksanaan', 'Draft');
                if ($tipe === 'surat') $suratKonflik[$id] = true;
                $konflik++;
            }
            unset($r);
        }
        unset($rows);
    }
    // Kegiatan ganda hasil ACC surat di dashboard yang sudah usang: buang beserta data modul turunannya.
    $final = [];
    foreach ($old['surat_masuk'] ?? [] as $s) if (in_array((string)acc_g($s, 'status'), ['Disetujui', 'Ditolak'], true)) $final[(string)acc_g($s, 'id')] = true;
    $kegLama = [];
    foreach ($old['kegiatan'] ?? [] as $k) $kegLama[(string)acc_g($k, 'id')] = true;
    $hapus = [];
    foreach ($new['kegiatan'] ?? [] as $k) {
        $sid = (string)acc_g($k, 'surat_id', '');
        $kid = (string)acc_g($k, 'id', '');
        if ($sid !== '' && isset($suratKonflik[$sid]) && isset($final[$sid]) && !isset($kegLama[$kid])) $hapus[$kid] = true;
    }
    if ($hapus) {
        $new['kegiatan'] = array_values(array_filter($new['kegiatan'], fn($k) => !isset($hapus[(string)acc_g($k, 'id', '')])));
        foreach (array_keys(WA_AUTOFILL) as $mk) {
            if (!isset($new[$mk]) || !is_array($new[$mk])) continue;
            $new[$mk] = array_values(array_filter($new[$mk], fn($x) => !isset($hapus[(string)acc_g($x, 'dari_kegiatan_id', '')])));
        }
    }
    return $konflik;
}