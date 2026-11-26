<?php
/**
 * Koneksi MySQL (PDO) bersama untuk seluruh aplikasi.
 * - db_connect()           : satu koneksi PDO per permintaan (dipakai lib.php dan auth.php)
 * - db_table_exists()      : cek tabel ada
 * - db_columns()           : daftar kolom (nama => tipe data)
 * - db_ensure_table()      : buat tabel pendukung hanya bila belum ada
 */
declare(strict_types=1);

function db_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/config_db.php';
    $c = is_file($file) ? (array)(require $file) : [];
    $env = static fn(string $k) => ($v = getenv($k)) !== false && $v !== '' ? $v : null;
    $cfg = [
        'host'     => $env('BASARNAS_DB_HOST') ?? ($c['host'] ?? '127.0.0.1'),
        'port'     => (int)($env('BASARNAS_DB_PORT') ?? ($c['port'] ?? 3306)),
        'name'     => $env('BASARNAS_DB_NAME') ?? ($c['name'] ?? 'basarnas'),
        'user'     => $env('BASARNAS_DB_USER') ?? ($c['user'] ?? 'root'),
        'pass'     => $env('BASARNAS_DB_PASS') ?? ($c['pass'] ?? ''),
        'charset'  => $c['charset'] ?? 'utf8mb4',
        'timezone' => $c['timezone'] ?? '+07:00',
    ];
    return $cfg;
}

function db_connect(): PDO
{
    static $p = null;
    if ($p instanceof PDO) return $p;
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException("Ekstensi PHP 'pdo_mysql' belum aktif. Aktifkan di php.ini (extension=pdo_mysql).");
    }
    $c = db_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    try {
        $p = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,   // izinkan banyak perintah & hasil bertipe angka asli (PHP >= 8.1)
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('Tidak dapat terhubung ke database MySQL (' . $c['host'] . ':' . $c['port'] . ', database "'
            . $c['name'] . '", user "' . $c['user'] . '"). Periksa config_db.php. Pesan server: ' . $e->getMessage(), 0, $e);
    }
    $p->exec("SET NAMES " . $c['charset'] . " COLLATE " . $c['charset'] . "_unicode_ci");
    $p->exec("SET time_zone = " . $p->quote((string)$c['timezone']));
    // Mode ketat tetap aktif agar data tidak terpotong diam-diam; hanya mode tanggal "nol" yang dilonggarkan.
    $p->exec("SET SESSION sql_mode = REPLACE(REPLACE(@@sql_mode, 'NO_BACKSLASH_ESCAPES', ''), 'ANSI_QUOTES', '')");
    return $p;
}

function db_table_exists(PDO $p, string $table): bool
{
    static $cache = [];
    if (!empty($cache[$table])) return true;
    $s = $p->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $s->execute([$table]);
    return $cache[$table] = (bool)$s->fetchColumn();
}

/** @return array<string,string> nama kolom => tipe data (huruf kecil, mis. 'bigint', 'double', 'longtext') */
function db_columns(PDO $p, string $table): array
{
    $s = $p->prepare('SELECT column_name AS cn, data_type AS dt FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $s->execute([$table]);
    $out = [];
    foreach ($s->fetchAll() as $r) $out[(string)$r['cn']] = strtolower((string)$r['dt']);
    return $out;
}

/** Buat tabel pendukung hanya bila belum ada (menghindari DDL berulang di setiap permintaan). */
function db_ensure_table(PDO $p, string $table, string $createSql): void
{
    static $done = [];
    if (isset($done[$table])) return;
    if (!db_table_exists($p, $table)) {
        try { $p->exec($createSql); }
        catch (PDOException $e) { if (!db_table_exists($p, $table)) throw $e; } // dibuat bersamaan oleh permintaan lain
    }
    $done[$table] = true;
}

/** Definisi tabel pendukung (satu tempat agar konsisten). */
const DB_DDL_SYNC_META = 'CREATE TABLE IF NOT EXISTS sync_meta (k VARCHAR(50) NOT NULL PRIMARY KEY, v BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_BLOB_CHUNK = 'CREATE TABLE IF NOT EXISTS blob_chunk (
    ref  VARCHAR(255) NOT NULL,
    no   INT NOT NULL,
    data LONGTEXT,
    PRIMARY KEY (ref, no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_ACC_NOTIF = 'CREATE TABLE IF NOT EXISTS acc_notif (kunci VARCHAR(255) NOT NULL PRIMARY KEY, waktu VARCHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_WA_INBOX = 'CREATE TABLE IF NOT EXISTS wa_inbox (msg_id VARCHAR(255) NOT NULL PRIMARY KEY, waktu VARCHAR(64)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_WA_NOTIF_BUKTI = 'CREATE TABLE IF NOT EXISTS wa_notif_bukti (kegiatan_id VARCHAR(100) NOT NULL PRIMARY KEY, waktu BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_WA_NOTIF_SELESAI = 'CREATE TABLE IF NOT EXISTS wa_notif_selesai (kegiatan_id VARCHAR(100) NOT NULL PRIMARY KEY, waktu BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_WA_UPLOAD = 'CREATE TABLE IF NOT EXISTS wa_upload (
    token VARCHAR(100) NOT NULL PRIMARY KEY,
    db_key VARCHAR(100) NOT NULL,
    rec_id VARCHAR(100) NOT NULL,
    modul TEXT, judul TEXT,
    nomor VARCHAR(64),
    nama TEXT,
    dibuat BIGINT NOT NULL,
    kedaluwarsa BIGINT NOT NULL,
    dipakai TINYINT NOT NULL DEFAULT 0,
    jenis VARCHAR(30) NOT NULL DEFAULT \'lampiran\',
    cmd TEXT,
    urut BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
    KEY wa_upload_nomor (nomor, dibuat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
const DB_DDL_USERS = 'CREATE TABLE IF NOT EXISTS users (
    id            BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    display_name  VARCHAR(255),
    password_hash VARCHAR(255) NOT NULL,
    created_at    VARCHAR(64) NOT NULL,
    last_login    VARCHAR(64),
    role          VARCHAR(30) NOT NULL DEFAULT \'pengguna\',
    jabatan       VARCHAR(30) NOT NULL DEFAULT \'Anggota\'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
const DB_DDL_LOGIN_ATTEMPTS = 'CREATE TABLE IF NOT EXISTS login_attempts (
    `key` VARCHAR(191) NOT NULL,
    ts    BIGINT NOT NULL,
    KEY idx_login_attempts (`key`, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
