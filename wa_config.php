<?php
/**
 * Pengaturan input data lewat WhatsApp (Dashboard BASARNAS).
 * File ini berisi token rahasia: jangan dibagikan dan jangan di-commit ke repositori publik.
 * Akses langsung lewat browser ditolak oleh .htaccess.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------------------------------------
 * PENGATURAN YANG DAPAT DIUBAH LEWAT MODUL "Pengaturan" (khusus Admin Utama)
 * Nilai yang disimpan di data/pengaturan_wa.json MENIMPA nilai bawaan di file ini untuk 5 pengaturan:
 *   nomor_approver, token, api_url, nomor_approver_pinjam, nomor_bot
 * Folder data/ dilindungi .htaccess (akses langsung ditolak). Hapus file JSON itu untuk kembali ke nilai bawaan.
 * ---------------------------------------------------------------------------------------------- */
const WA_PENGATURAN_FILE = __DIR__ . '/data/pengaturan_wa.json';

/** Membaca override pengaturan (array kosong bila belum ada / rusak). */
function wa_pengaturan_baca(): array
{
    $raw = @file_get_contents(WA_PENGATURAN_FILE);
    $j = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

/** Nilai satu pengaturan: override bila ada & valid, selain itu nilai bawaan. */
function wa_pg(string $kunci, mixed $bawaan): mixed
{
    static $pg = null;
    if ($pg === null) $pg = wa_pengaturan_baca();
    if (!array_key_exists($kunci, $pg)) return $bawaan;
    $v = $pg[$kunci];
    if (is_array($bawaan)) return (is_array($v) && $v) ? array_values(array_map('strval', $v)) : $bawaan;
    return (is_string($v) && trim($v) !== '') ? trim($v) : $bawaan;
}

/** Normalisasi satu nomor ke bentuk lokal 08xxxxxxxxxx; null bila tidak valid. */
function wa_pg_nomor(string $n): ?string
{
    $d = preg_replace('/\D+/', '', $n) ?? '';
    if ($d === '') return null;
    if (str_starts_with($d, '62')) $d = '0' . substr($d, 2);
    elseif (str_starts_with($d, '8')) $d = '0' . $d;
    return preg_match('/^08\d{7,12}$/', $d) ? $d : null;
}

/** Daftar nomor dari teks (pisah koma/titik koma/baris). Mengembalikan [daftar, galat]. */
function wa_pg_daftar_nomor(mixed $in, string $label, int $maks): array
{
    $teks = is_array($in) ? implode("\n", array_map('strval', $in)) : (string)$in;
    $bagian = preg_split('/[,;\n]+/', $teks) ?: [];
    $out = [];
    foreach ($bagian as $b) {
        if (trim($b) === '') continue;
        $n = wa_pg_nomor($b);
        if ($n === null) return [[], "$label: nomor \"" . trim($b) . "\" tidak valid (contoh: 0878-9191-8976)"];
        $out[$n] = $n;
    }
    if (!$out) return [[], "$label wajib diisi"];
    if (count($out) > $maks) return [[], "$label maksimal $maks nomor"];
    return [array_values($out), ''];
}

/** Bentuk tersamar token: 3 karakter awal + titik + 2 karakter akhir. */
function wa_pg_samar(string $tok): string
{
    if ($tok === '') return '';
    return strlen($tok) <= 6 ? str_repeat('•', strlen($tok)) : substr($tok, 0, 3) . str_repeat('•', 8) . substr($tok, -2);
}

/** Data untuk layar Pengaturan dari nilai yang sedang berlaku. Token TIDAK dikirim utuh. */
function wa_pengaturan_tampil(): array
{
    return [
        'nomor_approver'        => implode(', ', (array)WA_NOMOR_APPROVER),
        'token_samar'           => wa_pg_samar((string)WA_TOKEN),
        'token_ada'             => (string)WA_TOKEN !== '',
        'api_url'               => (string)WA_API_URL,
        'nomor_approver_pinjam' => implode(', ', (array)WA_NOMOR_APPROVER_PINJAM),
        'nomor_bot'             => (string)WA_NOMOR_BOT,
    ];
}

/** Sama seperti wa_pengaturan_tampil(), tetapi dari file yang baru saja disimpan (konstanta request ini masih nilai lama). */
function wa_pengaturan_tampil_baru(): array
{
    $pg = wa_pengaturan_baca();
    $d = wa_pengaturan_tampil();
    foreach (['nomor_approver', 'nomor_approver_pinjam'] as $k) if (!empty($pg[$k]) && is_array($pg[$k])) $d[$k] = implode(', ', $pg[$k]);
    foreach (['nomor_bot', 'api_url'] as $k) if (!empty($pg[$k]) && is_string($pg[$k])) $d[$k] = $pg[$k];
    if (!empty($pg['token']) && is_string($pg['token'])) { $d['token_samar'] = wa_pg_samar($pg['token']); $d['token_ada'] = true; }
    return $d;
}

/**
 * Validasi + simpan pengaturan. Token kosong = tidak diubah.
 * Mengembalikan [berhasil, pesan, daftarBidangYangBerubah].
 */
function wa_pengaturan_simpan(array $in): array
{
    [$pim, $e1] = wa_pg_daftar_nomor($in['nomor_approver'] ?? '', 'No WA pimpinan', 10);
    if ($e1 !== '') return [false, $e1, []];
    [$pnj, $e2] = wa_pg_daftar_nomor($in['nomor_approver_pinjam'] ?? '', 'No WA ACC peminjaman', 10);
    if ($e2 !== '') return [false, $e2, []];
    [$bot, $e3] = wa_pg_daftar_nomor($in['nomor_bot'] ?? '', 'No WA perantara/inputan', 1);
    if ($e3 !== '') return [false, $e3, []];

    // Penyetuju surat/kegiatan dan penyetuju peminjaman dipisah oleh desain aplikasi; nomor bot juga tidak boleh sama dengan penyetuju.
    if (array_intersect($pim, $pnj)) return [false, 'Nomor yang sama tidak boleh dipakai sebagai pimpinan sekaligus penyetuju peminjaman', []];
    if (array_intersect($bot, $pim) || array_intersect($bot, $pnj)) return [false, 'Nomor perantara/inputan tidak boleh sama dengan nomor penyetuju', []];

    $url = trim((string)($in['api_url'] ?? ''));
    $p = parse_url($url);
    if ($url === '' || strlen($url) > 300 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($p)
        || strtolower((string)($p['scheme'] ?? '')) !== 'https' || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])) {
        return [false, 'API website harus berupa alamat https:// yang valid (contoh: https://api.fonnte.com/send)', []];
    }

    $tok = trim((string)($in['token'] ?? ''));
    if ($tok !== '' && !preg_match('/^[\x21-\x7E]{8,255}$/', $tok)) {
        return [false, 'API Key WA tidak valid (8–255 karakter, tanpa spasi)', []];
    }

    $lama = wa_pengaturan_baca();
    $baru = $lama;
    $baru['nomor_approver'] = $pim;
    $baru['nomor_approver_pinjam'] = $pnj;
    $baru['nomor_bot'] = $bot[0];
    $baru['api_url'] = $url;
    if ($tok !== '') $baru['token'] = $tok;

    $ubah = [];
    $daftarNorm = static fn(array $l): string => implode(',', array_map(static fn($x) => (string)(wa_pg_nomor((string)$x) ?? $x), $l));
    if ($daftarNorm($pim) !== $daftarNorm((array)WA_NOMOR_APPROVER)) $ubah[] = 'No WA pimpinan';
    if ($daftarNorm($pnj) !== $daftarNorm((array)WA_NOMOR_APPROVER_PINJAM)) $ubah[] = 'No WA ACC peminjaman';
    if ($daftarNorm($bot) !== $daftarNorm([(string)WA_NOMOR_BOT])) $ubah[] = 'No WA perantara/inputan';
    if ($url !== (string)WA_API_URL) $ubah[] = 'API website';
    if ($tok !== '' && $tok !== (string)WA_TOKEN) $ubah[] = 'API Key WA';

    $dir = dirname(WA_PENGATURAN_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return [false, 'Folder data tidak dapat dibuat', []];
    $tmp = WA_PENGATURAN_FILE . '.tmp' . bin2hex(random_bytes(4));
    $json = json_encode($baru, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); return [false, 'Gagal menyimpan: folder data tidak dapat ditulis', []]; }
    @chmod($tmp, 0640);
    if (!@rename($tmp, WA_PENGATURAN_FILE)) { @unlink($tmp); return [false, 'Gagal menyimpan pengaturan', []]; }
    return [true, $ubah ? 'Pengaturan disimpan (' . implode(', ', $ubah) . ')' : 'Tidak ada perubahan', $ubah];
}

/** Penyedia gateway WhatsApp. Saat ini: 'fonnte' (device + token). */
const WA_PROVIDER = 'fonnte';

/** Nomor WhatsApp yang menerima pesan (nomor bot / device di Fonnte). */
define('WA_NOMOR_BOT', wa_pg('nomor_bot', '087891918976')); // dapat diubah di modul Pengaturan

/** Token device dari gateway (Fonnte > Device > Token). */
define('WA_TOKEN', wa_pg('token', '4y7WqFufp5mKpN4gEhsW')); // dapat diubah di modul Pengaturan

/** Endpoint pengiriman balasan. */
define('WA_API_URL', wa_pg('api_url', 'https://api.fonnte.com/send')); // dapat diubah di modul Pengaturan

/**
 * Kunci rahasia webhook. Daftarkan URL webhook di Fonnte sebagai:
 *   https://DOMAIN-ANDA/wa_webhook.php?key=<WA_WEBHOOK_KEY>
 * Permintaan tanpa kunci yang cocok ditolak, sehingga orang lain tidak bisa menyuntik data.
 * Ganti nilai ini bila bocor.
 */
const WA_WEBHOOK_KEY = '8e898ae7e51bd2731594ff11b5dddbfd';

/**
 * true  = hanya nomor yang tercantum di Daftar Anggota (kolom Nomor WhatsApp) atau di
 *         WA_NOMOR_DIIZINKAN yang boleh menginput data.
 * false = siapa pun yang mengirim pesan ke nomor di atas boleh menginput (tidak disarankan).
 */
const WA_BATASI_PENGIRIM = true;

/** Nomor tambahan yang diizinkan (format bebas: 0812..., 62812..., +62 812...). */
const WA_NOMOR_DIIZINKAN = [
    // '081234567890',
];

/**
 * Nomor pimpinan yang berwenang ACC/TOLAK lewat WhatsApp. Bot mengirim permintaan persetujuan ke nomor ini
 * dan hanya menerima perintah ACC/TOLAK dari nomor ini (format bebas: 0878..., 62878..., +62 878...).
 */
define('WA_NOMOR_APPROVER', wa_pg('nomor_approver', ['087879545233'])); // dapat diubah di modul Pengaturan

/**
 * Penyetuju khusus modul PEMINJAMAN (Firman). Peminjaman baru berstatus "Menunggu"; bot mengirim permintaan ACC ke nomor ini
 * dan hanya menerima ACC/TOLAK peminjaman (ID PMJ-...) dari nomor ini. Nomor di WA_NOMOR_APPROVER tidak berwenang untuk PMJ,
 * dan nomor ini tidak berwenang untuk Surat/Kegiatan/Pengajuan. Format nomor bebas.
 */
define('WA_NOMOR_APPROVER_PINJAM', wa_pg('nomor_approver_pinjam', ['085155159030'])); // dapat diubah di modul Pengaturan

/** Nama & peran penyetuju peminjaman (dipakai bila nomor belum ada di Daftar Anggota). Tampil sebagai "Firman (Petugas Peminjaman) — via WhatsApp". */
const WA_NAMA_APPROVER_PINJAM = 'Firman';
const WA_APPROVER_PERAN_PINJAM = 'Petugas Peminjaman';

/** Peran yang dicatat di data untuk keputusan dari WhatsApp (mis. KKS, KSD, Pimpinan). Tampil sebagai "Nama (peran) — via WhatsApp". */
const WA_APPROVER_PERAN = 'Pimpinan';

/**
 * Tujuan grup WhatsApp untuk tombol "Kirim ke Grup" pada panel "Sprin Belum Dibuat" di Dashboard.
 * Isi dengan ID grup dari Fonnte (Device > Get Group), bentuknya 1203630xxxxxxxxx@g.us.
 * Kosong = tombol menampilkan pesan bahwa grup belum diatur.
 */
const WA_GRUP_SPRIN = '';

/**
 * Tujuan grup WhatsApp untuk tombol "Send WA" pada Riwayat Pengecekan Siaga Rutin (PDF dikirim ke grup ini).
 * Isi dengan ID grup dari Fonnte (Device > Get Group), bentuknya 1203630xxxxxxxxx@g.us.
 * Kosong = tombol menampilkan pesan bahwa grup belum diatur.
 */
const WA_GRUP_SIAGA = '';

/** true = bot membalas pengirim (konfirmasi ID data / pesan galat / panduan format). */
const WA_BALAS = true;

/**
 * Alamat dasar dashboard untuk tautan upload lampiran yang dikirim bot (tanpa garis miring di akhir),
 * mis. 'https://contoh.ngrok-free.dev/kansar'. Kosongkan = otomatis dari alamat yang dipakai Fonnte memanggil webhook.
 * Tautan yang dikirim: <alamat dasar>/u.php/<KODE>
 */
const WA_BASE_URL = '';

/** Jendela waktu (menit) mengirim foto/PDF langsung ke chat WA setelah data tersimpan. */
const WA_UPLOAD_JENDELA_MENIT = 60;

/** Masa berlaku tautan upload (hari) dan ukuran maksimum satu file (MB). */
const WA_UPLOAD_LINK_HARI = 7;
const WA_UPLOAD_MAKS_MB = 10;
