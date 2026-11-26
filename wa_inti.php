<?php
/**
 * Inti input WhatsApp/formulir: spesifikasi modul, parser, validasi, pencatatan ke modul, teks balasan.
 * Dipakai wa_webhook.php dan u.php (formulir via tautan). Tidak menjalankan apa pun saat dimuat.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/wa_config.php';
require_once __DIR__ . '/wa_lampiran.php';

/* ============================== Utilitas umum ============================== */

function wa_out(int $code, array $d): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_OUT);
    exit;
}

function wa_log(string $line): void
{
    $f = DATA_DIR . '/wa_log.txt';
    if (is_file($f) && filesize($f) > 1048576) @rename($f, $f . '.1');
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $line) . "\n", FILE_APPEND | LOCK_EX);
}

/** Normalisasi nomor ke bentuk 62xxxxxxxxxx (tanpa +, spasi, atau strip). */
function wa_nomor(string $n): string
{
    $d = preg_replace('/\D+/', '', $n) ?? '';
    if ($d === '') return '';
    if (str_starts_with($d, '62')) return $d;
    if (str_starts_with($d, '0')) return '62' . substr($d, 1);
    if (str_starts_with($d, '8')) return '62' . $d;
    return $d;
}

function wa_key(string $s): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? ''; }

function wa_now_str(): string
{
    $bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return date('j') . ' ' . $bln[(int)date('n') - 1] . ' ' . date('Y') . ', ' . date('H.i');
}

function wa_next_id(string $prefix, array $rows): string
{
    $max = 0;
    foreach ($rows as $r) {
        $id = is_object($r) ? ($r->id ?? '') : ($r['id'] ?? '');
        $n = (int)(substr((string)strrchr('-' . $id, '-'), 1));
        if ($n > $max) $max = $n;
    }
    return $prefix . '-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

function wa_rows(array $db, string $key): array
{
    $v = $db[$key] ?? [];
    return is_array($v) ? $v : [];
}

/* ============================== Pengiriman balasan ============================== */

function wa_kirim(string $target, string $pesan, bool $sudahDicek = false): void
{
    if (!WA_BALAS || $target === '' || $pesan === '') return;
    // Jaring pengaman terakhir untuk SEMUA pesan keluar: isi persis sama ke nomor yang sama dalam 2 menit tidak dikirim lagi.
    if (!$sudahDicek && function_exists('acc_boleh_kirim') && !getenv('WA_DRY_RUN') && !acc_boleh_kirim(null, $target, $pesan, 120)) {
        wa_log('KIRIM DITAHAN (pesan identik < 2 menit) -> ' . $target);
        return;
    }
    if (getenv('WA_DRY_RUN')) { // untuk pengujian: tulis ke berkas, tidak mengirim
        @file_put_contents(DATA_DIR . '/wa_outbox_test.txt', "=> $target\n$pesan\n\n", FILE_APPEND);
        return;
    }
    $post = ['target' => $target, 'message' => $pesan, 'countryCode' => '62'];
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init(WA_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: ' . WA_TOKEN], CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $res = curl_exec($ch);
            if ($res === false) wa_log('KIRIM GAGAL: ' . curl_error($ch));
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'timeout' => 15,
                'header' => "Authorization: " . WA_TOKEN . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($post),
            ]]);
            if (@file_get_contents(WA_API_URL, false, $ctx) === false) wa_log('KIRIM GAGAL (stream)');
        }
    } catch (Throwable $e) {
        wa_log('KIRIM GALAT: ' . $e->getMessage());
    }
}

/**
 * Kirim satu pesan WA ke satu nomor, opsional dengan lampiran file (mis. PDF Sprin) lewat Fonnte.
 * Mengembalikan [berhasil(bool), keterangan(string)]. Tidak bergantung pada WA_BALAS (ini pengiriman yang diminta pengguna).
 */
function wa_kirim_lampiran(string $target, string $pesan, ?string $filePath = null, string $fileName = 'Sprin.pdf', ?string $fileUrl = null): array
{
    // Lampiran lewat URL publik (cara yang didukung semua paket Fonnte); bila gagal, coba unggah file langsung.
    if ($fileUrl !== null && $filePath !== null && !getenv('WA_DRY_RUN')) {
        [$okU, $ketU] = wa_kirim_lampiran($target, $pesan, null, $fileName, $fileUrl . '#kirim');
        if ($okU) return [true, 'terkirim'];
        wa_log('KIRIM LAMPIRAN via URL gagal (' . $ketU . '), coba unggah langsung');
        $fileUrl = null;
    }
    if ($fileUrl !== null && str_ends_with($fileUrl, '#kirim')) $fileUrl = substr($fileUrl, 0, -6);
    elseif ($fileUrl !== null) $fileUrl = null;
    if ($target === '' || $pesan === '') return [false, 'Nomor atau pesan kosong'];
    if (getenv('WA_DRY_RUN')) { // untuk pengujian: tulis ke berkas, tidak mengirim
        @file_put_contents(DATA_DIR . '/wa_outbox_test.txt', "=> $target" . ($filePath ? " [lampiran: $fileName]" : '') . ($fileUrl ? " [url: $fileUrl]" : '') . "\n$pesan\n\n", FILE_APPEND);
        return [true, 'dry-run'];
    }
    if (!function_exists('curl_init')) return [false, 'Ekstensi PHP curl belum aktif di server'];
    $post = ['target' => $target, 'message' => $pesan, 'countryCode' => '62'];
    if ($fileUrl !== null) {
        $post['url'] = $fileUrl;
        $post['filename'] = $fileName;
    } elseif ($filePath !== null && is_file($filePath)) {
        $mime = function_exists('mime_content_type') ? ((string)@mime_content_type($filePath) ?: 'application/pdf') : 'application/pdf';
        $post['file'] = new CURLFile($filePath, $mime, $fileName);
        $post['filename'] = $fileName;
    }
    $ch = curl_init(WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: ' . WA_TOKEN], CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        wa_log('KIRIM TUGAS GAGAL ' . $target . ': ' . $err);
        return [false, 'Tidak dapat menghubungi gateway: ' . $err];
    }
    curl_close($ch);
    $j = json_decode((string)$res, true);
    if (is_array($j) && !empty($j['status'])) return [true, 'terkirim'];
    $why = is_array($j) ? (string)($j['reason'] ?? $j['detail'] ?? 'ditolak gateway') : 'balasan gateway tidak dikenali';
    wa_log('KIRIM TUGAS DITOLAK ' . $target . ': ' . $why);
    return [false, $why];
}

/** Tanggal ISO (Y-m-d) → "28 September 2026". */
function wa_tgl_panjang(string $iso): string
{
    $iso = substr($iso, 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $x)) return $iso;
    $b = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return (int)$x[3] . ' ' . $b[(int)$x[2] - 1] . ' ' . $x[1];
}

/** Template pesan penugasan ke anggota yang ditugaskan. $namaAnggota = semua anggota bertugas. */
function wa_pesan_tugas(array $k, array $namaAnggota): string
{
    $a = (string)($k['tanggal'] ?? '');
    $b = (string)($k['tanggal_selesai'] ?? '');
    $tgl = ($b !== '' && substr($b, 0, 10) > substr($a, 0, 10))
        ? wa_tgl_panjang($a) . ' s.d. ' . wa_tgl_panjang($b) : wa_tgl_panjang($a);
    $jam = trim((string)($k['waktu'] ?? ''));
    // Uji Periodik: template ringkas tanpa daftar anggota
    if (($k['jenis'] ?? '') === 'uji_periodik') {
        return implode("\n", [
            'Kegiatan: ' . ($k['nama'] ?? '-'),
            'Tanggal: ' . ($tgl !== '' ? $tgl : '-'),
            'Jam: ' . ($jam !== '' ? $jam . ' WIB' : '-'),
            'Lokasi: ' . (trim((string)($k['lokasi'] ?? '')) ?: '-'),
        ]);
    }
    $baris = [
        '*NOTIFIKASI PENUGASAN*',
        '',
        'Nama Kegiatan: ' . ($k['nama'] ?? '-'),
        'Tanggal Kegiatan: ' . ($tgl !== '' ? $tgl : '-'),
        'Lokasi: ' . (trim((string)($k['lokasi'] ?? '')) ?: '-'),
        'Jam: ' . ($jam !== '' ? $jam . ' WIB' : '-'),
        'Anggota Yang Bertugas:',
    ];
    foreach (array_values($namaAnggota) as $i => $n) $baris[] = ($i + 1) . '. ' . $n;
    $baris[] = '';
    $baris[] = 'Surat Perintah (Sprin) terlampir. Mohon dipersiapkan. Terima kasih.';
    return implode("\n", $baris);
}

/** Pesan ke anggota yang DIGANTIKAN pada penugasan kegiatan (tidak lagi bertugas). $pengganti = nama anggota yang menggantikan. */
function wa_pesan_ganti(array $k, string $namaAnggota, array $pengganti): string
{
    $a = (string)($k['tanggal'] ?? '');
    $b = (string)($k['tanggal_selesai'] ?? '');
    $tgl = ($b !== '' && substr($b, 0, 10) > substr($a, 0, 10))
        ? wa_tgl_panjang($a) . ' s.d. ' . wa_tgl_panjang($b) : wa_tgl_panjang($a);
    $jam = trim((string)($k['waktu'] ?? ''));
    $baris = [
        '*PERUBAHAN PENUGASAN*',
        '',
        'Yth. ' . $namaAnggota . ',',
        'Anda *digantikan* dan tidak lagi bertugas pada kegiatan berikut:',
        '',
        'ID: ' . ($k['id'] ?? '-'),
        'Nama Kegiatan: ' . ($k['nama'] ?? '-'),
        'Tanggal Kegiatan: ' . ($tgl !== '' ? $tgl : '-'),
        'Jam: ' . ($jam !== '' ? $jam . ' WIB' : '-'),
        'Lokasi: ' . (trim((string)($k['lokasi'] ?? '')) ?: '-'),
    ];
    if ($pengganti) $baris[] = 'Digantikan oleh: ' . implode(', ', $pengganti);
    $baris[] = '';
    $baris[] = 'Tautan upload bukti/laporan untuk kegiatan ini sudah tidak berlaku bagi Anda. Terima kasih.';
    return implode("\n", $baris);
}

/** Pesan terpisah: tautan update kegiatan (bukti penugasan → Dalam Pelaksanaan). */
function wa_pesan_link_update(array $k, string $url): string
{
    return "*LINK UPDATE KEGIATAN*\n\nID: " . ($k['id'] ?? '-') . "\nKegiatan: " . ($k['nama'] ?? '-') . "\n\n"
        . "Saat sudah mulai bertugas, upload foto/dokumen bukti penugasan lewat tautan ini (boleh beberapa kali). Status kegiatan otomatis menjadi *Dalam Pelaksanaan*, lalu link upload laporan dikirim otomatis ke WhatsApp ini.\n\n"
        . $url;
}

/** Pesan terpisah: tautan upload laporan (PDF atau link → Selesai). */
function wa_pesan_link_laporan(array $k, string $url): string
{
    return "*LINK UPLOAD LAPORAN*\n\nID: " . ($k['id'] ?? '-') . "\nKegiatan: " . ($k['nama'] ?? '-') . "\n\n"
        . "Setelah kegiatan selesai, upload laporan (PDF) atau kirim link laporan lewat tautan ini. Status kegiatan otomatis menjadi *Selesai*. Tautan hanya bisa dipakai sekali.\n\n"
        . $url;
}

/* ============================== Parser nilai ============================== */

function wa_parse_tanggal(string $s): ?string
{
    $s = trim(mb_strtolower($s));
    if ($s === '') return '';
    if (in_array($s, ['hari ini', 'hariini', 'today'], true)) return date('Y-m-d');
    if ($s === 'besok') return date('Y-m-d', strtotime('+1 day'));
    if ($s === 'lusa') return date('Y-m-d', strtotime('+2 days'));
    $bln = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'ags' => 8, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];
    $y = $m = $d = 0;
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $x)) { [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]]; }
    elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $s, $x)) { [$d, $m, $y] = [(int)$x[1], (int)$x[2], (int)$x[3]]; if ($y < 100) $y += 2000; }
    elseif (preg_match('/^(\d{1,2})\s+([a-z]+)\.?\s+(\d{2,4})$/', $s, $x) && isset($bln[substr($x[2], 0, 3)])) {
        [$d, $m, $y] = [(int)$x[1], $bln[substr($x[2], 0, 3)], (int)$x[3]]; if ($y < 100) $y += 2000;
    } else return null;
    return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
}

function wa_parse_waktu(string $s): ?string
{
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/\s*(wib|wita|wit)\b/', '', $s) ?? $s;
    $s = preg_replace('/^pukul\s+/', '', $s) ?? $s;
    if ($s === '') return '';
    if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?$/', trim($s), $x)) return null;
    $h = (int)$x[1]; $mi = (int)($x[2] ?? 0);
    return ($h > 23 || $mi > 59) ? null : sprintf('%02d:%02d', $h, $mi);
}

function wa_parse_angka(string $s, bool $desimal = false): int|float|null
{
    $s = trim(mb_strtolower($s));
    if ($s === '') return null;
    $s = preg_replace('/^(rp\.?|idr)\s*/', '', $s) ?? $s;
    $s = preg_replace('/\s+(unit|buah|pcs|set|orang|jam|km|liter|knot|nm|%|persen)$/', '', $s) ?? $s;
    if ($desimal) {
        // "1.500.000" / "1.500.000,50" / "45000,5" / "45000.5"
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
        else $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
    $s = preg_replace('/[.,]/', '', $s) ?? $s;
    return preg_match('/^\d+$/', $s) ? (int)$s : null;
}

/** Cocokkan teks ke salah satu opsi (abaikan huruf besar/kecil, spasi, tanda baca; boleh sebagian / alias). */
function wa_match_opsi(string $v, array $opts, array $alias = []): ?string
{
    $k = wa_key($v);
    if ($k === '') return null;
    foreach ($opts as $o) if (wa_key($o) === $k) return $o;
    foreach ($alias as $a => $target) if (wa_key((string)$a) === $k) return $target;
    foreach ($opts as $o) { $ok = wa_key($o); if ($ok !== '' && (str_starts_with($ok, $k) || str_starts_with($k, $ok))) return $o; }
    foreach ($opts as $o) { $ok = wa_key($o); if ($ok !== '' && strlen($k) >= 3 && str_contains($ok, $k)) return $o; }
    return null;
}

/* ============================== Spesifikasi modul ============================== */

const WA_JENIS_KEGIATAN = [
    'sgts' => 'Sar Go To School', 'bimtek' => 'Bimtek', 'potensi' => 'Potensi', 'narasumber' => 'Narasumber', 'diklat' => 'Diklat',
    'pembinaan' => 'Pembinaan Tenaga SAR', 'pemberdayaan' => 'Pemberdayaan Masyarakat', 'uji_periodik' => 'Uji Periodik',
    'uji_pemeliharaan' => 'Pemeliharaan Sumda', 'lainnya' => 'Lainnya',
];
const WA_JENIS_ALIAS = [
    'sar go to school' => 'Sar Go To School', 'sgts' => 'Sar Go To School', 'sgs' => 'Sar Go To School', 'sekolah' => 'Sar Go To School',
    'pm' => 'Pemberdayaan Masyarakat', 'pemberdayaan' => 'Pemberdayaan Masyarakat', 'udik' => 'Uji Periodik', 'uji' => 'Uji Periodik',
    'upm' => 'Pemeliharaan Sumda', 'uji pemeliharaan' => 'Pemeliharaan Sumda', 'pemeliharaan sumda' => 'Pemeliharaan Sumda', 'pembinaan' => 'Pembinaan Tenaga SAR', 'lain' => 'Lainnya',
];
const WA_LOKASI_INV   = ['KPP Jakarta', 'USS Bekasi', 'USS Bogor', 'Pos Sukabumi'];
const WA_LOKASI_KDR   = ['KPP Jakarta', 'USS Bekasi', 'USS Bogor', 'Pos SAR Sukabumi'];
const WA_LOKASI_PBN   = ['KPP Jakarta', 'USS Bekasi', 'USS Bogor', 'Pos Sar Sukabumi', 'Kep Seribu', 'Dermaga Pantai Mutiara', 'KN SAR Ganesha', 'KN SAR Antasena'];
const WA_KAT_ALAT     = ['Palsar Darat', 'Palsar Air', 'Medis', 'Komunikasi', 'Palsar Udara'];
const WA_JENIS_KDR    = ['Mobil', 'Motor', 'Truk', 'Ambulans', 'Kapal', 'RIB / Perahu Karet'];
const WA_KONDISI_KDR  = ['Serviceable', 'Unserviceable', 'Scrap'];
const WA_SIFAT_SURAT  = ['Biasa', 'Segera', 'Sangat Segera', 'Rahasia'];
const WA_JENIS_PEMEL  = ['Perawatan Rutin', 'Servis Berkala', 'Perbaikan', 'Pemeriksaan / Inspeksi', 'Penggantian Suku Cadang', 'Kalibrasi'];

/** Ringkas: f(label, [alias...], tipe, wajib, opsi, alias-opsi, ketat) */
function wa_f(string $label, array $alias, string $tipe = 'text', bool $req = false, array $opts = [], array $optAlias = [], bool $strict = true): array
{
    return ['label' => $label, 'alias' => $alias, 'tipe' => $tipe, 'req' => $req, 'opts' => $opts, 'optAlias' => $optAlias, 'strict' => $strict];
}

function wa_spec(string $cmd): array
{
    switch ($cmd) {
        case 'kegiatan': return ['judul' => 'Kegiatan/Tugas', 'contoh' => "KEGIATAN\nNama: Sosialisasi SAR di SMAN 1 Jakarta\nJenis: Sar Go To School\nTanggal: 25/10/2026\nSampai: 26/10/2026\nWaktu: 08.00\nLokasi: SMAN 1 Jakarta\nPJ: Budi Santoso\nNo HP: 081234567890\nJumlah: 5\nDeskripsi: Pengenalan SAR untuk siswa kelas X",
            'fields' => [
                'nama' => wa_f('Nama', ['nama', 'namakegiatan', 'kegiatan', 'judul', 'acara', 'nama tugas', 'tugas'], 'text', true),
                'jenis' => wa_f('Jenis', ['jenis', 'kategori', 'tipe'], 'enum', false, array_values(WA_JENIS_KEGIATAN), WA_JENIS_ALIAS),
                'tanggal' => wa_f('Tanggal', ['tanggal', 'tgl', 'tanggalmulai', 'mulai'], 'date'),
                'tanggal_selesai' => wa_f('Sampai', ['sampai', 'selesai', 'tanggalselesai', 'sd', 'hingga', 'tglselesai'], 'date'),
                'waktu' => wa_f('Waktu', ['waktu', 'jam', 'pukul'], 'time'),
                'lokasi' => wa_f('Lokasi', ['lokasi', 'tempat']),
                'penanggung_jawab' => wa_f('PJ', ['pj', 'penanggungjawab', 'penanggung jawab']),
                'no_hp' => wa_f('No HP', ['nohp', 'hp', 'telp', 'telepon', 'kontak', 'nomorhp', 'nomor']),
                'jumlah' => wa_f('Jumlah', ['jumlah', 'jumlahanggota', 'anggota', 'jumlahdibutuhkan', 'peserta'], 'int'),
                'deskripsi' => wa_f('Deskripsi', ['deskripsi', 'keterangan', 'ket', 'uraian', 'catatan']),
            ]];
        case 'surat': return ['judul' => 'Surat Masuk', 'contoh' => "SURAT MASUK\nNomor: B/123/SEKRET/X/2026\nAsal: Dinas Pendidikan DKI\nTanggal Surat: 20/10/2026\nPerihal: Permohonan Narasumber SAR\nJenis: Narasumber\nTanggal Kegiatan: 30/10/2026\nWaktu: 09.00\nLokasi: Aula Dinas\nPJ: Siti\nNo HP: 081200000000\nJumlah: 2\nIsi: Mohon narasumber materi dasar SAR",
            'fields' => [
                'nomor_surat' => wa_f('Nomor', ['nomor', 'no', 'nomorsurat', 'nosurat'], 'text', true),
                'asal_surat' => wa_f('Asal', ['asal', 'asalsurat', 'dari', 'pengirim', 'instansi']),
                'tanggal_surat' => wa_f('Tanggal Surat', ['tanggalsurat', 'tglsurat', 'tanggal', 'tgl'], 'date'),
                'tanggal_diterima' => wa_f('Diterima', ['diterima', 'tanggalditerima', 'tglterima']),
                'perihal' => wa_f('Perihal', ['perihal', 'hal'], 'text', true),
                'jenis' => wa_f('Jenis', ['jenis', 'kategori'], 'enum', false, array_values(WA_JENIS_KEGIATAN), WA_JENIS_ALIAS),
                'tanggal_kegiatan' => wa_f('Tanggal Kegiatan', ['tanggalkegiatan', 'tglkegiatan', 'tanggalpelaksanaan'], 'date'),
                'waktu' => wa_f('Waktu', ['waktu', 'jam', 'pukul'], 'time'),
                'lokasi' => wa_f('Lokasi', ['lokasi', 'tempat']),
                'penanggung_jawab' => wa_f('PJ', ['pj', 'penanggungjawab']),
                'no_hp' => wa_f('No HP', ['nohp', 'hp', 'telp', 'kontak']),
                'jumlah' => wa_f('Jumlah', ['jumlah', 'jumlahanggota', 'jumlahdibutuhkan'], 'int'),
                'deskripsi' => wa_f('Isi', ['isi', 'isiringkas', 'deskripsi', 'ringkasan', 'keterangan', 'ket']),
            ]];
        case 'surat_keluar': return ['judul' => 'Surat Keluar', 'contoh' => "SURAT KELUAR\nNomor: B/045/KPP-JKT/X/2026\nTujuan: Kepala Dinas Kesehatan\nPerihal: Permohonan Dukungan Tim Medis\nTanggal: 21/10/2026\nSifat: Segera\nLampiran: 1 berkas\nPenandatangan: Kepala Kantor\nKeterangan: -",
            'fields' => [
                'nomor_surat' => wa_f('Nomor', ['nomor', 'no', 'nomorsurat', 'nosurat'], 'text', true),
                'tujuan' => wa_f('Tujuan', ['tujuan', 'kepada', 'ditujukan', 'tujuansurat'], 'text', true),
                'perihal' => wa_f('Perihal', ['perihal', 'hal'], 'text', true),
                'tanggal_surat' => wa_f('Tanggal', ['tanggal', 'tgl', 'tanggalsurat'], 'date'),
                'sifat' => wa_f('Sifat', ['sifat'], 'enum', false, WA_SIFAT_SURAT),
                'lampiran' => wa_f('Lampiran', ['lampiran']),
                'penandatangan' => wa_f('Penandatangan', ['penandatangan', 'ttd', 'ditandatangani']),
                'keterangan' => wa_f('Keterangan', ['keterangan', 'ket', 'catatan']),
            ]];
        case 'pembinaan': return ['judul' => 'Pembinaan Tenaga SAR', 'contoh' => "PEMBINAAN\nKategori: Pembelajaran\nNama: Pelatihan Pertolongan Pertama\nJenis: Praktek\nTanggal: 27/10/2026\nLokasi: KPP Jakarta\nMateri: Teknik CPR dan pembidaian\nPeserta: Tim Rescue Alpha\nJumlah: 12\nJam: 8\nHasil: Seluruh peserta lulus",
            'fields' => [
                'kategori_pembinaan' => wa_f('Kategori', ['kategori', 'tab'], 'enum', false, ['Pembelajaran', 'Samapta']),
                'nama_kegiatan' => wa_f('Nama', ['nama', 'namakegiatan', 'kegiatan', 'judul'], 'text', true),
                'jenis_pembelajaran' => wa_f('Jenis', ['jenis', 'jenispembelajaran'], 'enum', false, ['Teori', 'Praktek', 'Latihan'], ['praktik' => 'Praktek']),
                'tanggal' => wa_f('Tanggal', ['tanggal', 'tgl'], 'date'),
                'lokasi' => wa_f('Lokasi', ['lokasi', 'tempat'], 'enum', false, WA_LOKASI_PBN, [], false),
                'materi' => wa_f('Materi', ['materi', 'programlatihan', 'program']),
                'peserta' => wa_f('Peserta', ['peserta', 'namaanggota', 'namapeserta']),
                'jumlah_peserta' => wa_f('Jumlah', ['jumlah', 'jumlahpeserta', 'jumlahanggota'], 'int'),
                'jumlah_jam' => wa_f('Jam', ['jam', 'jumlahjam', 'durasi', 'jampelajaran'], 'num'),
                'hasil' => wa_f('Hasil', ['hasil', 'hasilpembelajaran']),
                'dokumentasi' => wa_f('Dokumentasi', ['dokumentasi', 'tautan', 'link']),
            ]];
        case 'potensi': return ['judul' => 'Potensi', 'contoh' => "POTENSI\nNama: Komunitas Selam Jakarta\nKategori: Organisasi\nKontak: Rudi Hartono\nNo Kontak: 081320000001\nAlamat: Jakarta Utara\nWilayah: DKI Jakarta\nKompetensi: Selam dasar-lanjut\nTersertifikasi: 12\nBelum: 8\nStatus: Aktif\nAlut: 2 perahu karet, 10 set selam\nKet: Siap dilibatkan operasi SAR\nLink: https://drive.google.com/...",
            'fields' => [
                'nama_potensi' => wa_f('Nama', ['nama', 'namapotensi', 'potensi'], 'text', true),
                'kategori' => wa_f('Kategori', ['kategori', 'jenis', 'tipe'], 'enum', false, ['TNI/Polri', 'Pemerintah', 'Perusahaan', 'Organisasi'],
                    ['tni' => 'TNI/Polri', 'polri' => 'TNI/Polri', 'tni polri' => 'TNI/Polri', 'pemda' => 'Pemerintah', 'instansi' => 'Pemerintah', 'swasta' => 'Perusahaan', 'pt' => 'Perusahaan', 'komunitas' => 'Organisasi', 'ormas' => 'Organisasi']),
                'nama_kontak' => wa_f('Kontak', ['kontak', 'namakontak', 'cp', 'pj', 'penanggungjawab']),
                'nomor_kontak' => wa_f('No Kontak', ['nokontak', 'nomorkontak', 'nohp', 'hp', 'telp', 'telepon', 'nomor']),
                'alamat' => wa_f('Alamat', ['alamat']),
                'wilayah' => wa_f('Wilayah', ['wilayah', 'daerah', 'lokasi']),
                'kompetensi' => wa_f('Kompetensi', ['kompetensi', 'keahlian', 'kemampuan']),
                'jumlah_tersertifikasi' => wa_f('Tersertifikasi', ['tersertifikasi', 'sertifikasi', 'bersertifikat'], 'int'),
                'jumlah_belum_sertifikasi' => wa_f('Belum', ['belum', 'belumtersertifikasi', 'belumsertifikasi'], 'int'),
                'status' => wa_f('Status', ['status'], 'enum', false, ['Aktif', 'Nonaktif'], ['non aktif' => 'Nonaktif', 'tidak aktif' => 'Nonaktif']),
                'alut_sapras' => wa_f('Alut', ['alut', 'alutsapras', 'sapras', 'peralatan']),
                'keterangan' => wa_f('Ket', ['ket', 'keterangan', 'catatan']),
                'dokumentasi' => wa_f('Link', ['link', 'tautan', 'dokumentasi', 'url']),
            ]];
        case 'peralatan': return ['judul' => 'Peralatan', 'contoh' => "PERALATAN\nNama: Tali Kernmantle 11mm\nKode Rak: R-03\nLokasi: KPP Jakarta\nKategori: Palsar Darat\nBaik: 10\nRusak: 2\nKet: Stok awal bulan",
            'fields' => [
                'nama_barang' => wa_f('Nama', ['nama', 'namabarang', 'barang', 'alat', 'peralatan'], 'text', true),
                'kode_rak' => wa_f('Kode Rak', ['koderak', 'rak']),
                'lokasi' => wa_f('Lokasi', ['lokasi', 'tempat'], 'enum', false, WA_LOKASI_INV, ['sukabumi' => 'Pos Sukabumi', 'pos sar sukabumi' => 'Pos Sukabumi', 'kpp' => 'KPP Jakarta']),
                'kategori' => wa_f('Kategori', ['kategori', 'jenis'], 'enum', false, WA_KAT_ALAT),
                'jumlah_baik' => wa_f('Baik', ['baik', 'jumlahbaik', 'jumlah', 'stok', 'qty'], 'int'),
                'jumlah_rusak' => wa_f('Rusak', ['rusak', 'jumlahrusak'], 'int'),
                'keterangan' => wa_f('Ket', ['ket', 'keterangan', 'catatan']),
            ]];
        case 'kendaraan': return ['judul' => 'Kendaraan', 'contoh' => "KENDARAAN\nNama: Rescue Truck 01\nJenis: Truk\nNopol: B 9123 SAR\nMerk: Isuzu Elf\nKondisi: Serviceable\nLokasi: KPP Jakarta\nTanggal: 20/10/2026\nKM: 45000\nBBM: 40\nKet: Siap operasi",
            'fields' => [
                'nama_kendaraan' => wa_f('Nama', ['nama', 'namakendaraan', 'kendaraan'], 'text', true),
                'jenis_kendaraan' => wa_f('Jenis', ['jenis', 'jeniskendaraan'], 'enum', false, WA_JENIS_KDR, ['perahu karet' => 'RIB / Perahu Karet', 'rib' => 'RIB / Perahu Karet', 'ambulance' => 'Ambulans', 'truck' => 'Truk']),
                'nomor_polisi' => wa_f('Nopol', ['nopol', 'nomorpolisi', 'plat', 'registrasi', 'nolambung', 'nomorlambung']),
                'merk_tipe' => wa_f('Merk', ['merk', 'tipe', 'merktipe']),
                'kondisi' => wa_f('Kondisi', ['kondisi'], 'enum', false, WA_KONDISI_KDR, ['baik' => 'Serviceable', 'layak' => 'Serviceable', 'rusak' => 'Unserviceable', 'tidak layak' => 'Unserviceable']),
                'lokasi' => wa_f('Lokasi', ['lokasi', 'pangkalan'], 'enum', false, WA_LOKASI_KDR, ['sukabumi' => 'Pos SAR Sukabumi', 'pos sukabumi' => 'Pos SAR Sukabumi', 'kpp' => 'KPP Jakarta']),
                'tanggal' => wa_f('Tanggal', ['tanggal', 'tgl'], 'date'),
                'km_saat_ini' => wa_f('KM', ['km', 'kmsaatini', 'odometer'], 'dec'),
                'bbm' => wa_f('BBM', ['bbm'], 'dec'),
                'endurance' => wa_f('Endurance', ['endurance'], 'dec'),
                'speed' => wa_f('Speed', ['speed', 'kecepatan'], 'dec'),
                'range' => wa_f('Range', ['range', 'jangkauan'], 'dec'),
                'konsumsi_bbm' => wa_f('Konsumsi BBM', ['konsumsibbm', 'konsumsi'], 'dec'),
                'cadangan_bbm' => wa_f('Cadangan BBM', ['cadanganbbm', 'cadangan'], 'dec'),
                'persen_bbm' => wa_f('Persen BBM', ['persenbbm', 'persentasebbm'], 'dec'),
                'kecepatan_angin' => wa_f('Kecepatan Angin', ['kecepatanangin', 'angin'], 'dec'),
                'arah_angin' => wa_f('Arah Angin', ['arahangin']),
                'keterangan' => wa_f('Ket', ['ket', 'keterangan', 'catatan']),
            ]];
        case 'peminjaman': return ['judul' => 'Peminjaman', 'contoh' => "PINJAM\nPeminjam: Budi Santoso\nJenis: Peralatan\nBarang: Tali Kernmantle 11mm\nJumlah: 2\nTanggal: 21/10/2026\nKembali: 25/10/2026\nKeperluan: Latihan vertikal\nPJ: Ahmad\nKet: -",
            'fields' => [
                'nama_peminjam' => wa_f('Peminjam', ['peminjam', 'namapeminjam', 'nama'], 'text', true),
                'jenis_barang' => wa_f('Jenis', ['jenis', 'jenisbarang'], 'enum', false, ['Peralatan', 'Kendaraan'], ['alat' => 'Peralatan', 'mobil' => 'Kendaraan']),
                'nama_barang' => wa_f('Barang', ['barang', 'namabarang', 'alat', 'kendaraan', 'peralatan'], 'text', true),
                'jumlah' => wa_f('Jumlah', ['jumlah', 'qty'], 'int'),
                'tanggal' => wa_f('Tanggal', ['tanggal', 'tglpinjam', 'tanggalpinjam', 'tgl'], 'date'),
                'tanggal_kembali_rencana' => wa_f('Kembali', ['kembali', 'rencanakembali', 'tanggalkembali', 'tglkembali'], 'date'),
                'keperluan' => wa_f('Keperluan', ['keperluan', 'untuk', 'kegiatan']),
                'penanggung_jawab' => wa_f('PJ', ['pj', 'penanggungjawab']),
                'keterangan' => wa_f('Ket', ['ket', 'keterangan', 'catatan']),
            ]];
        case 'pengajuan': return ['judul' => 'Pengajuan', 'contoh' => "PENGAJUAN KEBUTUHAN\nJenis: Peralatan\nBarang: Life Jacket\nJumlah: 20\nBiaya: 15000000\nUrgensi: Tinggi\nAlasan: Stok rusak setelah latihan\n\n(untuk pemeliharaan:)\nPENGAJUAN PEMELIHARAAN\nJenis: Kendaraan\nBarang: Rescue Truck 01\nPemeliharaan: Servis Berkala\nPelaksana: Bengkel Resmi\nUraian: Ganti oli dan filter\nBiaya: 1500000\nKM: 45000",
            'fields' => [
                'kategori_pengajuan' => wa_f('Kategori', ['kategori', 'jeniskategori'], 'enum', false, ['Kebutuhan', 'Pemeliharaan']),
                'jenis_barang' => wa_f('Jenis', ['jenis', 'jenisbarang'], 'enum', false, ['Peralatan', 'Kendaraan'], ['alat' => 'Peralatan', 'mobil' => 'Kendaraan']),
                'nama_barang' => wa_f('Barang', ['barang', 'nama', 'namabarang', 'alat', 'kendaraan', 'peralatan'], 'text', true),
                'tanggal' => wa_f('Tanggal', ['tanggal', 'tgl', 'tanggalpengajuan'], 'date'),
                'jumlah' => wa_f('Jumlah', ['jumlah', 'qty', 'jumlahdibutuhkan', 'jumlahunit'], 'int'),
                'estimasi_biaya' => wa_f('Estimasi Biaya', ['estimasi', 'estimasibiaya'], 'dec'),
                'tingkat_urgensi' => wa_f('Urgensi', ['urgensi', 'tingkaturgensi', 'prioritas'], 'enum', false, ['Rendah', 'Sedang', 'Tinggi']),
                'alasan' => wa_f('Alasan', ['alasan', 'spesifikasi']),
                'jenis_pemeliharaan' => wa_f('Pemeliharaan', ['pemeliharaan', 'jenispemeliharaan', 'tindakan'], 'enum', false, WA_JENIS_PEMEL),
                'pelaksana' => wa_f('Pelaksana', ['pelaksana', 'petugas', 'bengkel']),
                'uraian' => wa_f('Uraian', ['uraian', 'pekerjaan', 'uraianpekerjaan']),
                'suku_cadang' => wa_f('Suku Cadang', ['sukucadang', 'bahan']),
                'km_saat_ini' => wa_f('KM', ['km', 'kmsaatini'], 'dec'),
                'biaya' => wa_f('Biaya', ['biaya'], 'dec'),
                'jadwal_berikutnya' => wa_f('Jadwal Berikutnya', ['jadwalberikutnya', 'berikutnya'], 'date'),
                'keterangan' => wa_f('Ket', ['ket', 'keterangan', 'catatan']),
            ]];
    }
    return [];
}

/** Kata kunci pesan -> perintah. Kembalikan [cmd, sisa teks setelah kata kunci, kategori-implisit]. */
function wa_detect(string $head): array
{
    $orig = preg_replace('/^[\s\/\-:*#>]+/u', '', trim($head)) ?? trim($head);
    $h = mb_strtolower($orig);
    $map = [
        ['/^(bantuan|help|menu|panduan|\?)(\b|$)/u', 'bantuan'],
        ['/^(acc|setuju|setujui|approve)\b/u', 'acc'],
        ['/^(tolak|ditolak|reject)\b/u', 'tolak'],
        ['/^(menunggu|pending|antrian|antrean)\b/u', 'menunggu'],
        ['/^format\b/u', 'format'],
        ['/^formulir\b|^form\b/u', 'form'],
        ['/^(selesai|tuntas|done)\b/u', 'selesai'],
        ['/^(kembali|kembalikan|dikembalikan|pengembalian|balikin|return)\b/u', 'kembali'],
        ['/^(surat\s*keluar|suratkeluar|sk)\b/u', 'surat_keluar'],
        ['/^(surat\s*masuk|suratmasuk|persuratan|surat|sm)\b/u', 'surat'],
        ['/^(kegiatan|tugas|kgt)\b/u', 'kegiatan'],
        ['/^(pembinaan|pbn)\b/u', 'pembinaan'],
        ['/^(potensi|pot)\b/u', 'potensi'],
        ['/^(peralatan|alat|prl)\b/u', 'peralatan'],
        ['/^(kendaraan|kdr)\b/u', 'kendaraan'],
        ['/^(peminjaman|pinjam|pmj)\b/u', 'peminjaman'],
        ['/^(pengajuan|pgj|ajuan|ajukan)\b/u', 'pengajuan'],
    ];
    foreach ($map as [$re, $cmd]) {
        if (preg_match($re, $h, $m)) return [$cmd, trim(mb_substr($orig, mb_strlen($m[0])))]; // sisa teks mempertahankan huruf asli
    }
    return ['', ''];
}

/** Uraikan baris "Kunci: nilai" menjadi array [kunci-ternormalisasi => nilai]. Baris tanpa kunci disambung ke nilai sebelumnya. */
function wa_baris(string $text): array
{
    $out = []; $last = null;
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $line = preg_replace('/^[\-\*•▪●]+\s*/u', '', $line) ?? $line;
        if (preg_match('/^([^:=]{1,40}?)\s*[:=]\s*(.*)$/u', $line, $m) && wa_key($m[1]) !== '') {
            $last = wa_key($m[1]);
            $out[$last] = trim($m[2]);
        } elseif ($last !== null) {
            $out[$last] .= ' ' . $line;
        }
    }
    return $out;
}

/** Validasi dan konversi nilai sesuai spesifikasi. Kembalikan [data, galat[], kunci-tak-dikenal[]]. */
function wa_validasi(array $spec, array $kv): array
{
    $alias = [];
    foreach ($spec['fields'] as $k => $f) {
        $alias[$k] = $k;
        foreach ($f['alias'] as $a) $alias[wa_key($a)] = $k;
        $alias[wa_key($f['label'])] = $k;
    }
    $in = []; $unknown = [];
    foreach ($kv as $key => $val) {
        if (isset($alias[$key])) { if (!isset($in[$alias[$key]]) || $in[$alias[$key]] === '') $in[$alias[$key]] = $val; }
        else $unknown[] = $key;
    }
    $data = []; $err = [];
    foreach ($spec['fields'] as $k => $f) {
        $raw = trim((string)($in[$k] ?? ''));
        if ($raw === '' || $raw === '-') {
            if ($f['req']) $err[] = $f['label'] . ' wajib diisi';
            continue;
        }
        switch ($f['tipe']) {
            case 'date':
                $v = wa_parse_tanggal($raw);
                if ($v === null) { $err[] = $f['label'] . ': tanggal "' . $raw . '" tidak dikenali (pakai 25/10/2026)'; continue 2; }
                $data[$k] = $v; break;
            case 'time':
                $v = wa_parse_waktu($raw);
                if ($v === null) { $err[] = $f['label'] . ': jam "' . $raw . '" tidak dikenali (pakai 08.00)'; continue 2; }
                $data[$k] = $v; break;
            case 'int': case 'num': case 'dec':
                $v = wa_parse_angka($raw, $f['tipe'] !== 'int');
                if ($v === null) { $err[] = $f['label'] . ': "' . $raw . '" bukan angka'; continue 2; }
                $data[$k] = $v; break;
            case 'enum':
                $v = wa_match_opsi($raw, $f['opts'], $f['optAlias']);
                if ($v === null) {
                    if ($f['strict']) { $err[] = $f['label'] . ': "' . $raw . '" tidak dikenali. Pilihan: ' . implode(', ', $f['opts']); continue 2; }
                    $v = $raw;
                }
                $data[$k] = $v; break;
            default:
                $data[$k] = $raw;
        }
    }
    return [$data, $err, $unknown];
}

/* ============================== Pencatatan ke modul ============================== */

/** Ikuti logika autoFillModuleFromKegiatan() di index.php: kegiatan otomatis tercatat di modul sesuai Jenis. */
const WA_AUTOFILL = [
    'sgts'             => ['prefix' => 'SGS', 'nama' => 'nama_sekolah',   'tgl' => 'tanggal',             'tgl2' => null,              'waktu' => true,  'lokasi' => false, 'pj' => 'penanggung_jawab', 'jml' => true,  'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'bimtek'           => ['prefix' => 'BMT', 'nama' => 'nama_bimtek',    'tgl' => 'tanggal',             'tgl2' => null,              'waktu' => true,  'lokasi' => true,  'pj' => 'petugas',          'jml' => true,  'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'potensi'          => ['prefix' => 'POT', 'nama' => 'nama_potensi',   'tgl' => null,                  'tgl2' => null,              'waktu' => false, 'lokasi' => false, 'pj' => null,               'jml' => false, 'materi' => false, 'cat' => 'keterangan', 'status' => 'Aktif'],
    'narasumber'       => ['prefix' => 'NRS', 'nama' => 'nama',           'tgl' => null,                  'tgl2' => null,              'waktu' => false, 'lokasi' => false, 'pj' => null,               'jml' => false, 'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'diklat'           => ['prefix' => 'DKL', 'nama' => 'nama_diklat',    'tgl' => 'tanggal_mulai',       'tgl2' => 'tanggal_selesai', 'waktu' => false, 'lokasi' => true,  'pj' => 'instruktur',       'jml' => true,  'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'pembinaan'        => ['prefix' => 'PBN', 'nama' => 'nama_kegiatan',  'tgl' => 'tanggal',             'tgl2' => null,              'waktu' => false, 'lokasi' => true,  'pj' => null,               'jml' => true,  'materi' => true,  'cat' => null,         'status' => null],
    'pemberdayaan'     => ['prefix' => 'PBM', 'nama' => 'nama_kegiatan',  'tgl' => 'tanggal',             'tgl2' => null,              'waktu' => false, 'lokasi' => true,  'pj' => 'petugas',          'jml' => true,  'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'uji_periodik'     => ['prefix' => 'UPR', 'nama' => 'nama_uji',       'tgl' => 'tanggal_mulai',       'tgl2' => 'tanggal_selesai', 'waktu' => false, 'lokasi' => true,  'pj' => null,               'jml' => true,  'materi' => true,  'cat' => null,         'status' => 'Direncanakan'],
    'uji_pemeliharaan' => ['prefix' => 'UPM', 'nama' => 'nama_peralatan', 'tgl' => 'tanggal_pemeriksaan', 'tgl2' => null,              'waktu' => false, 'lokasi' => false, 'pj' => 'petugas',          'jml' => false, 'materi' => false, 'cat' => 'keterangan', 'status' => 'Baik'],
    'lainnya'          => ['prefix' => 'LNY', 'nama' => 'nama_kegiatan',  'tgl' => 'tanggal',             'tgl2' => null,              'waktu' => true,  'lokasi' => true,  'pj' => 'penanggung_jawab', 'jml' => true,  'materi' => false, 'cat' => 'keterangan', 'status' => 'Direncanakan'],
];

function wa_autofill(array &$db, string $jenis, array $k): ?string
{
    $s = WA_AUTOFILL[$jenis] ?? null;
    if (!$s) return null;
    $rows = wa_rows($db, $jenis);
    $d = [$s['nama'] => $k['nama']];
    if ($s['tgl']) $d[$s['tgl']] = $k['tanggal'];
    if ($s['tgl2']) $d[$s['tgl2']] = $k['tanggal_selesai'] ?: $k['tanggal'];
    if ($s['waktu']) $d['waktu'] = $k['waktu'];
    if ($s['lokasi']) $d['lokasi'] = $k['lokasi'];
    if ($s['pj']) $d[$s['pj']] = $k['penanggung_jawab'];
    if ($s['jml']) $d['jumlah_peserta'] = $k['jumlah_dibutuhkan'];
    if ($s['materi']) $d['materi'] = $k['deskripsi']; elseif ($s['cat']) $d[$s['cat']] = $k['deskripsi'];
    if ($s['status']) $d['status'] = $s['status'];
    $id = $k['id'];
    foreach ($rows as $r) if ((is_object($r) ? ($r->id ?? '') : ($r['id'] ?? '')) === $id) { $id = wa_next_id($s['prefix'], $rows); break; }
    $d['id'] = $id;
    $d['dari_kegiatan_id'] = $k['id'];
    $db[$jenis] = array_merge($rows, [$d]);
    return $id;
}

function wa_stempel(): array
{
    return ['tahun_input' => (int)date('Y'), 'bulan_input' => (int)date('n'), 'tanggal_input' => date('Y-m-d')];
}

function wa_tgl_id(?string $iso): string
{
    if (!$iso) return '-';
    $bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($iso);
    return $t ? date('j', $t) . ' ' . $bln[(int)date('n', $t) - 1] . ' ' . date('Y', $t) : $iso;
}

function wa_norm_nama(string $s): string { return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? ''); }

/** Simpan data hasil uraian ke $db. Kembalikan teks konfirmasi atau melempar RuntimeException(pesan galat untuk pengirim). */
function wa_simpan(array &$db, string $cmd, array $d, array $pelaku, string $kategori = ''): string
{
    $now = wa_now_str();
    $sumber = ['sumber' => 'WhatsApp', 'wa_pengirim' => $pelaku['nomor']];
    $oleh = ['nama' => $pelaku['nama'], 'peran' => 'Anggota (WhatsApp)'];

    switch ($cmd) {
        case 'kegiatan': {
            $label = $d['jenis'] ?? 'Lainnya';
            $jenis = array_search($label, WA_JENIS_KEGIATAN, true) ?: 'lainnya';
            $rows = wa_rows($db, 'kegiatan');
            $k = [
                'id' => wa_next_id('KGT', $rows), 'nomor' => str_pad((string)(count($rows) + 1), 3, '0', STR_PAD_LEFT) . '/KGT/' . date('Y'),
                'jenis' => $jenis, 'jenis_label' => WA_JENIS_KEGIATAN[$jenis], 'nama' => $d['nama'],
                'tanggal' => $d['tanggal'] ?? '', 'tanggal_selesai' => ($d['tanggal_selesai'] ?? ($d['tanggal'] ?? '')),
                'waktu' => $d['waktu'] ?? '', 'lokasi' => $d['lokasi'] ?? '', 'penanggung_jawab' => $d['penanggung_jawab'] ?? $pelaku['nama'],
                'no_hp' => $d['no_hp'] ?? $pelaku['nomor'], 'deskripsi' => $d['deskripsi'] ?? '', 'jumlah_dibutuhkan' => max(1, (int)($d['jumlah'] ?? 1)),
                'status_persetujuan' => 'Menunggu Persetujuan', 'keterangan_persetujuan' => '', 'anggota_ids' => [],
                'diajukan_oleh' => $oleh, 'status_pelaksanaan' => 'Draft', 'status_wa' => 'Belum Terkirim', 'wa_log' => [], 'dokumentasi' => '',
                'file_pdf' => null, 'dibuat' => $now, 'diperbarui' => $now,
            ] + $sumber;
            if ($k['tanggal'] && $k['tanggal_selesai'] && $k['tanggal_selesai'] < $k['tanggal']) throw new RuntimeException('Tanggal selesai tidak boleh sebelum tanggal kegiatan.');
            $linked = wa_autofill($db, $jenis, $k);
            if ($linked) $k['linked_record_id'] = $linked;
            $db['kegiatan'] = array_merge($rows, [$k]);
            return "✅ Kegiatan/Tugas tercatat\nID: {$k['id']} ({$k['jenis_label']})\nNama: {$k['nama']}\nTanggal: " . wa_tgl_id($k['tanggal'])
                . ($k['waktu'] ? ' ' . $k['waktu'] : '') . "\nLokasi: " . ($k['lokasi'] ?: '-')
                . "\nStatus: Menunggu persetujuan pimpinan" . ($linked ? "\nTercatat juga di modul {$k['jenis_label']} ($linked)" : '');
        }
        case 'surat': {
            $rows = wa_rows($db, 'surat_masuk');
            $label = $d['jenis'] ?? 'Lainnya';
            $jenis = array_search($label, WA_JENIS_KEGIATAN, true) ?: 'lainnya';
            $nomor = wa_norm_nama($d['nomor_surat']);
            foreach ($rows as $r) if (wa_norm_nama((string)(is_object($r) ? ($r->nomor_surat ?? '') : ($r['nomor_surat'] ?? ''))) === $nomor)
                throw new RuntimeException('Surat dengan nomor ' . $d['nomor_surat'] . ' sudah tercatat. Tidak dicatat ulang.');
            $s = [
                'id' => wa_next_id('SM', $rows), 'status' => 'Menunggu Persetujuan', 'acc' => [], 'keterangan_persetujuan' => '', 'kegiatan_id' => '',
                'diajukan_oleh' => $oleh, 'dibuat' => $now, 'diperbarui' => $now,
                'nomor_surat' => $d['nomor_surat'], 'asal_surat' => $d['asal_surat'] ?? '', 'tanggal_surat' => $d['tanggal_surat'] ?? '',
                'tanggal_diterima' => $d['tanggal_diterima'] ?? date('Y-m-d'), 'perihal' => $d['perihal'], 'jenis' => $jenis, 'jenis_label' => WA_JENIS_KEGIATAN[$jenis],
                'tanggal_kegiatan' => $d['tanggal_kegiatan'] ?? '', 'waktu' => $d['waktu'] ?? '', 'lokasi' => $d['lokasi'] ?? '',
                'penanggung_jawab' => $d['penanggung_jawab'] ?? '', 'no_hp' => $d['no_hp'] ?? '', 'jumlah_dibutuhkan' => max(1, (int)($d['jumlah'] ?? 1)),
                'deskripsi' => $d['deskripsi'] ?? '', 'file_pdf' => null,
            ] + $sumber;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s['tanggal_diterima']) === 0) $s['tanggal_diterima'] = date('Y-m-d');
            $db['surat_masuk'] = array_merge($rows, [$s]);
            return "✅ Surat masuk tercatat\nID: {$s['id']}\nNomor: {$s['nomor_surat']}\nPerihal: {$s['perihal']}\nJenis: {$s['jenis_label']}\nStatus: Menunggu ACC KKS/KSB/KSD/KSO";
        }
        case 'surat_keluar': {
            $rows = wa_rows($db, 'surat_keluar');
            $s = ['id' => wa_next_id('SK', $rows), 'nomor_surat' => $d['nomor_surat'], 'tanggal_surat' => $d['tanggal_surat'] ?? date('Y-m-d'),
                  'tujuan' => $d['tujuan'], 'perihal' => $d['perihal'], 'sifat' => $d['sifat'] ?? 'Biasa', 'lampiran' => $d['lampiran'] ?? '',
                  'penandatangan' => $d['penandatangan'] ?? '', 'keterangan' => $d['keterangan'] ?? '', 'file_pdf' => null,
                  'dibuat_oleh' => $oleh, 'dibuat' => $now, 'diperbarui' => $now] + $sumber;
            $db['surat_keluar'] = array_merge($rows, [$s]);
            return "✅ Surat keluar tercatat\nID: {$s['id']}\nNomor: {$s['nomor_surat']}\nTujuan: {$s['tujuan']}\nPerihal: {$s['perihal']}";
        }
        case 'pembinaan': {
            $rows = wa_rows($db, 'pembinaan');
            $kat = $d['kategori_pembinaan'] ?? ($kategori ?: 'Pembelajaran');
            $r = ['id' => wa_next_id('PBN', $rows), 'kategori_pembinaan' => $kat, 'nama_kegiatan' => $d['nama_kegiatan']];
            foreach (['jenis_pembelajaran', 'tanggal', 'lokasi', 'materi', 'peserta', 'jumlah_peserta', 'jumlah_jam', 'hasil', 'dokumentasi'] as $f) {
                if (!isset($d[$f])) continue;
                if ($kat === 'Samapta' && in_array($f, ['jenis_pembelajaran', 'jumlah_jam', 'hasil'], true)) continue; // hanya untuk Pembelajaran
                $r[$f] = $d[$f];
            }
            $r += ['diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $db['pembinaan'] = array_merge($rows, [$r]);
            return "✅ Pembinaan Tenaga SAR ($kat) tercatat\nID: {$r['id']}\nNama: {$r['nama_kegiatan']}\nTanggal: " . wa_tgl_id($r['tanggal'] ?? '') . "\nLokasi: " . ($r['lokasi'] ?? '-');
        }
        case 'peralatan': {
            $rows = wa_rows($db, 'peralatan');
            $r = ['id' => wa_next_id('PRL', $rows)] + array_intersect_key($d, array_flip(['nama_barang', 'kode_rak', 'lokasi', 'kategori', 'jumlah_baik', 'jumlah_rusak', 'keterangan']));
            $r += ['jumlah_baik' => 0, 'jumlah_rusak' => 0, 'diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $db['peralatan'] = array_merge($rows, [$r]);
            return "✅ Peralatan tercatat\nID: {$r['id']}\nNama: {$r['nama_barang']}\nLokasi: " . ($r['lokasi'] ?? '-') . "\nBaik: {$r['jumlah_baik']} · Rusak: {$r['jumlah_rusak']}";
        }
        case 'kendaraan': {
            $rows = wa_rows($db, 'kendaraan');
            $r = ['id' => wa_next_id('KDR', $rows)] + $d;
            $jenis = $r['jenis_kendaraan'] ?? '';
            $laut = in_array($jenis, ['Kapal', 'RIB / Perahu Karet'], true);
            if ($jenis !== '') $r['sapras'] = $laut ? 'Sapras Laut' : 'Sapras Darat';
            // Isian khusus laut / darat seperti pada formulir web
            foreach ($laut ? ['km_saat_ini', 'bbm'] : ['endurance', 'speed', 'range', 'konsumsi_bbm', 'cadangan_bbm', 'persen_bbm', 'kecepatan_angin', 'arah_angin'] as $f) unset($r[$f]);
            $r += ['diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $db['kendaraan'] = array_merge($rows, [$r]);
            return "✅ Kendaraan tercatat\nID: {$r['id']}\nNama: {$r['nama_kendaraan']}" . ($jenis ? "\nJenis: $jenis" : '')
                . (!empty($r['nomor_polisi']) ? "\nNopol/Registrasi: {$r['nomor_polisi']}" : '') . "\nKondisi: " . ($r['kondisi'] ?? '-');
        }
        case 'peminjaman': {
            $rows = wa_rows($db, 'peminjaman');
            $jenis = $d['jenis_barang'] ?? 'Peralatan';
            $jml = max(1, (int)($d['jumlah'] ?? 1));
            $n = wa_norm_nama($d['nama_barang']);
            $catatan = '';
            $lokasiPinjam = '';
            if ($jenis === 'Peralatan') {
                $lk = trim((string)($d['lokasi'] ?? ''));
                $cand = array_values(array_filter(wa_stok_alat($db), static fn($e) => $e['n'] === $n));
                if ($cand) { // stok dihitung per nama + lokasi (barang bernama sama di lokasi lain tidak ikut)
                    $alat = null;
                    if ($lk !== '') foreach ($cand as $e) if (wa_norm_nama($e['lk']) === wa_norm_nama($lk)) { $alat = $e; break; }
                    if ($alat === null) { // lokasi tidak disebut: pakai entri pertama yang stoknya cukup
                        $alat = $cand[0];
                        foreach ($cand as $e) if ($e['sisa'] >= $jml) { $alat = $e; break; }
                    }
                    if ($jml > $alat['sisa']) throw new RuntimeException('Stok "' . $alat['nama'] . '"' . ($alat['lk'] !== '' ? ' di ' . $alat['lk'] : '') . ' tersedia hanya ' . max(0, $alat['sisa']) . ', jumlah pinjam ' . $jml . ' melebihi stok.');
                    $lokasiPinjam = $alat['lk'];
                } else $catatan = "\n⚠️ Nama barang belum ada di data Peralatan (dicatat apa adanya).";
            } elseif ($jenis === 'Kendaraan') {
                $jml = 1; // kendaraan dipinjam per unit
                foreach ($rows as $p) {
                    $p = (array)$p;
                    if (($p['jenis_barang'] ?? '') === 'Kendaraan' && wa_norm_nama((string)($p['nama_barang'] ?? '')) === $n
                        && wa_pinjam_aktif($p))
                        throw new RuntimeException('Kendaraan "' . $d['nama_barang'] . '" masih tercatat dipinjam oleh ' . ($p['nama_peminjam'] ?? '-') . '.');
                }
            }
            $r = ['id' => wa_next_id('PMJ', $rows), 'nama_peminjam' => $d['nama_peminjam'], 'jenis_barang' => $jenis, 'nama_barang' => $d['nama_barang'],
                  'jumlah' => $jml, 'tanggal' => $d['tanggal'] ?? date('Y-m-d'), 'status' => 'Menunggu'];
            foreach (['tanggal_kembali_rencana', 'keperluan', 'penanggung_jawab', 'keterangan'] as $f) if (isset($d[$f])) $r[$f] = $d[$f];
            if ($lokasiPinjam !== '') $r['lokasi'] = $lokasiPinjam; // untuk hitung stok per lokasi
            // Peminjaman baru menunggu ACC/TOLAK penyetuju peminjaman (WA_NOMOR_APPROVER_PINJAM) -> status jadi Dipinjam / Ditolak.
            $r += ['status_persetujuan' => 'Menunggu Persetujuan', 'keterangan_persetujuan' => '', 'alasan_penolakan' => '', 'diajukan_oleh' => $oleh, 'diajukan_waktu' => $now,
                   'diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $db['peminjaman'] = array_merge($rows, [$r]);
            return "✅ Peminjaman tercatat\nID: {$r['id']}\nPeminjam: {$r['nama_peminjam']}\n{$jenis}: {$r['nama_barang']} x{$jml}\nPinjam: " . wa_tgl_id($r['tanggal'])
                . (!empty($r['tanggal_kembali_rencana']) ? "\nRencana kembali: " . wa_tgl_id($r['tanggal_kembali_rencana']) : '')
                . "\nStatus: ⏳ Menunggu persetujuan " . WA_NAMA_APPROVER_PINJAM . $catatan;
        }
        case 'potensi': {
            $rows = wa_rows($db, 'potensi');
            $n = wa_norm_nama($d['nama_potensi']);
            foreach ($rows as $x) {
                $x = (array)$x;
                if (wa_norm_nama((string)($x['nama_potensi'] ?? '')) === $n)
                    throw new RuntimeException('Potensi "' . $d['nama_potensi'] . '" sudah tercatat (' . ($x['id'] ?? '-') . '). Tidak dicatat ulang.');
            }
            $r = ['id' => wa_next_id('POT', $rows)] + array_intersect_key($d, array_flip(['nama_potensi', 'kategori', 'nama_kontak', 'nomor_kontak', 'alamat', 'wilayah', 'kompetensi',
                  'jumlah_tersertifikasi', 'jumlah_belum_sertifikasi', 'status', 'alut_sapras', 'keterangan', 'dokumentasi']));
            $r += ['jumlah_tersertifikasi' => 0, 'jumlah_belum_sertifikasi' => 0, 'status' => 'Aktif'] + wa_stempel() + $sumber;
            $r['diperbarui_oleh'] = $oleh; $r['diperbarui_waktu'] = $now;
            $db['potensi'] = array_merge($rows, [$r]);
            return "✅ Potensi tercatat\nID: {$r['id']}\nNama: {$r['nama_potensi']}" . (!empty($r['kategori']) ? "\nKategori: {$r['kategori']}" : '')
                . "\nWilayah: " . ($r['wilayah'] ?? '-') . "\nTersertifikasi: {$r['jumlah_tersertifikasi']} · Belum: {$r['jumlah_belum_sertifikasi']}";
        }
        case 'pengajuan': {
            $rows = wa_rows($db, 'pemeliharaan');
            $kat = $d['kategori_pengajuan'] ?? ($kategori ?: 'Kebutuhan');
            $r = ['id' => wa_next_id('PGJ', $rows), 'kategori_pengajuan' => $kat, 'jenis_barang' => $d['jenis_barang'] ?? 'Peralatan',
                  'nama_barang' => $d['nama_barang'], 'tanggal' => $d['tanggal'] ?? date('Y-m-d')];
            if ($kat === 'Kebutuhan') {
                $r['jumlah_kebutuhan'] = max(1, (int)($d['jumlah'] ?? 1));
                foreach (['estimasi_biaya', 'tingkat_urgensi', 'alasan'] as $f) if (isset($d[$f])) $r[$f] = $d[$f];
            } else {
                if ($r['jenis_barang'] === 'Peralatan') $r['jumlah'] = max(1, (int)($d['jumlah'] ?? 1));
                foreach (['jenis_pemeliharaan', 'pelaksana', 'uraian', 'suku_cadang', 'biaya', 'jadwal_berikutnya'] as $f) if (isset($d[$f])) $r[$f] = $d[$f];
                if ($r['jenis_barang'] === 'Kendaraan' && isset($d['km_saat_ini'])) $r['km_saat_ini'] = $d['km_saat_ini'];
                $r['status'] = 'Dijadwalkan';
            }
            if (isset($d['keterangan'])) $r['keterangan'] = $d['keterangan'];
            $r += ['status_persetujuan' => 'Menunggu Persetujuan', 'diajukan_oleh' => $oleh, 'diajukan_waktu' => $now,
                   'diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $db['pemeliharaan'] = array_merge($rows, [$r]);
            return "✅ Pengajuan $kat tercatat\nID: {$r['id']}\n{$r['jenis_barang']}: {$r['nama_barang']}\nStatus: Menunggu ACC KKS/KSO/KSD/Admin";
        }
    }
    throw new RuntimeException('Perintah tidak dikenal.');
}

/* ============================== Balasan "data tersimpan" + lampiran + perintah SELESAI ============================== */

/** Kata kunci perintah -> [kunci data di database, nama modul di balasan]. */
/**
 * Stok peralatan per entri (nama + lokasi). Peminjaman/pemeliharaan aktif mengurangi entri yang lokasinya sama;
 * data tanpa lokasi (lama / dari dashboard) mengurangi entri pertama dengan nama itu. Hasil: [['nama','n','lk','rak','sisa'],...]
 */
function wa_stok_alat(array $db): array
{
    $ent = [];
    foreach (wa_rows($db, 'peralatan') as $a) {
        $a = (array)$a;
        $nama = trim((string)($a['nama_barang'] ?? ''));
        if ($nama === '') continue;
        $ent[] = ['nama' => $nama, 'n' => wa_norm_nama($nama), 'lk' => trim((string)($a['lokasi'] ?? '')), 'rak' => trim((string)($a['kode_rak'] ?? '')), 'sisa' => (int)floor((float)($a['jumlah_baik'] ?? 0))];
    }
    $cari = static function (string $n, string $lk) use (&$ent): ?int {
        $first = null;
        foreach ($ent as $i => $e) {
            if ($e['n'] !== $n) continue;
            if ($first === null) $first = $i;
            if ($lk !== '' && wa_norm_nama($e['lk']) === wa_norm_nama($lk)) return $i;
        }
        return $first;
    };
    foreach (wa_rows($db, 'peminjaman') as $p) {
        $p = (array)$p;
        if (($p['jenis_barang'] ?? '') !== 'Peralatan' || !wa_pinjam_aktif($p)) continue;
        $i = $cari(wa_norm_nama((string)($p['nama_barang'] ?? '')), trim((string)($p['lokasi'] ?? '')));
        if ($i !== null) $ent[$i]['sisa'] -= (int)($p['jumlah'] ?? 1) ?: 1;
    }
    foreach (wa_rows($db, 'pemeliharaan') as $p) {
        $p = (array)$p;
        if (($p['status_persetujuan'] ?? 'Disetujui') === 'Ditolak' || ($p['status'] ?? '') !== 'Sedang Dikerjakan' || ($p['jenis_barang'] ?? '') !== 'Peralatan') continue;
        $i = $cari(wa_norm_nama((string)($p['nama_barang'] ?? '')), trim((string)($p['lokasi'] ?? '')));
        if ($i !== null) $ent[$i]['sisa'] -= (int)($p['jumlah'] ?? 1) ?: 1;
    }
    return $ent;
}

/**
 * Daftar barang yang bisa dipinjam untuk formulir Peminjaman (tautan WhatsApp).
 * Peralatan: stok tersedia = Jumlah Baik - sedang dipinjam - sedang dipelihara (sama dengan pemeriksaan di wa_simpan).
 * Kendaraan: tidak tersedia bila masih tercatat dipinjam.
 * Hasil: ['Peralatan' => [['n'=>nama,'l'=>label,'s'=>stok,'habis'=>bool],...], 'Kendaraan' => [...]]
 */
function wa_daftar_pinjam(array $db): array
{
    $alatPinjam = []; $kdrPinjam = []; $alatPelihara = []; $kdrPelihara = [];
    foreach (wa_rows($db, 'peminjaman') as $p) {
        $p = (array)$p;
        if (!wa_pinjam_aktif($p)) continue;
        $n = wa_norm_nama((string)($p['nama_barang'] ?? ''));
        if (($p['jenis_barang'] ?? '') === 'Peralatan') $alatPinjam[$n] = ($alatPinjam[$n] ?? 0) + ((int)($p['jumlah'] ?? 1) ?: 1);
        elseif (($p['jenis_barang'] ?? '') === 'Kendaraan') $kdrPinjam[$n] = true;
    }
    foreach (wa_rows($db, 'pemeliharaan') as $p) {
        $p = (array)$p;
        if (($p['status_persetujuan'] ?? 'Disetujui') === 'Ditolak' || ($p['status'] ?? '') !== 'Sedang Dikerjakan') continue;
        $n = wa_norm_nama((string)($p['nama_barang'] ?? ''));
        if (($p['jenis_barang'] ?? '') === 'Peralatan') $alatPelihara[$n] = ($alatPelihara[$n] ?? 0) + ((int)($p['jumlah'] ?? 1) ?: 1);
        elseif (($p['jenis_barang'] ?? '') === 'Kendaraan') $kdrPelihara[$n] = true;
    }
    $out = ['Peralatan' => [], 'Kendaraan' => []];
    foreach (wa_stok_alat($db) as $e) {
        $stok = max(0, $e['sisa']);
        $out['Peralatan'][] = ['n' => $e['nama'], 'lk' => $e['lk'], 'l' => $e['nama'] . ($e['rak'] !== '' ? " [{$e['rak']}]" : '') . ' — tersedia ' . $stok, 's' => $stok, 'habis' => $stok <= 0];
    }
    foreach (wa_rows($db, 'kendaraan') as $k) {
        $k = (array)$k;
        $nama = trim((string)($k['nama_kendaraan'] ?? ''));
        if ($nama === '') continue;
        $n = wa_norm_nama($nama);
        $np = trim((string)($k['nomor_polisi'] ?? ''));
        $pakai = isset($kdrPinjam[$n]);
        $out['Kendaraan'][] = ['n' => $nama, 'lk' => trim((string)($k['lokasi'] ?? '')), 'l' => $nama . ($np !== '' ? " [$np]" : '') . ($pakai ? ' — sedang dipinjam' : (isset($kdrPelihara[$n]) ? ' — sedang dipelihara' : ' — tersedia')),
                               's' => null, 'habis' => $pakai];
    }
    usort($out['Peralatan'], static fn($x, $y) => strcasecmp($x['n'], $y['n']));
    usort($out['Kendaraan'], static fn($x, $y) => strcasecmp($x['n'], $y['n']));
    return $out;
}

const WA_MODUL_DATA = [
    'kegiatan'     => ['kegiatan',     'Kegiatan/Tugas'],
    'surat'        => ['surat_masuk',  'Persuratan — Surat Masuk'],
    'surat_keluar' => ['surat_keluar', 'Persuratan — Surat Keluar'],
    'pembinaan'    => ['pembinaan',    'Pembinaan Tenaga SAR'],
    'peralatan'    => ['peralatan',    'Peralatan'],
    'kendaraan'    => ['kendaraan',    'Kendaraan'],
    'peminjaman'   => ['peminjaman',   'Peminjaman'],
    'pengajuan'    => ['pemeliharaan', 'Pengajuan'],
    'potensi'      => ['potensi',      'Potensi'],
];

/** Judul singkat data untuk ditampilkan di halaman upload. */
function wa_judul_data(string $cmd, array $d): string
{
    $kunci = ['kegiatan' => 'nama', 'surat' => 'perihal', 'surat_keluar' => 'perihal', 'pembinaan' => 'nama_kegiatan',
              'peralatan' => 'nama_barang', 'kendaraan' => 'nama_kendaraan', 'peminjaman' => 'nama_barang', 'pengajuan' => 'nama_barang', 'potensi' => 'nama_potensi'];
    return (string)($d[$kunci[$cmd] ?? ''] ?? '');
}

/**
 * Ubah teks konfirmasi wa_simpan() menjadi balasan lengkap:
 * "Data tersimpan di <modul>" + detail + ID untuk disimpan + (Kegiatan) cara menyelesaikan + tautan upload lampiran.
 */
function wa_balasan_tersimpan(PDO $p, string $cmd, string $detail, array $data, array $pelaku, bool $tautan = true): string
{
    [$dbKey, $modul] = WA_MODUL_DATA[$cmd] ?? [null, ''];
    if ($dbKey === null || !preg_match('/^ID: (\S+)(?: \((.+)\))?\s*$/mu', $detail, $m)) return $detail;
    $id = $m[1];
    $jenis = $m[2] ?? '';

    // Buang baris judul "✅ ... tercatat" dan baris ID (ID ditampilkan terpisah di bawah).
    $baris = preg_split('/\R/u', $detail) ?: [];
    array_shift($baris);
    $isi = [];
    foreach ($baris as $b) {
        if (preg_match('/^ID: /u', $b)) { if ($jenis !== '') $isi[] = 'Jenis: ' . $jenis; continue; }
        $isi[] = $b;
    }
    $judul = wa_judul_data($cmd, $data);
    // Baris yang hanya mengulang judul (mis. "Nama: ...") dibuang karena sudah tampil di baris judul.
    if ($judul !== '') $isi = array_values(array_filter($isi, static fn($b) => !(preg_match('/^[^:]{1,30}: (.+)$/u', $b, $mm) && trim($mm[1]) === $judul)));
    $out = "✅ Data tersimpan di *$modul*.\n" . ($judul !== '' ? "$modul: $judul\n" : '') . implode("\n", $isi) . "\n\n";
    $out .= "🔖 *Simpan ID ini: $id*\n";
    if ($cmd === 'kegiatan') $out .= "Kalau kegiatan ini sudah selesai dilaksanakan, balas dengan \"Selesai $id\".\n";
    if ($cmd === 'peminjaman') $out .= "⏳ Permintaan dikirim ke " . WA_NAMA_APPROVER_PINJAM . " untuk di-ACC. Anda akan menerima notifikasi WhatsApp saat disetujui (status *Dipinjam*) atau ditolak (beserta alasannya).\nSetelah disetujui dan barang dikembalikan, balas *Kembali $id* (ketik *KEMBALI* saja untuk melihat daftar pinjaman Anda).\n";

    if (!$tautan) return $out . "\n📎 Lampiran sudah terkirim.";
    try {
        $tok = wl_token_buat($p, $dbKey, $id, $modul, $judul, $pelaku['nomor'], $pelaku['nama']);
        if ($cmd === 'potensi') {
            $out .= "\n📎 Upload Lampiran (file atau link):\n" . wl_link($tok) . "\n\n"
                . "Di halaman itu Anda boleh mengirim 1 foto/PDF *atau* menempelkan link (mis. Google Drive). Foto otomatis dikompres.\n\n"
                . "Atau langsung kirim foto/PDF-nya di chat ini (pesan terpisah, tanpa teks) dalam " . wl_jendela_menit() . " menit.";
        } else
        $out .= "\n📷 Upload Lampiran PDF:\n" . wl_link($tok) . "\n\n"
            . "Maksimal 1 foto/file. Foto otomatis dikompres agar hemat penyimpanan.\n\n"
            . "Atau langsung kirim foto/PDF-nya di chat ini (pesan terpisah, tanpa teks) dalam " . wl_jendela_menit() . " menit.";
    } catch (Throwable $e) {
        wa_log('TOKEN UPLOAD GAGAL: ' . $e->getMessage());
        $out .= "\n(Tautan upload lampiran sedang tidak tersedia.)";
    }
    return $out;
}

/**
 * Balasan WA untuk satu formulir Peminjaman yang berisi beberapa barang.
 * $items: tiap elemen = ['id'=>..., 'jenis'=>..., 'nama'=>..., 'jumlah'=>...].
 */
function wa_balasan_pinjam_multi(PDO $p, array $items, array $data, array $pelaku, bool $tautan = true): string
{
    $out = "✅ Data tersimpan di *Peminjaman*.\nPeminjam: " . ($data['nama_peminjam'] ?? '-') . "\nPinjam: " . wa_tgl_id($data['tanggal'] ?? date('Y-m-d'))
        . (!empty($data['tanggal_kembali_rencana']) ? "\nRencana kembali: " . wa_tgl_id($data['tanggal_kembali_rencana']) : '')
        . "\n\n*" . count($items) . " barang dipinjam:*\n";
    foreach ($items as $i => $it) $out .= ($i + 1) . '. ' . $it['nama'] . ' x' . $it['jumlah'] . ' (' . $it['jenis'] . ') — ID ' . $it['id'] . "\n";
    $out .= "\n🔖 *Simpan ID ini: " . implode(', ', array_column($items, 'id')) . "*\n";
    $out .= "⏳ Status: *Menunggu* persetujuan " . WA_NAMA_APPROVER_PINJAM . ". Anda akan menerima notifikasi WhatsApp saat disetujui (*Dipinjam*) atau ditolak (beserta alasannya).\nSetelah disetujui dan barang dikembalikan, balas *Kembali <ID>* atau *KEMBALI SEMUA*.\n";
    if (!$tautan) return $out . "\n📎 Lampiran sudah terkirim (melekat pada " . $items[0]['id'] . ').';
    try {
        $judul = $items[0]['nama'] . ' +' . (count($items) - 1) . ' lainnya';
        $tok = wl_token_buat($p, WA_MODUL_DATA['peminjaman'][0], $items[0]['id'], WA_MODUL_DATA['peminjaman'][1], $judul, $pelaku['nomor'], $pelaku['nama']);
        $out .= "\n📷 Upload Lampiran PDF (melekat pada " . $items[0]['id'] . "):\n" . wl_link($tok) . "\n\n"
            . "Maksimal 1 foto/file. Foto otomatis dikompres agar hemat penyimpanan.";
    } catch (Throwable $e) {
        wa_log('TOKEN UPLOAD GAGAL: ' . $e->getMessage());
        $out .= "\n(Tautan upload lampiran sedang tidak tersedia.)";
    }
    return $out;
}

/** Siapa yang boleh menandai Kegiatan selesai: pengaju via WA, penanggung jawab, anggota bertugas, atau nomor di WA_NOMOR_DIIZINKAN. */
function wa_boleh_selesai(array $db, array $k, array $pelaku): bool
{
    if (($k['wa_pengirim'] ?? '') !== '' && wa_nomor((string)$k['wa_pengirim']) === $pelaku['nomor']) return true;
    foreach (WA_NOMOR_DIIZINKAN as $no) if (wa_nomor($no) === $pelaku['nomor']) return true;
    $nama = wa_norm_nama($pelaku['nama']);
    if ($nama === '') return false;
    if (wa_norm_nama((string)($k['penanggung_jawab'] ?? '')) === $nama) return true;
    foreach ($db['anggota'] ?? [] as $a) {
        $a = (array)$a;
        if (wa_norm_nama((string)($a['nama'] ?? '')) === $nama && in_array($a['id'] ?? null, (array)($k['anggota_ids'] ?? []), true)) return true;
    }
    return false;
}

/** Perintah "SELESAI <ID>". Kembalikan [pesan balasan, apakah data berubah]. */
function wa_selesai(array &$db, string $teks, array $pelaku): array
{
    if (!preg_match('/^[\s:\-#]*([A-Za-z]{2,5})[\s\-]*0*(\d{1,6})\b/u', $teks, $m))
        return ["❌ Sebutkan ID kegiatannya.\nContoh: *Selesai KGT-006*", false];
    $pre = strtoupper($m[1]); $no = (int)$m[2];
    if ($pre === 'KEG') $pre = 'KGT'; // "KEG-4" dianggap sama dengan KGT
    $rows = wa_rows($db, 'kegiatan');
    $idx = null;
    foreach ($rows as $i => $r) {
        $r = (array)$r;
        if (preg_match('/^([A-Za-z]+)-0*(\d+)$/', (string)($r['id'] ?? ''), $x) && strtoupper($x[1]) === $pre && (int)$x[2] === $no) { $idx = $i; break; }
    }
    if ($idx === null) return ["❌ Kegiatan dengan ID \"{$m[1]}-{$m[2]}\" tidak ditemukan. Periksa kembali ID dari pesan konfirmasi.", false];
    $k = $rows[$idx];
    $id = (string)$k['id'];
    if (!wa_boleh_selesai($db, $k, $pelaku))
        return ["⛔ Hanya pengaju, penanggung jawab, atau anggota yang bertugas di $id yang dapat menandainya selesai.", false];
    if (($k['status_persetujuan'] ?? '') !== 'Disetujui') {
        $st = (string)($k['status_persetujuan'] ?? '-');
        return ["⏳ Kegiatan $id belum bisa diselesaikan: status persetujuan masih \"$st\".\nTunggu validasi Admin/Validator, lalu kirim ulang perintah ini.", false];
    }
    if (($k['status_pelaksanaan'] ?? '') === 'Selesai') return ["ℹ️ Kegiatan $id ({$k['nama']}) sudah berstatus Selesai.", false];

    $rows[$idx]['status_pelaksanaan'] = 'Selesai';
    $rows[$idx]['diperbarui'] = wa_now_str();
    $rows[$idx]['selesai_via_wa'] = ['nama' => $pelaku['nama'], 'nomor' => $pelaku['nomor'], 'waktu' => wa_now_str()];
    $db['kegiatan'] = $rows;

    // Samakan status data di modul terkait (seperti syncModuleStatusFromKegiatan di dashboard).
    $lid = (string)($k['linked_record_id'] ?? ''); $jn = (string)($k['jenis'] ?? '');
    if ($lid !== '' && $jn !== '' && isset(WA_AUTOFILL[$jn]) && WA_AUTOFILL[$jn]['status'] === 'Direncanakan') {
        $mr = wa_rows($db, $jn);
        foreach ($mr as $i => $r) {
            $r = (array)$r;
            if (($r['id'] ?? '') === $lid && in_array($r['status'] ?? '', ['Direncanakan', 'Berlangsung'], true)) { $mr[$i]['status'] = 'Selesai'; break; }
        }
        $db[$jn] = $mr;
    }
    return ["✅ Kegiatan $id ({$k['nama']}) ditandai *Selesai*.\nData dipindahkan ke daftar Tugas Selesai di dashboard." . (empty($k['lampiran_files']) ? "\n\nBelum ada lampiran? Kirim foto/PDF-nya ke chat ini atau pakai tautan upload dari pesan konfirmasi." : ''), true];
}

/** Siapa yang boleh mengembalikan peminjaman: pengirim data, peminjam, penanggung jawab, nomor diizinkan, atau pimpinan. */
function wa_boleh_kembali(array $r, array $pelaku): bool
{
    if (($r['wa_pengirim'] ?? '') !== '' && wa_nomor((string)$r['wa_pengirim']) === $pelaku['nomor']) return true;
    foreach (WA_NOMOR_DIIZINKAN as $no) if (wa_nomor($no) === $pelaku['nomor']) return true;
    if (function_exists('acc_is_approver') && acc_is_approver($pelaku['nomor'])) return true;
    $nama = wa_norm_nama($pelaku['nama']);
    if ($nama === '' || $nama === wa_norm_nama($pelaku['nomor'])) return false;
    return wa_norm_nama((string)($r['nama_peminjam'] ?? '')) === $nama || wa_norm_nama((string)($r['penanggung_jawab'] ?? '')) === $nama;
}

/** Barang masih "terpakai" (stok dicadangkan): belum dikembalikan dan tidak ditolak. Status Menunggu (belum di-ACC) ikut dihitung. */
function wa_pinjam_aktif(array $r): bool { return !in_array(($r['status'] ?? ''), ['Dikembalikan', 'Ditolak'], true) && empty($r['tanggal_kembali']); }
/** Sudah di-ACC dan sedang dipinjam (boleh dikembalikan). */
function wa_pinjam_berjalan(array $r): bool { return wa_pinjam_aktif($r) && ($r['status'] ?? '') !== 'Menunggu'; }

/**
 * Perintah pengembalian: "KEMBALI" (daftar pinjaman aktif), "KEMBALI PMJ-001 [PMJ-002 ...]", "KEMBALI PMJ-001 2" (sebagian, 2 unit), "KEMBALI SEMUA".
 * Status menjadi Dikembalikan + Tanggal Dikembalikan hari ini, sehingga stok Tersedia di modul Peralatan pulih otomatis.
 * Kembalikan [pesan balasan, apakah data berubah].
 */
function wa_kembali(array &$db, string $teks, array $pelaku, ?PDO $p = null): array
{
    $rows = array_map(static fn($r) => (array)$r, array_values(wa_rows($db, 'peminjaman')));
    $teks = trim($teks);
    $aktif = [];
    foreach ($rows as $i => $r) if (wa_pinjam_berjalan($r) && wa_boleh_kembali($r, $pelaku)) $aktif[$i] = $r;

    if ($teks === '') {
        if (!$aktif) return ["ℹ️ Tidak ada peminjaman aktif atas nama Anda.", false];
        $out = "📦 *Peminjaman yang belum dikembalikan* (" . count($aktif) . "):\n";
        $n = 0;
        foreach ($aktif as $r) {
            if (++$n > 20) { $out .= "…dan " . (count($aktif) - 20) . " lainnya\n"; break; }
            $out .= "• {$r['id']} — " . ($r['nama_barang'] ?? '-') . ' x' . ((int)($r['jumlah'] ?? 1) ?: 1) . ' (' . ($r['nama_peminjam'] ?? '-') . '), sejak ' . wa_tgl_id($r['tanggal'] ?? '') . "\n";
        }
        return [$out . "\nKembalikan: *KEMBALI PMJ-001* (beberapa: *KEMBALI PMJ-001 PMJ-002*)\nSebagian: *KEMBALI PMJ-001 1* (1 unit)\nSemua milik Anda: *KEMBALI SEMUA*", false];
    }

    $targets = [];
    if (preg_match('/^[\s:\-]*(semua|semuanya|all)\b/ui', $teks)) {
        foreach ($aktif as $r) if (preg_match('/(\d+)$/', (string)($r['id'] ?? ''), $x)) $targets[] = ['no' => (int)$x[1], 'qty' => null];
        if (!$targets) return ["ℹ️ Tidak ada peminjaman aktif atas nama Anda.", false];
    } else {
        $norm = preg_replace('/\bpmj\s*[-:]?\s*(\d)/ui', 'PMJ-$1', $teks) ?? $teks;
        $norm = preg_replace('/[,;]+/', ' ', $norm) ?? $norm;
        foreach (preg_split('/\s+/u', trim($norm)) ?: [] as $tk) {
            if ($tk === '') continue;
            if (preg_match('/^PMJ-0*(\d+)$/i', $tk, $m)) { $targets[] = ['no' => (int)$m[1], 'qty' => null]; continue; }
            if ($targets && $targets[count($targets) - 1]['qty'] === null && preg_match('/^(?:x|jumlah|jml)?[:=]?(\d{1,4})$/i', $tk, $m)) { $targets[count($targets) - 1]['qty'] = (int)$m[1]; continue; }
        }
        if (!$targets) return ["❌ Sebutkan ID peminjamannya.\nContoh: *Kembali PMJ-003*\nKetik *KEMBALI* saja untuk melihat daftar pinjaman Anda.", false];
    }

    $now = wa_now_str(); $hari = date('Y-m-d'); $baris = []; $ubah = false;
    $buktiList = []; // data yang baru dikembalikan -> tiap data dapat tautan upload bukti pengembalian
    $oleh = ['nama' => $pelaku['nama'], 'peran' => 'Anggota (WhatsApp)'];
    foreach ($targets as $t) {
        $idx = null;
        foreach ($rows as $i => $r) if (preg_match('/^PMJ-0*(\d+)$/i', (string)($r['id'] ?? ''), $x) && (int)$x[1] === $t['no']) { $idx = $i; break; }
        $label = 'PMJ-' . str_pad((string)$t['no'], 3, '0', STR_PAD_LEFT);
        if ($idx === null) { $baris[] = "❌ $label tidak ditemukan."; continue; }
        $r = $rows[$idx]; $id = (string)$r['id']; $nm = (string)($r['nama_barang'] ?? '-');
        if (!wa_boleh_kembali($r, $pelaku)) { $baris[] = "⛔ $id: hanya peminjam, penanggung jawab, atau pengirim data yang dapat mengembalikan."; continue; }
        if (($r['status'] ?? '') === 'Menunggu') { $baris[] = "⏳ $id ($nm) masih menunggu persetujuan " . WA_NAMA_APPROVER_PINJAM . ", belum bisa dikembalikan."; continue; }
        if (($r['status'] ?? '') === 'Ditolak') { $baris[] = "❌ $id ($nm) ditolak, tidak ada barang yang perlu dikembalikan."; continue; }
        if (!wa_pinjam_aktif($r)) { $baris[] = "ℹ️ $id ($nm) sudah berstatus dikembalikan."; continue; }
        $jml = max(1, (int)($r['jumlah'] ?? 1));
        $qty = ($r['jenis_barang'] ?? '') === 'Kendaraan' ? null : $t['qty'];
        if ($qty !== null && $qty < 1) { $baris[] = "❌ $id: jumlah dikembalikan minimal 1."; continue; }
        if ($qty !== null && $qty > $jml) { $baris[] = "❌ $id ($nm): jumlah dikembalikan $qty melebihi yang dipinjam ($jml)."; continue; }
        if ($qty !== null && $qty < $jml) { // pengembalian sebagian: pecah menjadi data dikembalikan + sisa masih dipinjam
            $baru = $r;
            foreach (array_keys($baru) as $k) if (str_starts_with((string)$k, 'lampiran')) unset($baru[$k]);
            $baru['id'] = wa_next_id('PMJ', $rows);
            $baru['jumlah'] = $qty; $baru['status'] = 'Dikembalikan'; $baru['tanggal_kembali'] = $hari;
            $baru['keterangan'] = trim((string)($r['keterangan'] ?? '') . ' Dikembalikan sebagian dari ' . $id . '.');
            $baru['diperbarui_oleh'] = $oleh; $baru['diperbarui_waktu'] = $now;
            $rows[$idx]['jumlah'] = $jml - $qty; $rows[$idx]['diperbarui_oleh'] = $oleh; $rows[$idx]['diperbarui_waktu'] = $now;
            $rows[] = $baru;
            $baris[] = "✅ $id $nm: $qty unit dikembalikan, sisa " . ($jml - $qty) . " unit masih dipinjam (dicatat sebagai {$baru['id']}).";
            $buktiList[] = ['id' => (string)$baru['id'], 'nm' => $nm . ' x' . $qty];
        } else {
            $rows[$idx]['status'] = 'Dikembalikan'; $rows[$idx]['tanggal_kembali'] = $hari;
            $rows[$idx]['diperbarui_oleh'] = $oleh; $rows[$idx]['diperbarui_waktu'] = $now;
            $rows[$idx]['dikembalikan_via_wa'] = ['nama' => $pelaku['nama'], 'nomor' => $pelaku['nomor'], 'waktu' => $now];
            $baris[] = "✅ $id $nm x$jml dikembalikan.";
            $buktiList[] = ['id' => $id, 'nm' => $nm . ' x' . $jml];
        }
        $ubah = true;
    }
    if ($ubah) $db['peminjaman'] = $rows;
    return ["📦 *Pengembalian*\n" . implode("\n", $baris) . ($ubah ? "\n\nStok Peralatan di dashboard otomatis bertambah kembali." : '') . wa_tautan_bukti_kembali($p, $buktiList, $pelaku), $ubah];
}

/**
 * Tautan upload BUKTI PENGEMBALIAN (foto/PDF) untuk tiap data peminjaman yang baru dikembalikan.
 * Satu tautan = satu data = 1 foto/file; file masuk ke lampiran data tersebut dan ditandai "Bukti pengembalian" di dashboard.
 * Kegagalan membuat tautan tidak menggagalkan pengembalian (hanya pesan tambahan yang berubah).
 */
function wa_tautan_bukti_kembali(?PDO $p, array $daftar, array $pelaku): string
{
    if (!$p || !$daftar) return '';
    $o = [];
    foreach ($daftar as $d) {
        try {
            $tok = wl_token_buat($p, WA_MODUL_DATA['peminjaman'][0], (string)$d['id'], 'Peminjaman — Bukti Pengembalian', (string)$d['nm'], $pelaku['nomor'], $pelaku['nama'], 'bukti_kembali');
            $o[] = $d['id'] . ' (' . $d['nm'] . "):\n" . wl_link($tok);
        } catch (Throwable $e) {
            wa_log('TOKEN BUKTI KEMBALI GAGAL: ' . $e->getMessage());
        }
    }
    if (!$o) return "\n\n(Tautan upload bukti pengembalian sedang tidak tersedia.)";
    return "\n\n📷 *Upload bukti pengembalian* (foto/PDF barang yang sudah dikembalikan):\n" . implode("\n\n", $o)
        . "\n\nMaksimal 1 foto/file per tautan · foto otomatis dikompres · tautan berlaku " . wl_link_hari() . " hari.";
}

/** Lampiran yang dikirim langsung ke chat (tanpa teks). Kembalikan teks balasan. */
function wa_terima_lampiran(PDO $p, array $in, array $pelaku): string
{
    $tok = wl_token_chat_terbaru($p, $pelaku['nomor']);
    if (!$tok) return "ℹ️ Tidak ada data yang menunggu lampiran.\nKirim lampiran maksimal " . wl_jendela_menit() . " menit setelah data tersimpan, atau pakai tautan upload dari pesan konfirmasi.";
    $url = trim((string)($in['url'] ?? $in['file'] ?? $in['media'] ?? ''));
    $tmp = DATA_DIR . '/wa_' . bin2hex(random_bytes(6));
    $err = wl_unduh($url, $tmp);
    if ($err !== null) { wa_log("LAMPIRAN gagal unduh: $err"); return "❌ Lampiran belum masuk: $err"; }
    $nama = trim((string)($in['filename'] ?? '')) ?: 'lampiran';
    $h = wl_simpan($p, $tok, $tmp, $nama, 'chat', false); // kunci data sudah dipegang alur utama
    @unlink($tmp);
    if (!$h['ok']) return "❌ Lampiran belum masuk: " . $h['error'];
    return "✅ Lampiran diterima untuk *{$tok['rec_id']}*" . ($tok['judul'] !== '' ? " ({$tok['judul']})" : '') . ".\n{$h['meta']['name']} · " . max(1, (int)round($h['meta']['size'] / 1024)) . " KB";
}

/* ============================== Teks panduan ============================== */

function wa_bantuan(): string
{
    return "📋 *Input Data Dashboard BASARNAS via WhatsApp*\n\n"
        . "Awali pesan dengan kata kunci di baris pertama, lalu isi tiap data dengan format *Nama: isi* (satu per baris):\n\n"
        . "• *KEGIATAN* – kegiatan/tugas\n• *SURAT MASUK* / *SURAT KELUAR* – persuratan\n• *PEMBINAAN* – pembinaan tenaga SAR\n"
        . "• *POTENSI* – data potensi SAR (ketik *FORM POTENSI* untuk tautan formulir; lampiran boleh file atau link)\n• *PERALATAN*\n• *KENDARAAN*\n• *PINJAM* – peminjaman (ketik *PINJAM* saja untuk tautan formulir)\n• *KEMBALI* – pengembalian barang pinjaman + tautan upload bukti foto/file (ketik *KEMBALI* untuk daftar, *KEMBALI PMJ-001* untuk mengembalikan)\n• *PENGAJUAN KEBUTUHAN* / *PENGAJUAN PEMELIHARAAN*\n\n"
        . "Setelah data tersimpan, bot membalas *ID* dan tautan upload lampiran (maks. 1 foto/PDF). Foto/PDF juga bisa dikirim langsung ke chat ini (pesan terpisah, tanpa teks) dalam " . wl_jendela_menit() . " menit. Kegiatan yang sudah dilaksanakan: balas *Selesai <ID>*. Lebih suka mengisi lewat halaman web? Ketik *FORM KEGIATAN* (atau modul lain) untuk tautan formulir.\n\n"
        . "Contoh format tiap modul: ketik *FORMAT KEGIATAN*, *FORMAT SURAT*, *FORMAT PEMBINAAN*, *FORMAT POTENSI*, *FORMAT PERALATAN*, *FORMAT KENDARAAN*, *FORMAT PINJAM*, *FORMAT PENGAJUAN*.\n\n"
        . "Tanggal: 25/10/2026 (atau \"besok\"). Jam: 08.00. Modul lain tidak bisa diinput lewat WhatsApp.";
}

function wa_format(string $nama): string
{
    [$cmd] = wa_detect($nama);
    if (!in_array($cmd, ['kegiatan', 'surat', 'surat_keluar', 'pembinaan', 'peralatan', 'kendaraan', 'peminjaman', 'pengajuan', 'potensi'], true))
        return "Modul \"$nama\" tidak dikenal.\n\n" . wa_bantuan();
    $s = wa_spec($cmd);
    $wajib = [];
    foreach ($s['fields'] as $f) if ($f['req']) $wajib[] = $f['label'];
    return "📝 Format {$s['judul']}\n(wajib: " . implode(', ', $wajib) . "; lainnya boleh dikosongkan)\n\n" . $s['contoh'];
}


/** Perintah yang punya formulir tautan (FORM <modul>). */
const WA_FORM_CMDS = ['kegiatan', 'surat', 'surat_keluar', 'pembinaan', 'peralatan', 'kendaraan', 'peminjaman', 'pengajuan', 'potensi'];
