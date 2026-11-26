<?php
/**
 * Laporan kesiapan aset via WhatsApp -> Pengecekan Siaga Rutin (Dashboard BASARNAS).
 *
 * Pesan seperti:
 *     Selamat Siang Pimpinan
 *     Izin melaporkan perkembangan terkini mengenai kesiapan aset ...
 *     Minggu, 01 November 2026
 *     1. KN SAR GANESHA (Dermaga Kade Tg. Priok)
 *     * Endurance: 65,4 jam
 *     * Speed: 12 knot
 *     ...
 *     * Kecepatan angin: 3 knot
 *     * Arah angin: Tenggara
 * dikenali otomatis (tanpa kata kunci), lalu dicatat sebagai SATU riwayat di menu Pengecekan Siaga Rutin.
 *
 * Jenis pengecekan disesuaikan per laporan:
 *   - Sarana / Sapras Laut   : kapal, RB, RBB, RIB (Endurance, Speed, Range, BBM %, angin)
 *   - Sarana / Sapras Darat  : mobil, truk, motor, dst. (KM, BBM %, tanggal ganti oli)
 *   - Peralatan              : butir berisi Jumlah / Baik / Rusak / Hilang
 * Aset yang belum ada di modul Kendaraan / Peralatan dibuat otomatis; yang sudah ada diperbarui
 * (aturan sama dengan tombol Simpan di Pengecekan Siaga Rutin: nilai angka > 0 menggantikan nilai modul).
 *
 * Dipanggil dari wa_webhook.php (memerlukan wa_inti.php). Tidak ada akses web langsung (lihat .htaccess).
 */
declare(strict_types=1);

const WA_SIAGA_BULAN = [
    'januari' => 1, 'jan' => 1, 'februari' => 2, 'feb' => 2, 'pebruari' => 2, 'maret' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
    'mei' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8,
    'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'nop' => 11,
    'desember' => 12, 'des' => 12, 'dec' => 12,
];
const WA_SIAGA_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
/** Isian per kolom Sapras Laut / Darat (kunci sama dengan modul Kendaraan di index.php). */
const WA_SIAGA_KOLOM_LAUT  = ['endurance' => 'num', 'speed' => 'num', 'range' => 'num', 'konsumsi_bbm' => 'num', 'cadangan_bbm' => 'num', 'persen_bbm' => 'num', 'kecepatan_angin' => 'num', 'arah_angin' => 'txt'];
const WA_SIAGA_KOLOM_DARAT = ['km_saat_ini' => 'num', 'tgl_ganti_oli' => 'txt', 'bbm' => 'num'];

/* ----------------------------------------------------------------------------------------------
 * 1. Deteksi
 * -------------------------------------------------------------------------------------------- */

/**
 * Apakah pesan ini laporan kesiapan aset? Syarat (ketat agar perintah biasa tidak terganggu):
 * ada butir bernomor ("1. Nama aset") yang diikuti baris "* Kunci: nilai", dan
 * (ada kata penanda laporan, atau ada kunci khas seperti Endurance/Speed/Range/BBM/KM).
 */
function wa_siaga_deteksi(string $pesan): bool
{
    if (mb_strlen($pesan) < 30) return false;
    $lines = preg_split('/\R/u', $pesan) ?: [];
    $butir = 0; $isian = 0; $kunciKhas = 0;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if (preg_match('/^\d{1,2}\s*[.)]\s+\S/u', $ln)) { $butir++; continue; }
        if (preg_match('/^[\*\-•▪●]\s*([^:=]{1,40}?)\s*[:=]\s*\S/u', $ln, $m)) {
            $isian++;
            if (in_array(wa_key($m[1]), ['endurance', 'speed', 'range', 'bbm', 'bbmrutin', 'reservefuel', 'km', 'kmsaatini', 'airbersih', 'persenbbm'], true)) $kunciKhas++;
        }
    }
    if ($butir < 1 || $isian < 1) return false;
    $penanda = (bool)preg_match('/\b(kesiapan|siaga|melaporkan|laporan)\b/iu', $pesan);
    return $penanda || $kunciKhas >= 2;
}

/* ----------------------------------------------------------------------------------------------
 * 2. Penguraian
 * -------------------------------------------------------------------------------------------- */

/** Angka gaya Indonesia: "65,4" "784,8" "220.5" "1.234" "1.234,5". Null bila tidak ada angka. */
function wa_siaga_angka(string $v): ?float
{
    if (!preg_match('/-?\d[\d.,]*/', $v, $m)) return null;
    $s = rtrim($m[0], '.,');
    $hasDot = str_contains($s, '.'); $hasCom = str_contains($s, ',');
    if ($hasDot && $hasCom) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif ($hasCom) $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    elseif ($hasDot && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? (float)$s : null;
}

/** "40 jam 36 menit" -> 40.6 ; "65,4 jam" -> 65.4 ; "90 menit" -> 1.5 ; "12" -> 12. */
function wa_siaga_jam(string $v): ?float
{
    $j = null; $mnt = null;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam/iu', $v, $m)) $j = wa_siaga_angka($m[1]);
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:menit|mnt|min)\b/iu', $v, $m)) $mnt = wa_siaga_angka($m[1]);
    if ($j === null && $mnt === null) return wa_siaga_angka($v);
    return round(($j ?? 0) + ($mnt ?? 0) / 60, 2);
}

function wa_siaga_kosong(string $v): bool
{
    return preg_match('/^[\s\-–—_.\/]*$/u', $v) === 1 || in_array(mb_strtolower(trim($v)), ['nihil', 'tidak ada', 'n/a', 'na'], true);
}

/** Tanggal (yyyy-mm-dd) dari "Minggu, 01 November 2026" / "1 Nov 2026" / "01/11/2026"; hari ini bila tak ada. */
function wa_siaga_tanggal(string $pesan): string
{
    if (preg_match('/\b(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4})\b/u', $pesan, $m)) {
        $bln = WA_SIAGA_BULAN[mb_strtolower($m[2])] ?? 0;
        if ($bln && checkdate($bln, (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', (int)$m[3], $bln, (int)$m[1]);
    }
    if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})\b/', $pesan, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return date('Y-m-d');
}

/**
 * Urai pesan. Hasil: ['tanggal','items'=>[...],'global'=>[kunci=>nilai],'catatan'=>[...]].
 * Item: ['no','nama','lokasi','kosong'(bool),'f'=>[kunci terstandar => nilai],'ket'=>[teks...],'jenis'=>'laut|darat|alat']
 */
function wa_siaga_parse(string $pesan): array
{
    $items = []; $global = []; $ci = null; // $ci = indeks butir yang sedang dibaca
    foreach (preg_split('/\R/u', $pesan) ?: [] as $raw) {
        $ln = trim($raw);
        if ($ln === '') { $ci = null; continue; } // baris kosong menutup butir; isian sesudahnya = isian umum (angin, gelombang, dst.)

        if (preg_match('/^(\d{1,2})\s*[.)]\s+(.+)$/u', $ln, $m) && !preg_match('/[:=]\s*\S/u', $m[2])) {
            $nama = trim($m[2]); $lok = '';
            if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*$/u', $nama, $mm)) { $nama = trim($mm[1]); $lok = trim($mm[2]); }
            $items[] = ['no' => (int)$m[1], 'nama' => $nama, 'lokasi' => $lok, 'kosong' => false, 'f' => [], 'ket' => [], 'jenis' => ''];
            $ci = count($items) - 1;
            continue;
        }
        $isi = preg_replace('/^[\*\-•▪●]+\s*/u', '', $ln) ?? $ln;
        if (preg_match('/^([^:=]{1,40}?)\s*[:=]\s*(.*)$/u', $isi, $m) && wa_key($m[1]) !== '') {
            $k = wa_key($m[1]); $v = trim($m[2]);
            $umum = in_array($k, ['kecepatanangin', 'arahangin', 'angin', 'tinggigelombang', 'ketinggiangelombang', 'gelombang', 'tinggimukaair', 'ketinggianmukaair', 'mukaair', 'cuaca'], true);
            if ($umum || $ci === null) { if (!wa_siaga_kosong($v)) $global[$k] = $v; continue; }
            if (wa_siaga_kosong($v)) continue;
            $std = wa_siaga_kunci($k);
            if ($std === null) $items[$ci]['ket'][] = trim($m[1]) . ': ' . $v;
            else $items[$ci]['f'][$std] = $v;
            continue;
        }
        // baris "* -" di bawah butir = tidak ada data
        if ($ci !== null && wa_siaga_kosong($isi) && !$items[$ci]['f'] && !$items[$ci]['ket']) $items[$ci]['kosong'] = true;
    }
    foreach ($items as &$it) { $it['jenis'] = wa_siaga_jenis($it); }
    unset($it);
    return ['tanggal' => wa_siaga_tanggal($pesan), 'items' => $items, 'global' => $global];
}

/** Kunci bebas pengguna -> kunci terstandar (null = tidak dikenal, masuk keterangan). */
function wa_siaga_kunci(string $k): ?string
{
    return match (true) {
        $k === 'endurance' || $k === 'dayatahan' => 'endurance',
        $k === 'speed' || $k === 'kecepatan' || $k === 'kecepatankapal' => 'speed',
        $k === 'range' || $k === 'jangkauan' => 'range',
        in_array($k, ['bbm', 'bbmrutin', 'persenbbm', 'persentasebbm', 'bahanbakar', 'fuel', 'bbmpersen'], true) => 'bbm',
        in_array($k, ['reservefuel', 'cadanganbbm', 'cadangan', 'cadanganbahanbakar'], true) => 'cadangan',
        in_array($k, ['konsumsibbm', 'konsumsi', 'konsumsibahanbakar'], true) => 'konsumsi',
        in_array($k, ['km', 'kmsaatini', 'odometer'], true) => 'km',
        in_array($k, ['gantioli', 'tglgantioli', 'tanggalgantioli', 'oli'], true) => 'oli',
        in_array($k, ['jumlah', 'total', 'stok', 'qty'], true) => 'jumlah',
        in_array($k, ['baik', 'jumlahbaik'], true) => 'baik',
        in_array($k, ['rusak', 'jumlahrusak', 'rusakringan', 'rusakberat'], true) => 'rusak',
        in_array($k, ['hilang', 'jumlahhilang'], true) => 'hilang',
        in_array($k, ['kondisi'], true) => 'kondisi',
        in_array($k, ['posisi', 'letak'], true) => 'posisi',
        in_array($k, ['ket', 'keterangan', 'catatan'], true) => 'ket',
        default => null,
    };
}

/** Jenis pengecekan butir: laut | darat | alat. */
function wa_siaga_jenis(array $it): string
{
    $f = $it['f']; $n = mb_strtolower($it['nama']);
    if (isset($f['endurance']) || isset($f['speed']) || isset($f['range']) || isset($f['konsumsi'])) return 'laut';
    if (isset($f['km']) || isset($f['oli'])) return 'darat';
    if (isset($f['jumlah']) || isset($f['baik']) || isset($f['rusak']) || isset($f['hilang'])) return 'alat';
    if (preg_match('/^(kn|kri|kal|kapal|rb|rbb|rib|lcr)\b|\b(rib|rubber|boat|kapal|perahu|speedboat|jetski|jet ski)\b/iu', $n)) return 'laut';
    if (preg_match('/\b(mobil|truk|truck|rescue car|elf|hino|ambulan[cs]e?|motor|pick ?up|dumptruck|bus|minibus|mpv|suv|l300|hilux|triton)\b/iu', $n)) return 'darat';
    return isset($f['bbm']) ? 'darat' : 'alat';
}

/* ----------------------------------------------------------------------------------------------
 * 3. Pencocokan & pembuatan data modul
 * -------------------------------------------------------------------------------------------- */

function wa_siaga_norm(string $s): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? ''; }

/** Indeks baris modul yang cocok dengan $nama (sama persis dulu; lalu saling mengandung bila hanya satu kandidat). -1 bila tidak ada. */
function wa_siaga_cari(array $rows, string $kolomNama, string $nama, ?callable $saring = null): int
{
    $n = wa_siaga_norm($nama);
    if ($n === '') return -1;
    $kand = [];
    foreach ($rows as $i => $r) {
        if (!is_array($r) || ($saring && !$saring($r))) continue;
        $m = wa_siaga_norm((string)($r[$kolomNama] ?? ''));
        if ($m === '') continue;
        if ($m === $n) return $i;
        if (min(strlen($m), strlen($n)) >= 5 && (str_contains($m, $n) || str_contains($n, $m))) $kand[] = $i;
    }
    return count($kand) === 1 ? $kand[0] : -1;
}

function wa_siaga_sapras_row(array $r): string
{
    if (!empty($r['sapras'])) return (string)$r['sapras'];
    return in_array($r['jenis_kendaraan'] ?? '', ['Kapal', 'RIB / Perahu Karet'], true) ? 'Sapras Laut' : 'Sapras Darat';
}

function wa_siaga_jenis_kendaraan(string $nama, string $kat): string
{
    $n = mb_strtolower($nama);
    if ($kat === 'Sapras Laut') return preg_match('/^(kn|kri|kal)\b|kapal/u', $n) ? 'Kapal' : 'RIB / Perahu Karet';
    return match (true) {
        (bool)preg_match('/truk|truck|hino/u', $n) => 'Truk',
        (bool)preg_match('/motor/u', $n) => 'Motor',
        (bool)preg_match('/ambulan/u', $n) => 'Ambulans',
        default => 'Mobil',
    };
}

function wa_siaga_fmt(float $x): string { return rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ','); }

/** Nilai terstandar butir -> isian kolom modul Kendaraan. Mengembalikan [kolom => nilai(float|string)], tanpa nilai kosong. */
function wa_siaga_nilai_kendaraan(array $it, string $kat, array $global): array
{
    $f = $it['f']; $o = [];
    if ($kat === 'Sapras Laut') {
        if (isset($f['endurance']) && ($x = wa_siaga_jam($f['endurance'])) !== null) $o['endurance'] = $x;
        if (isset($f['speed']) && ($x = wa_siaga_angka($f['speed'])) !== null) $o['speed'] = $x;
        if (isset($f['range']) && ($x = wa_siaga_angka($f['range'])) !== null) $o['range'] = $x;
        if (isset($f['konsumsi']) && ($x = wa_siaga_angka($f['konsumsi'])) !== null) $o['konsumsi_bbm'] = $x;
        if (isset($f['bbm']) && ($x = wa_siaga_angka($f['bbm'])) !== null) $o['persen_bbm'] = $x;
        // "Reserve Fuel: 8%" = persen; kolom modul "Cadangan BBM" berisi LITER, jadi hanya angka ber-liter yang masuk kolom itu.
        if (isset($f['cadangan']) && preg_match('/\bl(iter)?\b/iu', $f['cadangan']) && ($x = wa_siaga_angka($f['cadangan'])) !== null) $o['cadangan_bbm'] = $x;
        if (isset($global['kecepatanangin']) && ($x = wa_siaga_angka($global['kecepatanangin'])) !== null) $o['kecepatan_angin'] = $x;
        elseif (isset($global['angin']) && ($x = wa_siaga_angka($global['angin'])) !== null) $o['kecepatan_angin'] = $x;
        if (!empty($global['arahangin'])) $o['arah_angin'] = $global['arahangin'];
    } else {
        if (isset($f['km']) && ($x = wa_siaga_angka($f['km'])) !== null) $o['km_saat_ini'] = $x;
        if (isset($f['bbm']) && ($x = wa_siaga_angka($f['bbm'])) !== null) $o['bbm'] = $x;
    }
    return $o;
}

/** Catatan (keterangan) item: isian yang tidak punya kolom sendiri. */
function wa_siaga_ket(array $it): string
{
    $ket = $it['ket'];
    if (isset($it['f']['cadangan']) && !preg_match('/\bl(iter)?\b/iu', $it['f']['cadangan'])) array_unshift($ket, 'Reserve fuel: ' . $it['f']['cadangan']);
    elseif (isset($it['f']['cadangan']) && wa_siaga_angka($it['f']['cadangan']) === null) array_unshift($ket, 'Reserve fuel: ' . $it['f']['cadangan']);
    if (!empty($it['f']['ket'])) $ket[] = $it['f']['ket'];
    if ($it['kosong']) $ket[] = 'Tidak ada data dilaporkan (-)';
    return implode('; ', $ket);
}

/* ----------------------------------------------------------------------------------------------
 * 4. Simpan
 * -------------------------------------------------------------------------------------------- */

/**
 * Catat laporan ke $db (belum disimpan ke database: pemanggil yang memanggil save_db).
 * Satu laporan dapat berisi beberapa jenis (mis. kapal + mobil): dibuat satu riwayat per jenis.
 * Mengembalikan teks balasan WhatsApp.
 */
function wa_siaga_simpan(array &$db, array $lap, array $pelaku): string
{
    $items = $lap['items'];
    if (!$items) throw new RuntimeException('Tidak ada butir aset bernomor yang terbaca pada laporan.');
    $tgl = $lap['tanggal']; $global = $lap['global'];
    $now = wa_now_str();
    $oleh = ['nama' => $pelaku['nama'], 'peran' => 'Anggota (WhatsApp)'];
    $sumber = ['sumber' => 'WhatsApp', 'wa_pengirim' => $pelaku['nomor']];
    $hari = WA_SIAGA_HARI[(int)date('w', strtotime($tgl))];
    $tglId = $hari . ', ' . wa_tgl_id($tgl);

    $kendaraan = wa_rows($db, 'kendaraan');
    $peralatan = wa_rows($db, 'peralatan');
    $riwayat = wa_rows($db, 'siaga_rutin'); // elemen lama (objek) dibiarkan apa adanya
    $kelompok = ['Sapras Laut' => [], 'Sapras Darat' => [], 'Peralatan' => []];
    $baru = []; $diperbarui = []; $dibuat = [];

    foreach ($items as $it) {
        $kat = ['laut' => 'Sapras Laut', 'darat' => 'Sapras Darat', 'alat' => 'Peralatan'][$it['jenis']];
        $ket = wa_siaga_ket($it);
        if ($kat === 'Peralatan') {
            $i = wa_siaga_cari($peralatan, 'nama_barang', $it['nama']);
            $f = $it['f'];
            $b = isset($f['baik']) ? (int)(wa_siaga_angka($f['baik']) ?? 0) : null;
            $r = isset($f['rusak']) ? (int)(wa_siaga_angka($f['rusak']) ?? 0) : 0;
            $h = isset($f['hilang']) ? (int)(wa_siaga_angka($f['hilang']) ?? 0) : 0;
            $jml = isset($f['jumlah']) ? (int)(wa_siaga_angka($f['jumlah']) ?? 0) : (int)(($b ?? 0) + $r + $h);
            if ($b === null) $b = max(0, $jml - $r - $h);
            $kond = $h > 0 ? 'Hilang' : ($r > 0 ? 'Rusak Ringan' : 'Baik');
            if (isset($f['kondisi'])) { $kk = mb_strtolower($f['kondisi']); $kond = str_contains($kk, 'berat') ? 'Rusak Berat' : (str_contains($kk, 'rusak') ? 'Rusak Ringan' : (str_contains($kk, 'hilang') ? 'Hilang' : $kond)); }
            if ($i < 0) {
                $rec = ['id' => wa_next_id('PRL', $peralatan), 'nama_barang' => $it['nama'], 'lokasi' => $it['lokasi'], 'jumlah_baik' => $b, 'jumlah_rusak' => $r, 'jumlah_hilang' => $h, 'jumlah' => $b + $r,
                        'keterangan' => '', 'diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
                $peralatan[] = $rec; $i = count($peralatan) - 1; $dibuat[] = $it['nama'] . ' (Peralatan)';
            } else {
                $rec = &$peralatan[$i];
                if ($kond !== 'Baik' && $jml > 0) { $rec['jumlah_baik'] = $b; $rec['jumlah_rusak'] = $r; $rec['jumlah_hilang'] = $h; $rec['jumlah'] = $b + $r; $diperbarui[$it['nama']] = true; }
                unset($rec);
            }
            if ($ket !== '') {
                $cat = "[Cek Siaga " . date('d-m-Y', strtotime($tgl)) . "] $ket";
                $kt = (string)($peralatan[$i]['keterangan'] ?? '');
                if (!str_contains($kt, $cat)) $peralatan[$i]['keterangan'] = $kt === '' ? $cat : "$kt\n$cat";
            }
            $kelompok['Peralatan'][] = ['ref' => $peralatan[$i]['id'], 'recId' => $peralatan[$i]['id'], 'nama' => $it['nama'], 'jumlah' => $jml, 'posisi' => $f['posisi'] ?? 'Rescue car carriel',
                'kondisi' => $kond, 'ket' => $ket, 'baik' => $b, 'rusak' => $r, 'hilang' => $h];
            continue;
        }

        // Sarana (kendaraan)
        $nilai = wa_siaga_nilai_kendaraan($it, $kat, $global);
        $i = wa_siaga_cari($kendaraan, 'nama_kendaraan', $it['nama'], static fn($r) => wa_siaga_sapras_row($r) === $kat);
        if ($i < 0) {
            $jk = wa_siaga_jenis_kendaraan($it['nama'], $kat);
            $rec = ['id' => wa_next_id('KDR', $kendaraan), 'nama_kendaraan' => $it['nama'], 'sapras' => $kat, 'jenis_kendaraan' => $jk, 'nomor_polisi' => '', 'merk_tipe' => '',
                    'kondisi' => 'Serviceable', 'lokasi' => $it['lokasi'], 'tanggal' => $tgl, 'keterangan' => ''] + $nilai
                 + ['diperbarui_oleh' => $oleh, 'diperbarui_waktu' => $now] + wa_stempel() + $sumber;
            $kendaraan[] = $rec; $i = count($kendaraan) - 1; $dibuat[] = $it['nama'] . " ($kat)";
        } else {
            $rec = &$kendaraan[$i];
            foreach ($nilai as $kol => $v) { // sama dengan dashboard: angka > 0 / teks tak kosong menggantikan nilai modul
                if (is_float($v) && !($v > 0)) continue;
                $rec[$kol] = $v;
            }
            if (($rec['lokasi'] ?? '') === '' && $it['lokasi'] !== '') $rec['lokasi'] = $it['lokasi'];
            $rec['diperbarui_oleh'] = $oleh; $rec['diperbarui_waktu'] = $now;
            unset($rec);
            $diperbarui[$it['nama']] = true;
        }
        if ($ket !== '') {
            $cat = "[Cek Siaga " . date('d-m-Y', strtotime($tgl)) . "] $ket";
            $kt = (string)($kendaraan[$i]['keterangan'] ?? '');
            if (!str_contains($kt, $cat)) $kendaraan[$i]['keterangan'] = $kt === '' ? $cat : "$kt\n$cat";
        }
        $kolom = $kat === 'Sapras Laut' ? WA_SIAGA_KOLOM_LAUT : WA_SIAGA_KOLOM_DARAT;
        $vals = [];
        foreach ($kolom as $kol => $tipe) $vals[$kol] = $nilai[$kol] ?? ($tipe === 'num' ? 0 : '');
        $kelompok[$kat][] = ['ref' => $kendaraan[$i]['id'], 'nama' => $it['nama'], 'jumlah' => 1, 'posisi' => '', 'kondisi' => 'Baik', 'ket' => $ket,
            'nopol' => (string)($kendaraan[$i]['nomor_polisi'] ?? ''), 'jenisK' => (string)($kendaraan[$i]['jenis_kendaraan'] ?? ''), 'vals' => $vals, 'bersih' => 'Ya',
            '_lok' => $it['lokasi'] !== '' ? $it['lokasi'] : (string)($kendaraan[$i]['lokasi'] ?? '')];
    }

    // Satu riwayat per jenis pengecekan
    $idBaru = [];
    foreach ($kelompok as $kat => $rows) {
        if (!$rows) continue;
        $lokasi = [];
        foreach ($rows as $r) if (!empty($r['_lok'])) $lokasi[$r['_lok']] = true;
        foreach ($rows as &$r) unset($r['_lok']);
        unset($r);
        $sar = $kat !== 'Peralatan';
        $rec = ['id' => wa_next_id('SGA', $riwayat), 'jenis' => $sar ? 'Sarana' : 'Peralatan',
                'lokasi' => ($sar && count($lokasi) === 1) ? (string)array_key_first($lokasi) : 'Semua Lokasi',
                'tanggal' => $tgl, 'waktu' => date('H:i'), 'pemeriksa' => $pelaku['nama'], 'ttd' => '', 'items' => $rows];
        if ($sar) $rec += ['sapras' => $kat, 'hari' => mb_strtoupper($hari), 'tim' => '', 'kegiatan' => 'Laporan kesiapan aset via WhatsApp'];
        $cuaca = [];
        foreach (['kecepatanangin' => 'Kecepatan angin', 'angin' => 'Kecepatan angin', 'arahangin' => 'Arah angin', 'tinggigelombang' => 'Tinggi gelombang', 'ketinggiangelombang' => 'Tinggi gelombang', 'gelombang' => 'Tinggi gelombang',
                  'tinggimukaair' => 'Tinggi muka air', 'ketinggianmukaair' => 'Tinggi muka air', 'mukaair' => 'Tinggi muka air', 'cuaca' => 'Cuaca'] as $k => $lb) {
            if (!empty($global[$k]) && !isset($cuaca[$lb])) $cuaca[$lb] = $global[$k];
        }
        if ($cuaca) $rec['cuaca'] = $cuaca;
        $rec += $sumber;
        $riwayat[] = $rec;
        $idBaru[$kat] = [$rec['id'], count($rows)];
    }

    $db['kendaraan'] = $kendaraan;
    $db['peralatan'] = $peralatan;
    $db['siaga_rutin'] = $riwayat;

    // Balasan
    $out = "✅ *Pengecekan Siaga Rutin tercatat*\n$tglId\n";
    foreach ($idBaru as $kat => [$id, $n]) $out .= "\n• $id — " . ($kat === 'Peralatan' ? 'Peralatan' : "Sarana ($kat)") . ": $n aset";
    $out .= "\n";
    foreach ($kelompok as $kat => $rows) foreach ($rows as $r) {
        $baris = [];
        if ($kat === 'Sapras Laut') {
            if (($r['vals']['persen_bbm'] ?? 0) > 0) $baris[] = 'BBM ' . wa_siaga_fmt((float)$r['vals']['persen_bbm']) . '%';
            if (($r['vals']['endurance'] ?? 0) > 0) $baris[] = 'Endurance ' . wa_siaga_fmt((float)$r['vals']['endurance']) . ' jam';
        } elseif ($kat === 'Sapras Darat') {
            if (($r['vals']['bbm'] ?? 0) > 0) $baris[] = 'BBM ' . wa_siaga_fmt((float)$r['vals']['bbm']) . '%';
            if (($r['vals']['km_saat_ini'] ?? 0) > 0) $baris[] = 'KM ' . wa_siaga_fmt((float)$r['vals']['km_saat_ini']);
        } else $baris[] = "Baik {$r['baik']} · Rusak {$r['rusak']} · Hilang {$r['hilang']}";
        $out .= "\n- {$r['nama']}" . ($baris ? ': ' . implode(', ', $baris) : ': tidak ada data');
    }
    if ($dibuat) $out .= "\n\n🆕 Belum ada di dashboard, dibuat otomatis:\n- " . implode("\n- ", $dibuat);
    if ($diperbarui) $out .= "\n\n🔄 Data modul diperbarui: " . count($diperbarui) . ' aset';
    $out .= "\n\nLihat di dashboard: menu *Pengecekan Siaga Rutin* (di bawah Kendaraan).";
    return $out;
}
