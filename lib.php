<?php
/**
 * Pustaka database Dashboard BASARNAS (MySQL/MariaDB via PDO; pengaturan di config_db.php).
 * - pdo()      : koneksi; membuat tabel + data awal otomatis saat pertama kali dijalankan
 * - load_db()  : membaca seluruh data dari SQL menjadi struktur yang dipakai antarmuka
 * - save_db()  : menyimpan struktur dari antarmuka kembali ke tabel-tabel SQL
 */
declare(strict_types=1);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/notif.php';

const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = DATA_DIR . '/uploads';
const SCHEMA_SQL = __DIR__ . '/database/schema.sql';
const SEED_JSON  = __DIR__ . '/database/seed.json';

const MODULE_TABLES = ['sgts','bimtek','potensi','narasumber','diklat','rescuer','pembinaan',
    'pemberdayaan','uji_periodik','uji_pemeliharaan','lainnya','peralatan','kendaraan','peminjaman'];
const CORE_KEYS = ['anggota','kegiatan','surat_masuk','uji_kategori','uji_imt'];
/** Tabel yang urutan barisnya mengikuti kolom id (AUTO_INCREMENT); tabel lain memakai kolom urut. */
const TABLES_ORDER_BY_ID = ['surat_masuk_acc', 'kegiatan_wa_log', 'peserta_kegiatan'];
const DELETE_ORDER = ['blob_chunk','kegiatan_anggota','kegiatan_wa_log','kegiatan_file','surat_masuk_acc','peserta_kegiatan',
    'uji_imt','uji_kategori','kegiatan','surat_masuk','anggota','app_state'];

const JSON_OUT = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

function pdo(bool $seed = true): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // Folder data/ tetap dipakai untuk file upload, log WhatsApp, dan kunci proses (bukan untuk database).
    foreach ([DATA_DIR, UPLOAD_DIR] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0775, true)) {
            throw new RuntimeException("Folder '$d' tidak dapat dibuat. Beri izin tulis pada folder aplikasi.");
        }
    }
    if (!is_writable(DATA_DIR)) {
        throw new RuntimeException("Folder 'data' tidak dapat ditulis. Beri izin tulis (chmod 775) untuk user web server.");
    }
    $p = db_connect();
    if (!db_table_exists($p, 'anggota')) db_init_schema($p, $seed);

    // Migrasi: kolom Tanggal Selesai pada Kegiatan/Tugas (database lama belum punya)
    if (!isset(table_cols($p, 'kegiatan', true)['tanggal_selesai'])) {
        $p->exec('ALTER TABLE kegiatan ADD COLUMN tanggal_selesai LONGTEXT');
        $p->exec("UPDATE kegiatan SET tanggal_selesai = tanggal WHERE tanggal_selesai IS NULL");
        table_cols($p, 'kegiatan', true);
    }
    // Migrasi: status Surat Masuk 'Selesai' (hasil Import Excel) - CHECK lama hanya mengizinkan Menunggu/Disetujui/Ditolak.
    db_migrasi_status_surat($p);
    db_ensure_table($p, 'sync_meta', DB_DDL_SYNC_META);
    db_ensure_table($p, 'blob_chunk', DB_DDL_BLOB_CHUNK);
    $p->exec("INSERT IGNORE INTO sync_meta (k, v) VALUES ('rev', 1)");
    return $pdo = $p;
}

/** Ganti CHECK status pada surat_masuk agar mengizinkan 'Selesai' (database lama). Aman dijalankan berulang. */
function db_migrasi_status_surat(PDO $p): void
{
    try {
        $q = $p->query("SELECT tc.CONSTRAINT_NAME AS n, cc.CHECK_CLAUSE AS c FROM information_schema.TABLE_CONSTRAINTS tc
            JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'surat_masuk' AND tc.CONSTRAINT_TYPE = 'CHECK'");
        $lama = false;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (stripos((string)$r['c'], 'status') === false || stripos((string)$r['c'], 'Selesai') !== false) continue;
            $n = str_replace('`', '', (string)$r['n']);
            try { $p->exec("ALTER TABLE surat_masuk DROP CONSTRAINT `$n`"); }
            catch (Throwable $e) { $p->exec("ALTER TABLE surat_masuk DROP CHECK `$n`"); }
            $lama = true;
        }
        if ($lama) {
            try { $p->exec("ALTER TABLE surat_masuk ADD CONSTRAINT chk_surat_status CHECK (status IS NULL OR status IN ('Menunggu Persetujuan','Disetujui','Ditolak','Selesai'))"); }
            catch (Throwable $e) { /* tanpa CHECK pun aman */ }
        }
    } catch (Throwable $e) { /* abaikan: server tidak mendukung pembacaan CHECK */ }
}

/** Jalankan schema.sql (per perintah) + data awal. Dijaga GET_LOCK agar dua permintaan pertama tidak saling bentrok. */
function db_init_schema(PDO $p, bool $seed): void
{
    $p->query("SELECT GET_LOCK('basarnas_init', 60)")->fetchColumn();
    try {
        if (db_table_exists($p, 'anggota')) return; // sudah dibuat permintaan lain
        try {
            foreach (sql_statements((string)file_get_contents(SCHEMA_SQL)) as $st) $p->exec($st);
            db_ensure_table($p, 'sync_meta', DB_DDL_SYNC_META);
            db_ensure_table($p, 'blob_chunk', DB_DDL_BLOB_CHUNK);
            $p->exec("INSERT IGNORE INTO sync_meta (k, v) VALUES ('rev', 1)");
            if ($seed && is_file(SEED_JSON)) {
                $d = json_decode((string)file_get_contents(SEED_JSON), true);
                if (is_array($d)) save_db($p, $d);
            }
        } catch (Throwable $e) {
            // DDL MySQL tidak bisa di-rollback: hapus tabel setengah jadi agar percobaan berikutnya mulai bersih.
            $p->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (sql_statements((string)file_get_contents(SCHEMA_SQL)) as $st) {
                if (preg_match('/^CREATE\s+VIEW\s+(\w+)/i', $st, $m)) $p->exec('DROP VIEW IF EXISTS ' . $m[1]);
                elseif (preg_match('/^CREATE\s+TABLE\s+(\w+)/i', $st, $m)) $p->exec('DROP TABLE IF EXISTS ' . $m[1]);
            }
            $p->exec('DROP TABLE IF EXISTS sync_meta');
            $p->exec('DROP TABLE IF EXISTS blob_chunk');
            $p->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e;
        }
    } finally {
        $p->query("SELECT RELEASE_LOCK('basarnas_init')")->fetchColumn();
    }
}

/** Pecah berkas .sql menjadi perintah tunggal (komentar "--" dibuang; titik koma di dalam tanda kutip dihormati). */
function sql_statements(string $sql): array
{
    $out = []; $cur = ''; $q = ''; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q === '') {
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') { while ($i < $n && $sql[$i] !== "\n") $i++; continue; }
            if ($c === "'" || $c === '"' || $c === '`') $q = $c;
            elseif ($c === ';') { if (trim($cur) !== '') $out[] = trim($cur); $cur = ''; continue; }
        } else {
            if ($c === '\\' && $q !== '`') { $cur .= $c . ($sql[++$i] ?? ''); continue; }
            if ($c === $q) { if (($sql[$i + 1] ?? '') === $q) { $cur .= $c; $i++; } else $q = ''; }
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = trim($cur);
    return $out;
}

/** Nomor revisi data: naik 1 setiap kali ada penyimpanan. Dipakai browser untuk mendeteksi perubahan dari pengguna lain. */
function get_rev(PDO $p): int
{
    return (int)$p->query("SELECT v FROM sync_meta WHERE k = 'rev'")->fetchColumn();
}
function bump_rev(PDO $p): int
{
    $p->exec("UPDATE sync_meta SET v = v + 1 WHERE k = 'rev'");
    return get_rev($p);
}

/** Kolom tabel (nama => tipe data), tanpa kolom bantu "urut". $refresh=true membaca ulang dari server. */
function table_cols(PDO $p, string $t, bool $refresh = false): array
{
    static $cache = [];
    if ($refresh || !isset($cache[$t])) {
        $c = db_columns($p, $t);
        unset($c['urut']);
        $cache[$t] = $c;
    }
    return $cache[$t];
}

/** Simpan satu baris: kunci yang cocok dengan kolom -> kolom; sisanya -> extra_json. */
function insert_row(PDO $p, string $table, array $row, array $skip = []): void
{
    $cols = table_cols($p, $table);
    $hasExtra = isset($cols['extra_json']);
    $vals = [];
    $extra = [];
    foreach ($row as $k => $v) {
        if (in_array($k, $skip, true) || $k === 'extra_json') continue;
        if (isset($cols[$k]) && (is_scalar($v) || $v === null) && col_accepts($cols[$k], $v)) {
            $vals[$k] = ($v === '') ? null : (is_bool($v) ? (int)$v : $v);
        } else {
            $extra[$k] = $v;
        }
    }
    if ($hasExtra) {
        $vals['extra_json'] = $extra ? json_encode($extra, JSON_OUT) : null;
    }
    if (!$vals) return;
    sql_replace($p, $table, $vals);
}

/* ===================== Nilai besar (lampiran base64) =====================
 * MySQL menolak satu perintah yang lebih besar dari max_allowed_packet (bawaan XAMPP hanya 1 MB) dan memutus koneksi
 * ("MySQL server has gone away"). Nilai teks > BLOB_MIN byte karena itu dipecah ke tabel blob_chunk (potongan kecil),
 * dan kolom aslinya hanya berisi penanda. load_db() menyatukannya kembali, sehingga antarmuka tidak berubah.
 */
const BLOB_MARK = '@@blobref:v1@@';
const BLOB_MIN  = 120000;

function table_pk(string $t): array
{
    switch ($t) {
        case 'uji_imt':   return ['uji_id', 'anggota_id'];
        case 'app_state': return ['key'];
        case 'kegiatan_file': return ['fid'];
        default:          return ['id'];
    }
}

function blob_ref(string $table, array $vals, string $col): string
{
    $k = [];
    foreach (table_pk($table) as $c) $k[] = (string)($vals[$c] ?? '');
    return $table . '|' . $col . '|' . sha1(implode("\x1f", $k));
}

function blob_put(PDO $p, string $ref, string $data): void
{
    $p->prepare('DELETE FROM blob_chunk WHERE ref = ?')->execute([$ref]);
    $ins = $p->prepare('INSERT INTO blob_chunk (ref, no, data) VALUES (?,?,?)');
    $len = strlen($data);
    for ($off = 0, $no = 0; $off < $len; $no++) {
        $n = min(BLOB_MIN, $len - $off);
        while ($n > 1 && $off + $n < $len && (ord($data[$off + $n]) & 0xC0) === 0x80) $n--; // jangan memotong karakter UTF-8
        $ins->execute([$ref, $no, substr($data, $off, $n)]);
        $off += $n;
    }
}

function blob_get(PDO $p, string $v): string
{
    if (strncmp($v, BLOB_MARK, strlen(BLOB_MARK)) !== 0) return $v;
    $s = $p->prepare('SELECT data FROM blob_chunk WHERE ref = ? ORDER BY no');
    $s->execute([substr($v, strlen(BLOB_MARK))]);
    return implode('', $s->fetchAll(PDO::FETCH_COLUMN));
}

/** Seperti REPLACE INTO biasa, tetapi nilai teks besar dipecah ke blob_chunk. */
function sql_replace(PDO $p, string $table, array $vals): void
{
    $pk = table_pk($table);
    foreach ($vals as $c => $v) {
        if (is_string($v) && strlen($v) > BLOB_MIN && !in_array($c, $pk, true)) {
            $ref = blob_ref($table, $vals, (string)$c);
            blob_put($p, $ref, $v);
            $vals[$c] = BLOB_MARK . $ref;
        }
    }
    $names = array_keys($vals);
    $sql = "REPLACE INTO $table (" . implode(',', array_map(fn($n) => '`' . $n . '`', $names)) . ') VALUES ('
        . implode(',', array_fill(0, count($names), '?')) . ')';
    $p->prepare($sql)->execute(array_values($vals));
}

/**
 * SQLite longgar soal tipe, MySQL tidak. Nilai yang tidak cocok dengan kolom angka (mis. teks di kolom jumlah,
 * pecahan di kolom bilangan bulat) tidak ditolak, tetapi disimpan di extra_json dan dikembalikan apa adanya saat dimuat.
 */
function col_accepts(string $type, $v): bool
{
    if ($v === null || $v === '') return true;
    if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
        return is_bool($v) || is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}$/', $v) === 1);
    }
    if (in_array($type, ['double', 'float', 'decimal'], true)) {
        return is_bool($v) || is_int($v) || is_float($v) && is_finite($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $v) === 1);
    }
    return true;
}

function row_out(array $r): array
{
    $extra = [];
    if (isset($r['extra_json']) && $r['extra_json'] !== '') {
        $d = json_decode((string)$r['extra_json'], true);
        if (is_array($d)) $extra = $d;
    }
    unset($r['extra_json'], $r['urut']);
    foreach ($r as $k => $v) if ($v === null) $r[$k] = '';
    return array_merge($r, $extra);
}

function fetch_all(PDO $p, string $table): array
{
    $ord = in_array($table, TABLES_ORDER_BY_ID, true) ? 'id' : 'urut';
    $rows = $p->query("SELECT * FROM $table ORDER BY $ord")->fetchAll();
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) {
            if (is_string($v) && $v !== '' && $v[0] === '@' && strncmp($v, BLOB_MARK, strlen(BLOB_MARK)) === 0) $r[$k] = blob_get($p, $v);
        }
    }
    unset($r);
    return $rows;
}

function group_by(array $rows, string $key): array
{
    $g = [];
    foreach ($rows as $r) $g[$r[$key]][] = $r;
    return $g;
}

function load_db(): array
{
    $p = pdo();
    $db = [];

    $db['anggota'] = array_map('row_out', fetch_all($p, 'anggota'));

    // Surat masuk
    $acc = group_by(fetch_all($p, 'surat_masuk_acc'), 'surat_id');
    $db['surat_masuk'] = [];
    foreach (fetch_all($p, 'surat_masuk') as $r) {
        $file = ($r['file_data'] !== null && $r['file_data'] !== '')
            ? ['name' => $r['file_name'], 'size' => (int)$r['file_size'], 'data' => $r['file_data']] : null;
        $o = row_out($r);
        unset($o['file_name'], $o['file_size'], $o['file_data']);
        $o['file_pdf'] = $file;
        $o['acc'] = array_map(fn($a) => ['role' => $a['role'], 'waktu' => (string)$a['waktu']], $acc[$r['id']] ?? []);
        $db['surat_masuk'][] = $o;
    }

    // Kegiatan / Tugas
    $ang = group_by(fetch_all($p, 'kegiatan_anggota'), 'kegiatan_id');
    $wa  = group_by(fetch_all($p, 'kegiatan_wa_log'), 'kegiatan_id');
    $fl  = group_by(fetch_all($p, 'kegiatan_file'), 'kegiatan_id');
    $db['kegiatan'] = [];
    foreach (fetch_all($p, 'kegiatan') as $r) {
        $lamp = ($r['lampiran_data'] !== null && $r['lampiran_data'] !== '')
            ? ['name' => $r['lampiran_name'], 'size' => (int)$r['lampiran_size'], 'data' => $r['lampiran_data']] : null;
        $k = row_out($r);
        unset($k['lampiran_name'], $k['lampiran_size'], $k['lampiran_data']);
        $k['file_pdf'] = $lamp;
        $k['anggota_ids'] = array_map(fn($a) => $a['anggota_id'], $ang[$r['id']] ?? []);
        $k['wa_log'] = array_map(fn($w) => ['waktu' => (string)$w['waktu'], 'jumlah' => (int)$w['jumlah']], $wa[$r['id']] ?? []);
        $k['hasil_files'] = [];
        $k['bukti_files'] = [];
        foreach ($fl[$r['id']] ?? [] as $f) {
            $m = ['fid' => $f['fid'], 'name' => (string)$f['name'], 'size' => (int)$f['size'],
                  'type' => (string)$f['mime'], 'uploaded' => (string)$f['uploaded']];
            if ($f['oleh'] !== null && $f['oleh'] !== '') $m['oleh'] = $f['oleh'];
            if ($f['mime'] === 'link') { $m['link'] = (string)$f['path']; $m['type'] = 'link'; } // hasil berupa tautan (bukan file)
            if ((int)$f['laporan'] === 1) $m['laporan'] = true;
            if ($f['kind'] === 'sprin') $k['sprin'] = $m;
            elseif ($f['kind'] === 'bukti') $k['bukti_files'][] = $m;
            else $k['hasil_files'][] = $m;
        }
        $db['kegiatan'][] = $k;
    }

    // Modul program + inventaris
    $peserta = group_by(fetch_all($p, 'peserta_kegiatan'), 'record_id');
    foreach (MODULE_TABLES as $m) {
        $db[$m] = [];
        foreach (fetch_all($p, $m) as $r) {
            $o = row_out($r);
            if ($m === 'diklat') {
                $pm = [];
                foreach ($peserta[$r['id']] ?? [] as $ps) {
                    if ($ps['record_id'] === $r['id'] && $ps['modul'] === 'diklat') {
                        $d = json_decode((string)$ps['data_json'], true);
                        if (is_array($d)) $pm[] = $d;
                    }
                }
                $o['peserta_matrix'] = $pm;
            }
            $db[$m][] = $o;
        }
    }

    $db['uji_kategori'] = array_map('row_out', fetch_all($p, 'uji_kategori'));

    $imt = new stdClass();
    foreach (fetch_all($p, 'uji_imt') as $r) {
        $jaw = json_decode((string)$r['jawaban_json'], true);
        $o = row_out($r);
        unset($o['jawaban_json'], $o['uji_id'], $o['anggota_id']);
        $o['jawaban'] = is_array($jaw) ? $jaw : [];
        $imt->{$r['uji_id'] . '|' . $r['anggota_id']} = $o;
    }
    $db['uji_imt'] = $imt;

    foreach ($p->query('SELECT `key`, `value` FROM app_state')->fetchAll() as $r) {
        $db[$r['key']] = json_decode(blob_get($p, (string)$r['value'])); // objek tetap objek
    }
    return $db;
}

/** Simpan seluruh snapshot (transaksi tunggal). $objs = hasil json_decode non-assoc untuk kunci tambahan. */
function save_db(PDO $p, array $db, ?object $objs = null, ?array $dbLama = null): int
{
    // Notifikasi perubahan (lonceng Admin): hanya bila pemanggil memasang konteks pelaku lewat notif_set() (berlaku sekali).
    $notifCtx = notif_ambil();
    $notifEv = [];
    if ($notifCtx) {
        try { $notifEv = notif_diff($dbLama ?? load_db(), $db); }
        catch (Throwable $e) { error_log('[basarnas-notif] diff: ' . $e->getMessage()); }
    }
    $p->exec('SET FOREIGN_KEY_CHECKS = 0');
    $p->beginTransaction();
    try {
        foreach (DELETE_ORDER as $t) $p->exec("DELETE FROM $t");
        foreach (MODULE_TABLES as $t) $p->exec("DELETE FROM $t");

        $list = static function ($v): array {
            return is_array($v) ? array_filter($v, fn($r) => is_array($r) && isset($r['id']) && $r['id'] !== '') : [];
        };

        foreach ($list($db['anggota'] ?? []) as $r) insert_row($p, 'anggota', $r);

        foreach ($list($db['surat_masuk'] ?? []) as $r) {
            $f = $r['file_pdf'] ?? null;
            if (is_array($f)) {
                $r['file_name'] = $f['name'] ?? null;
                $r['file_size'] = $f['size'] ?? null;
                $r['file_data'] = $f['data'] ?? null;
            }
            insert_row($p, 'surat_masuk', $r, ['acc', 'file_pdf']);
            foreach (($r['acc'] ?? []) as $a) {
                if (!is_array($a)) continue;
                $p->prepare('INSERT INTO surat_masuk_acc (surat_id, role, waktu) VALUES (?,?,?)')
                  ->execute([$r['id'], (string)($a['role'] ?? ''), (string)($a['waktu'] ?? '')]);
            }
        }

        foreach ($list($db['kegiatan'] ?? []) as $k) {
            $f = $k['file_pdf'] ?? null;
            if (is_array($f)) {
                $k['lampiran_name'] = $f['name'] ?? null;
                $k['lampiran_size'] = $f['size'] ?? null;
                $k['lampiran_data'] = $f['data'] ?? null;
            }
            insert_row($p, 'kegiatan', $k, ['anggota_ids', 'wa_log', 'hasil_files', 'bukti_files', 'sprin', 'file_pdf']);
            foreach (array_unique((array)($k['anggota_ids'] ?? [])) as $a) {
                $p->prepare('INSERT IGNORE INTO kegiatan_anggota (kegiatan_id, anggota_id) VALUES (?,?)')->execute([$k['id'], $a]);
            }
            foreach (($k['wa_log'] ?? []) as $w) {
                if (!is_array($w)) continue;
                $p->prepare('INSERT INTO kegiatan_wa_log (kegiatan_id, waktu, jumlah) VALUES (?,?,?)')
                  ->execute([$k['id'], (string)($w['waktu'] ?? ''), (int)($w['jumlah'] ?? 0)]);
            }
            $files = [];
            foreach (($k['hasil_files'] ?? []) as $f) $files[] = ['hasil', $f];
            foreach (($k['bukti_files'] ?? []) as $f) $files[] = ['bukti', $f];
            if (!empty($k['sprin']) && is_array($k['sprin'])) $files[] = ['sprin', $k['sprin']];
            foreach ($files as [$kind, $f]) {
                if (!is_array($f) || empty($f['fid'])) continue;
                $p->prepare('REPLACE INTO kegiatan_file (fid, kegiatan_id, kind, name, size, mime, uploaded, oleh, laporan, path) VALUES (?,?,?,?,?,?,?,?,?,?)')
                  ->execute([$f['fid'], $k['id'], $kind, $f['name'] ?? null, $f['size'] ?? null, !empty($f['link']) ? 'link' : ($f['type'] ?? null),
                             $f['uploaded'] ?? null, $f['oleh'] ?? null, !empty($f['laporan']) ? 1 : 0,
                             !empty($f['link']) ? (string)$f['link'] : 'uploads/' . $f['fid']]);
            }
        }

        foreach (MODULE_TABLES as $m) {
            foreach ($list($db[$m] ?? []) as $r) {
                $skip = [];
                if ($m === 'diklat') $skip[] = 'peserta_matrix';
                insert_row($p, $m, $r, $skip);
                if ($m === 'diklat') {
                    foreach (($r['peserta_matrix'] ?? []) as $ps) {
                        if (!is_array($ps)) continue;
                        $p->prepare('INSERT INTO peserta_kegiatan (modul, record_id, anggota_id, nama, jabatan, jk, data_json) VALUES (?,?,?,?,?,?,?)')
                          ->execute(['diklat', $r['id'], $ps['anggota_id'] ?? null, $ps['nama'] ?? null, $ps['jabatan'] ?? null,
                                     $ps['jk'] ?? null, json_encode($ps, JSON_OUT)]);
                    }
                }
            }
        }

        foreach ($list($db['uji_kategori'] ?? []) as $r) insert_row($p, 'uji_kategori', $r);

        foreach ((array)($db['uji_imt'] ?? []) as $key => $v) {
            if (!is_array($v)) continue;
            [$uji, $ang] = array_pad(explode('|', (string)$key, 2), 2, '');
            $row = $v;
            unset($row['jawaban']);
            $row['uji_id'] = $uji;
            $row['anggota_id'] = $ang;
            $row['jawaban_json'] = json_encode($v['jawaban'] ?? [], JSON_OUT);
            insert_row($p, 'uji_imt', $row);
        }

        $known = array_merge(CORE_KEYS, MODULE_TABLES);
        foreach ($db as $key => $val) {
            if (in_array($key, $known, true)) continue;
            $src = ($objs !== null && property_exists($objs, (string)$key)) ? $objs->{$key} : $val;
            sql_replace($p, 'app_state', ['key' => (string)$key, 'value' => json_encode($src, JSON_OUT)]);
        }

        $rev = bump_rev($p);
        $p->commit();
        if ($notifCtx && $notifEv) {
            try { notif_catat($p, $notifCtx, $notifEv); }
            catch (Throwable $e) { error_log('[basarnas-notif] catat: ' . $e->getMessage()); } // notifikasi gagal tidak boleh menggagalkan simpan
        }
        return $rev;
    } catch (Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        throw $e;
    } finally {
        $p->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}


/**
 * Status WhatsApp "Terkirim" yang sudah dicatat server tidak boleh ditimpa kembali menjadi "Belum Terkirim"
 * (dan riwayat kirimnya tidak boleh hilang) oleh halaman browser yang datanya belum diperbarui.
 * Pengecualian: daftar anggota bertugas memang berubah -> info tugas perlu dikirim ulang.
 */
function wa_jaga_status_kirim(array &$new, array $old): void
{
    $lama = [];
    foreach ($old['kegiatan'] ?? [] as $r) if (is_array($r) && !empty($r['id'])) $lama[(string)$r['id']] = $r;
    if (!$lama || !isset($new['kegiatan']) || !is_array($new['kegiatan'])) return;
    foreach ($new['kegiatan'] as $i => $r) {
        if (!is_array($r)) continue;
        $id = (string)($r['id'] ?? '');
        if (!isset($lama[$id])) continue;
        $o = $lama[$id];
        $a1 = (array)($o['anggota_ids'] ?? []); $a2 = (array)($r['anggota_ids'] ?? []);
        sort($a1); sort($a2);
        if ($a1 !== $a2) continue;
        if (($o['status_wa'] ?? '') === 'Terkirim' && ($r['status_wa'] ?? '') !== 'Terkirim') $new['kegiatan'][$i]['status_wa'] = 'Terkirim';
        if (count((array)($o['wa_log'] ?? [])) > count((array)($r['wa_log'] ?? []))) $new['kegiatan'][$i]['wa_log'] = $o['wa_log'];
    }
}


/* ---- Link bagikan Dashboard (tanpa login, hanya lihat). Kode dibuat otomatis oleh tombol "Bagikan" di Dashboard. ---- */
function tampil_file(): string { return DATA_DIR . '/tampil_token.txt'; }
function tampil_token_get(): string
{
    $t = is_file(tampil_file()) ? trim((string)@file_get_contents(tampil_file())) : '';
    return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}
function tampil_token_buat(): string
{
    $t = bin2hex(random_bytes(16));
    file_put_contents(tampil_file(), $t, LOCK_EX);
    return $t;
}
function tampil_token_cabut(): void { @unlink(tampil_file()); }
