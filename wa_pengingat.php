<?php
/**
 * Pengingat penugasan otomatis (H-3) lewat WhatsApp.
 *
 * Tiap anggota yang bertugas pada kegiatan yang SUDAH DISETUJUI dan belum Selesai menerima pengingat otomatis mulai
 * 3 hari sebelum tanggal pelaksanaan (H-3 s.d. hari H). Admin tidak perlu mengirim ulang info tugas:
 *  - pengingat dikirim SEKALI per (kegiatan, anggota, tanggal pelaksanaan) -> dicatat di tabel acc_notif (kunci "P3|...");
 *  - tautan update kegiatan (upload bukti) disertakan; bila belum ada atau sudah kedaluwarsa, dibuat otomatis;
 *  - anggota yang ditambahkan belakangan otomatis ikut mendapat pengingat; anggota yang sudah dikeluarkan tidak dikirimi
 *    dan tautan miliknya ditolak saat upload (lihat wl_kegiatan_boleh di wa_lampiran.php);
 *  - pada HARI PELAKSANAAN (hari H) anggota menerima notifikasi tersendiri "HARI PELAKSANAAN KEGIATAN" + tautan upload bukti
 *    (sekali per kegiatan/anggota/tanggal, kunci "HH|..."), sehingga tidak tertukar dengan pengingat H-3;
 *  - jadwal diubah -> pengingat untuk tanggal baru dikirim lagi (kunci memuat tanggal).
 *
 * Pemicu (idempoten, aman dipanggil berulang; jeda minimal WA_PENGINGAT_JEDA_DETIK antar pemeriksaan):
 *  1. otomatis setelah respons dashboard/webhook (acc_sweep_nanti) dan saat dashboard terbuka (polling sync);
 *  2. disarankan juga lewat cron agar tetap jalan walau dashboard tidak dibuka:
 *       *\/15 * * * * php /path/ke/basarnas-dashboard/wa_pengingat.php
 */
declare(strict_types=1);

require_once __DIR__ . '/wa_inti.php';

const WA_PENGINGAT_HARI_BAWAAN = 3;      // kirim mulai H-3
const WA_PENGINGAT_JEDA_DETIK  = 600;    // jeda minimal antar pemeriksaan
const WA_PENGINGAT_COBA_MAKS   = 3;      // gagal kirim: dicoba lagi paling banyak 3 kali per anggota

function wa_pengingat_hari(): int { return defined('WA_PENGINGAT_HARI') ? max(1, (int)WA_PENGINGAT_HARI) : WA_PENGINGAT_HARI_BAWAAN; }

/** Teks sisa hari. */
function wa_pengingat_label(int $sisa): string
{
    return $sisa <= 0 ? 'HARI INI' : ($sisa === 1 ? 'BESOK (H-1)' : $sisa . ' HARI LAGI (H-' . $sisa . ')');
}

function wa_pengingat_pesan(array $k, array $namaAnggota, int $sisa, string $url): string
{
    $a = (string)($k['tanggal'] ?? ''); $b = (string)($k['tanggal_selesai'] ?? '');
    $tgl = ($b !== '' && substr($b, 0, 10) > substr($a, 0, 10)) ? wa_tgl_panjang($a) . ' s.d. ' . wa_tgl_panjang($b) : wa_tgl_panjang($a);
    $jam = trim((string)($k['waktu'] ?? ''));
    $baris = [
        '*PENGINGAT PENUGASAN*',
        'Pelaksanaan: ' . wa_pengingat_label($sisa),
        '',
        'ID: ' . ($k['id'] ?? '-'),
        'Nama Kegiatan: ' . ($k['nama'] ?? '-'),
        'Tanggal Kegiatan: ' . ($tgl !== '' ? $tgl : '-'),
        'Jam: ' . ($jam !== '' ? $jam . ' WIB' : '-'),
        'Lokasi: ' . (trim((string)($k['lokasi'] ?? '')) ?: '-'),
        'Anggota Yang Bertugas:',
    ];
    foreach (array_values($namaAnggota) as $i => $n) $baris[] = ($i + 1) . '. ' . $n;
    $baris[] = '';
    $baris[] = 'Mohon dipersiapkan. Setelah mulai bertugas, upload foto/dokumen bukti penugasan lewat tautan ini (boleh beberapa kali):';
    $baris[] = $url;
    $baris[] = '';
    $baris[] = 'Tautan hanya berlaku untuk anggota yang tercantum bertugas. Pengingat ini dikirim otomatis.';
    return implode("\n", $baris);
}

/** Notifikasi khusus HARI PELAKSANAAN (hari H): kegiatan hari ini + tautan upload bukti untuk anggota yang bertugas. */
function wa_pengingat_pesan_hari_h(array $k, array $namaAnggota, string $url): string
{
    $a = (string)($k['tanggal'] ?? ''); $b = (string)($k['tanggal_selesai'] ?? '');
    $tgl = ($b !== '' && substr($b, 0, 10) > substr($a, 0, 10)) ? wa_tgl_panjang($a) . ' s.d. ' . wa_tgl_panjang($b) : wa_tgl_panjang($a);
    $jam = trim((string)($k['waktu'] ?? ''));
    $baris = [
        '*HARI PELAKSANAAN KEGIATAN*',
        'Kegiatan ini dilaksanakan HARI INI.',
        '',
        'ID: ' . ($k['id'] ?? '-'),
        'Nama Kegiatan: ' . ($k['nama'] ?? '-'),
        'Tanggal Kegiatan: ' . ($tgl !== '' ? $tgl : '-'),
        'Jam: ' . ($jam !== '' ? $jam . ' WIB' : '-'),
        'Lokasi: ' . (trim((string)($k['lokasi'] ?? '')) ?: '-'),
        'Anggota Yang Bertugas:',
    ];
    foreach (array_values($namaAnggota) as $i => $n) $baris[] = ($i + 1) . '. ' . $n;
    $baris[] = '';
    $baris[] = 'Silakan upload foto/dokumen bukti pelaksanaan lewat tautan ini (boleh beberapa kali):';
    $baris[] = $url;
    $baris[] = '';
    $baris[] = 'Tautan hanya berlaku untuk anggota yang tercantum bertugas. Notifikasi ini dikirim otomatis.';
    return implode("\n", $baris);
}

/** Tautan update aktif milik nomor ini untuk kegiatan ini; dibuat baru bila belum ada / sudah kedaluwarsa. */
function wa_pengingat_tautan(PDO $p, array $k, string $nama, string $no): string
{
    wl_tabel($p);
    $st = $p->prepare("SELECT token FROM wa_upload WHERE db_key = 'kegiatan' AND rec_id = ? AND nomor = ? AND jenis = 'update' AND dipakai = 0 AND kedaluwarsa > ? ORDER BY dibuat DESC LIMIT 1");
    $st->execute([(string)$k['id'], $no, time()]);
    $t = $st->fetchColumn();
    if (!$t) $t = wl_token_kegiatan($p, $k, $nama, $no, 'update');
    return wl_link((string)$t);
}

/** Satu putaran pemeriksaan. Mengembalikan jumlah pengingat terkirim. */
function wa_pengingat_sweep(): int
{
    $p = pdo();
    db_ensure_table($p, 'acc_notif', DB_DDL_ACC_NOTIF);
    $db = load_db();
    $hariIni = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
    $byId = [];
    foreach ($db['anggota'] ?? [] as $a) $byId[(string)($a['id'] ?? '')] = $a;

    $klaim  = $p->prepare('INSERT IGNORE INTO acc_notif (kunci, waktu) VALUES (?, ?)');
    $lepas  = $p->prepare('DELETE FROM acc_notif WHERE kunci = ?');
    $hitung = $p->prepare('SELECT COUNT(*) FROM acc_notif WHERE kunci LIKE ?');
    $kirim = 0;

    foreach ($db['kegiatan'] ?? [] as $k) {
        $kid = (string)($k['id'] ?? '');
        $tgl = substr((string)($k['tanggal'] ?? ''), 0, 10);
        if ($kid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) continue;
        if (($k['status_persetujuan'] ?? '') !== 'Disetujui' || ($k['status_pelaksanaan'] ?? '') === 'Selesai') continue;
        try { $mulai = new DateTimeImmutable($tgl, new DateTimeZone('Asia/Jakarta')); } catch (Throwable) { continue; }
        $sisa = (int)$hariIni->diff($mulai)->format('%r%a');
        if ($sisa < 0 || $sisa > wa_pengingat_hari()) continue; // belum masuk H-3, atau sudah lewat

        $petugas = [];
        foreach (array_unique((array)($k['anggota_ids'] ?? [])) as $aid) if (isset($byId[$aid])) $petugas[] = $byId[$aid];
        if (!$petugas) continue;
        $nama = array_map(static fn($a) => (string)($a['nama'] ?? ''), $petugas);

        $sudah = [];
        foreach ($petugas as $a) {
            $no = wa_nomor((string)($a['whatsapp'] ?? ''));
            if ($no === '' || isset($sudah[$no])) continue;
            $sudah[$no] = true;
            // Hari H memakai notifikasi tersendiri (kunci HH), H-3 s.d. H-1 memakai pengingat biasa (kunci P3).
            $kunci = ($sisa === 0 ? 'HH|' : 'P3|') . $kid . '|' . $no . '|' . $tgl;
            $klaim->execute([$kunci, date('c')]);
            if ($klaim->rowCount() === 0) continue; // sudah pernah (atau sedang dikirim proses lain)
            $hitung->execute([$kunci . '|gagal|%']);
            if ((int)$hitung->fetchColumn() >= WA_PENGINGAT_COBA_MAKS) continue;

            try {
                $url = wa_pengingat_tautan($p, $k, (string)($a['nama'] ?? ''), $no);
                [$ok, $ket] = wa_kirim_lampiran($no, $sisa === 0 ? wa_pengingat_pesan_hari_h($k, $nama, $url) : wa_pengingat_pesan($k, $nama, $sisa, $url));
            } catch (Throwable $e) { $ok = false; $ket = $e->getMessage(); }
            if ($ok) { $kirim++; wa_log(($sisa === 0 ? "HARI-H $kid" : "PENGINGAT $kid") . " -> $no: terkirim (H-$sisa)"); }
            else {
                $lepas->execute([$kunci]); // lepas klaim agar dicoba lagi pada putaran berikutnya
                $klaim->execute([$kunci . '|gagal|' . bin2hex(random_bytes(3)), date('c')]);
                wa_log("PENGINGAT $kid -> $no: gagal ($ket)");
            }
            usleep(700000);
        }
    }
    return $kirim;
}

/** Jalankan wa_pengingat_sweep() paling sering sekali per WA_PENGINGAT_JEDA_DETIK (dipakai pemicu otomatis). */
function wa_pengingat_jalankan(bool $paksa = false): int
{
    $f = DATA_DIR . '/pengingat.last';
    $h = @fopen($f, 'c+');
    if (!$h) return 0;
    try {
        if (!flock($h, LOCK_EX | LOCK_NB)) return 0; // proses lain sedang memeriksa
        $terakhir = (int)trim((string)stream_get_contents($h));
        if (!$paksa && $terakhir > 0 && time() - $terakhir < WA_PENGINGAT_JEDA_DETIK) return 0;
        ftruncate($h, 0); rewind($h); fwrite($h, (string)time()); fflush($h);
        @set_time_limit(180);
        return wa_pengingat_sweep();
    } catch (Throwable $e) {
        wa_log('PENGINGAT GALAT: ' . $e->getMessage());
        return 0;
    } finally {
        flock($h, LOCK_UN); fclose($h);
    }
}

/** Jalankan setelah respons HTTP selesai (tidak memperlambat dashboard). */
function wa_pengingat_nanti(): void
{
    static $terdaftar = false;
    if ($terdaftar) return;
    $terdaftar = true;
    ignore_user_abort(true);
    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        wa_pengingat_jalankan();
    });
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    date_default_timezone_set('Asia/Jakarta');
    $n = wa_pengingat_jalankan(true);
    echo "Pengingat terkirim: $n\n";
}