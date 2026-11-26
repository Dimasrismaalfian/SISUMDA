<?php
/**
 * Notifikasi perubahan data (lonceng di header, khusus Admin & Admin Utama).
 *
 * Alur:
 *  - Titik masuk yang mengubah data (api.php 'save', bot WhatsApp, tautan upload WA) memanggil notif_set(sumber, aktor)
 *    tepat sebelum save_db(). save_db() lalu membandingkan data lama vs baru (notif_diff) dan mencatat hasilnya.
 *  - Tanpa notif_set() tidak ada yang dicatat (mis. status kirim WA otomatis, pengingat, migrasi).
 *  - Konteks hanya berlaku untuk SATU kali save_db() (one-shot), supaya simpanan sistem sesudahnya tidak salah atribusi.
 *  - Tiap Admin / Admin Utama punya status "sudah dibaca" sendiri; perubahan buatan sendiri tidak menjadi notifikasi bagi dirinya.
 */
declare(strict_types=1);

const DB_DDL_NOTIFIKASI = 'CREATE TABLE IF NOT EXISTS notifikasi (
    id          BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    waktu       BIGINT NOT NULL,
    sumber      VARCHAR(20) NOT NULL,
    aktor       VARCHAR(255),
    aktor_uid   BIGINT NULL,
    modul       VARCHAR(60) NOT NULL,
    modul_label VARCHAR(120),
    rec_id      VARCHAR(100),
    aksi        VARCHAR(10) NOT NULL,
    judul       TEXT,
    detail      TEXT,
    KEY idx_notifikasi_waktu (waktu),
    KEY idx_notifikasi_rec (modul, rec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
const DB_DDL_NOTIFIKASI_BACA = 'CREATE TABLE IF NOT EXISTS notifikasi_baca (
    user_id  BIGINT NOT NULL,
    notif_id BIGINT NOT NULL,
    PRIMARY KEY (user_id, notif_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';

/** Perubahan oleh pelaku yang sama pada data yang sama dalam jendela ini digabung jadi satu notifikasi (menahan banjir akibat autosave). */
const NOTIF_GABUNG_DETIK = 300;
/** Batas notifikasi individual per satu kali simpan; selebihnya diringkas. */
const NOTIF_MAKS_PER_SIMPAN = 15;
/** Lama notifikasi disimpan. */
const NOTIF_SIMPAN_HARI = 60;
/** Kolom yang berubah sendiri / hanya bookkeeping: tidak dianggap perubahan data. */
const NOTIF_ABAIKAN_FIELD = ['status_wa', 'wa_log', 'diperbarui', 'dibuat', 'updated', 'updated_at', 'urut'];
/** Kolom status: perubahannya ditampilkan "lama → baru". */
const NOTIF_FIELD_STATUS = ['status_persetujuan', 'status_pelaksanaan', 'status', 'kondisi', 'keterangan_proses', 'sprin_proses'];
/** Kunci data teratas yang bukan daftar rekaman milik pengguna (tidak dipantau). */
const NOTIF_ABAIKAN_KUNCI = ['uji_imt', 'wa_config', 'wa_samapta'];

const NOTIF_LABEL_MODUL = [
    'anggota' => 'Daftar Anggota', 'kegiatan' => 'Kegiatan/Tugas', 'surat_masuk' => 'Surat Masuk', 'surat_keluar' => 'Surat Keluar',
    'sgts' => 'Sar Go To School', 'bimtek' => 'Bimtek', 'potensi' => 'Potensi', 'narasumber' => 'Narasumber', 'diklat' => 'Diklat',
    'rescuer' => 'Rescuer', 'pembinaan' => 'Pembinaan Tenaga SAR', 'pemberdayaan' => 'Pemberdayaan Masyarakat',
    'uji_periodik' => 'Uji Periodik', 'uji_pemeliharaan' => 'Pemeliharaan Sumda', 'lainnya' => 'Lainnya',
    'peralatan' => 'Peralatan', 'kendaraan' => 'Kendaraan', 'peminjaman' => 'Peminjaman', 'pemeliharaan' => 'Pengajuan',
    'pengajuan' => 'Pengajuan', 'siaga_rutin' => 'Pengecekan Siaga Rutin', 'sumda_riwayat' => 'Riwayat Pemeliharaan Sumda',
    'uji_kategori' => 'Nilai Uji Periodik',
];

/* ---------------------------------------------------------------- konteks pelaku (one-shot) */

function notif_set(?string $sumber, string $aktor = '', ?int $uid = null): void
{
    $GLOBALS['__notif_konteks'] = $sumber === null ? null : ['sumber' => $sumber, 'aktor' => $aktor, 'uid' => $uid];
}
/** Ambil konteks lalu hapus (berlaku untuk satu save_db saja). */
function notif_ambil(): ?array
{
    $k = $GLOBALS['__notif_konteks'] ?? null;
    $GLOBALS['__notif_konteks'] = null;
    return is_array($k) ? $k : null;
}

/** Peran tampilan akun dari database: 'Admin Utama' / 'Admin' / 'KKS' / ... / 'Anggota'. */
function notif_peran_user(int $uid): string
{
    $s = auth_db()->prepare('SELECT role, jabatan FROM users WHERE id = ?');
    $s->execute([$uid]);
    $r = $s->fetch();
    if (!$r) return 'Anggota';
    if (($r['role'] ?? '') === ROLE_ADMIN_UTAMA) return 'Admin Utama';
    return in_array($r['jabatan'] ?? '', JABATAN_LIST, true) ? (string)$r['jabatan'] : 'Anggota';
}
function notif_boleh_lihat(string $peran): bool { return $peran === 'Admin Utama' || $peran === 'Admin'; }

/** Pasang konteks untuk perubahan yang dibuat pengguna yang sedang login lewat dashboard. */
function notif_set_user(array $user): void
{
    $peran = notif_peran_user((int)$user['id']);
    $sumber = notif_boleh_lihat($peran) ? 'admin' : 'anggota';
    $nama = trim((string)($user['name'] ?? '')) ?: (string)($user['username'] ?? '?');
    notif_set($sumber, $nama . ' (' . $peran . ')', (int)$user['id']);
}

/* ---------------------------------------------------------------- pembandingan data lama vs baru */

/** Samakan struktur (objek/array, bool/angka/teks) agar perbedaan tipe tidak dianggap perubahan. */
function notif_norm($v)
{
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) {
        if (!$v) return '';
        $o = [];
        foreach ($v as $k => $x) $o[(string)$k] = notif_norm($x);
        if (array_is_list($v)) {
            $sc = array_filter($o, 'is_string');
            if (count($sc) === count($o)) sort($o); // daftar sederhana (mis. anggota_ids): urutan tidak penting
        } else {
            ksort($o);
        }
        return $o;
    }
    if ($v === null) return '';
    if ($v === true) return '1';
    if ($v === false) return '0';
    $s = (string)$v;
    if (strlen($s) > 400) return 'h:' . md5($s); // PDF/foto base64 dsb.
    if (is_numeric($s) && !preg_match('/^[+-]?0\d/', $s)) return (string)(0 + $s);
    return $s;
}

/** Daftar rekaman ber-id => peta id => rekaman; null bila bukan daftar rekaman. */
function notif_rekaman($v): ?array
{
    if (is_object($v)) $v = (array)$v;
    if (!is_array($v)) return null;
    $out = [];
    foreach ($v as $r) {
        if (is_object($r)) $r = (array)$r;
        if (!is_array($r) || !isset($r['id']) || (string)$r['id'] === '') return $v === [] ? [] : null;
        $out[(string)$r['id']] = $r;
    }
    return $out;
}

function notif_judul(array $r, string $id): string
{
    foreach (['nama', 'judul', 'perihal', 'nama_kegiatan', 'nomor_surat', 'nama_barang', 'nama_alat', 'nama_anggota', 'nama_peralatan', 'pemeriksa', 'petugas', 'nomor'] as $f) {
        $t = trim((string)($r[$f] ?? ''));
        if ($t !== '' && !is_array($r[$f])) return $id . ' · ' . mb_strimwidth($t, 0, 80, '…');
    }
    return $id;
}

/** @return list<array{modul:string,id:string,aksi:string,judul:string,detail:string}> */
function notif_diff(array $lama, array $baru): array
{
    $ev = [];
    $kunci = array_unique(array_merge(array_keys($baru), array_keys($lama)));
    foreach ($kunci as $k) {
        $k = (string)$k;
        if (in_array($k, NOTIF_ABAIKAN_KUNCI, true)) continue;
        if (!array_key_exists($k, $baru)) continue; // kunci hilang dari kiriman: bukan penghapusan oleh pengguna
        $rn = notif_rekaman($baru[$k]);
        if ($rn === null) continue;
        $ro = notif_rekaman($lama[$k] ?? []) ?? [];
        $bag = [];
        foreach ($rn as $id => $r) {
            if (!isset($ro[$id])) { $bag[] = ['modul' => $k, 'id' => $id, 'aksi' => 'tambah', 'judul' => notif_judul($r, $id), 'detail' => '']; continue; }
            $o = $ro[$id];
            $ubah = []; $stat = [];
            foreach (array_unique(array_merge(array_keys($r), array_keys($o))) as $f) {
                $f = (string)$f;
                if (in_array($f, NOTIF_ABAIKAN_FIELD, true)) continue;
                $a = notif_norm($o[$f] ?? null); $b = notif_norm($r[$f] ?? null);
                if ($a === $b) continue;
                if (in_array($f, NOTIF_FIELD_STATUS, true) && is_string($a) && is_string($b)) $stat[] = str_replace('_', ' ', $f) . ': ' . ($a === '' ? '-' : $a) . ' → ' . ($b === '' ? '-' : $b);
                else $ubah[] = str_replace('_', ' ', $f);
            }
            if (!$ubah && !$stat) continue;
            $d = $stat;
            if ($ubah) $d[] = 'Diubah: ' . implode(', ', array_slice($ubah, 0, 5)) . (count($ubah) > 5 ? ' +' . (count($ubah) - 5) . ' lainnya' : '');
            $bag[] = ['modul' => $k, 'id' => $id, 'aksi' => 'ubah', 'judul' => notif_judul($r, $id), 'detail' => mb_strimwidth(implode(' · ', $d), 0, 300, '…')];
        }
        foreach ($ro as $id => $o) {
            if (!isset($rn[$id])) $bag[] = ['modul' => $k, 'id' => $id, 'aksi' => 'hapus', 'judul' => notif_judul($o, $id), 'detail' => ''];
        }
        // Banyak sekaligus (mis. Import Excel) diringkas jadi satu notifikasi per modul & jenis aksi
        $per = [];
        foreach ($bag as $e) $per[$e['aksi']][] = $e;
        foreach ($per as $aksi => $list) {
            if (count($list) > 10) {
                $verb = ['tambah' => 'ditambahkan', 'ubah' => 'diubah', 'hapus' => 'dihapus'][$aksi];
                $ev[] = ['modul' => $k, 'id' => '', 'aksi' => $aksi, 'judul' => count($list) . ' data ' . $verb, 'detail' => $list[0]['judul'] . ' dan lainnya'];
            } else {
                foreach ($list as $e) $ev[] = $e;
            }
        }
    }
    return $ev;
}

/* ---------------------------------------------------------------- penyimpanan */

function notif_pastikan_tabel(PDO $p): void
{
    db_ensure_table($p, 'notifikasi', DB_DDL_NOTIFIKASI);
    db_ensure_table($p, 'notifikasi_baca', DB_DDL_NOTIFIKASI_BACA);
}

function notif_catat(PDO $p, array $ctx, array $events): void
{
    if (!$events) return;
    notif_pastikan_tabel($p);
    $now = time();
    $sisa = 0;
    if (count($events) > NOTIF_MAKS_PER_SIMPAN) { $sisa = count($events) - NOTIF_MAKS_PER_SIMPAN; $events = array_slice($events, 0, NOTIF_MAKS_PER_SIMPAN); }
    $cari = $p->prepare('SELECT id, aksi FROM notifikasi WHERE sumber = ? AND aktor = ? AND modul = ? AND rec_id = ? AND waktu > ? ORDER BY id DESC LIMIT 1');
    $upd  = $p->prepare('UPDATE notifikasi SET waktu = ?, judul = ?, detail = ? WHERE id = ?');
    $unr  = $p->prepare('DELETE FROM notifikasi_baca WHERE notif_id = ?');
    $ins  = $p->prepare('INSERT INTO notifikasi (waktu, sumber, aktor, aktor_uid, modul, modul_label, rec_id, aksi, judul, detail) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($events as $e) {
        $label = NOTIF_LABEL_MODUL[$e['modul']] ?? ucwords(str_replace('_', ' ', $e['modul']));
        if ($e['id'] !== '') {
            $cari->execute([$ctx['sumber'], $ctx['aktor'], $e['modul'], $e['id'], $now - NOTIF_GABUNG_DETIK]);
            if ($r = $cari->fetch()) {
                if ($r['aksi'] === $e['aksi'] && $e['aksi'] === 'ubah') { // autosave berulang: perbarui yang sudah ada
                    $upd->execute([$now, $e['judul'], $e['detail'], $r['id']]);
                    $unr->execute([$r['id']]);
                    continue;
                }
                if ($r['aksi'] === 'tambah' && $e['aksi'] === 'ubah') continue; // melengkapi data yang baru ditambahkan sendiri
            }
        }
        $ins->execute([$now, $ctx['sumber'], $ctx['aktor'], $ctx['uid'], $e['modul'], $label, $e['id'], $e['aksi'], $e['judul'], $e['detail']]);
    }
    if ($sisa > 0) {
        $ins->execute([$now, $ctx['sumber'], $ctx['aktor'], $ctx['uid'], '', 'Beberapa modul', '', 'ubah', $sisa . ' perubahan lainnya', '']);
    }
    if (random_int(1, 25) === 1) { // bersih-bersih berkala
        $p->prepare('DELETE FROM notifikasi WHERE waktu < ?')->execute([$now - NOTIF_SIMPAN_HARI * 86400]);
        $p->exec('DELETE FROM notifikasi_baca WHERE notif_id NOT IN (SELECT id FROM notifikasi)');
    }
}

/** Jumlah notifikasi belum dibaca untuk akun ini (perubahan buatan sendiri tidak dihitung). */
function notif_belum_dibaca(PDO $p, int $uid): int
{
    try {
        $s = $p->prepare('SELECT COUNT(*) FROM notifikasi n WHERE (n.aktor_uid IS NULL OR n.aktor_uid <> ?)
            AND NOT EXISTS (SELECT 1 FROM notifikasi_baca b WHERE b.user_id = ? AND b.notif_id = n.id)');
        $s->execute([$uid, $uid]);
        return (int)$s->fetchColumn();
    } catch (PDOException $e) {
        return 0; // tabel belum dibuat = belum ada notifikasi
    }
}

function notif_daftar(PDO $p, int $uid, int $limit = 60): array
{
    notif_pastikan_tabel($p);
    $s = $p->prepare('SELECT n.id, n.waktu, n.sumber, n.aktor, n.modul, n.modul_label, n.rec_id, n.aksi, n.judul, n.detail,
            (b.notif_id IS NOT NULL) AS baca
        FROM notifikasi n LEFT JOIN notifikasi_baca b ON b.notif_id = n.id AND b.user_id = ?
        WHERE (n.aktor_uid IS NULL OR n.aktor_uid <> ?)
        ORDER BY n.id DESC LIMIT ' . max(1, min(200, $limit)));
    $s->execute([$uid, $uid]);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $r['id'] = (int)$r['id']; $r['waktu'] = (int)$r['waktu']; $r['baca'] = (bool)$r['baca'];
        $out[] = $r;
    }
    return $out;
}

/** $ids kosong = tandai semua. */
function notif_tandai_baca(PDO $p, int $uid, array $ids): void
{
    notif_pastikan_tabel($p);
    if (!$ids) {
        $p->prepare('INSERT IGNORE INTO notifikasi_baca (user_id, notif_id) SELECT ?, id FROM notifikasi')->execute([$uid]);
        return;
    }
    $st = $p->prepare('INSERT IGNORE INTO notifikasi_baca (user_id, notif_id) VALUES (?, ?)');
    foreach (array_slice($ids, 0, 500) as $id) $st->execute([$uid, (int)$id]);
}
