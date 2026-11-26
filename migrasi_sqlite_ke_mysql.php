<?php
/**
 * Memindahkan data lama (SQLite: data/basarnas.db dan data/auth.db) ke MySQL.
 * HANYA bisa dijalankan dari terminal (CLI). Atur dulu config_db.php dan buat database MySQL-nya.
 *
 *   php migrasi_sqlite_ke_mysql.php                       -> pakai data/basarnas.db dan data/auth.db
 *   php migrasi_sqlite_ke_mysql.php path/basarnas.db path/auth.db
 *   php migrasi_sqlite_ke_mysql.php --timpa               -> boleh menimpa data yang sudah ada di MySQL
 *
 * File SQLite tidak diubah/dihapus. Folder data/uploads tetap dipakai apa adanya.
 * Butuh ekstensi pdo_sqlite (hanya untuk migrasi ini) dan pdo_mysql.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/auth.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--timpa'));
$timpa = in_array('--timpa', $argv, true);
$fileData = $args[0] ?? DATA_DIR . '/basarnas.db';
$fileAuth = $args[1] ?? DATA_DIR . '/auth.db';

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Ekstensi pdo_sqlite diperlukan untuk membaca file lama.\n"); exit(1); }

function sq(string $f): ?PDO
{
    if (!is_file($f) || filesize($f) === 0) return null;
    $s = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $s;
}

function salin(PDO $src, PDO $dst, string $t, string $orderBy): int
{
    $ada = $src->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $src->quote($t))->fetchColumn();
    if (!$ada) return -1;
    $cols = table_cols($dst, $t);
    $cols['urut'] = 'bigint';
    $n = 0;
    foreach ($src->query("SELECT * FROM $t ORDER BY $orderBy") as $r) {
        $vals = [];
        $extra = [];
        foreach ($r as $k => $v) {
            if (!isset($cols[$k]) || $k === 'urut') continue;
            if ($k === 'extra_json') { $extra = json_decode((string)$v, true) ?: []; continue; }
            if (col_accepts($cols[$k], $v)) { $vals[$k] = $v; }
            elseif (isset($cols['extra_json'])) { $extra[$k] = $v; $vals[$k] = null; }
            else $vals[$k] = null;
        }
        if (isset($cols['extra_json'])) $vals['extra_json'] = $extra ? json_encode($extra, JSON_OUT) : null;
        sql_replace($dst, $t, $vals);
        $n++;
    }
    return $n;
}

$my = pdo(false); // buat tabel (tanpa data awal)
$sd = sq($fileData);
$sa = sq($fileAuth);
if (!$sd && !$sa) { fwrite(STDERR, "Tidak ada file SQLite yang ditemukan ($fileData / $fileAuth).\n"); exit(1); }

$ada = (int)$my->query('SELECT COUNT(*) FROM anggota')->fetchColumn() + (int)$my->query('SELECT COUNT(*) FROM kegiatan')->fetchColumn();
if ($ada > 0 && !$timpa) {
    fwrite(STDERR, "Database MySQL sudah berisi data ($ada baris anggota/kegiatan). Tambahkan --timpa bila memang ingin menimpanya.\n");
    exit(1);
}

$my->exec('SET FOREIGN_KEY_CHECKS = 0');
if ($sd) {
    echo "== Data dashboard ({$fileData}) ==\n";
    foreach (array_merge(DELETE_ORDER, MODULE_TABLES) as $t) $my->exec("DELETE FROM $t");
    $urutan = ['ref_jenis_kegiatan', 'anggota', 'surat_masuk', 'surat_masuk_acc', 'kegiatan', 'kegiatan_anggota', 'kegiatan_wa_log',
               'kegiatan_file', ...MODULE_TABLES, 'peserta_kegiatan', 'uji_kategori', 'uji_imt', 'app_state'];
    foreach ($urutan as $t) {
        $ob = in_array($t, TABLES_ORDER_BY_ID, true) ? 'id' : 'rowid';
        $n = salin($sd, $my, $t, $ob);
        echo sprintf("  %-20s %s\n", $t, $n < 0 ? '(tidak ada di file lama, dilewati)' : "$n baris");
    }
    // tabel pendukung WhatsApp (bila ada di file lama)
    $ddl = ['acc_notif' => DB_DDL_ACC_NOTIF, 'wa_upload' => DB_DDL_WA_UPLOAD, 'wa_notif_bukti' => DB_DDL_WA_NOTIF_BUKTI, 'wa_notif_selesai' => DB_DDL_WA_NOTIF_SELESAI];
    foreach ($ddl as $t => $sql) {
        $ada = $sd->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $sd->quote($t))->fetchColumn();
        if (!$ada) continue;
        db_ensure_table($my, $t, $sql);
        $n = salin($sd, $my, $t, 'rowid');
        echo sprintf("  %-20s %s\n", $t, "$n baris");
    }
    $rev = bump_rev($my);
    echo "  revisi data -> $rev\n";
}
if ($sa) {
    echo "== Akun ({$fileAuth}) ==\n";
    $mu = auth_db();
    $mu->exec('DELETE FROM login_attempts');
    $mu->exec('DELETE FROM users');
    $n = salin($sa, $mu, 'users', 'id');
    echo sprintf("  %-20s %s\n", 'users', $n < 0 ? '(tidak ada)' : "$n akun");
}
$my->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "\nSelesai. Setelah memastikan dashboard berjalan normal, file data/*.db lama boleh diarsipkan/dihapus.\n";
