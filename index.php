<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/auth.php';
try {
    // Link tampilan (tanpa login, hanya lihat): index.php?tampil=<token di tampil_config.php>
    $VIEW_ONLY = false;
    $tampilToken = tampil_token_get(); // dibuat otomatis lewat tombol Bagikan di Dashboard
    if (isset($_GET['tampil'])) {
        if ($tampilToken === '' || !hash_equals($tampilToken, (string)$_GET['tampil'])) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Link dashboard ini tidak valid atau sudah dimatikan. Minta link baru dari pengelola.";
            exit;
        }
        $VIEW_ONLY = true;
    }
    if ($VIEW_ONLY) {
        $USER = ['id' => 0, 'name' => 'Tampilan Publik', 'username' => 'tamu'];
        $CSRF = '';
        $IS_SU = false;
        $MY_ROLE = 'Tamu';
    } else {
    $USER = require_login();
    $CSRF = csrf_token();
    $roleStmt = auth_db()->prepare('SELECT role, jabatan FROM users WHERE id = ?');
    $roleStmt->execute([$USER['id']]);
    $roleRow = $roleStmt->fetch() ?: ['role' => ROLE_PENGGUNA, 'jabatan' => 'Anggota'];
    $IS_SU = ($roleRow['role'] === ROLE_ADMIN_UTAMA);
    $MY_ROLE = $IS_SU ? 'Admin Utama' : (in_array($roleRow['jabatan'], JABATAN_LIST, true) ? $roleRow['jabatan'] : 'Anggota');
    }
    // Lonceng notifikasi perubahan data: hanya Admin & Admin Utama (bukan mode lihat saja)
    $BELL = !$VIEW_ONLY && ($IS_SU || $MY_ROLE === 'Admin');
    $NOTIF_AWAL = $BELL ? notif_belum_dibaca(pdo(), (int)$USER['id']) : 0;
    $INITIAL_REV = get_rev(pdo()); // dibaca sebelum data agar perubahan di sela-sela tetap terdeteksi polling
    $INITIAL_DB = load_db();
    if ($VIEW_ONLY) { // data publik: buang kontak pribadi & log WhatsApp
        foreach ($INITIAL_DB['anggota'] ?? [] as $i => $a) { unset($INITIAL_DB['anggota'][$i]['whatsapp'], $INITIAL_DB['anggota'][$i]['email']); }
        unset($INITIAL_DB['wa_samapta'], $INITIAL_DB['wa_config']);
        foreach (['kegiatan', 'surat_masuk', 'pengajuan'] as $k) {
            foreach ($INITIAL_DB[$k] ?? [] as $i => $r) { unset($INITIAL_DB[$k][$i]['wa_log'], $INITIAL_DB[$k][$i]['no_hp']); }
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dashboard tidak dapat memuat database.\n\n" . $e->getMessage() . "\n\nPastikan MySQL berjalan, pengaturan di config_db.php benar, ekstensi PHP pdo_mysql aktif, dan folder 'data' dapat ditulis.";
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($VIEW_ONLY) header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Dashboard Program BASARNAS</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-950:#081321; --navy-900:#0B2545; --navy-800:#13315C; --navy-700:#1C4980; --navy-600:#2C5F9E;
    --orange-600:#D9480F; --orange-500:#F2542D; --orange-400:#FF7A45; --orange-100:#FFE7DB;
    --bg:#F3F5F9; --panel:#FFFFFF; --line:#E3E7EF; --text:#111827; --muted:#5B667A;
    --ok:#0F9D58; --ok-bg:#E5F6EC; --warn:#B7791F; --warn-bg:#FDF3DA; --bad:#C0392B; --bad-bg:#FBE5E1; --info:#2C5F9E; --info-bg:#E7EEF9;
    box-sizing:border-box;
    padding-top:env(safe-area-inset-top,0px);
    padding-bottom:env(safe-area-inset-bottom,0px);
  }
  *{box-sizing:border-box}
  html{scroll-padding-top:env(safe-area-inset-top,0px); scroll-behavior:smooth;}
  body{background:var(--bg); color:var(--text); font-family:'Inter',ui-sans-serif,system-ui,sans-serif; margin:0;}
  .font-display{font-family:'Space Grotesk',ui-sans-serif,system-ui,sans-serif;}
  ::-webkit-scrollbar{height:8px;width:8px}
  ::-webkit-scrollbar-thumb{background:var(--line); border-radius:8px}
  .panel{background:var(--panel); border:1px solid var(--line);}
  .badge{display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .6rem; border-radius:999px; font-size:.72rem; font-weight:600; white-space:nowrap;}
  .b-ok{background:var(--ok-bg); color:var(--ok);}
  .b-warn{background:var(--warn-bg); color:var(--warn);}
  .b-bad{background:var(--bad-bg); color:var(--bad);}
  .b-info{background:var(--info-bg); color:var(--info);}
  .navlink{display:flex; align-items:center; gap:.65rem; padding:.55rem .7rem; border-radius:.6rem; font-size:.855rem; color:#C9D6EE; cursor:pointer; transition:background .12s;}
  .navlink:hover{background:rgba(255,255,255,.06);}
  .navlink.active{background:var(--orange-500); color:#fff;}
  .navlink .ico{width:1.55rem; height:1.55rem; border-radius:.4rem; display:flex; align-items:center; justify-content:center; font-size:.68rem; font-weight:700; background:rgba(255,255,255,.1); color:#fff; flex-shrink:0;}
  .navlink.active .ico{background:rgba(255,255,255,.25);}
  input[type=text],input[type=date],input[type=time],input[type=tel],input[type=email],input[type=url],input[type=number],select,textarea{
    width:100%; padding:.5rem .65rem; border-radius:.5rem; border:1px solid var(--line); background:var(--bg); color:var(--text); font-size:.85rem; outline:none;
  }
  input:focus,select:focus,textarea:focus{border-color:var(--orange-500); box-shadow:0 0 0 3px var(--orange-100);}
  .btn{display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .9rem; border-radius:.55rem; font-size:.82rem; font-weight:600; cursor:pointer; border:1px solid transparent; transition:opacity .12s;}
  .btn:hover{opacity:.88}
  .btn-primary{background:var(--orange-500); color:#fff;}
  .btn-navy{background:var(--navy-800); color:#fff;}
  .btn-ghost{background:transparent; color:var(--text); border-color:var(--line);}
  /* Lonceng notifikasi (Admin & Admin Utama) */
  .notif-wrap{position:relative;}
  .notif-btn{position:relative;}
  .notif-badge{position:absolute; top:-6px; right:-6px; min-width:18px; height:18px; padding:0 5px; border-radius:999px; background:var(--orange-500); color:#fff; font-size:.65rem; font-weight:700; line-height:18px; text-align:center; border:2px solid var(--panel); box-sizing:content-box;}
  .notif-btn.ada svg{color:var(--orange-500);}
  @keyframes notifGoyang{0%,100%{transform:rotate(0)}15%{transform:rotate(14deg)}30%{transform:rotate(-12deg)}45%{transform:rotate(9deg)}60%{transform:rotate(-6deg)}75%{transform:rotate(3deg)}}
  .notif-btn.goyang svg{animation:notifGoyang .9s ease-in-out; transform-origin:50% 10%;}
  .notif-pop{position:absolute; right:0; top:calc(100% + 10px); width:392px; max-width:calc(100vw - 16px); border-radius:.9rem; box-shadow:0 18px 50px rgba(8,19,33,.22); z-index:60; overflow:hidden;}
  .notif-head{display:flex; align-items:center; justify-content:space-between; gap:.5rem; padding:.7rem .9rem; border-bottom:1px solid var(--line);}
  .notif-tabs{display:flex; gap:.35rem; padding:.5rem .9rem; border-bottom:1px solid var(--line); overflow-x:auto;}
  .notif-tab{border:1px solid var(--line); background:transparent; color:var(--muted); border-radius:999px; padding:.18rem .65rem; font-size:.72rem; font-weight:600; cursor:pointer; white-space:nowrap;}
  .notif-tab.on{background:var(--navy-800); border-color:var(--navy-800); color:#fff;}
  .notif-list{max-height:min(460px, 64vh); overflow-y:auto;}
  .notif-item{display:flex; gap:.65rem; padding:.7rem .9rem; border-bottom:1px solid var(--line); cursor:pointer; position:relative; text-align:left; width:100%; background:transparent; border-left:0; border-right:0; border-top:0; font:inherit; color:inherit;}
  .notif-item:hover{background:var(--bg);}
  .notif-item.baru{background:#FFF6F1;}
  .notif-item.baru:hover{background:#FFEDE3;}
  .notif-item.baru::before{content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--orange-500);}
  .notif-ico{width:2rem; height:2rem; border-radius:999px; flex-shrink:0; display:flex; align-items:center; justify-content:center;}
  .notif-ico.wabot{background:var(--ok-bg); color:var(--ok);}
  .notif-ico.admin{background:var(--info-bg); color:var(--info);}
  .notif-ico.anggota{background:var(--orange-100); color:var(--orange-600);}
  .notif-kosong{padding:2.2rem 1rem; text-align:center; color:var(--muted); font-size:.82rem;}
  @media (max-width:640px){ .notif-pop{position:fixed; left:8px; right:8px; top:62px; width:auto; max-width:none;} }
  .colhead{background:transparent; border:1px dashed transparent; border-radius:.4rem; padding:.15rem .4rem; font:inherit; letter-spacing:inherit; text-transform:inherit; color:inherit; cursor:pointer; white-space:nowrap;}
  .colhead:hover{border-color:var(--line); background:var(--bg); color:var(--text);}
  #colMenu{position:fixed; z-index:9999; width:15rem; background:var(--panel); border:1px solid var(--line); border-radius:.6rem; box-shadow:0 10px 30px rgba(0,0,0,.18); padding:.6rem; display:none; flex-direction:column; gap:.45rem;}
  #colMenu.show{display:flex;}
  .dd{position:relative; display:inline-block;}
  .dd-menu{display:none; position:absolute; right:0; top:calc(100% + .3rem); min-width:9rem; background:var(--panel); border:1px solid var(--line); border-radius:.55rem; box-shadow:0 8px 24px rgba(0,0,0,.14); padding:.25rem; z-index:50;}
  .dd.open .dd-menu{display:block;}
  .dd-item{display:flex; align-items:center; gap:.5rem; width:100%; text-align:left; padding:.45rem .65rem; border-radius:.4rem; font-size:.8rem; font-weight:500; color:var(--text); background:transparent; border:0; cursor:pointer;}
  .dd-item:hover{background:var(--bg);}
  .btn-ok{background:var(--ok); color:#fff;}
  .btn-bad{background:var(--bad); color:#fff;}
  table{border-collapse:collapse; width:100%;}
  th{text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); padding:.55rem .7rem; border-bottom:1px solid var(--line); white-space:nowrap;}
  td{padding:.6rem .7rem; border-bottom:1px solid var(--line); font-size:.82rem; vertical-align:top;}
  tr:last-child td{border-bottom:none;}
  .tablewrap{overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; scrollbar-width:thin; scrollbar-color:var(--line) transparent;}
  .scrolltop{overflow-x:auto; overflow-y:hidden; height:14px; margin-bottom:.35rem; position:sticky; bottom:0; background:var(--panel); border:0; box-shadow:none; z-index:20; padding-bottom:env(safe-area-inset-bottom,0px); -webkit-overflow-scrolling:touch; overscroll-behavior-x:contain; scrollbar-width:thin; scrollbar-color:var(--line) transparent;}
  .scrolltop > div{height:1px;}
  .scrolltop.hide{display:none;}
  /* Nama kegiatan: maksimal 3 baris, sisanya dipotong dengan (...) */
  /* Tabel Data Peserta Uji Periodik: kolom rapi, nilai tidak terpotong, nama & jabatan tampil utuh */
  .rekap-uji{min-width:920px;}
  .rekap-uji th, .rekap-uji td{vertical-align:middle;}
  .rekap-uji th.c-num{text-align:center; white-space:normal; line-height:1.2; min-width:4.6rem;}
  .rekap-uji td.c-no{width:2.5rem; text-align:center; color:var(--muted);}
  .rekap-uji td.c-nama{min-width:13rem; font-weight:600; line-height:1.35;}
  .rekap-uji td.c-jab{min-width:17rem; line-height:1.35;}
  .rekap-uji td.c-num{text-align:center; white-space:nowrap; font-variant-numeric:tabular-nums;}
  .rekap-uji td.c-akhir{font-weight:700;}
  .rekap-uji tbody tr:hover td{background:var(--bg);}
  .clamp3{display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:3; line-clamp:3; overflow:hidden; text-overflow:ellipsis; word-break:break-word;}
  /* Daftar "Menunggu Persetujuan": bila lebih dari 6 item, tinggi dibatasi (±6 item) dan bisa di-scroll vertikal */
  .menunggu-scroll{max-height:324px; overflow-y:auto; overflow-x:hidden; padding-right:.35rem; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; scrollbar-width:thin; scrollbar-color:var(--line) transparent;}
  .modal-backdrop{overscroll-behavior:contain; -webkit-overflow-scrolling:touch; position:fixed; inset:0; background:rgba(8,19,33,.55); display:flex; align-items:flex-start; justify-content:center; padding:3vh 1rem; overflow-y:auto; z-index:50;}
  .toast{position:fixed; bottom:calc(1.2rem + env(safe-area-inset-bottom,0px)); left:50%; transform:translateX(-50%); background:var(--navy-900); color:#fff; padding:.65rem 1.1rem; border-radius:.6rem; font-size:.83rem; z-index:100; box-shadow:0 8px 24px rgba(0,0,0,.25);}
  .barrow{display:grid; grid-template-columns:150px 1fr 40px; align-items:center; gap:.6rem; font-size:.78rem; padding:.28rem 0;}
  .bartrack{height:9px; border-radius:999px; background:var(--line); overflow:hidden;}
  .barfill{height:100%; border-radius:999px; background:linear-gradient(90deg,var(--navy-700),var(--orange-500));}
  #sidebar{min-height:0; height:100vh; position:sticky; top:0; overflow:hidden; display:flex; flex-direction:column;}
  #navlist{min-height:0; flex:1 1 auto; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch;}
  @media(min-width:901px){
    body.sb-collapsed #sidebar{display:none;}
  }
  @media(max-width:900px){
    #sidebar{position:fixed; left:0; top:0; bottom:0; z-index:40; transform:translateX(-100%); transition:transform .18s;}
    #sidebar.open{transform:translateX(0);}
    #scrim{display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:35;}
    #scrim.open{display:block;}
  }
  /* ===== Responsif semua perangkat (ponsel, tablet, desktop) ===== */
  html{-webkit-text-size-adjust:100%; text-size-adjust:100%;}
  html,body{max-width:100%; overflow-x:clip;}  /* clip (bukan hidden) agar position:sticky sidebar/header tetap berfungsi */
  img,svg,video,canvas{max-width:100%;}
  svg.chart, .chart svg{height:auto;}
  #app{min-width:0;}
  #pageSub{overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .panel, .modal-backdrop > div{min-width:0;}
  td,th{overflow-wrap:break-word;}
  td.font-medium:first-child{white-space:nowrap;}  /* ID (mis. KGT-013) tidak dipenggal */
  .tablewrap > table{min-width:640px;}  /* kolom tetap terbaca; sisanya scroll ke samping */
  @media(max-width:900px){
    #sidebar{width:min(280px,85vw);}
    .modal-backdrop{padding:2vh .5rem;}
  }
  @media(max-width:640px){
    #app{padding:.75rem;}
    header.panel{padding-left:.65rem; padding-right:.65rem; gap:.5rem;}
    html{font-size:13.5px;}  /* perkecil seluruh teks (rem) di HP agar proporsi mendekati desktop */
    input,select,textarea{font-size:13px !important; max-width:100%;}
    @supports (-webkit-touch-callout:none){ input,select,textarea{font-size:16px !important;} }  /* iOS tetap 16px agar tidak zoom otomatis */
    .btn{font-size:.78rem; padding:.4rem .7rem;}
    .badge{font-size:.68rem;}
    .navlink{font-size:.8rem;}
    h1,h2,h3{letter-spacing:-.005em;}
    .btn{min-height:34px;}
    .barrow{grid-template-columns:100px 1fr 34px; gap:.4rem; font-size:.72rem;}
    th{padding:.45rem .5rem;} td{padding:.5rem .5rem; font-size:.78rem;}
    .flex.items-center.justify-between.flex-wrap > *{max-width:100%;}
    div[style*="min-width:150px"],div[style*="min-width:160px"],div[style*="min-width:170px"],div[style*="min-width:180px"],
    div[style*="min-width:200px"],div[style*="min-width:210px"],div[style*="min-width:220px"],div[style*="min-width:250px"],div[style*="min-width:260px"]{min-width:0 !important; flex:1 1 100%;}
    .toast{max-width:92vw; text-align:center;}
  }
  @media(max-width:380px){
    .barrow{grid-template-columns:84px 1fr 30px;}
  }
  /* ===== Perbaikan tampilan mobile Dashboard ===== */
  .periode-bar{display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:.5rem; margin-bottom:.75rem;}
  .periode-sel{display:flex; align-items:center; gap:.5rem; min-width:0;}
  .periode-sel select{width:auto;}
  .stat-card{min-width:0; overflow:hidden;}
  .stat-card .stat-label{overflow-wrap:anywhere; line-height:1.25;}
  .stat-card .stat-num{line-height:1.15;}
  .stat-foot{display:flex; flex-wrap:wrap; align-items:center; column-gap:.5rem; row-gap:.15rem; margin-top:.5rem; padding-top:.5rem; border-top:1px solid var(--line); font-size:11px;}
  .stat-foot .sf-item{display:inline-flex; align-items:center; gap:.3rem; white-space:nowrap;}
  .stat-foot .sf-sep{color:var(--line);}
  .chart-scroll{overflow:hidden; max-width:100%;}
  .dash-toolbar{display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; min-width:0;}
  @media(max-width:640px){
    .dash-tables{display:none !important;}  /* tabel data per modul disembunyikan di HP/Android */
    .periode-bar{justify-content:space-between;}
    .periode-sel{flex:1 1 100%; width:100%;}
    .periode-sel select{flex:1 1 0; min-width:0; width:100% !important; padding:.4rem .5rem;}
    .stat-card{padding:.75rem !important;}
    .stat-card .stat-label{font-size:.7rem;}
    .stat-card .stat-num{font-size:1.35rem;}
    .stat-foot{flex-direction:column; align-items:flex-start;}
    .stat-foot .sf-sep{display:none;}
    .panel.rounded-xl{padding:.85rem;}
    .dash-toolbar{width:100%; margin-left:0 !important;}
    .dash-toolbar input[type=date]{flex:1 1 130px; min-width:0; width:100% !important;}
    .dash-toolbar select{flex:1 1 140px; min-width:0; width:100% !important;}
    .tablewrap > table{min-width:560px;}
    header.panel h1{font-size:1rem;}
  }
  @media(max-width:360px){
    #app{padding:.5rem;}
    .stat-card .stat-num{font-size:1.25rem;}
  }

  /* ===== Modul Kegiatan/Tugas: tampilan HP/Android (kartu, bukan tabel lebar) ===== */
  .keg-steps{display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; margin-bottom:.75rem;}
  @media(max-width:640px){
    .keg-steps{display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.4rem;}
    .keg-steps .keg-arrow{display:none;}
    .keg-steps .keg-acc{order:5; grid-column:1 / -1; text-align:center; line-height:1.3;}
    .keg-steps .btn{justify-content:center; text-align:center; flex-wrap:wrap; white-space:normal; line-height:1.2; padding:.45rem .3rem; font-size:.74rem; min-height:44px; min-width:0;}
    .keg-steps .btn .badge{margin-left:.2rem !important;}

    .keg-toolbar{gap:.5rem !important;}
    .keg-toolbar input.keg-search{width:100% !important; flex:1 1 100%; min-width:0;}
    .keg-toolbar > .text-xs{flex:1 1 100%; margin-left:0 !important;}
    .keg-toolbar .periode-sel{flex:1 1 100%;}
    .keg-toolbar .btn{flex:1 1 calc(50% - .25rem); justify-content:center; margin-left:0 !important; min-width:0; text-align:center;}
    .keg-toolbar .btn.btn-primary{flex:1 1 100%;}

  }

  /* ===== Kegiatan/Tugas di HP: teks diperkecil, kolom ringkas, tanpa scroll horizontal ===== */
  @media(max-width:640px){
    .tablewrap:has(> table.tbl-fit){overflow-x:visible;}
    .scrolltop:has(+ .tablewrap > table.tbl-fit){display:none;}
    .tablewrap > table.tbl-fit{min-width:0; width:100%; table-layout:fixed;}
    table.tbl-fit-s th:nth-child(1){width:13%;} table.tbl-fit-s th:nth-child(2){width:23%;} table.tbl-fit-s th:nth-child(3){width:16%;}
    table.tbl-fit-s th:nth-child(4){width:18%;} table.tbl-fit-s th:nth-child(7){width:17%;} table.tbl-fit-s th:nth-child(9){width:13%;}
    table.tbl-fit-k th:nth-child(1){width:13%;} table.tbl-fit-k th:nth-child(2){width:26%;} table.tbl-fit-k th:nth-child(4){width:16%;}
    table.tbl-fit-k th:nth-child(7){width:16%;} table.tbl-fit-k th:nth-child(8){width:15%;} table.tbl-fit-k th:nth-child(10){width:14%;}
    table.tbl-fit-t th:nth-child(1){width:16%;} table.tbl-fit-t th:nth-child(2){width:34%;} table.tbl-fit-t th:nth-child(4){width:20%;}
    table.tbl-fit-t th:nth-child(8){width:16%;} table.tbl-fit-t th:nth-child(9){width:14%;}
    table.tbl-fit .xs-hide{display:none;}
    table.tbl-fit th{font-size:.56rem; letter-spacing:0; padding:.35rem .2rem; white-space:normal; line-height:1.15; overflow-wrap:break-word; word-break:normal; font-size:.52rem;}
    table.tbl-fit td{font-size:.62rem; line-height:1.3; padding:.45rem .2rem; overflow-wrap:break-word; word-break:break-word; min-width:0; vertical-align:top;}
    table.tbl-fit td.font-medium:first-child{white-space:nowrap; word-break:normal; font-size:.62rem;}
    table.tbl-fit .clamp3{-webkit-line-clamp:4; line-clamp:4;}
    table.tbl-fit .badge{font-size:.52rem; padding:.1rem .25rem; white-space:normal; word-break:normal; overflow-wrap:normal; line-height:1.2; display:inline-block; max-width:100%;}
    table.tbl-fit .text-\[11px\]{font-size:.55rem !important;}
    table.tbl-fit .btn{font-size:.56rem; padding:.2rem .1rem !important; min-height:0; width:100%; justify-content:center; white-space:nowrap; word-break:normal;}
    table.tbl-fit td.c-aksi .flex{flex-direction:column; gap:.2rem;}
  }
  /* ===== Mobile: tabel list modul lain memakai gaya ringkas seperti Kegiatan/Tugas (tbl-fit otomatis) ===== */
  @media(max-width:640px){
    table.tbl-fit-auto th:first-child{width:15%;}
    table.tbl-fit-auto th.th-aksi{width:15%;}
    table.tbl-fit-auto th.th-empty{width:8%;}
    table.tbl-fit-auto th:first-child{width:19%;}
    table.tbl-fit-auto th.th-aksi{width:19%;}
    table.tbl-fit-auto td.font-medium:first-child, table.tbl-fit-auto td:first-child{white-space:normal !important; word-break:normal; overflow-wrap:anywhere; hyphens:none;}
    table.tbl-fit-auto td.c-aksi{white-space:normal !important; overflow:hidden;}
    table.tbl-fit-auto td.c-aksi .btn, table.tbl-fit-auto td.c-aksi button{width:100%; display:flex; margin:.15rem 0 0 0; white-space:normal; line-height:1.15; font-size:.56rem;}
    table.tbl-fit-auto td.c-aksi .badge{display:block; text-align:center; margin:0; width:100%;}
    table.tbl-fit-auto td.c-aksi > .flex, table.tbl-fit-auto td.c-aksi > div{flex-direction:column; align-items:stretch;}
    #app .panel > .flex.flex-wrap > input[type=text]{flex:1 1 100%; width:100% !important;}
    .modal-backdrop > div > div.p-5{padding:1rem;}
  }
  /* ===== Tampilan publik (link Bagikan Dashboard) ===== */
  .pub-hero{position:relative; overflow:hidden; border-radius:1.25rem; padding:1.5rem 1.5rem 1.25rem; margin-bottom:1.25rem; color:#fff;
    background:radial-gradient(900px 340px at 92% -20%, rgba(242,84,45,.55), transparent 60%), radial-gradient(700px 300px at -10% 120%, rgba(44,95,158,.65), transparent 60%), linear-gradient(135deg,var(--navy-950),var(--navy-800) 62%,var(--navy-700));
    box-shadow:0 18px 40px rgba(8,19,33,.22);}
  .pub-hero::after{content:''; position:absolute; inset:0; pointer-events:none; opacity:.07; background-image:repeating-linear-gradient(115deg,#fff 0 1px,transparent 1px 22px);}
  .pub-hero > *{position:relative; z-index:1;}
  .pub-top{display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:1rem;}
  .pub-chip{display:inline-flex; align-items:center; gap:.45rem; padding:.22rem .7rem; border-radius:999px; font-size:.7rem; font-weight:700; letter-spacing:.04em; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);}
  .pub-dot{width:8px; height:8px; border-radius:50%; background:#3DDC84; box-shadow:0 0 0 0 rgba(61,220,132,.7); animation:pubPulse 1.8s infinite;}
  @keyframes pubPulse{0%{box-shadow:0 0 0 0 rgba(61,220,132,.65)}70%{box-shadow:0 0 0 9px rgba(61,220,132,0)}100%{box-shadow:0 0 0 0 rgba(61,220,132,0)}}
  .pub-hero h2{font-size:1.65rem; line-height:1.15; font-weight:700; margin:.7rem 0 .3rem;}
  .pub-hero p.sub{font-size:.85rem; color:rgba(255,255,255,.78); max-width:46rem; margin:0;}
  .pub-clock{text-align:right; min-width:0;}
  .pub-clock .jam{font-size:1.9rem; font-weight:700; line-height:1; letter-spacing:.02em;}
  .pub-clock .tgl{font-size:.78rem; color:rgba(255,255,255,.8); margin-top:.25rem;}
  .pub-hero .periode-sel{margin-top:.65rem; justify-content:flex-end; flex-wrap:wrap;}
  .pub-hero .periode-sel select{background:rgba(255,255,255,.95); color:#111827; border-color:transparent;}
  .pub-kpis{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.75rem; margin-top:1.15rem;}
  @media (min-width:768px){ .pub-kpis{grid-template-columns:repeat(5,minmax(0,1fr));} }
  .pub-kpi{border-radius:.95rem; padding:.85rem .95rem; background:rgba(255,255,255,.09); border:1px solid rgba(255,255,255,.16); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); min-width:0;}
  .pub-kpi .l{font-size:.72rem; color:rgba(255,255,255,.78); display:flex; align-items:center; gap:.4rem;}
  .pub-kpi .v{font-size:1.75rem; font-weight:700; line-height:1.1; margin-top:.2rem;}
  .pub-kpi .s{font-size:.68rem; color:rgba(255,255,255,.65); margin-top:.1rem;}
  .pub-sec{display:flex; align-items:flex-end; justify-content:space-between; gap:.75rem; margin:1.6rem 0 .75rem;}
  .pub-sec h3{font-size:1.02rem; font-weight:700; display:flex; align-items:center; gap:.55rem; margin:0;}
  .pub-sec h3::before{content:''; width:5px; height:1.15rem; border-radius:4px; background:linear-gradient(180deg,var(--orange-500),var(--navy-700));}
  .pub-sec small{font-size:.72rem; color:var(--muted);}
  .pub-grid{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.8rem;}
  @media (min-width:640px){ .pub-grid{grid-template-columns:repeat(3,minmax(0,1fr));} }
  @media (min-width:1024px){ .pub-grid{grid-template-columns:repeat(4,minmax(0,1fr));} }
  .pub-card{--c:var(--navy-800); position:relative; overflow:hidden; border-radius:1rem; padding:1rem; background:var(--panel); border:1px solid var(--line); min-width:0; transition:transform .18s ease, box-shadow .18s ease; animation:pubIn .5s ease both;}
  .pub-card::before{content:''; position:absolute; left:0; top:0; right:0; height:4px; background:var(--c);}
  .pub-card:hover{transform:translateY(-3px); box-shadow:0 12px 28px rgba(8,19,33,.12);}
  @keyframes pubIn{from{opacity:0; transform:translateY(10px)}to{opacity:1; transform:none}}
  .pub-ico{width:38px; height:38px; border-radius:.8rem; display:flex; align-items:center; justify-content:center; color:var(--c); background:color-mix(in srgb, var(--c) 13%, white); flex:none;}
  .pub-card .nm{font-size:.78rem; color:var(--muted); line-height:1.25; overflow-wrap:anywhere;}
  .pub-card .num{font-size:1.9rem; font-weight:700; line-height:1.1; color:var(--c); margin-top:.55rem;}
  .pub-meter{height:6px; border-radius:99px; background:var(--line); overflow:hidden; margin-top:.6rem;}
  .pub-meter > i{display:block; height:100%; border-radius:99px; background:var(--c);}
  .pub-sub{margin-top:.6rem; padding-top:.55rem; border-top:1px dashed var(--line); font-size:.72rem; display:flex; flex-direction:column; gap:.18rem;}
  .pub-sub div{display:flex; justify-content:space-between; gap:.5rem; min-width:0;}
  .pub-sub span:first-child{color:var(--muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .pub-sub b{color:var(--navy-800);}
  .pub-two{display:grid; grid-template-columns:minmax(0,1fr); gap:.9rem;}
  @media (min-width:1024px){ .pub-two{grid-template-columns:minmax(0,2fr) minmax(0,3fr);} }
  .pub-stack{display:flex; height:14px; border-radius:99px; overflow:hidden; background:var(--line); margin:.7rem 0 .55rem;}
  .pub-stack i{display:block; height:100%; transition:width .6s ease;}
  .pub-legend{display:flex; flex-wrap:wrap; gap:.4rem .9rem; font-size:.75rem; color:var(--muted);}
  .pub-legend b{color:var(--text);}
  .pub-legend .d{display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:.35rem;}
  .pub-item{display:flex; align-items:flex-start; justify-content:space-between; gap:.6rem; padding:.6rem 0; border-bottom:1px solid var(--line);}
  .pub-item:last-child{border-bottom:0;}
  .pub-item .t{font-size:.85rem; font-weight:600; overflow-wrap:anywhere;}
  .pub-item .m{font-size:.72rem; color:var(--muted); margin-top:.1rem;}
  .pub-foot{margin:1.8rem 0 .5rem; text-align:center; font-size:.72rem; color:var(--muted);}
  @media (max-width:639px){ .pub-hero{padding:1.15rem 1rem 1rem; border-radius:1rem;} .pub-hero h2{font-size:1.3rem;} .pub-clock{text-align:left;} .pub-hero .periode-sel{justify-content:flex-start;} .pub-clock .jam{font-size:1.5rem;} }
  @media (prefers-reduced-motion:reduce){ .pub-card{animation:none;} .pub-dot{animation:none;} .pub-card:hover{transform:none;} }
  @media print{ .pub-hero{box-shadow:none;} }
  /* Tombol kembali ke atas (khusus Dashboard) */
  #btnToTop{position:fixed; right:1.25rem; bottom:1.25rem; width:46px; height:46px; border-radius:999px; border:0; cursor:pointer; background:var(--orange-500); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 8px 24px rgba(8,19,33,.28); z-index:45; opacity:0; visibility:hidden; transform:translateY(10px); transition:opacity .2s, transform .2s, visibility .2s;}
  #btnToTop.show{opacity:1; visibility:visible; transform:none;}
  #btnToTop:hover{filter:brightness(1.08);}
  #btnToTop:focus-visible{outline:3px solid rgba(221,74,36,.35); outline-offset:2px;}
  @media print{ #btnToTop{display:none !important;} }
  @media print{ html,body{overflow:visible;} }
</style>
</head>
<body>

<div id="scrim" onclick="toggleSidebar(false)"></div>

<div class="flex min-h-screen">
  <!-- SIDEBAR -->
  <aside id="sidebar" style="background:var(--navy-950);" class="w-[260px] flex-shrink-0 flex flex-col" >
    <div class="px-4 pt-5 pb-4" style="padding-top:calc(1.25rem + env(safe-area-inset-top,0px));">
      <div class="flex items-center gap-2.5">
        <div style="background:var(--orange-500);" class="w-9 h-9 rounded-lg flex items-center justify-center text-white font-display font-bold text-sm">SAR</div>
        <div class="leading-tight">
          <div class="text-white font-display font-semibold text-sm">BASARNAS</div>
          <div class="text-[11px]" style="color:#93A2BE;">Dashboard Program</div>
        </div>
      </div>
    </div>
    <nav id="navlist" class="flex-1 overflow-y-auto px-3 space-y-0.5 pb-4"></nav>
    <div class="px-4 py-3 text-[11px] border-t" style="border-color:rgba(255,255,255,.08); color:#7284A3;">Data tersimpan di database server</div>
  </aside>

  <!-- MAIN -->
  <div class="flex-1 min-w-0 flex flex-col">
    <header class="panel flex items-center gap-3 px-4 py-3 sticky top-0 z-30" style="padding-top:calc(.75rem + env(safe-area-inset-top,0px));">
      <?php if (!$VIEW_ONLY): ?>
      <button class="btn btn-ghost !px-2.5" onclick="toggleSidebar()" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
      </button>
      <?php endif; ?>
      <div class="min-w-0">
        <h1 id="pageTitle" class="font-display font-semibold text-base sm:text-lg truncate">Dashboard</h1>
        <p id="pageSub" class="text-xs" style="color:var(--muted);">Ringkasan program dan kegiatan SAR</p>
      </div>
      <div class="ml-auto flex items-center gap-2">
        <?php if ($VIEW_ONLY): ?>
        <span class="badge b-info">Mode lihat saja</span>
        <?php else: ?>
        <?php if ($IS_SU): ?>
        <a href="kelola_akun.php" class="btn btn-ghost !px-2.5 text-xs" title="Kelola akun pengguna" style="text-decoration:none;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span class="hidden sm:inline">Kelola Akun</span>
        </a>
        <?php endif; ?>
        <div class="flex items-center gap-2 pl-2 ml-1 border-l" style="border-color:var(--line);">
          <?php if ($BELL): ?>
          <div class="notif-wrap" id="notifWrap">
            <button type="button" id="notifBtn" class="btn btn-ghost !px-2.5 notif-btn" onclick="notifToggle(event)" title="Notifikasi perubahan data" aria-label="Notifikasi" aria-haspopup="true" aria-expanded="false">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
              <span id="notifBadge" class="notif-badge" style="display:none;">0</span>
            </button>
            <div id="notifPop" class="panel notif-pop" style="display:none;" role="dialog" aria-label="Notifikasi"></div>
          </div>
          <?php endif; ?>
          <div class="hidden sm:block text-right leading-tight min-w-0">
            <div class="text-xs font-semibold truncate" style="max-width:140px;"><?= htmlspecialchars($USER['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="text-[11px]" style="color:var(--muted);">@<?= htmlspecialchars($USER['username'], ENT_QUOTES, 'UTF-8') ?> · <b><?= htmlspecialchars($MY_ROLE, ENT_QUOTES, 'UTF-8') ?></b></div>
          </div>
          <form method="post" action="logout.php" onsubmit="return keluarAman(event)" class="m-0">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-ghost !px-2.5" title="Keluar" aria-label="Keluar">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
              <span class="hidden sm:inline">Keluar</span>
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </header>

    <main id="app" class="p-4 sm:p-6 flex-1 min-w-0"></main>
  </div>
</div>

<?php if ($VIEW_ONLY): ?><style>#sidebar{display:none!important;}</style><?php endif; ?>
<div id="modalRoot"></div>
<button type="button" id="btnToTop" aria-label="Kembali ke atas" title="Kembali ke atas">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>
<div id="toastRoot"></div>

<script>window.__INITIAL_REV__ = <?= (int)$INITIAL_REV ?>;</script>
<script>window.__INITIAL_DB__ = <?= json_encode($INITIAL_DB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;</script>
<script>
/* ============================= STATE & DATA ============================= */
const VIEW_ONLY = <?= $VIEW_ONLY ? 'true' : 'false' ?>; // link tampilan tanpa login: hanya lihat Dashboard
const IS_SU = <?= $IS_SU ? 'true' : 'false' ?>; // akun Admin Utama (ditentukan server)
const STORAGE_KEY = 'basarnas_dashboard_v1';
const currentRole = <?= json_encode($MY_ROLE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; // peran terikat ke akun (dipilih saat daftar), tidak bisa diganti dari header
const currentUserName = <?= json_encode((string)$USER['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; // nama akun yang sedang login
/* Jejak pelaku: {nama, peran} -> "Nama sebagai (Peran)" */
function pelaku(){ return {nama: currentUserName, peran: currentRole}; }
function pelakuTeks(p){
  const arr = Array.isArray(p) ? p : (p ? [p] : []);
  const t = arr.filter(x=>x && (x.nama || x.peran)).map(x=>(x.nama || '-') + ' sebagai (' + (x.peran || '-') + ')');
  return t.length ? t.join('; ') : '-';
}
/* Data lama (sebelum ada jejak pelaku): peran diambil dari teks keterangan persetujuan bila ada */
function disetujuOlehKegiatan(k){
  if(k.disetujui_oleh && (!Array.isArray(k.disetujui_oleh) || k.disetujui_oleh.length)) return k.disetujui_oleh;
  const m = /(?:Disetujui|Ditolak) oleh ([^:]+?)(?: pada|:|\.|$)/.exec(k.keterangan_persetujuan || '');
  if(m) return {nama:'-', peran:m[1].trim()};
  const a = /\(ACC ([^)]+)\)/.exec(k.keterangan_persetujuan || '');
  return a ? a[1].split(',').map(r=>({nama:'-', peran:r.trim()})) : null;
}
let currentView = 'dashboard';
let search = {};

function uid(prefix, arr){
  const n = (arr.length ? Math.max(...arr.map(x=>parseInt((x.id||'0').split('-').pop())||0)) : 0) + 1;
  return prefix + '-' + String(n).padStart(3,'0');
}
function todayISO(){ return new Date().toISOString().slice(0,10); }
/* Tampilan tanggal di Dashboard: tanggal-bulan-tahun (data tetap tersimpan yyyy-mm-dd) */
function tglDMY(v){
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v==null?'':v).trim());
  return m ? m[3]+'-'+m[2]+'-'+m[1] : v;
}
/* Rentang tanggal kegiatan: "dd-mm-yyyy" atau "dd-mm-yyyy s.d. dd-mm-yyyy" bila berlangsung lebih dari sehari */
function tglRentang(k){
  const a = String(k.tanggal||'').slice(0,10), b = String(k.tanggal_selesai||'').slice(0,10);
  return (b && b > a) ? tglDMY(a)+' s.d. '+tglDMY(b) : tglDMY(a);
}
function dashVal(f, row){ const v = fieldValue(f,row); return f.type==='date' ? tglDMY(v) : v; }
function dashCell(key, f, row){ return f.type==='date' ? esc(tglDMY(fieldValue(f,row))) : cellHtml(key,f,row); }
function nowStr(){ return new Date().toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}); }
function esc(s){ return (s===undefined||s===null) ? '' : String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* ---- Module field definitions (10 kelas program generik) ---- */
const GENERIC_MODULES = [
{key:'sgts', label:'Sar Go To School', prefix:'SGS', fields:[
  {key:'nama_sekolah', label:'Nama Sekolah', type:'text', required:true, col:1},
  {key:'alamat_sekolah', label:'Alamat Sekolah', type:'text'},
  {key:'tanggal', label:'Tanggal Kegiatan', type:'date', col:1},
  {key:'waktu', label:'Waktu', type:'time'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'penanggung_jawab', label:'Penanggung Jawab', type:'text', col:1},
  {key:'narasumber', label:'Narasumber', type:'text'},
  {key:'anggota_bertugas', label:'Anggota Bertugas', type:'text'},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status Kegiatan', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
]},
{key:'bimtek', label:'Bimtek', prefix:'BMT', fields:[
  {key:'nama_bimtek', label:'Nama Bimtek', type:'text', required:true, col:1},
  {key:'jenis_bimtek', label:'Jenis Bimtek', type:'text'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'tanggal', label:'Tanggal', type:'date', col:1},
  {key:'waktu', label:'Waktu', type:'time'},
  {key:'lokasi', label:'Lokasi', type:'text', col:1},
  {key:'penyelenggara', label:'Penyelenggara', type:'text'},
  {key:'narasumber', label:'Narasumber', type:'text'},
  {key:'peserta', label:'Peserta', type:'text'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'petugas', label:'Anggota/Petugas', type:'text'},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
]},
{key:'potensi', label:'Potensi', prefix:'POT', fields:[
  {key:'nama_potensi', label:'Nama Potensi', type:'text', required:true, col:1},
  {key:'kategori', label:'Kategori', type:'select', options:['TNI/Polri','Pemerintah','Perusahaan','Organisasi'], col:1},
  {key:'nama_kontak', label:'Nama Kontak', type:'text'},
  {key:'nomor_kontak', label:'Nomor Kontak', type:'tel'},
  {key:'alamat', label:'Alamat', type:'text'},
  {key:'wilayah', label:'Wilayah', type:'text', col:1},
  {key:'kompetensi', label:'Kompetensi', type:'text'},
  {key:'jumlah_tersertifikasi', label:'Tersertifikasi', type:'number', col:1},
  {key:'jumlah_belum_sertifikasi', label:'Belum Tersertifikasi', type:'number', col:1},
  {key:'jumlah_total', label:'Seluruh Anggota', type:'computed', computeFrom:['jumlah_tersertifikasi','jumlah_belum_sertifikasi'], col:1},
  {key:'status', label:'Status Potensi', type:'select', options:['Aktif','Nonaktif'], col:1, isStatus:1},
  {key:'alut_sapras', label:'Alut & Sapras', type:'text'},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
]},
{key:'narasumber', label:'Narasumber', prefix:'NRS', fields:[
  {key:'nama', label:'Nama Narasumber', type:'text', required:true, col:1},
  {key:'instansi', label:'Instansi', type:'text', col:1},
  {key:'jabatan', label:'Jabatan', type:'text'},
  {key:'keahlian', label:'Keahlian', type:'text', col:1},
  {key:'nomor_kontak', label:'Nomor Kontak', type:'tel'},
  {key:'email', label:'Email', type:'email'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'kegiatan_terkait', label:'Kegiatan Terkait', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
]},
{key:'diklat', label:'Diklat', prefix:'DKL', fields:[
  {key:'nama_diklat', label:'Nama Diklat', type:'text', required:true, col:1},
  {key:'jenis_diklat', label:'Jenis Diklat', type:'text'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'tanggal_mulai', label:'Tanggal Mulai', type:'date', col:1},
  {key:'tanggal_selesai', label:'Tanggal Selesai', type:'date'},
  {key:'lokasi', label:'Lokasi', type:'text', col:1},
  {key:'penyelenggara', label:'Penyelenggara', type:'text'},
  {key:'instruktur', label:'Instruktur', type:'text'},
  {key:'peserta', label:'Peserta', type:'text'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'anggota_bertugas', label:'Anggota Bertugas', type:'text'},
  {key:'sertifikat', label:'Sertifikat', type:'text'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
]},
{key:'rescuer', label:'Rescuer', prefix:'RSC', fields:[
  {key:'nama_anggota', label:'Nama Anggota', type:'text', required:true, col:1, derivedFrom:{ref:'anggota', idKey:'anggota_id', field:'nama'}},
  {key:'nip', label:'NIP/ID Anggota', type:'text', col:1, derivedFrom:{ref:'anggota', idKey:'anggota_id', field:'nip'}},
  {key:'jabatan', label:'Jabatan', type:'text'},
  {key:'kompetensi', label:'Kompetensi', type:'text'},
  {key:'spesialisasi', label:'Spesialisasi', type:'text'},
  {key:'sertifikasi', label:'Sertifikasi', type:'text'},
  {key:'masa_berlaku', label:'Masa Berlaku Sertifikasi', type:'date'},
  {key:'status', label:'Status Aktif', type:'select', options:['Aktif','Nonaktif'], col:1, isStatus:1},
  {key:'riwayat', label:'Riwayat Penugasan', type:'textarea'},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
]},
/* Pembinaan Tenaga SAR: satu tabel data dengan tiga tab/kategori (Pembinaan, Pembelajaran, Samapta).
   Kategori disimpan di kolom 'kategori_pembinaan' (data lama tanpa kategori = Pembinaan).
   Field ber-'kat' hanya tampil di kategorinya; 'labels' mengganti label per kategori;
   'col' = kolom di tabel Dashboard; 'tabCols' = kolom tabel di halaman modul per tab. */
{key:'pembinaan', label:'Pembinaan Tenaga SAR', prefix:'PBN',
  tabs:['Pembelajaran','Samapta'],
  tabCols:{
    'Pembelajaran':['nama_kegiatan','materi','tanggal','lokasi','jumlah_peserta','dokumentasi'],
    'Samapta':['nama_kegiatan','materi','tanggal','lokasi','jumlah_peserta','dokumentasi'],
  },
  fields:[
  {key:'nama_kegiatan', label:'Nama Kegiatan', type:'text', required:true, col:1},
  {key:'kategori_pembinaan', label:'Kategori', type:'text', col:1, noForm:1},
  {key:'jenis_pembelajaran', label:'Jenis', type:'select', options:['Teori','Praktek','Latihan'], kat:'Pembelajaran'},
  {key:'tanggal', label:'Tanggal', type:'date', col:1},
  {key:'lokasi', label:'Lokasi', type:'select', options:['KPP Jakarta','USS Bekasi','USS Bogor','Pos Sar Sukabumi','Kep Seribu','Dermaga Pantai Mutiara','KN SAR Ganesha','KN SAR Antasena']},
  {key:'materi', label:'Materi', labels:{Samapta:'Materi / Program Latihan'}, type:'text'},
  {key:'peserta', label:'Peserta', labels:{Samapta:'Nama Anggota'}, type:'text'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', labels:{Pembelajaran:'Jumlah Anggota'}, type:'number'},
  {key:'jumlah_jam', label:'Jumlah Jam Pembelajaran', type:'number', kat:'Pembelajaran', placeholder:'Contoh: 8'},
  {key:'hasil', label:'Hasil', labels:{Pembelajaran:'Hasil Pembelajaran'}, type:'textarea', kat:'Pembelajaran'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', labels:{Pembelajaran:'Dokumentasi (file apa saja)', Samapta:'Dokumentasi (file apa saja)'}, type:'text'},
]},
{key:'pemberdayaan', label:'Pemberdayaan Masyarakat', prefix:'PBM', fields:[
  {key:'nama_kegiatan', label:'Nama Kegiatan', type:'text', required:true, col:1},
  {key:'sasaran', label:'Kelompok/Masyarakat Sasaran', type:'text', col:1},
  {key:'lokasi', label:'Lokasi', type:'text', col:1},
  {key:'tanggal', label:'Tanggal', type:'date', col:1},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'narasumber', label:'Narasumber', type:'text'},
  {key:'petugas', label:'Petugas', type:'text'},
  {key:'hasil', label:'Hasil Kegiatan', type:'textarea'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
]},
{key:'uji_periodik', label:'Uji Periodik', prefix:'UPR', fields:[
  {key:'nama_uji', label:'Nama Uji Periodik', type:'text', required:true, col:1},
  {key:'jenis_uji', label:'Jenis Uji', type:'text'},
  {key:'materi', label:'Materi', type:'text'},
  {key:'tanggal_mulai', label:'Tanggal Mulai', type:'date', col:1},
  {key:'tanggal_selesai', label:'Tanggal Selesai', type:'date'},
  {key:'lokasi', label:'Lokasi', type:'text', col:1},
  {key:'penyelenggara', label:'Penyelenggara', type:'text'},
  {key:'penguji', label:'Penguji', type:'text'},
  {key:'peserta', label:'Peserta', type:'text'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'anggota_bertugas', label:'Anggota Bertugas', type:'text'},
  {key:'sertifikat', label:'Sertifikat', type:'text'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1, autoFromKegiatan:1},
]},
{key:'uji_pemeliharaan', label:'Pemeliharaan Sumda', prefix:'UPM', fields:[
  {key:'barang_id', label:'ID', type:'text', col:1, noForm:1},
  {key:'nama_peralatan', label:'Peralatan', type:'text', required:true, col:1},
  {key:'barang_jenis', label:'Jenis', type:'text', noForm:1},
  {key:'lokasi', label:'Lokasi', type:'text', col:1, noForm:1},
  {key:'tanggal_pemeriksaan', label:'Tanggal', type:'date', col:1},
  {key:'kondisi_baik', label:'Kondisi Baik', type:'number', col:1},
  {key:'kondisi_rusak_ringan', label:'Kondisi Rusak Ringan', type:'number', col:1},
  {key:'kondisi_rusak_berat', label:'Kondisi Rusak Berat', type:'number', col:1},
  {key:'hilang', label:'Hilang', type:'number', col:1},
  {key:'petugas', label:'Petugas / Pemeriksa', type:'text'},
  {key:'keterangan', label:'Keterangan', type:'textarea', col:1},
]},
{key:'lainnya', label:'Lainnya', prefix:'LNY', fields:[
  {key:'nama_kegiatan', label:'Nama Kegiatan', type:'text', required:true, col:1},
  {key:'jenis_kegiatan', label:'Jenis Kegiatan', type:'text'},
  {key:'tanggal', label:'Tanggal', type:'date', col:1},
  {key:'waktu', label:'Waktu', type:'time'},
  {key:'lokasi', label:'Lokasi', type:'text', col:1},
  {key:'penyelenggara', label:'Penyelenggara', type:'text'},
  {key:'penanggung_jawab', label:'Penanggung Jawab', type:'text'},
  {key:'peserta', label:'Peserta', type:'text'},
  {key:'jumlah_peserta', label:'Jumlah Peserta', type:'number'},
  {key:'anggota_bertugas', label:'Anggota Bertugas', type:'text'},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
  {key:'dokumentasi', label:'Dokumentasi (tautan)', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Direncanakan','Berlangsung','Selesai'], col:1, isStatus:1},
]},
];

const ANGGOTA_FIELDS = [
  {key:'nama', label:'Nama Lengkap', type:'text', required:true, col:1},
  {key:'nip', label:'NIP/ID', type:'text', col:1},
  {key:'jabatan', label:'Jabatan', type:'text', col:1},
  {key:'unit', label:'Unit', type:'text', col:1},
  {key:'whatsapp', label:'Nomor WhatsApp', type:'tel', required:true, col:1},
  {key:'email', label:'Email', type:'email'},
  {key:'kompetensi', label:'Kompetensi', type:'text'},
  {key:'spesialisasi', label:'Spesialisasi', type:'text'},
  {key:'sertifikasi', label:'Sertifikasi', type:'text'},
  {key:'status', label:'Status Anggota', type:'select', options:['Aktif','Nonaktif'], col:1, isStatus:1},
  {key:'keterangan', label:'Keterangan', type:'textarea'},
];

/* Modul inventaris: berdiri sendiri (bukan jenis Kegiatan/Tugas), dikelola langsung lewat tombol Tambah/Ubah/Hapus. */
const EXTRA_MODULES = [
{key:'peralatan', label:'Peralatan', prefix:'PRL', fields:[
  {key:'nama_barang', label:'Nama Barang', type:'text', required:true, col:1},
  {key:'kode_rak', label:'Kode Rak', type:'text', col:1},
  {key:'lokasi', label:'Lokasi', type:'select', options:['KPP Jakarta','USS Bekasi','USS Bogor','Pos Sukabumi'], col:1},
  {key:'kategori', label:'Kategori', type:'select', options:['Palsar Darat','Palsar Air','Medis','Komunikasi','Palsar Udara'], col:1},
  {key:'jumlah_baik', label:'Jumlah Baik', type:'number', col:1},
  {key:'jumlah_rusak', label:'Jumlah Rusak', type:'number', col:1},
  {key:'jumlah_hilang', label:'Jumlah Hilang', type:'number'},
  {key:'jumlah', label:'Jumlah', type:'computed', computeFrom:['jumlah_baik','jumlah_rusak'], col:1},
  {key:'tersedia', label:'Tersedia', type:'computed', computeFrom:['jumlah_baik'], minusBorrowed:1, col:1},
  {key:'ket_pinjam', label:'Keterangan Pinjam', type:'text', col:1, noForm:1, pinjamInfo:1},
  {key:'keterangan', label:'Ket', type:'textarea', col:1},
]},
{key:'kendaraan', label:'Kendaraan', prefix:'KDR', fields:[
  {key:'nama_kendaraan', label:'Nama Kendaraan', type:'text', required:true, col:1},
  {key:'sapras', label:'Sapras', type:'hidden'},
  {key:'jenis_kendaraan', label:'Jenis Kendaraan', type:'select', options:['Mobil','Motor','Truk','Ambulans','Kapal','RIB / Perahu Karet'], col:1},
  {key:'nomor_polisi', label:'Nomor Polisi / Registrasi', type:'text', col:1},
  {key:'merk_tipe', label:'Merk / Tipe', type:'text'},
  {key:'kondisi', label:'Kondisi', type:'select', options:['Serviceable','Unserviceable','Scrap'], col:1},
  {key:'lokasi', label:'Lokasi / Pangkalan', type:'select', options:['KPP Jakarta','USS Bekasi','USS Bogor','Pos SAR Sukabumi']},
  {key:'tanggal', label:'Tanggal', type:'date', col:1},
  {key:'km_saat_ini', label:'KM Saat Ini', type:'number', decimal:1, darat:1, placeholder:'mis. 45000'},
  {key:'tgl_ganti_oli', label:'Tanggal Ganti Oli Terakhir', type:'date', darat:1},
  {key:'bbm', label:'BBM (%)', type:'number', decimal:1, darat:1, unit:'%', placeholder:'mis. 40%'},
  {key:'endurance', label:'Endurance', type:'number', laut:1, unit:'Jam', placeholder:'mis. 12 (jam)'},
  {key:'speed', label:'Speed (knot/km per jam)', type:'number', laut:1, unit:'knot', placeholder:'mis. 25 (knot)'},
  {key:'range', label:'Range (nm/km)', type:'number', laut:1, unit:'nm', placeholder:'mis. 300 (nm)'},
  {key:'konsumsi_bbm', label:'Konsumsi Bahan Bakar (liter/jam)', type:'number', laut:1, unit:'liter/jam', placeholder:'mis. 80 (liter/jam)'},
  {key:'cadangan_bbm', label:'Cadangan Bahan Bakar (liter)', type:'number', laut:1, unit:'liter', placeholder:'mis. 500 (liter)'},
  {key:'persen_bbm', label:'Persentase Bahan Bakar (%)', type:'number', laut:1, unit:'%', placeholder:'mis. 75%'},
  {key:'kecepatan_angin', label:'Kecepatan Angin', type:'number', laut:1, unit:'knot', placeholder:'mis. 15 (knot)'},
  {key:'arah_angin', label:'Arah Angin', type:'text', laut:1, placeholder:'mis. Barat Laut'},
  {key:'keterangan', label:'Ket', type:'textarea', col:1},
]},
{key:'peminjaman', label:'Peminjaman', prefix:'PMJ', fields:[
  {key:'nama_peminjam', label:'Nama Peminjam', type:'text', required:true, col:1},
  {key:'jenis_barang', label:'Jenis', type:'select', options:['Peralatan','Kendaraan'], col:1},
  {key:'nama_barang', label:'Nama Peralatan / Kendaraan', type:'text', required:true, col:1},
  {key:'jumlah', label:'Jumlah', type:'number'},
  {key:'tanggal', label:'Tanggal Pinjam', type:'date', col:1},
  {key:'tanggal_kembali_rencana', label:'Rencana Kembali', type:'date'},
  {key:'tanggal_kembali', label:'Tanggal Dikembalikan', type:'date'},
  {key:'keperluan', label:'Keperluan / Kegiatan', type:'text'},
  {key:'penanggung_jawab', label:'Penanggung Jawab', type:'text'},
  {key:'status', label:'Status', type:'select', options:['Dipinjam','Dikembalikan','Terlambat'], col:1, isStatus:1},
  {key:'keterangan', label:'Ket', type:'textarea', col:1},
]},
/* Pengajuan: dua kategori (Kebutuhan & Pemeliharaan) untuk Peralatan / Kendaraan, dengan ACC/Tolak pimpinan.
   Kunci data internal tetap 'pemeliharaan' agar data lama tidak hilang. Field ber-kat hanya tampil di kategorinya. */
{key:'pemeliharaan', label:'Pengajuan', prefix:'PGJ', fields:[
  {key:'kategori_pengajuan', label:'Kategori', type:'text', col:1, noForm:1},
  {key:'jenis_barang', label:'Jenis', type:'select', options:['Peralatan','Kendaraan'], col:1},
  {key:'nama_barang', label:'Nama Peralatan / Kendaraan', labelK:'Nama Barang yang Dibutuhkan', type:'text', required:true, col:1},
  {key:'tanggal', label:'Tanggal Pengajuan', type:'date', col:1},
  {key:'jumlah_kebutuhan', label:'Jumlah Dibutuhkan', type:'number', kat:'Kebutuhan'},
  {key:'estimasi_biaya', label:'Estimasi Biaya (Rp)', type:'number', decimal:1, kat:'Kebutuhan', placeholder:'mis. 5000000'},
  {key:'tingkat_urgensi', label:'Tingkat Urgensi', type:'select', options:['Rendah','Sedang','Tinggi'], kat:'Kebutuhan'},
  {key:'alasan', label:'Alasan / Spesifikasi yang Dibutuhkan', type:'textarea', kat:'Kebutuhan'},
  {key:'jumlah', label:'Jumlah Unit (khusus peralatan)', type:'number', kat:'Pemeliharaan'},
  {key:'jenis_pemeliharaan', label:'Jenis Pemeliharaan', type:'select', options:['Perawatan Rutin','Servis Berkala','Perbaikan','Pemeriksaan / Inspeksi','Penggantian Suku Cadang','Ganti Oli','Kalibrasi'], kat:'Pemeliharaan'},
  {key:'tanggal_selesai', label:'Tanggal Selesai', type:'date', kat:'Pemeliharaan'},
  {key:'pelaksana', label:'Petugas / Bengkel', type:'text', kat:'Pemeliharaan'},
  {key:'uraian', label:'Uraian Pekerjaan / Tindakan', type:'textarea', kat:'Pemeliharaan'},
  {key:'suku_cadang', label:'Suku Cadang / Bahan yang Digunakan', type:'text', kat:'Pemeliharaan'},
  {key:'km_saat_ini', label:'KM Saat Pemeliharaan (khusus kendaraan darat)', type:'number', decimal:1, placeholder:'mis. 45000', kat:'Pemeliharaan'},
  {key:'biaya', label:'Biaya (Rp)', type:'number', decimal:1, placeholder:'mis. 1500000', kat:'Pemeliharaan'},
  {key:'kondisi_setelah', label:'Kondisi Setelah Pemeliharaan', type:'select', options:['Baik / Layak Pakai','Perlu Tindak Lanjut','Rusak'], kat:'Pemeliharaan'},
  {key:'jadwal_berikutnya', label:'Jadwal Pemeliharaan Berikutnya', type:'date', kat:'Pemeliharaan'},
  {key:'status', label:'Status Pekerjaan', type:'select', options:['Dijadwalkan','Sedang Dikerjakan','Selesai'], col:1, isStatus:1, kat:'Pemeliharaan'},
  {key:'status_persetujuan', label:'Persetujuan', type:'text', col:1, noForm:1, noDetail:1},
  {key:'keterangan', label:'Ket', type:'textarea'},
]},
];
const TABLE_MODULES = GENERIC_MODULES.concat(EXTRA_MODULES);

/* Pengecekan Siaga Rutin: pilihan posisi & kondisi (didefinisikan di atas agar tersedia saat inisialisasi) */
const SIAGA_POSISI = ['Rescue car carriel','Gudang Peralatan','Pos Jaga','Kapal / RIB','Lainnya'];
const SIAGA_KONDISI = ['Baik','Rusak Ringan','Rusak Berat','Hilang'];
/* Peralatan: pilihan Posisi mengikuti format lembar pengecekan */
const SIAGA_POSISI_ALAT = ['Rescue car carriel','Rescue car elf','Truck personil Hino','Gudang operasi','Gudang perlengkapan','Rak LCR','Perorangan','Mess','Di pinjam','Tidak Ada','Lainnya'];

const NAV_ORDER = [
  {key:'dashboard', label:'Dashboard', short:'DB'},
  {key:'kegiatan', label:'Kegiatan/Tugas', short:'KT'},
  {key:'persuratan', label:'Persuratan', short:'PS'},
  ...TABLE_MODULES.flatMap(m=>{
    const it = {key:m.key, label:m.label, short:m.prefix.slice(0,2)};
    // Pengecekan Siaga Rutin: tepat di bawah Kendaraan
    return m.key==='kendaraan' ? [it, {key:'siaga_rutin', label:'Pengecekan Siaga Rutin', short:'SR'}] : [it];
  }),
  {key:'anggota', label:'Daftar Anggota', short:'AG'},
  {key:'pengaturan', label:'Pengaturan', short:'PG'}, // khusus Admin Utama
];

/* ============================= SEED DATA ============================= */
function seed(){
  const anggota = [
  ];

  const kegiatan = [
    {id:'KGT-001', nomor:'001/KGT/2026', jenis:'sgts', jenis_label:'Sar Go To School', nama:'Sosialisasi SAR SDN 05 Tangerang', tanggal:'2026-09-28', waktu:'08:00', lokasi:'SDN 05 Tangerang', penanggung_jawab:'Bambang Setiawan', deskripsi:'Edukasi keselamatan air dan simulasi evakuasi dasar untuk siswa kelas 4-6.', jumlah_dibutuhkan:3, status_persetujuan:'Menunggu Persetujuan', keterangan_persetujuan:'', anggota_ids:[], status_pelaksanaan:'Draft', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:'', file_pdf:null, dibuat: nowStr(), diperbarui: nowStr()},
    {id:'KGT-002', nomor:'002/KGT/2026', jenis:'bimtek', jenis_label:'Bimtek', nama:'Bimtek Water Rescue Regu 2', tanggal:'2026-09-25', waktu:'09:00', lokasi:'Dermaga Tanjung Priok', penanggung_jawab:'Andi Wijaya', deskripsi:'Bimbingan teknis penyelamatan di perairan untuk regu 2.', jumlah_dibutuhkan:4, status_persetujuan:'Disetujui', keterangan_persetujuan:'Disetujui oleh KSO — sesuai jadwal latihan rutin.', anggota_ids:[], status_pelaksanaan:'Dalam Pelaksanaan', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:'', file_pdf:null, dibuat: nowStr(), diperbarui: nowStr()},
    {id:'KGT-003', nomor:'003/KGT/2026', jenis:'uji_periodik', jenis_label:'Uji Periodik', nama:'Uji Periodik Peralatan Selam', tanggal:'2026-09-18', waktu:'13:00', lokasi:'Gudang Peralatan Pusat', penanggung_jawab:'Fajar Nugroho', deskripsi:'Pengujian berkala tabung selam dan regulator sebelum operasi.', jumlah_dibutuhkan:2, status_persetujuan:'Disetujui', keterangan_persetujuan:'Disetujui oleh KKS.', anggota_ids:[], status_pelaksanaan:'Selesai', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:'', file_pdf:null, dibuat: nowStr(), diperbarui: nowStr()},
    {id:'KGT-004', nomor:'004/KGT/2026', jenis:'diklat', jenis_label:'Diklat', nama:'Diklat Dasar Rescuer Angkatan 12', tanggal:'2026-09-10', waktu:'08:00', lokasi:'Pusdiklat BASARNAS', penanggung_jawab:'Hendra Gunawan', deskripsi:'Pengajuan diklat dasar ditolak karena bentrok dengan jadwal siaga nasional.', jumlah_dibutuhkan:6, status_persetujuan:'Ditolak', keterangan_persetujuan:'Ditolak oleh KSD — bentrok dengan status siaga nasional pada tanggal terkait.', anggota_ids:[], status_pelaksanaan:'Draft', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:'', file_pdf:null, dibuat: nowStr(), diperbarui: nowStr()},
  ];

  const db = { anggota, kegiatan };
  TABLE_MODULES.forEach(m=>{ db[m.key] = sampleFor(m); });
  return db;
}

function sampleFor(m){
  const base = {id: uid(m.prefix, []), status: (m.fields.find(f=>f.isStatus)||{}).options ? m.fields.find(f=>f.isStatus).options[0] : undefined};
  const rows = [];
  const text = {
    sgts:[{nama_sekolah:'SMPN 12 Bekasi', alamat_sekolah:'Jl. Raya Bekasi Timur', tanggal:'2026-08-14', waktu:'09:00', jumlah_peserta:120, materi:'Pengenalan SAR & Water Safety', penanggung_jawab:'Siti Rahmawati', narasumber:'Andi Wijaya', anggota_bertugas:'', keterangan:'Kegiatan tahunan', dokumentasi:'', status:'Selesai'}],
    bimtek:[{nama_bimtek:'Bimtek Vertical Rescue', jenis_bimtek:'Teknis', materi:'Teknik SRT & Anchor', tanggal:'2026-08-20', waktu:'08:30', lokasi:'Gedung Latihan BASARNAS', penyelenggara:'Bidang Operasi', narasumber:'Fajar Nugroho', peserta:'Regu 3', jumlah_peserta:8, petugas:'', keterangan:'', dokumentasi:'', status:'Selesai'}],
    potensi:[{nama_potensi:'Komunitas Selam Jakarta', kategori:'Organisasi', nama_kontak:'Rudi Hartono', nomor_kontak:'+62 813-2000-0001', alamat:'Jakarta Utara', wilayah:'DKI Jakarta', kompetensi:'Selam dasar-lanjut', jumlah_tersertifikasi:14, jumlah_belum_sertifikasi:6, jumlah_total:20, status:'Aktif', alut_sapras:'2 unit perahu karet, alat selam lengkap', keterangan:'Mitra potensial SAR', dokumentasi:''}],
    narasumber:[{nama:'Dr. Arief Kurniawan', instansi:'Kemenkes RI', jabatan:'Dokter Spesialis Emergency', keahlian:'Medical Emergency Response', nomor_kontak:'+62 811-3000-0001', email:'arief.k@kemenkes.go.id', materi:'Penanganan Korban Bencana', kegiatan_terkait:'Diklat, Bimtek', status:'Direncanakan', keterangan:''}],
    diklat:[{nama_diklat:'Diklat Dasar Rescuer Angkatan 11', jenis_diklat:'Diklat Dasar', materi:'Dasar-dasar SAR', tanggal_mulai:'2026-07-01', tanggal_selesai:'2026-07-14', lokasi:'Pusdiklat BASARNAS', penyelenggara:'Pusdiklat', instruktur:'Bambang Setiawan', peserta:'20 calon rescuer', jumlah_peserta:20, anggota_bertugas:'', sertifikat:'Diterbitkan', dokumentasi:'', status:'Selesai'}],
    rescuer:[],
    pembinaan:[{nama_kegiatan:'Pembinaan Potensi SAR Wilayah Barat', jenis_pembinaan:'Rutin', sasaran:'Kelompok Potensi SAR', tanggal:'2026-08-05', lokasi:'Balai Desa Cengkareng', materi:'Standar Prosedur Evakuasi', pembina:'Bambang Setiawan', peserta:'25 orang', anggota_bertugas:'', hasil:'Peserta memahami SOP dasar', dokumentasi:'', status:'Selesai'}],
    pemberdayaan:[{nama_kegiatan:'Pemberdayaan Nelayan Tangguh Bencana', sasaran:'Kelompok Nelayan Muara Angke', lokasi:'Muara Angke', tanggal:'2026-08-22', jumlah_peserta:40, materi:'Mitigasi Bencana Laut', narasumber:'Dr. Arief Kurniawan', petugas:'', hasil:'40 nelayan terlatih dasar mitigasi', dokumentasi:'', status:'Selesai'}],
    uji_periodik:[{nama_uji:'Uji Periodik Kompetensi Selam Angkatan 1', jenis_uji:'Uji Kompetensi', materi:'Penyelaman dan penyelamatan air', tanggal_mulai:'2026-08-01', tanggal_selesai:'2026-08-02', lokasi:'Gudang Peralatan Pusat', penyelenggara:'Kantor SAR Jakarta', penguji:'Fajar Nugroho', peserta:'Tim Rescuer Selam', jumlah_peserta:'', anggota_bertugas:'', sertifikat:'', dokumentasi:'', status:'Layak'}],
    uji_pemeliharaan:[],
  };
  (text[m.key]||[]).forEach(row=>{ rows.push(Object.assign({id: uid(m.prefix, rows)}, row)); });
  return rows;
}

/* Dideklarasikan sebelum load(): migrateDB() (dipanggil lewat load()) memakai daftar ini untuk sinkron Absensi */
const ABS_SUMBER_AUTO = ['Samapta','Pembelajaran'];
/* Dideklarasikan sebelum load(): migrasi data saat halaman dimuat memanggil persist() */
let _syncSaving = 0;
let _persistSnap = null, _persistTimer = null, _persistChain = Promise.resolve(), _persistPending = false;
let DB = load();
function migrateDB(db){
  let changed = false;
  // Perbaikan data Rescuer: nama/NIP sekarang diambil dari Daftar Anggota lewat
  // anggota_id. Kalau ada data Rescuer yang belum punya anggota_id, atau
  // anggota_id-nya tidak cocok dengan Daftar Anggota yang tersimpan (mis. dari
  // versi data lama), tautkan ulang ke anggota yang benar-benar ada.
  if(Array.isArray(db.rescuer)){
    const anggotaIds = new Set((db.anggota||[]).map(a=>a.id));
    db.rescuer.forEach(r=>{
      if(!r.anggota_id || !anggotaIds.has(r.anggota_id)){
        if(db.anggota && db.anggota.length){ r.anggota_id = db.anggota[0].id; changed = true; }
      }
    });
  }
  // Bersihkan data Diklat yang tidak lengkap (Nama Diklat kosong), mis. baris
  // kosong yang sempat tersimpan tanpa nama.
  if(Array.isArray(db.diklat)){
    const before = db.diklat.length;
    db.diklat = db.diklat.filter(d => d.nama_diklat && String(d.nama_diklat).trim() !== '');
    if(db.diklat.length !== before) changed = true;
  }
  // Matriks peserta lama Uji Periodik (MFR, JR, HART, dst.) dihapus; peserta kini otomatis dari 4 card kategori.
  if(Array.isArray(db.uji_periodik)){
    db.uji_periodik.forEach(r=>{
      if('peserta_matrix' in r){ delete r.peserta_matrix; changed = true; }
      if('kolom_peserta' in r){ delete r.kolom_peserta; changed = true; }
    });
  }
  if(db.kolom_peserta && 'uji_periodik' in db.kolom_peserta){ delete db.kolom_peserta.uji_periodik; changed = true; }
  // Uji Periodik kini berformat seperti Diklat: petakan field lama (peralatan) ke field baru.
  if(Array.isArray(db.uji_periodik)){
    db.uji_periodik.forEach(r=>{
      if(!r.nama_uji && r.nama_peralatan){
        r.nama_uji = r.nama_peralatan;
        if(!r.jenis_uji && r.jenis_peralatan) r.jenis_uji = r.jenis_peralatan;
        if(!r.tanggal_mulai && r.tanggal_uji) r.tanggal_mulai = r.tanggal_uji;
        if(!r.penguji && r.petugas) r.penguji = r.petugas;
        changed = true;
      }
    });
  }
  // Surat Masuk (alur awal Kegiatan/Tugas): sediakan daftar kosong bila belum ada.
  if(!Array.isArray(db.surat_masuk)){ db.surat_masuk = []; changed = true; }
  // Persuratan: Surat Keluar (Surat Masuk diambil dari db.surat_masuk milik Kegiatan/Tugas).
  if(!Array.isArray(db.surat_keluar)){ db.surat_keluar = []; changed = true; }
  // Data hasil Import Excel tidak memerlukan persetujuan atasan: yang masih "Menunggu Persetujuan" dianggap selesai (tanpa ACC). Data lain tidak diubah.
  db.surat_masuk.forEach(r=>{
    // Surat Masuk hasil Import Excel: tanpa ACC, tidak menjadi Kegiatan/Tugas, langsung berstatus Selesai.
    if(r && r.sumber==='Import Excel' && r.status!=='Selesai'){
      r.status = 'Selesai'; r.tanpa_acc = true;
      r.keterangan_persetujuan = 'Data hasil Import Excel — tidak memerlukan persetujuan atasan.'; changed = true;
    }
  });
  if(Array.isArray(db.kegiatan)) db.kegiatan.forEach(r=>{
    if(r && r.sumber==='Import Excel' && r.status_persetujuan==='Menunggu Persetujuan'){
      r.status_persetujuan = 'Disetujui'; r.tanpa_acc = true;
      r.keterangan_persetujuan = 'Data hasil Import Excel — tidak memerlukan persetujuan atasan.'; changed = true;
    }
  });
  // Modul inventaris baru (Peralatan, Kendaraan, Peminjaman): sediakan daftar kosong bila belum ada.
  EXTRA_MODULES.forEach(m=>{ if(!Array.isArray(db[m.key])){ db[m.key] = []; changed = true; } });
  // Pengecekan Siaga Rutin: riwayat pengecekan (daftar alat/kendaraan diambil dari modul Peralatan & Kendaraan).
  if(!Array.isArray(db.siaga_rutin)){ db.siaga_rutin = []; changed = true; }
  if(!Array.isArray(db.sumda_riwayat)){ db.sumda_riwayat = []; changed = true; }
  // Pengajuan: data lama (modul Pemeliharaan) dianggap kategori Pemeliharaan yang sudah disetujui.
  db.pemeliharaan.forEach(r=>{
    if(!r.kategori_pengajuan){ r.kategori_pengajuan = 'Pemeliharaan'; changed = true; }
    if(!r.status_persetujuan){ r.status_persetujuan = 'Disetujui'; changed = true; }
  });
  // Modul "Pembinaan SAR" diganti nama menjadi "Pembinaan Tenaga SAR": perbarui label yang tersimpan di data lama.
  const lblPbn = (GENERIC_MODULES.find(m=>m.key==='pembinaan')||{}).label;
  ['kegiatan','surat_masuk'].forEach(k=>{
    if(!Array.isArray(db[k])) return;
    db[k].forEach(r=>{ if(r && r.jenis==='pembinaan' && r.jenis_label!==lblPbn){ r.jenis_label = lblPbn; changed = true; } });
  });
  // Pembinaan Tenaga SAR tab Pembelajaran: Pengajar/Fasilitator & Anggota Bertugas dihilangkan, bersihkan data lama.
  if(Array.isArray(db.pembinaan)){
    db.pembinaan.forEach(r=>{
      if(pbnKat(r)!=='Pembelajaran') return;
      ['pengajar','anggota_bertugas','metode'].forEach(k=>{ if(k in r){ delete r[k]; changed = true; } });
    });
  }
  // Pembinaan Tenaga SAR tab Pembelajaran: Materi "Hard" diganti "HART".
  if(Array.isArray(db.pembinaan)){
    db.pembinaan.forEach(r=>{ if(r && pbnKat(r)==='Pembelajaran' && r.materi==='Hard'){ r.materi = 'HART'; changed = true; } });
  }
  // Absensi Pembinaan Tenaga SAR: sediakan daftar dan sinkronkan nama anggota dari data Samapta yang sudah ada.
  if(!Array.isArray(db.pembinaan_absensi)){ db.pembinaan_absensi = []; changed = true; }
  if(pbnAbsSync(db)) changed = true;
  // IMT Pembinaan Tenaga SAR: daftar isian (anggota_id, jenis kelamin, BB, TB) per anggota.
  if(!Array.isArray(db.pembinaan_imt)){ db.pembinaan_imt = []; changed = true; }
  // IMT per bulan: isian lama yang belum punya periode dimasukkan ke bulan berjalan.
  db.pembinaan_imt.forEach(r=>{ if(r && !r.bulan){ r.bulan = new Date().toLocaleDateString('sv-SE').slice(0,7); changed = true; } });
  // Modul Lainnya (data lama belum punya daftar ini): sediakan daftar kosong.
  if(!Array.isArray(db.lainnya)){ db.lainnya = []; changed = true; }
  // Kegiatan yang ditolak: pelaksanaan Draft, dan data di modul jenis terkait dihapus.
  if(Array.isArray(db.kegiatan)){
    db.kegiatan.forEach(k=>{
      if(k.status_persetujuan!=='Ditolak') return;
      if(k.status_pelaksanaan!=='Draft'){ k.status_pelaksanaan = 'Draft'; changed = true; }
      // Data modul hasil kegiatan yang ditolak dihapus dari modul jenisnya (kegiatan tetap ada).
      GENERIC_MODULES.forEach(m=>{
        const arr = db[m.key]; if(!Array.isArray(arr)) return;
        const sisa = arr.filter(r=>!((m.key===k.jenis && k.linked_record_id && r.id===k.linked_record_id) || r.dari_kegiatan_id===k.id));
        if(sisa.length!==arr.length){ db[m.key] = sisa; changed = true; }
      });
      if(k.linked_record_id){ k.linked_record_id = ''; changed = true; }
    });
  }
  // Status Narasumber kini mengikuti Kegiatan/Tugas (Direncanakan/Berlangsung/Selesai): petakan data lama.
  if(Array.isArray(db.narasumber)){
    const pel = {'Draft':'Direncanakan','Dalam Pelaksanaan':'Berlangsung','Selesai':'Selesai'};
    db.narasumber.forEach(r=>{
      if(['Direncanakan','Berlangsung','Selesai'].includes(r.status)) return;
      const kg = (db.kegiatan||[]).find(k=>(r.dari_kegiatan_id && k.id===r.dari_kegiatan_id) || (k.jenis==='narasumber' && k.linked_record_id===r.id));
      r.status = (kg && pel[kg.status_pelaksanaan]) || 'Direncanakan';
      changed = true;
    });
  }
  // Kondisi Kendaraan kini Serviceable / Unserviceable / Scrap: petakan data lama.
  if(Array.isArray(db.kendaraan)){
    const kMap = {'Baik':'Serviceable','Rusak Ringan':'Unserviceable','Rusak Berat':'Scrap'};
    db.kendaraan.forEach(r=>{ if(kMap[r.kondisi]){ r.kondisi = kMap[r.kondisi]; changed = true; } });
  }
  // Peralatan format baru (Nama Barang, Kode Rak, Jumlah Baik/Rusak): petakan data lama.
  if(Array.isArray(db.peralatan)){
    const katMap = {'SAR Air':'Palsar Air','SAR Darat':'Palsar Darat','Selam':'Palsar Air','Penyelamatan Vertikal':'Palsar Darat','Lainnya':'Palsar Darat'};
    db.peralatan.forEach(r=>{
      if('nama_barang' in r) return;
      const jml = parseFloat(r.jumlah)||0;
      r.nama_barang = r.nama_peralatan || '';
      r.kode_rak = r.lokasi_penyimpanan || '';
      r.kategori = katMap[r.kategori] || r.kategori || 'Palsar Darat';
      r.jumlah_baik = (r.kondisi==='Baik') ? jml : 0;
      r.jumlah_rusak = (r.kondisi==='Baik') ? 0 : jml;
      r.jumlah = jml;
      changed = true;
    });
    // Data Peralatan yang belum punya Lokasi: isi default KPP Jakarta.
    db.peralatan.forEach(r=>{ if(!r.lokasi){ r.lokasi = 'KPP Jakarta'; changed = true; } });
  }
  return changed;
}
function load(){
  // Data dimuat dari database SQL oleh PHP (window.__INITIAL_DB__)
  const init = window.__INITIAL_DB__;
  if(init && typeof init==='object' && Array.isArray(init.anggota)){
    if(migrateDB(init)) persist(init);
    return init;
  }
  const fresh = seed();
  migrateDB(fresh);
  persist(fresh);
  return fresh;
}
/* Simpan ke database lewat api.php (digabung/ditunda 300 ms, berurutan) */
const API_URL = 'api.php';
function persist(db){
  if(VIEW_ONLY) return; // tampilan publik tidak pernah menyimpan
  _persistSnap = db || DB;
  _persistPending = true;
  clearTimeout(_persistTimer);
  _persistTimer = setTimeout(flushPersist, 300);
}
function flushPersist(){
  clearTimeout(_persistTimer);
  if(!_persistPending) return _persistChain;
  _persistPending = false;
  const body = JSON.stringify(_persistSnap);
  _syncSaving++;
  _persistChain = _persistChain.then(() => fetch(API_URL + '?action=save', {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body
    }).then(r => r.json().catch(()=>({ok:false})).then(j => {
      if(r.status === 401){ location.href = 'login.php'; throw new Error(j.error || 'Sesi berakhir'); }
      if(!r.ok || !j.ok) throw new Error(j.error || ('HTTP '+r.status));
      // Revisi hasil simpanan sendiri tidak dianggap "perubahan orang lain"
      if(j.rev && j.rev - 1 === SYNC_REV) SYNC_REV = j.rev;
      // Ada data yang sudah diputuskan (ACC/Tolak) oleh pihak lain lebih dulu: server menolak menimpanya, muat ulang tampilan
      if(j.konflik){ try{ toast('Data ini sudah di-ACC/ditolak lebih dulu oleh pihak lain. Tampilan diperbarui.'); }catch(e){} SYNC_REV = -1; setTimeout(syncTick, 300); }
    }))
  ).catch(err => { console.error(err); try{ toast('Gagal menyimpan ke server: ' + err.message); }catch(e){} })
   .finally(() => { _syncSaving--; });
  return _persistChain;
}
window.addEventListener('beforeunload', ev => { if(_persistPending){ flushPersist(); ev.preventDefault(); ev.returnValue = ''; } });

/* ============================= SINKRONISASI OTOMATIS =============================
   Browser menanyakan nomor revisi ke server tiap beberapa detik (api.php?action=sync).
   Jika ada perubahan dari pengguna/perangkat lain, data dimuat ulang dan tampilan
   diperbarui tanpa refresh. Pemuatan ditunda selama pengguna sedang mengisi form/modal
   atau masih ada perubahan lokal yang belum tersimpan, supaya ketikan tidak hilang. */
const SYNC_INTERVAL = 4000;
let SYNC_REV = window.__INITIAL_REV__ || 0, _syncBusy = false, _syncDeferred = null, _syncWarned = false;
function syncUserBusy(){
  if(_persistPending) return true;
  const m = document.getElementById('modalRoot');
  if(m && m.innerHTML.trim() !== '') return true;
  const a = document.activeElement;
  return !!(a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName) && !a.readOnly && !a.disabled && a.type !== 'checkbox' && a.type !== 'button');
}
function syncApply(j){
  const fresh = j.db;
  if(!fresh || !Array.isArray(fresh.anggota)) return;
  const migrated = migrateDB(fresh);
  DB = fresh;
  SYNC_REV = j.rev;
  _syncDeferred = null; _syncWarned = false;
  const y = window.scrollY;
  try{ render(currentView); }catch(e){ console.error(e); }
  window.scrollTo(0, y);
  if(migrated) persist(DB);
}
async function syncTick(){
  if(VIEW_ONLY) return;
  if(_syncBusy || document.hidden) return;
  _syncBusy = true;
  try{
    const since = _syncDeferred ? _syncDeferred.rev : SYNC_REV;
    const r = await fetch(API_URL + '?action=sync&since=' + since, {cache:'no-store'});
    if(r.status === 401){ location.href = 'login.php'; return; }
    const j = await r.json();
    if(!r.ok || !j.ok) throw new Error(j.error || ('HTTP '+r.status));
    if(NOTIF_ON && typeof j.notif === 'number' && j.notif !== NOTIF.belum) notifSetJumlah(j.notif);
    if(j.changed) _syncDeferred = j;
    if(_syncDeferred){
      if(!syncUserBusy() && _syncSaving === 0) syncApply(_syncDeferred);
      else if(!_syncWarned){ _syncWarned = true; toast('Ada pembaruan dari pengguna lain, akan dimuat setelah form ditutup'); }
    }
  }catch(e){ console.warn('sync:', e.message); }
  finally{ _syncBusy = false; }
}
setInterval(syncTick, SYNC_INTERVAL);

/* ============================= NOTIFIKASI (LONCENG) =============================
   Khusus Admin & Admin Utama. Server mencatat setiap penambahan/perubahan/penghapusan data oleh
   bot WhatsApp, Admin, dan Anggota (api.php?action=notif_list). Jumlah belum dibaca ikut dalam
   polling sinkronisasi (j.notif), jadi lonceng ikut berubah tanpa refresh. */
const NOTIF_ON = <?= $BELL ? 'true' : 'false' ?>;
const NOTIF = {belum: <?= (int)$NOTIF_AWAL ?>, items: [], skew: 0, buka: false, filter: 'semua', termuat: false};
const NOTIF_JUDUL_ASAL = document.title;
const NOTIF_SUMBER = {wabot:'WA Bot', admin:'Admin', anggota:'Anggota'};
const NOTIF_AKSI = {tambah:'menambah', ubah:'mengubah', hapus:'menghapus'};
/* kunci data di server -> menu di sidebar */
const NOTIF_VIEW = {surat_masuk:'kegiatan', surat_keluar:'persuratan', sumda_riwayat:'uji_pemeliharaan', pengajuan:'pemeliharaan'};
function notifIkon(s){
  if(s==='wabot') return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5z"/></svg>';
  if(s==='admin') return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
  return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8"/></svg>';
}
function notifWaktuRel(ts){
  const d = Math.max(0, Math.floor(Date.now()/1000 + NOTIF.skew - ts));
  if(d < 60) return 'Baru saja';
  if(d < 3600) return Math.floor(d/60) + ' menit lalu';
  if(d < 86400) return Math.floor(d/3600) + ' jam lalu';
  if(d < 7*86400) return Math.floor(d/86400) + ' hari lalu';
  return new Date(ts*1000).toLocaleDateString('id-ID', {day:'numeric', month:'short', year:'numeric'});
}
function notifSetJumlah(n){
  const naik = n > NOTIF.belum;
  NOTIF.belum = n;
  const b = document.getElementById('notifBadge'), btn = document.getElementById('notifBtn');
  if(!b || !btn) return;
  b.style.display = n > 0 ? '' : 'none';
  b.textContent = n > 99 ? '99+' : String(n);
  btn.classList.toggle('ada', n > 0);
  btn.title = n > 0 ? n + ' notifikasi belum dibaca' : 'Notifikasi perubahan data';
  document.title = n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' + NOTIF_JUDUL_ASAL : NOTIF_JUDUL_ASAL;
  if(naik){
    btn.classList.remove('goyang'); void btn.offsetWidth; btn.classList.add('goyang');
    if(NOTIF.buka) notifMuat(); else NOTIF.termuat = false;
  }
}
async function notifApi(aksi, body){
  const r = await fetch(API_URL + '?action=' + aksi, body
    ? {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body: JSON.stringify(body), cache:'no-store'}
    : {cache:'no-store'});
  if(r.status === 401){ location.href = 'login.php'; throw new Error('login'); }
  const j = await r.json();
  if(!r.ok || !j.ok) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}
async function notifMuat(){
  try{
    const j = await notifApi('notif_list');
    NOTIF.items = j.items || []; NOTIF.skew = (j.now || 0) - Date.now()/1000; NOTIF.termuat = true;
    notifSetJumlah(j.belum || 0);
    if(NOTIF.buka) notifGambar();
  }catch(e){
    if(NOTIF.buka){ const el = document.getElementById('notifPop'); if(el) el.innerHTML = '<div class="notif-kosong">Gagal memuat notifikasi: ' + esc(e.message) + '</div>'; }
  }
}
function notifGambar(){
  const el = document.getElementById('notifPop'); if(!el) return;
  const f = NOTIF.filter;
  const hit = NOTIF.items.filter(x => f === 'semua' || x.sumber === f);
  const tab = (k, t) => `<button type="button" class="notif-tab ${f===k?'on':''}" onclick="notifFilter('${k}')">${t}</button>`;
  const baris = hit.map(x => {
    const modul = x.modul_label ? esc(x.modul_label) : 'data';
    return `<button type="button" class="notif-item ${x.baca?'':'baru'}" onclick="notifKlik(${x.id})">
      <span class="notif-ico ${esc(x.sumber)}" title="${esc(NOTIF_SUMBER[x.sumber]||x.sumber)}">${notifIkon(x.sumber)}</span>
      <span class="min-w-0 flex-1" style="min-width:0;">
        <span class="block text-xs" style="line-height:1.35;"><b>${esc(x.aktor||'-')}</b> ${esc(NOTIF_AKSI[x.aksi]||'mengubah')} data <b>${modul}</b></span>
        ${x.judul ? `<span class="block text-xs mt-0.5" style="color:var(--text); overflow-wrap:anywhere;">${esc(x.judul)}</span>` : ''}
        ${x.detail ? `<span class="block text-[11px] mt-0.5" style="color:var(--muted); overflow-wrap:anywhere;">${esc(x.detail)}</span>` : ''}
        <span class="block text-[11px] mt-1" style="color:var(--muted);">${esc(NOTIF_SUMBER[x.sumber]||x.sumber)} · ${esc(notifWaktuRel(x.waktu))}</span>
      </span>
    </button>`;
  }).join('');
  el.innerHTML = `
    <div class="notif-head">
      <div><div class="font-display font-semibold text-sm">Notifikasi</div><div class="text-[11px]" style="color:var(--muted);">${NOTIF.belum > 0 ? NOTIF.belum + ' belum dibaca' : 'Semua sudah dibaca'} · perubahan dari WA Bot, Admin & Anggota</div></div>
      <button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" onclick="notifTandaiSemua()" ${NOTIF.belum>0?'':'disabled style="opacity:.5;cursor:default;"'}>Tandai dibaca</button>
    </div>
    <div class="notif-tabs">${tab('semua','Semua')}${tab('wabot','WA Bot')}${tab('admin','Admin')}${tab('anggota','Anggota')}</div>
    <div class="notif-list">${baris || '<div class="notif-kosong">' + (NOTIF.termuat ? 'Belum ada notifikasi.' : 'Memuat…') + '</div>'}</div>`;
}
function notifFilter(f){ NOTIF.filter = f; notifGambar(); }
function notifTutup(){
  NOTIF.buka = false;
  const el = document.getElementById('notifPop'), btn = document.getElementById('notifBtn');
  if(el) el.style.display = 'none';
  if(btn) btn.setAttribute('aria-expanded', 'false');
}
function notifToggle(ev){
  if(ev) ev.stopPropagation();
  if(NOTIF.buka){ notifTutup(); return; }
  NOTIF.buka = true;
  const el = document.getElementById('notifPop'), btn = document.getElementById('notifBtn');
  el.style.display = ''; btn.setAttribute('aria-expanded', 'true');
  btn.classList.remove('goyang');
  notifGambar();
  notifMuat();
}
async function notifTandaiSemua(){
  NOTIF.items.forEach(x => x.baca = true);
  notifSetJumlah(0); notifGambar();
  try{ await notifApi('notif_baca', {semua:true}); }catch(e){ console.warn('notif:', e.message); notifMuat(); }
}
async function notifKlik(id){
  const x = NOTIF.items.find(i => i.id === id); if(!x) return;
  if(!x.baca){
    x.baca = true; notifSetJumlah(Math.max(0, NOTIF.belum - 1));
    notifApi('notif_baca', {ids:[id]}).catch(e => { console.warn('notif:', e.message); });
  }
  notifTutup();
  notifBukaData(x);
}
/* Buka menu/data terkait. Data yang sudah dihapus: cukup buka modulnya. */
function notifBukaData(x){
  if(!x.modul) return;
  const view = NOTIF_VIEW[x.modul] || x.modul;
  if(!NAV_ORDER.some(n => n.key === view)) return;
  if(x.modul === 'surat_masuk') kegTab = 'surat';
  else if(x.modul === 'kegiatan') kegTab = 'kegiatan';
  else if(x.modul === 'surat_keluar') psTab = 'keluar';
  render(view);
  if(x.modul === 'kegiatan' && x.aksi !== 'hapus' && x.rec_id && (DB.kegiatan||[]).some(k => k.id === x.rec_id)){
    try{ openKegiatanDetail(x.rec_id); }catch(e){ console.warn(e); }
  }
  else if(x.modul === 'surat_masuk' && x.aksi !== 'hapus' && x.rec_id && (DB.surat_masuk||[]).some(s => s.id === x.rec_id)){
    try{ openSuratDetail(x.rec_id); }catch(e){ console.warn(e); }
  }
}
if(NOTIF_ON){
  document.addEventListener('click', ev => { if(NOTIF.buka && !ev.target.closest('#notifWrap')) notifTutup(); }, true); // capture: panel digambar ulang saat diklik, target lama sudah lepas dari DOM
  document.addEventListener('keydown', ev => { if(ev.key === 'Escape' && NOTIF.buka) notifTutup(); });
  setInterval(() => { if(NOTIF.buka && !document.hidden) notifGambar(); }, 60000); // segarkan label "x menit lalu"
  notifSetJumlah(NOTIF.belum); // tampilkan jumlah awal (dari server saat halaman dibuka)
}
if(VIEW_ONLY){ setInterval(()=>{ if(!document.hidden && !document.getElementById('modalRoot').innerHTML.trim()) location.reload(); }, 300000); } // segarkan data tiap 5 menit
document.addEventListener('visibilitychange', () => { if(!document.hidden) syncTick(); });
window.addEventListener('focus', syncTick);

/* Keluar: pastikan perubahan terakhir sudah tersimpan sebelum sesi diakhiri */
function keluarAman(ev){
  const f = ev.target;
  ev.preventDefault();
  Promise.resolve(flushPersist()).catch(()=>{}).then(() => { _persistPending = false; f.submit(); });
  return false;
}

/* ============================= SIDEBAR ============================= */
const SIDEBAR_KEY = 'basarnas_sidebar_collapsed';
(function initSidebar(){
  try{ if(localStorage.getItem(SIDEBAR_KEY)==='1') document.body.classList.add('sb-collapsed'); }catch(e){}
})();
function toggleSidebar(force){
  // Desktop: sidebar dibuka/ditutup (disembunyikan) dan pilihan diingat
  if(window.innerWidth > 900){
    if(force === false) return; // klik menu di desktop tidak menutup sidebar
    const collapsed = force !== undefined ? !force : !document.body.classList.contains('sb-collapsed');
    document.body.classList.toggle('sb-collapsed', collapsed);
    try{ localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); }catch(e){}
    return;
  }
  // Mobile: sidebar geser masuk dengan latar gelap
  const sb = document.getElementById('sidebar'), sc = document.getElementById('scrim');
  const open = force !== undefined ? force : !sb.classList.contains('open');
  sb.classList.toggle('open', open); sc.classList.toggle('open', open);
}

/* ============================= PENGECEKAN SIAGA RUTIN ============================= */
/* Checklist kesiapan (di bawah menu Kendaraan). Pilih "Peralatan" atau "Kendaraan": baris tabel diambil dari modul tersebut.
   Riwayat: DB.siaga_rutin. */
/* Kendaraan: kondisi modul <-> kondisi pengecekan */
const SIAGA_KOND_KE_CEK = {'Serviceable':'Baik', 'Unserviceable':'Rusak Ringan', 'Scrap':'Rusak Berat'};
const SIAGA_SAPRAS = ['Sapras Darat','Sapras Laut'];
/* Jenis Pengecekan: "Peralatan" atau "Sarana". Data lama berjenis "Kendaraan" diperlakukan sebagai Sarana. */
function siagaIsSarana(j){ return j==='Sarana' || j==='Kendaraan'; }
/* Keterangan BBM (Sapras Darat): < 50 = BBM Hampir Habis, >= 50 = BBM Tersedia */
function siagaBbmStatus(v){
  const n = parseFloat(String(v==null?'':v).replace(',','.'));
  if(v==='' || v==null || isNaN(n)) return '';
  return n < 50 ? 'BBM Hampir Habis' : 'BBM Tersedia';
}
function siagaBbmBadge(v){
  const st = siagaBbmStatus(v);
  return st ? '<span class="badge '+(st==='BBM Tersedia'?'b-ok':'b-bad')+'">'+st+'</span>' : '';
}
function siagaBbmNote(i, v){
  if(siagaDraft && siagaDraft.rows[i]) siagaDraft.rows[i].vals['bbm'] = v;
  const el = document.getElementById('siaga_bbm_note_'+i); if(el) el.innerHTML = siagaBbmBadge(v);
}
/* Kolom isian Sarana mengikuti modul Kendaraan: Sapras Darat (KM, BBM) / Sapras Laut (Endurance, Speed, Range, dst.) */
function siagaSaprasKolom(kat){
  const m = TABLE_MODULES.find(x=>x.key==='kendaraan');
  return m.fields.filter(f=> kat==='Sapras Laut' ? f.laut : f.darat);
}
function siagaFieldLabel(key, kat){
  const m = TABLE_MODULES.find(x=>x.key==='kendaraan');
  const f = m.fields.find(x=>x.key===key);
  return f ? labelKendaraan(f, kat==='Sapras Laut') : key;
}
/* Daftar tetap peralatan untuk Pengecekan Siaga Rutin (hanya 32 item ini yang tampil di Peralatan) */
const SIAGA_ALAT_DAFTAR = ['Perahu Karet LCR','Motor Tempel Yamaha','Tangki Motor Tempel','Kunci Motor Tempel','Selang Motor Tempel','Blower','Pompa Manual','Dayung','Helmet','Life Jacket','Ring Bouy','Tali Lempar 25m','Tali Karmantel','Webbing','Kantong Mayat','Lampu Tembak','Senter','Medical Bag','Peralatan Selam','Tabung Selam','Genset','Kabel Roll','HT','Charger HT','Tandu Lipat','Velbed','Kompresor Tabung Selam','LSB + Neck Collar','Aqua Eye','Under Water Search Device','Night Vision','Rescue Net'];
/* Logo untuk PDF Pengecekan Peralatan */
const SIAGA_LOGO_BASARNAS = 'data:image/png;base64,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';
const SIAGA_LOGO_SAR = 'data:image/png;base64,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';
function siagaNorm(x){ return String(x||'').toLowerCase().replace(/buoy/g,'bouy').replace(/[^a-z0-9]/g,''); }
/* Data modul Peralatan yang cocok dengan nama alat (sama persis, atau diawali nama alat bila nama cukup panjang) */
function siagaCocokAlat(nama){
  const n = siagaNorm(nama);
  return (DB.peralatan||[]).filter(r=>{ const m = siagaNorm(r.nama_barang); return m===n || (n.length>=5 && m.startsWith(n)); });
}
function siagaSumber(jenis, kat){
  if(!siagaIsSarana(jenis)){
    return SIAGA_ALAT_DAFTAR.map((nama, idx)=>{
      const cocok = siagaCocokAlat(nama);
      const nb = cocok.reduce((a,r)=>a+(parseInt(r.jumlah_baik,10)||0),0), nr = cocok.reduce((a,r)=>a+(parseInt(r.jumlah_rusak,10)||0),0), nh = cocok.reduce((a,r)=>a+(parseInt(r.jumlah_hilang,10)||0),0);
      // Form pengecekan dimulai dari Jumlah 0 & Kondisi Baik; stok modul Peralatan (b0/r0/h0) hanya jadi acuan sinkronisasi
      return {ref:'ALT-'+String(idx+1).padStart(2,'0'), recId:(cocok.length ? cocok[0].id : ''), nama:nama, nopol:'', jenisK:'', vals:{}, v0:{},
              lok:'', jumlah:0, posisi:SIAGA_POSISI_ALAT[0], kondisi:'Baik', ket:'', j0:0, k0:'Baik', baik:nb, rusak:nr, hilang:nh, b0:nb, r0:nr, h0:nh};
    });
  }
  return siagaSumberSarana(jenis, kat);
}
function siagaSumberSarana(jenis, kat){
  const sar = siagaIsSarana(jenis);
  const kolom = sar ? siagaSaprasKolom(kat) : [];
  return (DB[sar ? 'kendaraan' : 'peralatan']||[]).filter(r=>!sar || saprasKategori(r)===kat).map(r=>{
    const nb = parseInt(r.jumlah_baik,10)||0, nr = parseInt(r.jumlah_rusak,10)||0, nh = parseInt(r.jumlah_hilang,10)||0;
    const jml = sar ? 1 : (nb + nr + nh);
    const kond = sar ? (SIAGA_KOND_KE_CEK[r.kondisi] || SIAGA_KONDISI[0]) : (nh>0 ? 'Hilang' : (nr>0 ? 'Rusak Ringan' : 'Baik'));
    const v0 = {}; kolom.forEach(f=>{ v0[f.key] = (r[f.key]===undefined || r[f.key]===null) ? '' : r[f.key]; });
    // Form dimulai dari 0 (kolom angka) / kosong (teks) & Kondisi Baik, sama seperti Peralatan; nilai modul hanya jadi acuan sinkronisasi
    const vals = {}; kolom.forEach(f=>{ vals[f.key] = f.type==='number' ? 0 : ''; });
    return {ref:r.id, nama: sar ? (r.nama_kendaraan||'-') : (r.nama_barang||'-'),
            nopol: r.nomor_polisi||'', jenisK: r.jenis_kendaraan||'', vals:vals, v0:sar ? Object.assign({}, vals) : {},
            lok:r.lokasi||'', jumlah:jml, posisi:(sar ? '' : SIAGA_POSISI_ALAT[0]), kondisi:sar ? 'Baik' : kond, ket:'', bersih:'Ya', j0:jml, k0:sar ? 'Baik' : kond,
            baik:nb, rusak:nr, hilang:nh, b0:nb, r0:nr, h0:nh};
  });
}
let siagaDraft = null;
function siagaValsBerubah(r){ return JSON.stringify(r.vals||{}) !== JSON.stringify(r.v0||{}); }
function siagaBerubah(r){ return r.jumlah!==r.j0 || r.kondisi!==r.k0 || r.baik!==r.b0 || r.rusak!==r.r0 || r.hilang!==r.h0 || siagaValsBerubah(r); }
/* Peralatan: kondisi otomatis dari Jumlah Baik / Rusak / Hilang */
function siagaKondisiHitung(r){
  if(r.hilang>0 && r.rusak>0) return 'Rusak & Hilang';
  if(r.hilang>0) return 'Hilang';
  if(r.rusak>0) return 'Rusak';
  return 'Baik';
}
/* Peralatan: Jumlah & Kondisi diisi langsung per baris (format lembar pengecekan) */
function siagaJumlahAlat(i, el){ const r = siagaDraft.rows[i]; if(r) r.jumlah = Math.max(0, parseInt(el.value,10) || 0); }
function siagaAlatAngka(r){
  if(r.jumlah===r.j0 && r.kondisi===r.k0) return {baik:r.b0, rusak:r.r0, hilang:r.h0};
  if(r.kondisi==='Baik') return {baik:r.jumlah, rusak:0, hilang:0};
  if(r.kondisi==='Hilang') return {baik:0, rusak:0, hilang:r.jumlah};
  return {baik:0, rusak:r.jumlah, hilang:0};
}
function siagaSelisih(r){ return r.baik + r.rusak + r.hilang - r.jumlah; }
function siagaHitung(i, field, el){
  const r = siagaDraft.rows[i]; if(!r) return;
  r[field] = Math.max(0, parseInt(el.value,10) || 0);
  r.kondisi = siagaKondisiHitung(r);
  const k = document.getElementById('siaga_k_'+i); if(k) k.innerHTML = siagaKondisiBadge(r.kondisi);
  const w = document.getElementById('siaga_w_'+i);
  if(w){ const sel = siagaSelisih(r); w.textContent = sel ? ('Total harus '+r.jumlah+' (selisih '+(sel>0?'+':'')+sel+')') : ''; }
}
const SIAGA_HARI = ['MINGGU','SENIN','SELASA','RABU','KAMIS','JUMAT','SABTU'];
const SIAGA_TIM = ['Tim 1','Tim 2','Tim 3','Tim 4','Tim 5'];
function siagaHari(iso){ const m = String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? SIAGA_HARI[new Date(+m[1], +m[2]-1, +m[3]).getDay()] : ''; }
function siagaGantiTanggal(v){ if(!siagaDraft) return; siagaDraft.tanggal = v; const h = document.getElementById('siaga_hari'); if(h) h.value = siagaHari(v); }
function siagaLokasiSarana(kat){
  const set = LOKASI_KENDARAAN.concat(kat==='Sapras Laut' ? LOKASI_LAUT_EXTRA : []);
  siagaLokasiOpsi('Sarana', kat).forEach(o=>{ if(!set.includes(o)) set.push(o); });
  return set;
}
function siagaBaru(jenis, sapras){ return {jenis:jenis||'Peralatan', sapras:sapras||'Sapras Darat', lokasi:'', pemeriksa:'', tim:'Tim 1', kegiatan:'', tanggal:todayISO(), rows:[], store:{}, extra:[], ttd:''}; }
/* Baris = data modul terpilih (disaring Sapras & Lokasi). Isian yang sudah diubah disimpan per item, jadi tidak hilang saat ganti Lokasi/Jenis. */
function siagaDraftSiap(){
  if(!siagaDraft) siagaDraft = siagaBaru('Peralatan');
  const d = siagaDraft;
  d.rows = [];
  siagaSumber(d.jenis, d.sapras).forEach(src=>{
    const key = d.jenis+'|'+src.ref;
    const cur = d.store[key];
    if(cur){
      const belumUbah = !siagaBerubah(cur);
      cur.nama = src.nama; cur.lok = src.lok; cur.j0 = src.j0; cur.k0 = src.k0; cur.b0 = src.b0; cur.r0 = src.r0; cur.h0 = src.h0;
      cur.nopol = src.nopol; cur.jenisK = src.jenisK; cur.v0 = src.v0;
      if(belumUbah){ cur.jumlah = src.jumlah; cur.kondisi = src.kondisi; cur.baik = src.baik; cur.rusak = src.rusak; cur.hilang = src.hilang; cur.vals = src.vals; }
    } else d.store[key] = src;
    if(!d.lokasi || d.store[key].lok===d.lokasi) d.rows.push(d.store[key]);
  });
  if(!siagaIsSarana(d.jenis)) (d.extra||[]).forEach(r=>d.rows.push(r));
  return d;
}
function siagaGantiJenis(j){
  const d = siagaDraft; if(!d || d.jenis===j) return;
  d.jenis = j; d.lokasi = '';
  renderSiaga();
}
function siagaGantiSapras(k){
  const d = siagaDraft; if(!d || d.sapras===k) return;
  d.sapras = k; d.lokasi = '';
  renderSiaga();
}
function siagaGantiLokasi(v){ if(siagaDraft){ siagaDraft.lokasi = v; renderSiaga(); } }
function siagaLokasiOpsi(jenis, kat){
  const sar = siagaIsSarana(jenis);
  const set = [];
  (DB[sar ? 'kendaraan' : 'peralatan']||[]).forEach(r=>{
    if(sar && saprasKategori(r)!==kat) return;
    if(r.lokasi && !set.includes(r.lokasi)) set.push(r.lokasi);
  });
  return set;
}
function siagaKondisiBadge(k){
  return '<span class="badge '+(k==='Baik' ? 'b-ok' : (k==='Rusak Ringan' ? 'b-warn' : 'b-bad'))+'">'+esc(k||'-')+'</span>';
}
function siagaJenisLabel(r){
  if(!siagaIsSarana(r.jenis)) return 'Peralatan';
  return 'Sarana' + (r.sapras ? ' — '+r.sapras : '');
}
function renderSiaga(){
  const d = siagaDraft = siagaDraftSiap();
  const ro = modulReadOnly('siaga_rutin');
  const posOpsi = siagaIsSarana(d.jenis) ? SIAGA_POSISI : SIAGA_POSISI_ALAT;
  const opt = (list, v) => list.map(o=>`<option value="${esc(o)}" ${o===v?'selected':''}>${esc(o)}</option>`).join('');
  const riwayat = (DB.siaga_rutin||[]).slice().reverse();
  const sar = siagaIsSarana(d.jenis);
  const laut = sar && d.sapras==='Sapras Laut';
  const kolom = sar ? siagaSaprasKolom(d.sapras) : [];
  const nCol = sar ? (3 + 1 + kolom.length + 2 + (laut?0:1)) : 6;
  const inputKolom = (f, i, r) => {
    const isNum = f.type==='number';
    const v = r.vals && r.vals[f.key]!==undefined ? r.vals[f.key] : '';
    const bbmDarat = f.key==='bbm' && !laut;
    const onIn = bbmDarat ? `siagaBbmNote(${i},this.value)` : `siagaDraft.rows[${i}].vals['${f.key}']=this.value`;
    const pct = bbmDarat || f.key==='persen_bbm';
    const inp = `<input type="${isNum?'number':(f.type==='date'?'date':'text')}" ${isNum?`step="${f.decimal?'any':'1'}" min="0"${pct?' max="100"':''}`:''} style="min-width:${isNum?'6':(f.type==='date'?'10':'8')}rem;${pct?'padding-right:2rem;':''}" placeholder="${esc(f.placeholder||'')}" aria-label="${esc(f.label)} ${esc(r.nama)}" value="${esc(v)}" oninput="${onIn}" ${ro?'disabled':''}>`;
    const inpPct = pct ? `<div style="position:relative;">${inp}<span aria-hidden="true" style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--muted);">%</span></div>` : inp;
    return `<td>${inpPct}${bbmDarat ? `<div id="siaga_bbm_note_${i}" class="mt-1">${siagaBbmBadge(v)}</div>` : ''}</td>`;
  };
  document.getElementById('app').innerHTML = `
    <div class="panel rounded-xl p-4 mb-4">
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <h3 class="font-display font-semibold text-base">Form Pengecekan Siaga Rutin</h3>
      </div>
      <div class="flex flex-wrap items-end gap-3 mb-4">
        <div>
          <label class="block text-sm mb-1" for="siaga_jenis">Jenis Pengecekan:</label>
          <select id="siaga_jenis" style="width:auto;min-width:12rem;" onchange="siagaGantiJenis(this.value)" ${ro?'disabled':''}>
            <option value="Peralatan" ${!sar?'selected':''}>Peralatan</option>
            <option value="Sarana" ${sar?'selected':''}>Sarana</option>
          </select>
        </div>
        ${sar ? `<div>
          <div class="block text-sm mb-1">Sarana:</div>
          <div class="flex flex-wrap gap-2" id="siaga_sapras">
            ${SIAGA_SAPRAS.map(k=>`<button type="button" class="btn ${d.sapras===k?'btn-primary':'btn-ghost'}" onclick="siagaGantiSapras('${k}')" ${ro?'disabled':''}>${k}</button>`).join('')}
          </div>
        </div>` : ''}
        ${''}
        <span class="text-xs pb-2" style="color:var(--muted);">${sar ? 'Daftar diambil dari modul Kendaraan ('+d.sapras+') ('+d.rows.length+' data)' : 'Daftar peralatan siaga ('+d.rows.length+' item)'}</span>
      </div>
      ${sar ? `<div class="grid sm:grid-cols-3 gap-3 mb-3">
        <div>
          <label class="block text-sm mb-1" for="siaga_tanggal">Tanggal</label>
          <input id="siaga_tanggal" type="date" value="${esc(d.tanggal)}" onchange="siagaGantiTanggal(this.value)" ${ro?'disabled':''}>
        </div>
        <div>
          <label class="block text-sm mb-1" for="siaga_hari">Hari</label>
          <input id="siaga_hari" type="text" value="${esc(siagaHari(d.tanggal))}" readonly>
        </div>
        <div>
          <label class="block text-sm mb-1" for="siaga_tim">Tim</label>
          <input id="siaga_tim" type="text" placeholder="Nama tim, mis. Tim 1" value="${esc(d.tim||'')}" oninput="siagaDraft.tim=this.value" ${ro?'disabled':''}>
        </div>
      </div>
      <div class="grid sm:grid-cols-2 gap-3 mb-3">
        <div>
          <label class="block text-sm mb-1" for="siaga_pemeriksa">Pemeriksa (opsional)</label>
          <input id="siaga_pemeriksa" type="text" placeholder="Nama pemeriksa" value="${esc(d.pemeriksa)}" oninput="siagaDraft.pemeriksa=this.value" ${ro?'disabled':''}>
        </div>
        <div>
          <label class="block text-sm mb-1" for="siaga_kegiatan">Kegiatan (opsional)</label>
          <input id="siaga_kegiatan" type="text" placeholder="Contoh: pemanasan dan memeriksa kendaraan operasional" value="${esc(d.kegiatan)}" oninput="siagaDraft.kegiatan=this.value" ${ro?'disabled':''}>
        </div>
      </div>
      <div class="grid sm:grid-cols-2 gap-3 mb-1">
        <div>
          <label class="block text-sm mb-1" for="siaga_lokasi">Unit / Lokasi</label>
          <select id="siaga_lokasi" onchange="siagaGantiLokasi(this.value)" ${ro?'disabled':''}>
            <option value="">Semua Lokasi</option>
            ${siagaLokasiSarana(d.sapras).map(o=>`<option value="${esc(o)}" ${d.lokasi===o?'selected':''}>${esc(o)}</option>`).join('')}
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1" for="siaga_lokasi_pilih">Lokasi terpilih</label>
          <input id="siaga_lokasi_pilih" type="text" value="${esc(d.lokasi || 'Semua Lokasi')}" readonly>
        </div>
      </div>
      <p class="text-sm mb-4">Pilih USS/POS untuk menampilkan kendaraan sesuai lokasi.</p>
      <hr class="mb-4" style="border-color:var(--line, rgba(128,128,128,.3));">` : `      <div class="grid sm:grid-cols-3 gap-3 mb-4">
        <div class="sm:col-span-2">
          <label class="block text-sm mb-1" for="siaga_pemeriksa">Nama Pemeriksa / Tim:</label>
          <input id="siaga_pemeriksa" type="text" placeholder="Contoh: Komandan Tim" value="${esc(d.pemeriksa)}" oninput="siagaDraft.pemeriksa=this.value" ${ro?'disabled':''}>
        </div>
        <div>
          <label class="block text-sm mb-1" for="siaga_tanggal">Tanggal Pengecekan:</label>
          <input id="siaga_tanggal" type="date" value="${esc(d.tanggal)}" onchange="siagaDraft.tanggal=this.value" ${ro?'disabled':''}>
        </div>
      </div>`}
      ${sar ? '' : `<style>
        .siaga-alat table{border-collapse:collapse;color:#111;}
        .siaga-alat th{background:#fff;color:#111;text-align:center;font-weight:700;border:1px solid #9b1c1c;}
        .siaga-alat td{border:1px solid #9b1c1c;color:#111;}
        .siaga-alat tbody tr:nth-child(odd) td{background:#fff;}
        .siaga-alat tbody tr:nth-child(even) td{background:#fff;}
        .siaga-alat td:first-child{text-align:center;}
        .siaga-alat td input,.siaga-alat td select{background:transparent;color:#111;}
      </style>`}
      <div class="tablewrap ${sar?'':'siaga-alat'}">
        <table>
          <thead><tr><th>No.</th><th>${sar?'Nama Kendaraan':'Nama Alat'}</th>${sar ? `<th>${laut?'No Lambung':'Nomor Polisi / Registrasi'}</th><th>Jenis Kendaraan</th>${kolom.map(f=>`<th>${esc(f.label)}</th>`).join('')}` : '<th>Jumlah</th>'}${sar ? '' : '<th>Posisi</th>'}${(sar&&!laut)?'<th>Kebersihan</th>':''}<th>Kondisi</th><th>Keterangan/Masalah</th></tr></thead>
          <tbody>
            ${d.rows.length ? d.rows.map((r,i)=> sar ? `<tr>
              <td>${i+1}</td>
              <td class="font-medium">${esc(r.nama)}</td>
              <td>${esc(r.nopol||'-')}</td>
              <td>${esc(r.jenisK||'-')}</td>
              ${kolom.map(f=>inputKolom(f, i, r)).join('')}
              ${laut ? '' : `<td><select style="width:auto;min-width:5rem;" aria-label="Kebersihan ${esc(r.nama)}" onchange="siagaDraft.rows[${i}].bersih=this.value" ${ro?'disabled':''}><option value="Ya" ${(r.bersih||'Ya')==='Ya'?'selected':''}>Ya</option><option value="Tidak" ${r.bersih==='Tidak'?'selected':''}>Tidak</option></select></td>`}
              <td><select style="width:auto;min-width:8rem;" aria-label="Kondisi ${esc(r.nama)}" onchange="siagaDraft.rows[${i}].kondisi=this.value" ${ro?'disabled':''}>${opt(SIAGA_KONDISI, r.kondisi)}</select></td>
              <td><input type="text" placeholder="Opsional..." style="min-width:12rem;" value="${esc(r.ket)}" aria-label="Keterangan ${esc(r.nama)}" oninput="siagaDraft.rows[${i}].ket=this.value" ${ro?'disabled':''}></td>
            </tr>` : `<tr>
              <td>${i+1}</td>
              <td class="font-medium">${r.extra ? `<div class="flex items-center gap-1"><input type="text" id="siaga_nama_${i}" placeholder="Nama alat..." style="min-width:10rem;" aria-label="Nama alat tambahan ${i+1}" value="${esc(r.nama)}" oninput="siagaDraft.rows[${i}].nama=this.value" ${ro?'disabled':''}>${ro?'':`<button type="button" class="btn btn-ghost !px-2 !py-1" title="Hapus baris ini" aria-label="Hapus baris tambahan" onclick="siagaHapusTambah(${i})">&times;</button>`}</div>` : esc(r.nama)}</td>
              <td style="text-align:center;"><input type="number" min="0" step="1" style="width:5rem;text-align:center;" aria-label="Jumlah ${esc(r.nama)}" value="${r.jumlah}" oninput="siagaJumlahAlat(${i},this)" ${ro?'disabled':''}></td>
              <td><select style="min-width:11rem;" aria-label="Posisi ${esc(r.nama)}" onchange="siagaDraft.rows[${i}].posisi=this.value" ${ro?'disabled':''}>${opt(posOpsi.includes(r.posisi)?posOpsi:posOpsi.concat([r.posisi]), r.posisi)}</select></td>
              <td><select style="width:auto;min-width:8rem;" aria-label="Kondisi ${esc(r.nama)}" onchange="siagaDraft.rows[${i}].kondisi=this.value" ${ro?'disabled':''}>${opt(SIAGA_KONDISI, r.kondisi)}</select></td>
              <td><input type="text" placeholder="Opsional..." style="min-width:12rem;" value="${esc(r.ket)}" aria-label="Keterangan ${esc(r.nama)}" oninput="siagaDraft.rows[${i}].ket=this.value" ${ro?'disabled':''}></td>
            </tr>`).join('') : `<tr><td colspan="${nCol}" class="text-center py-6" style="color:var(--muted);">Belum ada data ${sar?'Kendaraan '+d.sapras:'Peralatan'}${sar&&d.lokasi?' di '+esc(d.lokasi):''}. Tambahkan dulu di menu ${sar?'Kendaraan':'Peralatan'}.</td></tr>`}
          </tbody>
        </table>
      </div>
      <div class="flex flex-wrap items-center justify-between gap-2 mt-4">
        ${(sar || ro) ? '<span></span>' : '<button class="btn btn-ghost" onclick="siagaTambah()">+ Tambah Data</button>'}
        <div class="flex flex-wrap gap-2">
          <button class="btn btn-ghost" onclick="siagaUnduhPdf()">⬇ Unduh PDF</button>
          ${ro ? '' : '<button class="btn btn-primary" onclick="siagaSimpan()">Simpan Pengecekan</button>'}
        </div>
      </div>
    </div>
    ${sar ? '' : `
    <div class="panel rounded-xl p-4 mb-4">
      <div class="flex flex-wrap items-center gap-2 mb-1">
        <h3 class="font-display font-semibold text-base">Tanda Tangan Pemeriksa</h3>
        <span class="text-xs" style="color:var(--muted);">${ro ? 'Mode lihat saja' : 'Tanda tangan di kotak dengan jari / mouse'}</span>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Tanda tangan ikut tersimpan di riwayat dan tercetak pada PDF.</p>
      <canvas id="siaga_ttd" width="600" height="200" aria-label="Kotak tanda tangan pemeriksa" style="width:100%;max-width:420px;aspect-ratio:3/1;background:#fff;border:2px dashed #9aa3b2;border-radius:10px;touch-action:none;cursor:${ro?'not-allowed':'crosshair'};display:block;"></canvas>
      <div class="flex flex-wrap items-center gap-2 mt-3">
        ${ro ? '' : '<button type="button" class="btn btn-ghost" onclick="siagaTtdHapus()">Hapus Tanda Tangan</button>'}
        <span class="text-sm font-medium">${esc(d.pemeriksa || '')}</span>
      </div>
    </div>`}
    <div class="panel rounded-xl p-4">
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <h3 class="font-display font-semibold text-base">Riwayat Pengecekan</h3>
        <span class="text-xs" style="color:var(--muted);">${riwayat.length} data</span>
      </div>
      <div class="tablewrap">
        <table>
          <thead><tr><th>ID</th><th>Jenis</th><th>Lokasi</th><th>Tanggal</th><th>Waktu</th><th>Pemeriksa / Tim</th><th>Dicek</th><th>Kondisi Baik</th><th>Bermasalah</th><th></th></tr></thead>
          <tbody>
            ${riwayat.length ? riwayat.map(r=>{
              const it = r.items||[], bad = it.filter(x=>x.kondisi!=='Baik').length;
              return `<tr style="cursor:pointer;" onclick="siagaDetail('${esc(r.id)}')">
                <td class="font-medium">${esc(r.id)}</td><td>${esc(siagaJenisLabel(r))}</td><td>${esc(r.lokasi||'-')}</td><td>${esc(tglDMY(r.tanggal))}</td><td>${esc(r.waktu||'')}</td><td>${esc(r.pemeriksa || r.tim || '-')}</td>
                <td>${it.length}</td><td>${it.length-bad}</td>
                <td>${bad ? `<span class="badge b-bad">${bad} item</span>` : `<span class="badge b-ok">Semua baik</span>`}</td>
                <td class="whitespace-nowrap" onclick="event.stopPropagation()">
                  <button class="btn btn-ghost !px-2 !py-1" onclick="siagaUnduhPdfRiwayat('${esc(r.id)}')">PDF</button>
                  ${ro ? '' : `<button id="siaga_wa_${esc(r.id)}" class="btn btn-ghost !px-2 !py-1" onclick="siagaKirimWA('${esc(r.id)}')" title="Bagikan PDF lewat WhatsApp (pilih grup/kontak sendiri)">Send WA</button>`}
                  <button class="btn btn-ghost !px-2 !py-1" onclick="siagaDetail('${esc(r.id)}')">Detail</button>
                  ${(isApprover() && !ro) ? `<button class="btn btn-ghost !px-2 !py-1" onclick="siagaHapus('${esc(r.id)}')">Hapus</button>` : ''}
                </td></tr>`;
            }).join('') : `<tr><td colspan="10" class="text-center py-6" style="color:var(--muted);">Belum ada riwayat pengecekan.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
  enhanceTableScroll();
  siagaTtdInit();
}
/* Tambah Data: baris alat baru di bawah daftar (tidak mengubah modul Peralatan, hanya tercatat di riwayat pengecekan) */
function siagaTambah(){
  const d = siagaDraft; if(!d || siagaIsSarana(d.jenis) || guardRO('siaga_rutin')) return;
  if(!Array.isArray(d.extra)) d.extra = [];
  d.extra.push({ref:'TMB-'+Date.now()+'-'+d.extra.length, recId:'', extra:true, nama:'', nopol:'', jenisK:'', vals:{}, v0:{}, lok:'',
    jumlah:0, posisi:SIAGA_POSISI_ALAT[0], kondisi:'Baik', ket:'', j0:0, k0:'Baik', baik:0, rusak:0, hilang:0, b0:0, r0:0, h0:0});
  renderSiaga();
  const el = document.getElementById('siaga_nama_'+(siagaDraft.rows.length-1)); if(el) el.focus();
}
function siagaHapusTambah(i){
  const d = siagaDraft; if(!d || guardRO('siaga_rutin')) return;
  const r = d.rows[i]; if(!r || !r.extra) return;
  d.extra = (d.extra||[]).filter(x=>x!==r);
  renderSiaga();
}
function siagaReset(){ const j = siagaDraft ? siagaDraft.jenis : 'Peralatan', l = siagaDraft ? siagaDraft.lokasi : '', s = siagaDraft ? siagaDraft.sapras : 'Sapras Darat'; siagaDraft = siagaBaru(j, s); siagaDraft.lokasi = l; renderSiaga(); }
function siagaSimpan(){ if(guardRO('siaga_rutin')) return;
  const d = siagaDraft; if(!d) return;
  const sar = siagaIsSarana(d.jenis);
  const nmJenis = sar ? 'Kendaraan '+d.sapras : 'Peralatan';
  if(!sar && !String(d.pemeriksa||'').trim()){ toast('Nama Pemeriksa / Tim wajib diisi'); const el = document.getElementById('siaga_pemeriksa'); if(el) el.focus(); return; }
  if(!d.tanggal){ toast('Tanggal pengecekan wajib diisi'); return; }
  const kosong = d.rows.findIndex(r=>r.extra && !String(r.nama||'').trim());
  if(kosong>=0){ toast('Nama alat pada baris tambahan wajib diisi'); const el = document.getElementById('siaga_nama_'+kosong); if(el) el.focus(); return; }
  if(!d.rows.length){ toast('Tidak ada data '+nmJenis+(d.lokasi?' di '+d.lokasi:'')+' untuk dicek'); return; }
  if(!Array.isArray(DB.siaga_rutin)) DB.siaga_rutin = [];
  const now = new Date();
  DB.siaga_rutin.push(Object.assign({
    id: uid('SGA', DB.siaga_rutin), jenis: d.jenis, lokasi: (sar ? d.lokasi : '') || 'Semua Lokasi', tanggal: d.tanggal,
    waktu: String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0'),
    pemeriksa: String(d.pemeriksa||'').trim(), ttd: sar ? '' : (d.ttd || ''),
    items: d.rows.map(r=>Object.assign({ref:r.ref, nama:String(r.nama||'').trim(), jumlah:r.jumlah, posisi:r.posisi, kondisi:r.kondisi, ket:String(r.ket||'').trim()},
      sar ? {nopol:r.nopol, jenisK:r.jenisK, vals:Object.assign({}, r.vals), bersih:r.bersih||'Ya'} : siagaAlatAngka(r)))
  }, sar ? {sapras:d.sapras, hari:siagaHari(d.tanggal), tim:d.tim, kegiatan:String(d.kegiatan||'').trim()} : {}));
  const nUbah = siagaTerapkanKeModul(d);
  persist(DB);
  siagaDraft = siagaBaru(d.jenis, d.sapras); siagaDraft.lokasi = d.lokasi; siagaDraft.tim = d.tim;
  toast('Pengecekan siaga tersimpan' + (nUbah ? ' · '+nUbah+' data '+nmJenis+' diperbarui' : ''));
  renderSiaga();
}
/* Hubungkan hasil pengecekan ke modul Peralatan / Kendaraan. Hanya baris yang BERUBAH dari data modul yang diterapkan.
   Peralatan : Jumlah Baik / Rusak / Hilang hasil pengecekan menggantikan nilai di modul (Jumlah modul = Baik + Rusak).
   Sarana    : Baik -> Serviceable; Rusak Ringan/Berat/Hilang -> Unserviceable (Scrap tetap bila belum diubah);
               isian KM/BBM (Darat) atau Endurance/Speed/Range dst. (Laut) yang diubah ikut menggantikan nilai di modul Kendaraan.
   Keterangan pengecekan ditambahkan ke kolom Ket modul. */
function siagaTerapkanKeModul(d){
  const kend = siagaIsSarana(d.jenis);
  const arr = DB[kend ? 'kendaraan' : 'peralatan'] || [];
  const tgl = tglDMY(d.tanggal);
  const kolom = kend ? siagaSaprasKolom(d.sapras) : [];
  let n = 0;
  d.rows.forEach(r=>{
    const rec = arr.find(x=>x.id===(kend ? r.ref : r.recId)); if(!rec) return;
    let ubah = false;
    if(kend){
      if(r.kondisi!==r.k0){
        const baru = r.kondisi==='Baik' ? 'Serviceable' : 'Unserviceable';
        if(rec.kondisi!==baru){ rec.kondisi = baru; ubah = true; }
      }
      kolom.forEach(f=>{
        const baru = r.vals[f.key], lama = r.v0[f.key];
        if(String(baru)===String(lama) || baru==='' || baru==null) return;
        if(f.type==='number' && !(parseFloat(baru)>0)) return;
        if(f.type==='number'){ const num = parseFloat(baru); rec[f.key] = (baru==='' || isNaN(num)) ? '' : num; }
        else rec[f.key] = String(baru||'').trim();
        ubah = true;
      });
    } else if(r.jumlah>0 && r.kondisi!=='Baik'){
      // Rusak Ringan / Rusak Berat / Hilang -> otomatis tercatat di modul Peralatan (total unit tetap)
      const b0 = parseInt(rec.jumlah_baik,10)||0, r0 = parseInt(rec.jumlah_rusak,10)||0, h0 = parseInt(rec.jumlah_hilang,10)||0;
      const total = Math.max(b0+r0+h0, r.jumlah);
      let rusak = r0, hilang = h0;
      if(r.kondisi==='Hilang') hilang = Math.min(r.jumlah, total); else rusak = Math.min(r.jumlah, total);
      if(rusak+hilang>total) { if(r.kondisi==='Hilang') rusak = total-hilang; else hilang = total-rusak; }
      rec.jumlah_baik = total - rusak - hilang; rec.jumlah_rusak = rusak; rec.jumlah_hilang = hilang; rec.jumlah = rec.jumlah_baik + rec.jumlah_rusak;
      const cat = '[Cek Siaga '+tgl+'] '+r.kondisi+': '+r.jumlah+' unit';
      if(!String(rec.keterangan||'').includes(cat)) rec.keterangan = (rec.keterangan ? rec.keterangan+'\n' : '') + cat;
      ubah = true;
    }
    if(String(r.ket||'').trim()){
      const cat = '[Cek Siaga '+tgl+'] '+String(r.ket).trim();
      if(!String(rec.keterangan||'').includes(cat)){ rec.keterangan = (rec.keterangan ? rec.keterangan+'\n' : '') + cat; ubah = true; }
    }
    if(ubah) n++;
  });
  return n;
}
/* ---- Tanda tangan pemeriksa (kotak gambar) ---- */
function siagaTtdInit(){
  const c = document.getElementById('siaga_ttd'); if(!c || !siagaDraft) return;
  const d = siagaDraft, ro = modulReadOnly('siaga_rutin');
  const ctx = c.getContext('2d');
  ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
  if(d.ttd){ const im = new Image(); im.onload = ()=>ctx.drawImage(im, 0, 0, c.width, c.height); im.src = d.ttd; }
  if(ro) return;
  let on = false, last = null;
  const pos = e => { const r = c.getBoundingClientRect(); return {x:(e.clientX-r.left)*c.width/r.width, y:(e.clientY-r.top)*c.height/r.height}; };
  c.addEventListener('pointerdown', e=>{
    e.preventDefault(); on = true; last = pos(e);
    try{ c.setPointerCapture(e.pointerId); }catch(_){}
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(last.x+0.01, last.y); ctx.stroke();
  });
  c.addEventListener('pointermove', e=>{
    if(!on) return; e.preventDefault();
    const p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p;
  });
  const selesai = ()=>{ if(!on) return; on = false; if(siagaDraft) siagaDraft.ttd = c.toDataURL('image/png'); };
  c.addEventListener('pointerup', selesai);
  c.addEventListener('pointercancel', selesai);
  c.addEventListener('pointerleave', selesai);
}
function siagaTtdHapus(){
  if(siagaDraft) siagaDraft.ttd = '';
  const c = document.getElementById('siaga_ttd'); if(c) c.getContext('2d').clearRect(0, 0, c.width, c.height);
}

/* ---- Unduh PDF Pengecekan Peralatan (template Kantor Pencarian dan Pertolongan Jakarta) ---- */
function siagaTglPanjang(iso){
  const B = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso||'')); return m ? (+m[3])+' '+B[+m[2]-1]+' '+m[1] : '';
}
function siagaPdfAlat(src){
  if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
  try{
    const doc = new window.jspdf.jsPDF({orientation:'portrait', unit:'pt', format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 32;
    const merah = [155,28,28], biru = [219,229,246];
    // Kop surat
    try{ doc.addImage(SIAGA_LOGO_BASARNAS, 'PNG', M, 26, 55, 70); }catch(e){}
    try{ doc.addImage(SIAGA_LOGO_SAR, 'PNG', W-M-70, 26, 70, 70); }catch(e){}
    doc.setTextColor(0); doc.setFont('helvetica','bold');
    doc.setFontSize(14); doc.text('PENGECEKAN PALSAR SIAGA RUTIN', W/2, 56, {align:'center'});
    doc.setFontSize(11); doc.text('KANTOR PENCARIAN DAN PERTOLONGAN JAKARTA', W/2, 76, {align:'center'});
    doc.setLineWidth(1.6); doc.setDrawColor(0); doc.line(M, 104, W-M, 104);
    // Diperiksa oleh
    doc.setFontSize(9); doc.text('Diperiksa Oleh Tim', M, 126);
    doc.text(':', M+90, 126);
    doc.setFont('helvetica','normal'); doc.text(String(src.pemeriksa||'-'), M+102, 126);
    // Tabel
    const body = (src.items||[]).map((x,i)=>[i+1, x.nama||'', x.jumlah==null?'':x.jumlah, x.posisi||'', x.kondisi||'', x.ket||'']);
    doc.autoTable({
      head:[['No','Nama Alat','Jumlah','Posisi','Kondisi','Keterangan']], body, startY:140, margin:{left:M, right:M},
      theme:'grid',
      styles:{font:'helvetica', fontSize:7.5, cellPadding:{top:3, bottom:3, left:4, right:4}, lineColor:merah, lineWidth:0.6, textColor:[30,30,30], valign:'middle'},
      headStyles:{fillColor:biru, textColor:0, fontStyle:'bold', fontSize:9.5, halign:'center'},
      alternateRowStyles:{fillColor:biru},
      bodyStyles:{fillColor:[255,255,255]},
      columnStyles:{0:{halign:'center', cellWidth:28}, 1:{cellWidth:165}, 2:{halign:'center', cellWidth:56}, 3:{cellWidth:125}, 4:{cellWidth:68}, 5:{cellWidth:'auto'}}
    });
    // Blok tanda tangan
    let y = (doc.lastAutoTable ? doc.lastAutoTable.finalY : 140) + 28;
    if(y + 135 > H - 30){ doc.addPage(); y = 60; }
    const x = W - M - 190;
    doc.setFont('helvetica','normal'); doc.setFontSize(10);
    doc.text('Jakarta, '+siagaTglPanjang(src.tanggal), x, y);
    doc.text('Pemeriksa,', x, y+14);
    if(src.ttd){ try{ doc.addImage(src.ttd, 'PNG', x, y+20, 150, 50); }catch(e){} }
    doc.setFont('helvetica','bold'); doc.text(String(src.pemeriksa||'-'), x, y+88);
    const n = new Date(), p2 = v=>String(v).padStart(2,'0');
    const stamp = n.getFullYear()+p2(n.getMonth()+1)+p2(n.getDate())+'-'+p2(n.getHours())+p2(n.getMinutes())+p2(n.getSeconds());
    return siagaSimpanPdf(doc, 'Laporan-Peralatan-'+stamp+(src.id ? '-'+src.id.replace(/[^A-Za-z0-9]/g,'') : '')+'.pdf', src);
  }catch(e){ console.error(e); toast('Gagal membuat PDF'); }
}
/* ---- Unduh PDF Sapras Darat: format "Laporan Pemeriksaan Kendaraan Operasional" ---- */
function siagaPdfDarat(src){
  try{
    const doc = new window.jspdf.jsPDF({orientation:'portrait', unit:'pt', format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 40;
    const abu = [230,230,230], bar = [238,238,238];
    const items = src.items||[];
    const pem = (src.pem!==undefined ? src.pem : (src.pemeriksa||'')) || '-';
    doc.setTextColor(0); doc.setFont('helvetica','bold'); doc.setFontSize(14);
    doc.text('LAPORAN PEMERIKSAAN KENDARAAN OPERASIONAL', W/2, 56, {align:'center'});
    const hari = src.hari || siagaHari(src.tanggal);
    const info = [['Hari/Tanggal', (hari?hari+', ':'')+siagaTglPanjang(src.tanggal)], ['Tim', src.tim||'-'], ['Pemeriksa', pem],
                  ['Unit/Lokasi', src.lokasi||'Semua Lokasi'], ['Kegiatan', src.kegiatan||'-']];
    doc.setFontSize(10);
    let y = 100;
    info.forEach(a=>{
      doc.setFont('helvetica','normal'); doc.text(a[0], M, y); doc.text(':', M+84, y);
      const t = doc.splitTextToSize(String(a[1]), W-M-(M+96)); doc.text(t, M+96, y); y += 15*t.length + 2;
    });
    const cek = (need)=>{ if(y+need > H-40){ doc.addPage(); y = 50; } };
    const judul = (t)=>{
      cek(40); doc.setFillColor(bar[0],bar[1],bar[2]); doc.rect(M, y, W-2*M, 18, 'F');
      doc.setFont('helvetica','bold'); doc.setFontSize(10); doc.text(t, M+4, y+12.5); y += 28;
    };
    const sub = (t)=>{ cek(60); doc.setFont('helvetica','bold'); doc.setFontSize(10); doc.text(t, M, y); y += 8; };
    const num = v => (v===undefined||v===null||v==='') ? '' : String(v);
    const tabel = (rows)=>{
      const body = rows.length ? rows.map((x,i)=>{
        const v = x.vals||{};
        return [i+1, x.nama||'', x.nopol||'', num(v.bbm)!=='' ? num(v.bbm)+'%' : '', num(v.km_saat_ini), x.bersih||'Ya', x.ket||''];
      }) : [['-','-','-','-','-','-','-']];
      doc.autoTable({
        head:[['No','Kendaraan','Plat','BBM','KM','Kebersihan','Keterangan']], body, startY:y, margin:{left:M, right:M}, theme:'grid',
        styles:{font:'helvetica', fontSize:8.5, cellPadding:{top:4.5, bottom:4.5, left:4, right:4}, lineColor:[0,0,0], lineWidth:0.6, textColor:0, valign:'middle'},
        headStyles:{fillColor:abu, textColor:0, fontStyle:'bold', fontSize:8.5, halign:'left'},
        bodyStyles:{fillColor:[255,255,255]},
        columnStyles:{0:{halign:'center', cellWidth:26}, 1:{cellWidth:120}, 2:{cellWidth:80}, 3:{halign:'center', cellWidth:38}, 4:{halign:'center', cellWidth:48}, 5:{halign:'center', cellWidth:56}, 6:{cellWidth:'auto'}},
        didParseCell:function(h){ if(h.section==='head' && h.column.index>=3 && h.column.index<=5) h.cell.styles.halign='center'; }
      });
      y = doc.lastAutoTable.finalY + 18;
    };
    const diperbaiki = items.filter(x=>x.kondisi && x.kondisi!=='Baik');
    const aktif = items.filter(x=>!(x.kondisi && x.kondisi!=='Baik'));
    const tersedia = aktif.filter(x=>siagaBbmStatus((x.vals||{}).bbm)==='BBM Tersedia');
    const habis = aktif.filter(x=>siagaBbmStatus((x.vals||{}).bbm)!=='BBM Tersedia');
    judul('PERSENTASE BAHAN BAKAR KENDARAAN');
    sub('Kendaraan dengan bahan bakar lebih dari 50%'); tabel(tersedia);
    sub('Kendaraan dengan bahan bakar di bawah 50%'); tabel(habis);
    judul('KENDARAAN SEDANG DIPERBAIKI');
    doc.setFont('helvetica','normal'); doc.setFontSize(10);
    if(!diperbaiki.length){ doc.text('-', M+4, y); y += 26; }
    else diperbaiki.forEach((x,i)=>{
      const t = doc.splitTextToSize((i+1)+'. '+(x.nama||'')+(x.nopol?' ('+x.nopol+')':'')+' — '+x.kondisi+(x.ket?' — '+x.ket:''), W-2*M-8);
      cek(14*t.length+8); doc.text(t, M+4, y); y += 14*t.length;
      if(i===diperbaiki.length-1) y += 12;
    });
    judul('DESKRIPSI LAINNYA');
    const n = new Date(), p2 = v=>String(v).padStart(2,'0');
    const stamp = n.getFullYear()+p2(n.getMonth()+1)+p2(n.getDate())+'_'+p2(n.getHours())+p2(n.getMinutes())+p2(n.getSeconds());
    return siagaSimpanPdf(doc, 'laporan_kendaraan_'+(src.id ? src.id.replace(/[^A-Za-z0-9]/g,'')+'_' : '')+stamp+'.pdf', src);
  }catch(e){ console.error(e); toast('Gagal membuat PDF'); }
}
function siagaPdfSarana(src){
  if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
  if(src.sapras!=='Sapras Laut') return siagaPdfDarat(src); // Sapras Darat: format Laporan Pemeriksaan Kendaraan Operasional
  try{
    const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight(), M = 28;
    const merah = [155,28,28], biru = [219,229,246];
    try{ doc.addImage(SIAGA_LOGO_BASARNAS, 'PNG', M, 20, 46, 58); }catch(e){}
    try{ doc.addImage(SIAGA_LOGO_SAR, 'PNG', W-M-58, 20, 58, 58); }catch(e){}
    doc.setTextColor(0); doc.setFont('helvetica','bold');
    doc.setFontSize(14); const laut = src.sapras==='Sapras Laut';
    doc.text('PENGECEKAN SARANA SIAGA RUTIN ('+(laut?'SAPRAS LAUT':'SAPRAS DARAT')+')', W/2, 44, {align:'center'});
    doc.setFontSize(11); doc.text('KANTOR PENCARIAN DAN PERTOLONGAN JAKARTA', W/2, 62, {align:'center'});
    doc.setLineWidth(1.6); doc.setDrawColor(0); doc.line(M, 88, W-M, 88);
    doc.setFontSize(9);
    const info = [['Diperiksa Oleh Tim', src.pemeriksa||'-'], ['Tanggal', (src.hari?src.hari+', ':'')+siagaTglPanjang(src.tanggal)], ['Unit / Lokasi', src.lokasi||'Semua Lokasi']];
    info.forEach((a,k)=>{ doc.setFont('helvetica','bold'); doc.text(a[0], M, 106+k*13); doc.text(':', M+90, 106+k*13); doc.setFont('helvetica','normal'); doc.text(String(a[1]), M+102, 106+k*13); });
    const items = src.items||[];
    let nHabis = 0, nAda = 0;
    const kolomL = laut ? siagaSaprasKolom('Sapras Laut') : [];
    const bodyL = items.map((x,i)=>{
      const v = x.vals||{};
      return [i+1, x.nama||'', x.nopol||'-', x.jenisK||'-'].concat(kolomL.map(f=>{
        const val = v[f.key]; if(val===undefined||val==='') return '-';
        return f.key==='persen_bbm' ? val+'%' : val;
      }), [x.kondisi||'', x.ket||'']);
    });
    const body = laut ? bodyL : items.map((x,i)=>{
      const v = x.vals||{}, st = siagaBbmStatus(v.bbm);
      if(st==='BBM Hampir Habis') nHabis++; else if(st==='BBM Tersedia') nAda++;
      const bv = (v.bbm===undefined||v.bbm==='') ? '-' : v.bbm+'%';
      return [i+1, x.nama||'', x.nopol||'-', x.jenisK||'-', (v.km_saat_ini===undefined||v.km_saat_ini==='')?'-':v.km_saat_ini, bv,
        st==='BBM Hampir Habis' ? 'BBM Hampir Habis ('+bv+')' : '', st==='BBM Tersedia' ? 'BBM Tersedia ('+bv+')' : '',
        x.kondisi||'', x.ket||''];
    });
    if(laut) doc.autoTable({
      head:[['No','Nama Kendaraan','No Lambung','Jenis Kendaraan'].concat(kolomL.map(f=>f.label), ['Kondisi','Keterangan'])],
      body, startY:148, margin:{left:M, right:M}, theme:'grid',
      styles:{font:'helvetica', fontSize:7, cellPadding:{top:3, bottom:3, left:3, right:3}, lineColor:merah, lineWidth:0.6, textColor:[30,30,30], valign:'middle'},
      headStyles:{fillColor:biru, textColor:0, fontStyle:'bold', fontSize:7.5, halign:'center'},
      alternateRowStyles:{fillColor:[255,255,255]}, bodyStyles:{fillColor:[255,255,255]},
      columnStyles:{0:{halign:'center', cellWidth:24}}
    });
    else doc.autoTable({
      head:[['No','Nama Kendaraan','Nomor Polisi','Jenis Kendaraan','KM Saat Ini','BBM','BBM Hampir Habis','BBM Tersedia','Kondisi','Keterangan']],
      body, startY:148, margin:{left:M, right:M}, theme:'grid',
      styles:{font:'helvetica', fontSize:7.5, cellPadding:{top:3, bottom:3, left:3, right:3}, lineColor:merah, lineWidth:0.6, textColor:[30,30,30], valign:'middle'},
      headStyles:{fillColor:biru, textColor:0, fontStyle:'bold', fontSize:8, halign:'center'},
      alternateRowStyles:{fillColor:[255,255,255]}, bodyStyles:{fillColor:[255,255,255]},
      columnStyles:{0:{halign:'center', cellWidth:24}, 4:{halign:'center'}, 5:{halign:'center', cellWidth:34}, 6:{halign:'center', cellWidth:78}, 7:{halign:'center', cellWidth:78}},
      didParseCell:function(h){
        if(h.section!=='body') return;
        if(h.column.index===6 && h.cell.raw){ h.cell.styles.fillColor=[254,226,226]; h.cell.styles.textColor=[153,27,27]; h.cell.styles.fontStyle='bold'; }
        if(h.column.index===7 && h.cell.raw){ h.cell.styles.fillColor=[220,252,231]; h.cell.styles.textColor=[22,101,52]; h.cell.styles.fontStyle='bold'; }
      }
    });
    let y = (doc.lastAutoTable ? doc.lastAutoTable.finalY : 148) + 14;
    doc.setFont('helvetica','normal'); doc.setFontSize(8.5);
    if(y+14 > H-30){ doc.addPage(); y = 50; }
    if(!laut){ doc.text('Total BBM Hampir Habis (< 50): '+nHabis+'   |   Total BBM Tersedia (>= 50): '+nAda, M, y); y += 20; }
    if(y + 115 > H - 24){ doc.addPage(); y = 50; }
    const x = W - M - 190;
    doc.setFontSize(10);
    doc.text('Jakarta, '+siagaTglPanjang(src.tanggal), x, y);
    doc.text('Pemeriksa,', x, y+14);
    if(src.ttd){ try{ doc.addImage(src.ttd, 'PNG', x, y+20, 150, 50); }catch(e){} }
    doc.setFont('helvetica','bold'); doc.text(String(src.pemeriksa||'-'), x, y+88);
    const n = new Date(), p2 = v=>String(v).padStart(2,'0');
    const stamp = n.getFullYear()+p2(n.getMonth()+1)+p2(n.getDate())+'-'+p2(n.getHours())+p2(n.getMinutes())+p2(n.getSeconds());
    return siagaSimpanPdf(doc, 'Laporan-Sarana-'+(laut?'Laut':'Darat')+'-'+stamp+(src.id ? '-'+src.id.replace(/[^A-Za-z0-9]/g,'') : '')+'.pdf', src);
  }catch(e){ console.error(e); toast('Gagal membuat PDF'); }
}
function siagaUnduhPdf(){
  const d = siagaDraft; if(!d) return;
  if(siagaIsSarana(d.jenis)){
    const items = d.rows.map(r=>({nama:String(r.nama||'').trim(), nopol:r.nopol, jenisK:r.jenisK, vals:Object.assign({}, r.vals), bersih:r.bersih||'Ya', kondisi:r.kondisi, ket:String(r.ket||'').trim()}));
    if(!items.length){ toast('Tidak ada data kendaraan untuk dicetak'); return; }
    siagaPdfSarana({pemeriksa:String(d.pemeriksa||'').trim() || d.tim || '-', pem:String(d.pemeriksa||'').trim(), tim:d.tim, kegiatan:String(d.kegiatan||'').trim(), tanggal:d.tanggal, hari:siagaHari(d.tanggal), lokasi:d.lokasi, items, ttd:d.ttd, sapras:d.sapras});
    return;
  }
  if(!String(d.pemeriksa||'').trim()){ toast('Nama Pemeriksa / Tim wajib diisi'); const el = document.getElementById('siaga_pemeriksa'); if(el) el.focus(); return; }
  const items = d.rows.filter(r=>String(r.nama||'').trim()).map(r=>({nama:String(r.nama).trim(), jumlah:r.jumlah, posisi:r.posisi, kondisi:r.kondisi, ket:String(r.ket||'').trim()}));
  if(!items.length){ toast('Tidak ada data peralatan untuk dicetak'); return; }
  siagaPdfAlat({pemeriksa:String(d.pemeriksa).trim(), tanggal:d.tanggal, items, ttd:d.ttd});
}
/* Simpan PDF ke perangkat (unduh) ATAU, bila src.blob=true, kembalikan {blob,name} untuk dikirim ke grup WhatsApp. */
function siagaSimpanPdf(doc, nama, src){
  if(src && src.blob) return {blob: doc.output('blob'), name: nama};
  doc.save(nama);
}
function siagaPdfDariRiwayat(r, blob){
  if(siagaIsSarana(r.jenis))
    return siagaPdfSarana({pemeriksa:r.pemeriksa || r.tim || '-', pem:r.pemeriksa||'', tim:r.tim||'', kegiatan:r.kegiatan||'', tanggal:r.tanggal, hari:r.hari, lokasi:r.lokasi, items:r.items||[], ttd:r.ttd||'', id:r.id, sapras:r.sapras, blob});
  return siagaPdfAlat({pemeriksa:r.pemeriksa || r.tim || '-', tanggal:r.tanggal, items:r.items||[], ttd:r.ttd||'', id:r.id, blob});
}
/* Teks pembuka yang menyertai PDF saat dibagikan ke WhatsApp. */
function siagaPesanWA(r){
  const it = r.items||[], bad = it.filter(x=>x.kondisi!=='Baik').length;
  const jenis = siagaIsSarana(r.jenis) ? 'Sarana'+(r.sapras?' — '+r.sapras:'') : 'Peralatan';
  return '*PENGECEKAN SIAGA RUTIN*\n\nID: '+r.id+'\nJenis: '+jenis+'\nLokasi: '+(r.lokasi||'-')+'\nTanggal: '+(siagaTglPanjang(r.tanggal)||'-')+(r.waktu?' '+r.waktu+' WIB':'')
    +'\nPemeriksa/Tim: '+(r.pemeriksa||r.tim||'-')+'\nDicek: '+it.length+' | Baik: '+(it.length-bad)+' | Bermasalah: '+bad;
}
/* Tombol "Send WA": membuka WhatsApp, pengguna memilih sendiri grup/kontak tujuan (tanpa ID grup).
   1) HP/browser yang mendukung berbagi file: menu bagikan bawaan -> pilih WhatsApp -> pilih grup, PDF ikut terlampir.
   2) Selain itu (mis. komputer): PDF diunggah ke server, WhatsApp dibuka dengan teks + tautan unduh PDF, lalu pilih tujuan. */
let siagaWaSibuk = {};
async function siagaKirimWA(id){
  if(guardRO('siaga_rutin')) return;
  const r = (DB.siaga_rutin||[]).find(x=>x.id===id); if(!r || siagaWaSibuk[id]) return;
  if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
  const out = siagaPdfDariRiwayat(r, true);
  if(!out || !out.blob){ toast('Gagal membuat PDF'); return; }
  const pesan = siagaPesanWA(r);
  let file = null;
  try{ file = new File([out.blob], out.name, {type:'application/pdf'}); }catch(e){}
  // 1) Menu bagikan bawaan perangkat (harus dipanggil langsung dari klik)
  if(file && navigator.canShare && navigator.share && navigator.canShare({files:[file]})){
    try{ await navigator.share({files:[file], text:pesan, title:'Pengecekan Siaga '+id}); }
    catch(e){ if(e && e.name!=='AbortError'){ console.error(e); toast('Gagal membuka menu bagikan'); } }
    return;
  }
  // 2) Cadangan: tautan unduh PDF lewat WhatsApp Web / aplikasi desktop
  const w = window.open('', '_blank'); // dibuka saat klik agar tidak diblokir pop-up blocker
  siagaWaSibuk[id] = true;
  const btn = document.getElementById('siaga_wa_'+id); if(btn){ btn.disabled = true; btn.textContent = 'Menyiapkan…'; }
  try{
    await flushPersist(); // pastikan riwayat ini sudah ada di server
    const fd = new FormData(); fd.append('id', id); fd.append('file', out.blob, out.name);
    const res = await fetch(API_URL + '?action=siaga_bagikan', {method:'POST', headers:{'X-Requested-With':'basarnas'}, body: fd});
    const j = await res.json().catch(()=>({}));
    if(!(res.ok && j.ok)){ if(w) w.close(); toast(j.error || ('Gagal menyiapkan PDF (kode '+res.status+')')); return; }
    const u = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(j.pesan || pesan + '\n\n' + j.url);
    if(w) w.location.href = u; else window.location.href = u;
  }catch(e){
    console.error(e); if(w) w.close(); toast('Koneksi gagal, PDF belum disiapkan');
  }finally{
    delete siagaWaSibuk[id];
    const b2 = document.getElementById('siaga_wa_'+id); if(b2){ b2.disabled = false; b2.textContent = 'Send WA'; }
  }
}
function siagaUnduhPdfRiwayat(id){
  const r = (DB.siaga_rutin||[]).find(x=>x.id===id); if(!r) return;
  if(siagaIsSarana(r.jenis)){
    siagaPdfSarana({pemeriksa:r.pemeriksa || r.tim || '-', pem:r.pemeriksa||'', tim:r.tim||'', kegiatan:r.kegiatan||'', tanggal:r.tanggal, hari:r.hari, lokasi:r.lokasi, items:r.items||[], ttd:r.ttd||'', id:r.id, sapras:r.sapras});
    return;
  }
  siagaPdfAlat({pemeriksa:r.pemeriksa || r.tim || '-', tanggal:r.tanggal, items:r.items||[], ttd:r.ttd||'', id:r.id});
}
function siagaDetail(id){
  const r = (DB.siaga_rutin||[]).find(x=>x.id===id); if(!r) return;
  const sar = siagaIsSarana(r.jenis);
  const it = r.items||[];
  const laut = r.sapras==='Sapras Laut';
  const keys = [];
  it.forEach(x=>Object.keys(x.vals||{}).forEach(k=>{ if(!keys.includes(k)) keys.push(k); }));
  const adaNopol = sar && it.some(x=>x.nopol!==undefined);
  const adaBbm = sar && !laut && keys.includes('bbm');
  const adaPos = it.some(x=>x.posisi);
  const adaBJH = false; // Peralatan: format ringkas (Jumlah, Posisi, Kondisi, Keterangan)
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-display font-semibold text-base">Detail Pengecekan ${esc(r.id)}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-5 gap-3 text-sm mb-4">
        <div><div class="text-xs" style="color:var(--muted);">Jenis</div>${esc(siagaJenisLabel(r))}</div>
        <div><div class="text-xs" style="color:var(--muted);">Lokasi</div>${esc(r.lokasi||'-')}</div>
        <div><div class="text-xs" style="color:var(--muted);">Pemeriksa / Tim</div>${esc([r.pemeriksa, r.tim].filter(Boolean).join(' · ') || '-')}</div>
        <div><div class="text-xs" style="color:var(--muted);">Tanggal</div>${esc((r.hari ? r.hari+', ' : '')+tglDMY(r.tanggal))}</div>
        <div><div class="text-xs" style="color:var(--muted);">Waktu Input</div>${esc(r.waktu||'-')}</div>
        ${r.kegiatan ? `<div class="sm:col-span-5"><div class="text-xs" style="color:var(--muted);">Kegiatan</div>${esc(r.kegiatan)}</div>` : ''}
      </div>
      <div class="tablewrap"><table>
        <thead><tr><th>No.</th><th>${sar?'Nama Kendaraan':'Nama Alat'}</th>${adaNopol?`<th>${laut?'No Lambung':'Nomor Polisi / Registrasi'}</th><th>Jenis Kendaraan</th>`:''}${keys.map(k=>`<th>${esc(siagaFieldLabel(k, r.sapras))}</th>`).join('')}${(!adaNopol)?'<th>Jumlah</th>':''}${adaBJH?'<th>Baik</th><th>Rusak</th><th>Hilang</th>':''}${adaBbm?'<th>BBM Hampir Habis</th><th>BBM Tersedia</th>':''}${adaPos?'<th>Posisi</th>':''}<th>Kondisi</th><th>Keterangan/Masalah</th></tr></thead>
        <tbody>${it.map((x,i)=>`<tr><td>${i+1}</td><td class="font-medium">${esc(x.nama)}</td>${adaNopol?`<td>${esc(x.nopol||'-')}</td><td>${esc(x.jenisK||'-')}</td>`:''}${keys.map(k=>`<td>${esc((x.vals&&x.vals[k]!==undefined&&x.vals[k]!=='')?(/^\d{4}-\d{2}-\d{2}$/.test(String(x.vals[k]))?tglDMY(x.vals[k]):x.vals[k]+((k==='bbm'&&adaBbm)||k==='persen_bbm'?'%':'')):'-')}</td>`).join('')}${(!adaNopol)?`<td>${esc(x.jumlah)}</td>`:''}${adaBJH?(x.baik!==undefined?`<td>${esc(x.baik)}</td><td>${esc(x.rusak)}</td><td>${esc(x.hilang)}</td>`:'<td>-</td><td>-</td><td>-</td>'):''}${adaBbm?`<td>${siagaBbmStatus(x.vals&&x.vals.bbm)==='BBM Hampir Habis'?'<span class="badge b-bad">BBM Hampir Habis</span>':'-'}</td><td>${siagaBbmStatus(x.vals&&x.vals.bbm)==='BBM Tersedia'?'<span class="badge b-ok">BBM Tersedia</span>':'-'}</td>`:''}${adaPos?`<td>${esc(x.posisi)}</td>`:''}<td>${siagaKondisiBadge(x.kondisi)}</td><td>${esc(x.ket||'-')}</td></tr>`).join('')}</tbody>
      </table></div>
      <div class="flex justify-end mt-4"><button class="btn btn-ghost" onclick="closeModal()">Tutup</button></div>
    </div>`, true);
}
function siagaHapus(id){ if(guardRO('siaga_rutin')) return;
  if(!confirm('Hapus riwayat pengecekan '+id+'?')) return;
  DB.siaga_rutin = (DB.siaga_rutin||[]).filter(x=>x.id!==id);
  persist(DB); toast('Riwayat dihapus'); renderSiaga();
}

/* ============================= PENGATURAN (khusus Admin Utama) ============================= */
const PENGATURAN_BIDANG = [
  {key:'nomor_approver', label:'No WA Pimpinan', hint:'Nomor yang menerima & berwenang ACC/TOLAK Surat serta Kegiatan lewat WhatsApp. Bila lebih dari satu, pisahkan dengan koma.', ph:'0878-7954-5233'},
  {key:'nomor_approver_pinjam', label:'No WA ACC Peminjaman', hint:'Nomor yang berwenang ACC/TOLAK Peminjaman lewat WhatsApp (bawaan 0851-5515-9030).', ph:'0851-5515-9030'},
  {key:'nomor_bot', label:'No WA Perantara / Inputan', hint:'Nomor WhatsApp bot yang menerima pesan input data (bawaan 0878-9191-8976). Harus sama dengan nomor device di gateway.', ph:'0878-9191-8976'},
  {key:'token', label:'API Key WA', hint:'Token device gateway WhatsApp (Fonnte). Demi keamanan nilai lama tidak ditampilkan utuh; kosongkan bila tidak diganti.', ph:'Isi hanya jika ingin mengganti', password:true},
  {key:'api_url', label:'API Website', hint:'Alamat endpoint API pengiriman WhatsApp, wajib https:// (bawaan https://api.fonnte.com/send).', ph:'https://api.fonnte.com/send'},
];
async function renderPengaturan(){
  const app = document.getElementById('app');
  if(!isSuper()){ app.innerHTML = '<div class="panel rounded-xl p-6 text-sm">Akses ditolak. Modul ini hanya untuk Admin Utama.</div>'; return; }
  app.innerHTML = '<div class="panel rounded-xl p-6 text-sm" style="color:var(--muted);">Memuat pengaturan…</div>';
  let d;
  try{
    const r = await fetch(API_URL + '?action=pengaturan_get', {cache:'no-store'});
    const j = await r.json();
    if(!r.ok || !j.ok) throw new Error(j.error || 'Gagal memuat pengaturan');
    d = j.data;
  }catch(e){
    app.innerHTML = `<div class="panel rounded-xl p-6 text-sm" style="color:var(--danger,#b91c1c);">${esc(e.message)}</div>`;
    return;
  }
  const baris = PENGATURAN_BIDANG.map(f=>{
    const nilai = f.key==='token' ? '' : (d[f.key]||'');
    const info = f.key==='token' ? `<div class="text-[11px] mt-1" style="color:var(--muted);">Saat ini: <b>${esc(d.token_samar || '(belum diisi)')}</b></div>` : '';
    return `<div class="mb-4">
      <label class="block text-sm font-medium mb-1" for="pg_${f.key}">${esc(f.label)}</label>
      <input id="pg_${f.key}" type="${f.password?'password':'text'}" class="w-full" autocomplete="${f.password?'new-password':'off'}" spellcheck="false" placeholder="${esc(f.ph)}" value="${esc(nilai)}">
      <div class="text-[11px] mt-1" style="color:var(--muted);">${esc(f.hint)}</div>${info}
    </div>`;
  }).join('');
  let kcfg = Object.assign({}, KOLASE_DEFAULT);
  try{ kcfg = await kolaseTplLoad(); }catch(e){}
  app.innerHTML = `<div class="panel rounded-xl p-4 sm:p-6 max-w-2xl">
    <h2 class="text-base font-semibold mb-1">Pengaturan WhatsApp & API</h2>
    <p class="text-xs mb-4" style="color:var(--muted);">Perubahan langsung berlaku untuk semua notifikasi, ACC lewat WhatsApp, dan input data via WA. Hanya Admin Utama yang dapat melihat dan mengubah halaman ini.</p>
    ${baris}
    <div class="flex items-center gap-2 mt-2">
      <button id="pgSimpan" class="btn btn-primary" onclick="simpanPengaturan()">Simpan Pengaturan</button>
      <button class="btn btn-ghost" onclick="renderPengaturan()">Batalkan perubahan</button>
    </div>
  </div>` + kolasePanelHtml(kcfg);
}
function kolasePanelHtml(c){
  const lbl = 'block text-sm font-medium mb-1';
  const hint = t => `<div class="text-[11px] mt-1" style="color:var(--muted);">${t}</div>`;
  return `<div class="panel rounded-xl p-4 sm:p-6 max-w-2xl mt-4">
    <h2 class="text-base font-semibold mb-1">Template Foto Kolase</h2>
    <p class="text-xs mb-4" style="color:var(--muted);">Dipakai saat tombol <b>Unduh</b> di Bukti Penugasan (Kegiatan/Tugas) membuat kolase foto bergaya poster. Tata letak &amp; tinggi kolase menyesuaikan jumlah serta rasio (portrait/landscape) foto yang diupload anggota. Header &amp; footer memakai gambar <code>assets/kolase_header.png</code> dan <code>assets/kolase_footer.png</code> (ganti file tersebut bila perlu).</p>
    <div class="mb-4"><label class="${lbl}" for="kt_judul">Judul</label>
      <input id="kt_judul" type="text" class="w-full" maxlength="200" value="${esc(c.judul)}">
      ${hint('Bisa memakai {nama}, {id}, {tanggal}, {lokasi}, {jumlah}. Bawaan: {nama}.')}</div>
    <div class="mb-4"><label class="${lbl}" for="kt_tanggal">Teks Tanggal (miring, di bawah judul)</label>
      <input id="kt_tanggal" type="text" class="w-full" maxlength="200" value="${esc(c.tanggal)}">
      ${hint('Bawaan {tanggal} → mis. "Selasa, 1 September 2026". Kosongkan bila tidak perlu.')}</div>
    <label class="flex items-center gap-2 text-sm mb-4"><input id="kt_kapital" type="checkbox" ${c.huruf_besar!==false?'checked':''}> Judul dengan HURUF KAPITAL</label>
    <div class="flex items-center gap-2 mt-2">
      <button id="ktSimpan" class="btn btn-primary" onclick="simpanKolaseTpl()">Simpan Template</button>
      <button class="btn btn-ghost" onclick="renderPengaturan()">Batalkan perubahan</button>
    </div>
  </div>`;
}
async function simpanKolaseTpl(){
  if(!isSuper()){ toast('Hanya Admin Utama'); return; }
  const v = id => (document.getElementById(id)||{}).value || '';
  const cfg = {
    judul: v('kt_judul').trim() || KOLASE_DEFAULT.judul,
    tanggal: v('kt_tanggal').trim(),
    huruf_besar: !!(document.getElementById('kt_kapital')||{}).checked,
  };
  const btn = document.getElementById('ktSimpan'); if(btn) btn.disabled = true;
  try{
    await fdbDel(KOLASE_CFG_FID);
    await fdbPut({fid:KOLASE_CFG_FID, kid:'', name:'kolase-template.json', blob:new Blob([JSON.stringify(cfg)], {type:'application/json'})});
    toast('Template foto kolase disimpan');
    renderPengaturan();
  }catch(e){
    toast('Gagal menyimpan template: ' + e.message); if(btn) btn.disabled = false;
  }
}
async function simpanPengaturan(){
  if(!isSuper()){ toast('Hanya Admin Utama'); return; }
  const body = {};
  PENGATURAN_BIDANG.forEach(f=>{ body[f.key] = (document.getElementById('pg_'+f.key)||{}).value || ''; });
  if(!confirm('Simpan perubahan pengaturan? Nomor penyetuju dan API Key akan langsung berlaku.')) return;
  const btn = document.getElementById('pgSimpan'); if(btn) btn.disabled = true;
  try{
    const r = await fetch(API_URL + '?action=pengaturan_simpan', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body: JSON.stringify(body)});
    const j = await r.json().catch(()=>({}));
    if(!r.ok || !j.ok){ toast(j.error || 'Gagal menyimpan pengaturan'); if(btn) btn.disabled = false; return; }
    toast(j.pesan || 'Pengaturan disimpan');
    renderPengaturan();
  }catch(e){
    toast('Gagal menyimpan: ' + e.message); if(btn) btn.disabled = false;
  }
}

/* ============================= TOAST / MODAL ============================= */
function toast(msg){
  const el = document.createElement('div');
  el.className='toast'; el.textContent = msg;
  document.getElementById('toastRoot').appendChild(el);
  setTimeout(()=>el.remove(), 2600);
}
function openModal(html, wide){
  document.getElementById('modalRoot').innerHTML = `
    <div class="modal-backdrop" onclick="if(event.target===this)closeModal()">
      <div class="panel rounded-xl w-full ${wide?'max-w-3xl':'max-w-lg'} shadow-2xl">${html}</div>
    </div>`;
}
let _modalCloseHook = null;
function closeModal(){
  document.getElementById('modalRoot').innerHTML='';
  if(_modalCloseHook){ const h = _modalCloseHook; _modalCloseHook = null; try{ h(); }catch(e){ console.error(e); } }
}

/* ============================= ROLE / NAV ============================= */
function isSuper(){ return IS_SU && currentRole === 'Admin Utama'; } // Admin Utama: akses penuh semua fitur
function isApprover(){ return currentRole !== 'Anggota'; }

/* Peran Anggota hanya boleh membuka modul berikut; modul lain menampilkan keterangan akses ditolak. */
const ANGGOTA_MODUL = ['kegiatan','pembinaan','peralatan','kendaraan','siaga_rutin','peminjaman','pemeliharaan']; // pemeliharaan = menu "Pengajuan"
const PESAN_AKSES_ANGGOTA = 'Hanya lihat: Anggota tidak dapat menambah, mengubah, atau menghapus data modul ini';
function isAnggotaOnly(){ return currentRole === 'Anggota' && !isSuper(); }
function bolehAkses(view){ return true; } // semua modul dapat dilihat; modul terkunci bersifat lihat saja untuk Anggota
/* Modul di luar ANGGOTA_MODUL: Anggota hanya boleh MELIHAT (tidak bisa tambah/ubah/hapus/simpan). */
function modulReadOnly(key){ return isAnggotaOnly() && !ANGGOTA_MODUL.includes(key); }
/* Modul yang disembunyikan total dari role Anggota */
function modulTersembunyi(key){ return (isAnggotaOnly() && key==='anggota') || (key==='pengaturan' && !isSuper()); }
function guardRO(key){
  if(modulReadOnly(key)){ toast('Mode lihat saja: peran Anggota tidak dapat mengubah data modul ini'); return true; }
  return false;
}
function awalView(){ return VIEW_ONLY ? 'dashboard' : isAnggotaOnly() ? 'kegiatan' : 'dashboard'; }

function renderSidebar(){
  document.getElementById('navlist').innerHTML = NAV_ORDER.filter(n=>!modulTersembunyi(n.key)).map(n => {
    const kunci = modulReadOnly(n.key);
    return `
    <div class="navlink ${currentView===n.key?'active':''}" style="${kunci?'opacity:.55;':''}" title="${kunci?esc(PESAN_AKSES_ANGGOTA):''}" onclick="render('${n.key}'); toggleSidebar(false);">
      <span class="ico">${n.short}</span><span class="truncate">${esc(n.label)}</span>${kunci?'<span class="ml-auto text-[11px]" aria-hidden="true">👁</span>':''}
    </div>`;
  }).join('');
}

/* ============================= ROUTER ============================= */
function render(view){
  if(VIEW_ONLY) view = 'dashboard';
  if(modulTersembunyi(view)) view = awalView();
  currentView = view;
  renderSidebar();
  const titles = {dashboard:['Dashboard','Ringkasan program dan kegiatan SAR'], kegiatan:['Kegiatan/Tugas','Surat masuk, ACC pimpinan, penugasan & info WhatsApp'], persuratan:['Persuratan','Surat masuk dan surat keluar'], anggota:['Daftar Anggota','Master data personel SAR'], siaga_rutin:['Pengecekan Siaga Rutin','Pemeriksaan rutin kesiapan alat dan sarana siaga'], pengaturan:['Pengaturan','Nomor WhatsApp & API (khusus Admin Utama)']};
  const gm = TABLE_MODULES.find(m=>m.key===view);
  const t = titles[view] || (gm ? [gm.label, 'Data program ' + gm.label] : ['Dashboard','']);
  document.getElementById('pageTitle').textContent = t[0];
  document.getElementById('pageSub').textContent = t[1];

  if(view==='dashboard') (VIEW_ONLY ? renderDashboardPublik() : renderDashboard());
  else if(view==='kegiatan') renderKegiatan();
  else if(view==='persuratan') renderPersuratan();
  else if(view==='siaga_rutin') renderSiaga();
  else if(view==='anggota') renderGenericTable('anggota', ANGGOTA_FIELDS, 'Anggota', true);
  else if(view==='pengaturan') renderPengaturan();
  else if(gm) renderGenericTable(gm.key, gm.fields, gm.label, false);
  if(modulReadOnly(view)) tampilBannerLihatSaja();
}
function tampilBannerLihatSaja(){
  const app = document.getElementById('app'); if(!app) return;
  app.insertAdjacentHTML('afterbegin', `<div class="rounded-lg px-3 py-2 mb-3 text-xs flex items-center gap-2" style="background:var(--info-bg); color:var(--info);" role="status"><span aria-hidden="true">👁</span><span><strong>Mode lihat saja.</strong> Anda dapat melihat data modul ini, tetapi tidak dapat menambah, mengubah, atau menghapus.</span></div>`);
}

/* ============================= DASHBOARD ============================= */
// Periode (bulan + tahun) per modul. Default: tahun berjalan, semua bulan. Data dihitung berdasarkan tanggal
// kegiatannya; data tanpa tanggal (mis. Potensi, Narasumber) memakai bulan & tahun saat data diinput.
const BULAN_NAMA = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
let periode = {};
function getPeriode(key){ return periode[key] || (periode[key] = {y:new Date().getFullYear(), m:0}); }
function recPeriode(r){
  for(const v of [r.tanggal, r.tanggal_mulai, r.tanggal_pemeriksaan]){
    const m = String(v==null ? '' : v).match(/((?:19|20)\d{2})-(\d{1,2})/);
    if(m) return {y:parseInt(m[1],10), m:parseInt(m[2],10)};
  }
  if(r.tahun_input) return {y:parseInt(r.tahun_input,10), m:parseInt(r.bulan_input,10)||new Date().getMonth()+1};
  const now = new Date();
  return {y:now.getFullYear(), m:now.getMonth()+1}; // data lama tanpa tanggal
}
function inPeriode(r, key){
  const p = getPeriode(key), rp = recPeriode(r);
  return (!p.y || rp.y===p.y) && (!p.m || rp.m===p.m);
}
function periodeTahunList(key){
  const set = new Set([new Date().getFullYear(), getPeriode(key).y].filter(Boolean));
  const src = key==='dashboard' ? GENERIC_MODULES.map(m=>DB[m.key]||[]).concat([DB.kegiatan||[]]) : [DB[key]||[]];
  src.forEach(arr=>arr.forEach(r=>set.add(recPeriode(r).y)));
  return Array.from(set).sort((a,b)=>b-a);
}
function periodeSelectHtml(key){
  const p = getPeriode(key);
  return `<span class="periode-sel">
    <select style="width:auto;" aria-label="Bulan" onchange="setPeriode('${key}','m',this.value)">
      <option value="0" ${!p.m?'selected':''}>Semua Bulan</option>
      ${BULAN_NAMA.map((n,i)=>`<option value="${i+1}" ${p.m===i+1?'selected':''}>${n}</option>`).join('')}
    </select>
    <select style="width:auto;" aria-label="Tahun" onchange="setPeriode('${key}','y',this.value)">
      <option value="0" ${!p.y?'selected':''}>Semua Tahun</option>
      ${periodeTahunList(key).map(y=>`<option value="${y}" ${p.y===y?'selected':''}>${y}</option>`).join('')}
    </select>
  </span>`;
}
function setPeriode(key, field, val){ getPeriode(key)[field] = parseInt(val,10) || 0; render(currentView); }
/* ---- Filter rentang tanggal (Dari - Sampai) untuk tiap tabel "Data per Modul" di Dashboard ----
   Bila rentang diisi, rentang itu menggantikan filter Bulan/Tahun untuk tabel tersebut (berlaku juga ke tombol Unduh).
   Tanggal acuan: tanggal kegiatan/surat/pinjam/mulai dst. Modul tanpa kolom tanggal memakai tanggal data diinput. */
let dashRange = {};
function getDashRange(key){ return dashRange[key] || (dashRange[key] = {from:'', to:''}); }
function dashRangeAktif(key){ const g = dashRange[key]; return !!(g && (g.from || g.to)); }
function recRangeTanggal(key, r){
  if(key==='kendaraan_darat' || key==='kendaraan_laut') key = 'kendaraan';
  const iso = v => /^\d{4}-\d{2}-\d{2}/.test(String(v==null?'':v)) ? String(v).slice(0,10) : '';
  const meta = TABLE_MODULES.find(m=>m.key===key);
  let a = '', b = '';
  if(key==='kegiatan'){ a = iso(r.tanggal); b = iso(r.tanggal_selesai); }
  else if(key==='surat_masuk') a = iso(r.tanggal_surat);
  else if(key==='siaga_rutin') a = iso(r.tanggal);
  else if(meta){
    const df = meta.fields.find(f=>f.type==='date');
    if(df){ a = iso(r[df.key]); if(df.key==='tanggal_mulai') b = iso(r.tanggal_selesai); }
  }
  if(!a) a = iso(r.tanggal_input);
  if(!a){
    const p = recPeriode(r), pad = n => String(n).padStart(2,'0');
    a = p.y+'-'+pad(p.m)+'-01'; b = p.y+'-'+pad(p.m)+'-'+pad(new Date(p.y, p.m, 0).getDate());
  }
  return [a, (b && b >= a) ? b : a];
}
function inDashRange(key, r){
  const g = dashRange[key]; if(!g || (!g.from && !g.to)) return true;
  const [a, b] = recRangeTanggal(key, r);
  return (!g.from || b >= g.from) && (!g.to || a <= g.to);
}
/* Penyaring baris tabel Dashboard: rentang tanggal bila diisi, jika tidak pakai aturan lama (periode / stok) */
function dashLolos(key, r, stok, prRec){
  if(dashRangeAktif(key)) return inDashRange(key, r);
  return stok ? true : inPeriode(prRec || r, 'dashboard');
}
function dashRangeHtml(key){
  const g = getDashRange(key);
  return `<span class="inline-flex flex-wrap items-center gap-1 text-xs min-w-0" style="color:var(--muted);">
    <span>Dari</span>
    <input type="date" style="width:auto;" aria-label="Dari tanggal" value="${esc(g.from)}" ${g.to?`max="${esc(g.to)}"`:''} onchange="setDashRange('${key}','from',this.value)">
    <span>s/d</span>
    <input type="date" style="width:auto;" aria-label="Sampai tanggal" value="${esc(g.to)}" ${g.from?`min="${esc(g.from)}"`:''} onchange="setDashRange('${key}','to',this.value)">
    ${dashRangeAktif(key) ? `<button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" onclick="resetDashRange('${key}')">Reset</button>` : ''}
  </span>`;
}
function setDashRange(key, field, val){
  const g = getDashRange(key); g[field] = val || '';
  if(g.from && g.to && g.from > g.to){ const t = g.from; g.from = g.to; g.to = t; }
  const y = window.scrollY; render(currentView); window.scrollTo(0, y);
}
function resetDashRange(key){
  dashRange[key] = {from:'', to:''};
  const y = window.scrollY; render(currentView); window.scrollTo(0, y);
}
/* ---- Grafik dashboard (SVG murni, tanpa library) ---- */
const CH_FONT = "font-family:Inter,system-ui,sans-serif;";
const CH_MQ = window.matchMedia('(max-width:640px)');
const chMob = () => CH_MQ.matches;
const chCut = (t, n) => { t = String(t); return t.length > n ? t.slice(0, Math.max(1, n-1)) + '…' : t; };
(CH_MQ.addEventListener ? CH_MQ.addEventListener.bind(CH_MQ,'change') : CH_MQ.addListener.bind(CH_MQ))(()=>{ if(typeof currentView!=='undefined' && currentView==='dashboard') render('dashboard'); });
function chEmpty(msg){ return `<div class="text-sm py-10 text-center" style="color:var(--muted);">${esc(msg)}</div>`; }
function chLineSvg(labels, values, opt){
  opt = opt || {}; const unit = opt.unit || 'kegiatan', yLab = opt.yLabel || 'Jumlah', fmt = opt.fmt || (v=>v), aria = opt.aria || 'Grafik garis jumlah kegiatan per bulan';
  const M=chMob(), fs=M?9:11, W=M?360:640, H=M?230:260, L=M?26:38, R=M?10:16, T=34, B=34, pw=W-L-R, ph=H-T-B;
  const max = Math.max(...values, 1), step = Math.max(1, Math.ceil(max/4)), top = step*Math.ceil(max/step);
  const xs = i => L + (labels.length>1 ? pw*i/(labels.length-1) : pw/2);
  const ys = v => T + ph - (v/top)*ph;
  let grid=''; for(let v=0; v<=top; v+=step){ grid += `<line x1="${L}" y1="${ys(v)}" x2="${W-R}" y2="${ys(v)}" style="stroke:var(--line);"/><text x="${L-8}" y="${ys(v)+4}" text-anchor="end" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${v}</text>`; }
  const pts = values.map((v,i)=>`${xs(i)},${ys(v)}`).join(' ');
  const area = `${xs(0)},${ys(0)} ${pts} ${xs(values.length-1)},${ys(0)}`;
  return `<div class="chart-scroll"><svg class="chart-wide" viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="${esc(aria)}">
    ${grid}
    <polygon points="${area}" style="fill:var(--orange-500);opacity:.10;"/>
    <polyline points="${pts}" fill="none" style="stroke:var(--orange-500);" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    ${values.map((v,i)=>`<g><circle cx="${xs(i)}" cy="${ys(v)}" r="4" style="fill:#fff;stroke:var(--orange-500);" stroke-width="2"/><title>${esc(labels[i])}: ${fmt(v)} ${unit}</title>${v>0?`<text x="${xs(i)}" y="${ys(v)-9}" text-anchor="middle" font-size="${fs}" font-weight="600" style="${CH_FONT}fill:var(--navy-800);">${fmt(v)}</text>`:''}</g>`).join('')}
    ${labels.map((l,i)=>(opt.labelStep && i%opt.labelStep!==0) ? '' : `<text x="${xs(i)}" y="${H-10}" text-anchor="middle" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${esc(l)}</text>`).join('')}
    <text x="4" y="11" font-size="10" style="${CH_FONT}fill:var(--muted);">${esc(yLab)}</text>
  </svg></div>`;
}
function chDonutHtml(items){
  const total = items.reduce((t,i)=>t+i.n,0);
  const R=62, C=2*Math.PI*R; let off=0;
  const segs = total ? items.filter(i=>i.n>0).map(i=>{
    const len = C*i.n/total, seg = `<circle cx="90" cy="90" r="${R}" fill="none" stroke="${i.color}" stroke-width="26" stroke-dasharray="${len} ${C-len}" stroke-dashoffset="${-off}" transform="rotate(-90 90 90)"><title>${esc(i.tip || (i.label+': '+i.n))}</title></circle>`;
    off += len; return seg;
  }).join('') : '';
  return `<svg viewBox="0 0 180 180" width="100%" style="max-width:190px;display:block;margin:0 auto;" role="img">
      <circle cx="90" cy="90" r="${R}" fill="none" style="stroke:var(--line);" stroke-width="26"/>${segs}
      <text x="90" y="92" text-anchor="middle" font-size="26" font-weight="700" style="${CH_FONT}fill:var(--navy-800);">${total}</text>
      <text x="90" y="110" text-anchor="middle" font-size="10" style="${CH_FONT}fill:var(--muted);">Total</text>
    </svg>
    <div class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-3 text-xs">
      ${items.map(i=>`<span class="inline-flex items-center gap-1.5"${i.tip?` title="${esc(i.tip)}" style="cursor:help;"`:''}><span style="width:10px;height:10px;border-radius:3px;background:${i.color};display:inline-block;"></span><span style="color:var(--muted);">${esc(i.label)}</span> <strong>${i.n}</strong>${total?` <span style="color:var(--muted);">(${Math.round(i.n*100/total)}%)</span>`:''}</span>`).join('')}
    </div>`;
}
function chBarSvg(cats, series){
  const M=chMob(), fs=M?9:11, W=M?360:640, L=M?26:38, R=M?10:16, T=22, pw=W-L-R;
  const maxLen = Math.max(0, ...cats.map(c=>String(c).length)), gw0 = (W-L-R)/Math.max(1,cats.length);
  const rot = cats.length > (M?5:9) || maxLen*fs*0.56 > gw0*0.92, rotCh = M?18:26, rotPx = Math.min(maxLen, rotCh)*(fs-1)*0.56;
  const B = rot ? Math.max(M?78:92, Math.ceil(rotPx*0.77)+28) : 40, H = (M?240:250) + (rot ? B-40 : 0), ph=H-T-B;
  const max = Math.max(1, ...series.flatMap(s=>s.values)), step = Math.max(1, Math.ceil(max/4)), top = step*Math.ceil(max/step);
  const ys = v => T + ph - (v/top)*ph;
  const gw = pw/cats.length, bw = Math.min(34, (gw*0.7)/series.length), maxCh = rot ? rotCh : Math.max(3, Math.floor(gw/(fs*0.55)));
  let grid=''; for(let v=0; v<=top; v+=step){ grid += `<line x1="${L}" y1="${ys(v)}" x2="${W-R}" y2="${ys(v)}" style="stroke:var(--line);"/><text x="${L-8}" y="${ys(v)+4}" text-anchor="end" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${v}</text>`; }
  const bars = cats.map((c,ci)=>{
    const gx = L + gw*ci + gw/2 - (bw*series.length)/2;
    return series.map((sr,si)=>{ const v=sr.values[ci], x=gx+bw*si, y=ys(v);
      return `<g><rect x="${x}" y="${y}" width="${bw-3}" height="${Math.max(0,ys(0)-y)}" rx="3" style="fill:${sr.colors ? sr.colors[ci] : sr.color};"><title>${esc(c)} · ${esc(sr.name)}: ${v}</title></rect>${v>0?`<text x="${x+(bw-3)/2}" y="${y-5}" text-anchor="middle" font-size="${fs}" font-weight="600" style="${CH_FONT}fill:var(--navy-800);">${v}</text>`:''}</g>`; }).join('')
      + (rot ? `<text transform="translate(${L+gw*ci+gw/2+3} ${ys(0)+10}) rotate(-50)" text-anchor="end" font-size="${fs-1}" style="${CH_FONT}fill:var(--muted);">${esc(chCut(c,maxCh))}<title>${esc(c)}</title></text>`
      : `<text x="${L+gw*ci+gw/2}" y="${H-20}" text-anchor="middle" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${esc(chCut(c,maxCh))}</text>`);
  }).join('');
  return `<div class="chart-scroll"><svg class="chart-wide" viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Grafik batang">${grid}${bars}</svg></div>
    <div class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-1 text-xs">${series.filter(sr=>!sr.colors).map(sr=>`<span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:${sr.color};display:inline-block;"></span><span style="color:var(--muted);">${esc(sr.name)}</span></span>`).join('')}</div>`;
}
/* ---- Dashboard: Peta Sebaran Potensi SAR di Jabodetabek (SVG skematis, tanpa pustaka/koneksi luar) ---- */
let potPetaMetrik = 'anggota', potPetaSel = '';
function setPotPeta(k, v){ if(k==='metrik'){ potPetaMetrik = v; } else { potPetaSel = (potPetaSel===v) ? '' : v; } render(currentView); }
// Batas wilayah berupa perkiraan [bujur, lintang]; urutan = urutan gambar (kabupaten besar di bawah, kota kecil di atasnya).
const POT_PETA = [
  {id:'kab_bogor', nama:'Kab. Bogor', full:'Kabupaten Bogor', lbl:[107.03,-6.56], p:[[106.42,-6.29],[106.52,-6.33],[106.63,-6.35],[106.68,-6.37],[106.76,-6.37],[106.96,-6.37],[107.07,-6.38],[107.16,-6.43],[107.21,-6.52],[107.19,-6.66],[107.08,-6.72],[106.95,-6.76],[106.82,-6.78],[106.70,-6.73],[106.60,-6.70],[106.52,-6.62],[106.45,-6.52],[106.40,-6.42]]},
  {id:'kab_tangerang', nama:'Kab. Tangerang', full:'Kabupaten Tangerang', lbl:[106.50,-6.17], p:[[106.42,-6.03],[106.52,-6.03],[106.62,-6.03],[106.69,-6.05],[106.73,-6.09],[106.73,-6.25],[106.63,-6.35],[106.52,-6.33],[106.42,-6.29],[106.40,-6.15]]},
  {id:'kab_bekasi', nama:'Kab. Bekasi', full:'Kabupaten Bekasi', lbl:[107.11,-6.17], p:[[106.98,-6.10],[107.01,-6.04],[107.03,-5.98],[107.11,-5.99],[107.17,-6.15],[107.22,-6.33],[107.16,-6.43],[107.07,-6.38],[106.97,-6.30],[106.94,-6.17]]},
  {id:'kota_bogor', nama:'Kota Bogor', full:'Kota Bogor', p:[[106.77,-6.54],[106.84,-6.55],[106.85,-6.60],[106.82,-6.66],[106.76,-6.65],[106.74,-6.59]]},
  {id:'depok', nama:'Depok', full:'Kota Depok', p:[[106.77,-6.33],[106.80,-6.355],[106.86,-6.355],[106.90,-6.345],[106.93,-6.34],[106.94,-6.38],[106.90,-6.43],[106.82,-6.45],[106.75,-6.43],[106.76,-6.37]]},
  {id:'tangsel', nama:'Tangsel', full:'Kota Tangerang Selatan', p:[[106.63,-6.24],[106.71,-6.25],[106.76,-6.25],[106.77,-6.27],[106.77,-6.33],[106.76,-6.37],[106.68,-6.37],[106.63,-6.35]]},
  {id:'kota_tangerang', nama:'Kota Tangerang', full:'Kota Tangerang', p:[[106.60,-6.10],[106.66,-6.09],[106.72,-6.11],[106.73,-6.17],[106.73,-6.22],[106.71,-6.25],[106.63,-6.24],[106.59,-6.17]]},
  {id:'kota_bekasi', nama:'Kota Bekasi', full:'Kota Bekasi', p:[[106.98,-6.15],[107.04,-6.19],[107.06,-6.27],[107.01,-6.34],[106.96,-6.37],[106.93,-6.34],[106.93,-6.30],[106.93,-6.22],[106.95,-6.175]]},
  {id:'jakut', nama:'Jakut', full:'Jakarta Utara', p:[[106.73,-6.09],[106.78,-6.10],[106.84,-6.11],[106.90,-6.09],[106.96,-6.09],[106.98,-6.10],[106.98,-6.14],[106.93,-6.15],[106.89,-6.145],[106.84,-6.15],[106.80,-6.145],[106.76,-6.135],[106.73,-6.12]]},
  {id:'jakbar', nama:'Jakbar', full:'Jakarta Barat', p:[[106.73,-6.12],[106.76,-6.135],[106.80,-6.145],[106.815,-6.17],[106.80,-6.19],[106.81,-6.215],[106.78,-6.23],[106.76,-6.25],[106.72,-6.24],[106.73,-6.22],[106.73,-6.17]]},
  {id:'jakpus', nama:'Jakpus', full:'Jakarta Pusat', p:[[106.80,-6.145],[106.84,-6.15],[106.89,-6.145],[106.88,-6.18],[106.87,-6.20],[106.86,-6.23],[106.84,-6.22],[106.81,-6.215],[106.80,-6.19],[106.815,-6.17]]},
  {id:'jaksel', nama:'Jaksel', full:'Jakarta Selatan', p:[[106.76,-6.25],[106.78,-6.23],[106.81,-6.215],[106.84,-6.22],[106.86,-6.23],[106.86,-6.26],[106.85,-6.30],[106.86,-6.355],[106.80,-6.355],[106.77,-6.33],[106.77,-6.27]]},
  {id:'jaktim', nama:'Jaktim', full:'Jakarta Timur', p:[[106.89,-6.145],[106.93,-6.15],[106.95,-6.175],[106.93,-6.22],[106.93,-6.30],[106.93,-6.32],[106.90,-6.345],[106.86,-6.355],[106.85,-6.30],[106.86,-6.26],[106.86,-6.23],[106.87,-6.20],[106.88,-6.18]]},
  {id:'seribu', nama:'Kep. Seribu', full:'Kepulauan Seribu', p:null}
];
// Kata kunci pencocokan teks Wilayah/Alamat potensi -> wilayah peta (urutan penting: yang spesifik lebih dulu).
const POT_PETA_KEYS = [
  ['seribu', /SERIBU|KEP\.?\s*1000|PRAMUKA|TIDUNG|UNTUNG\s*JAWA|PULAU\s*(PARI|HARAPAN|PANGGANG|KELAPA)|KEPULAUAN/],
  ['tangsel', /TANGERANG\s*SELATAN|TANGSEL|SERPONG|\bBSD\b|PAMULANG|CIPUTAT|PONDOK\s*AREN|PAGEDANGAN|CISAUK/],
  ['kab_tangerang', /KAB(UPATEN|\.)?\s*TANGERANG|TIGARAKSA|BALARAJA|CIKUPA|CURUG|PASAR\s*KEMIS|TELUKNAGA|KOSAMBI|\bMAUK\b|SEPATAN|PAKUHAJI|LEGOK|KRESEK|CISOKA|JAMBE|RAJEG|SUKADIRI|KRONJO|KEMIRI|SOLEAR|PANONGAN/],
  ['kota_tangerang', /TANGERANG|CILEDUG|CIPONDOH|KARAWACI|BATUCEPER|NEGLASARI|PERIUK|JATIUWUNG|CIBODAS|KARANG\s*TENGAH/],
  ['jakut', /JAKARTA\s*UTARA|JAKUT|PENJARINGAN|\bKOJA\b|CILINCING|TANJUNG\s*PRIOK|KELAPA\s*GADING|PADEMANGAN|ANCOL|MUARA\s*ANGKE|PLUIT|SUNTER|MARUNDA/],
  ['jakbar', /JAKARTA\s*BARAT|JAKBAR|CENGKARENG|KALIDERES|GROGOL|KEMBANGAN|KEBON\s*JERUK|PALMERAH|TAMAN\s*SARI|TAMBORA|PURI\s*INDAH|DAAN\s*MOGOT|SLIPI/],
  ['jakpus', /JAKARTA\s*PUSAT|JAKPUS|MENTENG|GAMBIR|TANAH\s*ABANG|SENEN|KEMAYORAN|SAWAH\s*BESAR|CEMPAKA\s*PUTIH|JOHAR\s*BARU|MONAS|THAMRIN/],
  ['jaksel', /JAKARTA\s*SELATAN|JAKSEL|KEBAYORAN|PANCORAN|TEBET|MAMPANG|PASAR\s*MINGGU|JAGAKARSA|CILANDAK|SETIABUDI|PESANGGRAHAN|KUNINGAN|BLOK\s*M/],
  ['jaktim', /JAKARTA\s*TIMUR|JAKTIM|CAKUNG|DUREN\s*SAWIT|PULO\s*GADUNG|PULOGADUNG|MATRAMAN|JATINEGARA|KRAMAT\s*JATI|CIRACAS|MAKASAR|PASAR\s*REBO|HALIM|CIBUBUR/],
  ['depok', /DEPOK|SAWANGAN|CIMANGGIS|CINERE|SUKMAJAYA|CILODONG|TAPOS|BOJONGSARI/],
  ['kab_bekasi', /KAB(UPATEN|\.)?\s*BEKASI|CIKARANG|TAMBUN|CIBITUNG|BABELAN|MUARA\s*GEMBONG|CIBARUSAH|TARUMAJAYA|SUKATANI|SERANG\s*BARU|KEDUNGWARINGIN|SUKAKARYA|CABANGBUNGIN|PEBAYURAN/],
  ['kota_bekasi', /BEKASI|BANTARGEBANG|JATIASIH|JATISAMPURNA|MUSTIKAJAYA|PONDOK\s*GEDE|PONDOKGEDE|RAWALUMBU|MEDAN\s*SATRIA/],
  ['kota_bogor', /KOTA\s*BOGOR|BOGOR\s*(TENGAH|UTARA|SELATAN|TIMUR|BARAT)|TANAH\s*SAREAL/],
  ['kab_bogor', /KAB(UPATEN|\.)?\s*BOGOR|CIBINONG|CILEUNGSI|CITEUREUP|GUNUNG\s*PUTRI|JONGGOL|PARUNG|CIAWI|CISARUA|MEGAMENDUNG|LEUWILIANG|CIBUNGBULANG|DRAMAGA|CIAMPEA|CIGUDEG|JASINGA|RUMPIN|SENTUL|BOJONGGEDE|TAJURHALANG|CARIU|CIOMAS|TAMANSARI|CIJERUK|CIGOMBONG|CISEENG|KEMANG|GUNUNG\s*SINDUR/],
  ['kota_bogor', /BOGOR/]
];
function potWilayahId(r){
  // "Jl. Raya Bogor/Bekasi/..." adalah nama jalan, bukan wilayah -> dibuang dulu sebelum dicocokkan
  const bersih = s => String(s||'').toUpperCase().replace(/\b(JL|JLN|JALAN)\.?\s*(RAYA\s*)?(BOGOR|BEKASI|TANGERANG|DEPOK|JAKARTA)\b(\s*RAYA)?/g,' ');
  for(const f of [r.wilayah, r.alamat]){
    const t = bersih(f); if(!t.trim()) continue;
    for(const [id, re] of POT_PETA_KEYS){ if(re.test(t)) return id; }
  }
  return '';
}
function potPetaDetail(id){
  const r = (DB.potensi||[]).find(x=>String(x.id)===String(id)); if(!r) return;
  const n = v => parseFloat(v)||0;
  const tot = n(r.jumlah_tersertifikasi)+n(r.jumlah_belum_sertifikasi);
  const v = x => (x===undefined||x===null||String(x).trim()==='') ? '-' : esc(String(x));
  const dok = String(r.dokumentasi||'').trim();
  const dokHtml = !dok ? '-' : (/^https?:\/\//i.test(dok) ? `<a href="${esc(dok)}" target="_blank" rel="noopener noreferrer" class="underline" style="color:var(--navy-800);">${esc(dok)}</a>` : esc(dok));
  const kontak = String(r.nomor_kontak||'').trim();
  const baris = [
    ['Kategori', v(r.kategori)], ['Status Potensi', v(r.status)],
    ['Alamat', v(r.alamat)], ['Wilayah', v(r.wilayah)],
    ['Nama Kontak', v(r.nama_kontak)], ['Nomor Kontak', kontak ? `<a href="tel:${esc(kontak.replace(/[^\d+]/g,''))}" class="underline" style="color:var(--navy-800);">${esc(kontak)}</a>` : '-'],
    ['Kompetensi', v(r.kompetensi)], ['Alut & Sapras', v(r.alut_sapras)],
    ['Tersertifikasi', n(r.jumlah_tersertifikasi)], ['Belum Tersertifikasi', n(r.jumlah_belum_sertifikasi)],
    ['Seluruh Anggota', tot], ['Keterangan', v(r.keterangan)], ['Dokumentasi', dokHtml]
  ];
  openModal(`<div class="p-5 max-h-[85vh] overflow-y-auto">
    <div class="flex items-start justify-between gap-3 mb-3"><div><h3 class="font-display font-semibold text-base">${esc(r.nama_potensi||'-')}</h3><div class="text-xs" style="color:var(--muted);">Informasi lengkap potensi · ${esc(r.id||'')}</div></div><button type="button" class="btn btn-ghost" onclick="closeModal()" aria-label="Tutup">Tutup</button></div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">${baris.map(b=>`<div class="py-2 text-sm" style="border-bottom:1px solid var(--line);"><div class="text-[11px]" style="color:var(--muted);">${b[0]}</div><div class="font-medium break-words">${b[1]}</div></div>`).join('')}</div>
  </div>`, true);
}
function potPetaCardHtml(rows, periodeTxt){
  const n = v => parseFloat(v)||0, m = potPetaMetrik;
  const head = (aksi) => `<div class="mb-2 flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-display font-semibold text-sm">Peta Sebaran Potensi SAR · Jabodetabek</h3><div class="text-[11px]" style="color:var(--muted);">Wilayah tempat potensi berada (dari isian Wilayah/Alamat) · Periode ${esc(periodeTxt)}</div></div>${aksi||''}</div>`;
  if(!rows.length) return `<div class="panel rounded-xl p-4">${head('')}${chEmpty('Belum ada data potensi pada periode ini.')}</div>`;
  const agg = {}; POT_PETA.forEach(g=>{ agg[g.id] = {n:0, sert:0, bel:0, rows:[]}; });
  const unmapped = [];
  rows.forEach(r=>{ const id = potWilayahId(r); if(!id || !agg[id]){ unmapped.push(r); return; }
    const a = agg[id]; a.n++; a.sert += n(r.jumlah_tersertifikasi); a.bel += n(r.jumlah_belum_sertifikasi); a.rows.push(r); });
  const val = a => m==='potensi' ? a.n : a.sert + a.bel;
  const satuan = m==='potensi' ? 'potensi' : 'anggota';
  const max = Math.max(1, ...POT_PETA.map(g=>val(agg[g.id])));
  const W = 470, H = 500, X = lon => ((lon-106.38)*520), Y = lat => ((-5.84-lat)*520);
  const tip = g => { const a = agg[g.id]; return g.full+': '+a.n+' potensi · '+(a.sert+a.bel)+' anggota ('+a.sert+' tersertifikasi, '+a.bel+' belum)'; };
  const opac = g => { const v = val(agg[g.id]); return v ? 0.2 + 0.8*v/max : 0; };
  const poly = g => { const a = agg[g.id], v = val(a), sel = potPetaSel===g.id;
    return `<polygon points="${g.p.map(q=>X(q[0]).toFixed(1)+','+Y(q[1]).toFixed(1)).join(' ')}" onclick="setPotPeta('sel','${g.id}')" style="cursor:pointer;fill:${v?'var(--navy-800)':'var(--panel)'};fill-opacity:${v?opac(g).toFixed(2):1};stroke:${sel?'var(--orange-500)':'#B8C4D8'};stroke-width:${sel?2.8:1};stroke-linejoin:round;"><title>${esc(tip(g))}</title></polygon>`; };
  const bagian = POT_PETA.filter(g=>g.p);
  const centroid = g => g.lbl || [g.p.reduce((t,q)=>t+q[0],0)/g.p.length, g.p.reduce((t,q)=>t+q[1],0)/g.p.length];
  const label = g => { const v = val(agg[g.id]), c = centroid(g), x = X(c[0]).toFixed(1), y = Y(c[1]).toFixed(1), fg = opac(g) > 0.5 ? '#fff' : 'var(--navy-800)';
    return `<text x="${x}" y="${(+y-1).toFixed(1)}" text-anchor="middle" font-size="8.5" font-weight="600" style="${CH_FONT}fill:${fg};pointer-events:none;">${esc(g.nama)}</text>`
      + (v ? `<text x="${x}" y="${(+y+10).toFixed(1)}" text-anchor="middle" font-size="10" font-weight="700" style="${CH_FONT}fill:${fg};pointer-events:none;">${v}</text>` : ''); };
  // Kepulauan Seribu: inset skematis di laut sebelah utara Jakarta
  const gs = POT_PETA.find(g=>g.id==='seribu'), vs = val(agg.seribu), fgs = opac(gs) > 0.5 ? '#fff' : 'var(--navy-800)', sx = X(106.50), sy = Y(-5.86);
  const inset = `<g onclick="setPotPeta('sel','seribu')" style="cursor:pointer;"><rect x="${sx.toFixed(1)}" y="${sy.toFixed(1)}" width="118" height="46" rx="10" style="fill:${vs?'var(--navy-800)':'var(--panel)'};fill-opacity:${vs?opac(gs).toFixed(2):1};stroke:${potPetaSel==='seribu'?'var(--orange-500)':'#B8C4D8'};stroke-width:${potPetaSel==='seribu'?2.8:1};stroke-dasharray:${potPetaSel==='seribu'?'0':'4 3'};"/>`
    + [[76,14],[88,24],[100,13],[84,35],[104,32]].map(d=>`<circle cx="${(sx+d[0]).toFixed(1)}" cy="${(sy+d[1]).toFixed(1)}" r="3" style="fill:${vs?'#fff':'#B8C4D8'};fill-opacity:.85;"/>`).join('')
    + `<text x="${(sx+9).toFixed(1)}" y="${(sy+20).toFixed(1)}" font-size="9" font-weight="600" style="${CH_FONT}fill:${fgs};">Kep. Seribu</text>${vs?`<text x="${(sx+9).toFixed(1)}" y="${(sy+35).toFixed(1)}" font-size="11" font-weight="700" style="${CH_FONT}fill:${fgs};">${vs}</text>`:''}<title>${esc(tip(gs))}</title></g>`;
  const selG = bagian.find(g=>g.id===potPetaSel);
  const svg = `<svg viewBox="0 0 ${W} ${H}" width="100%" style="display:block;max-width:560px;max-height:520px;margin:0 auto;border-radius:12px;" role="img" aria-label="Peta sebaran potensi SAR di wilayah Jabodetabek">
    <rect width="${W}" height="${H}" rx="12" style="fill:#E6EEF8;"/>
    <text x="14" y="20" font-size="9" style="${CH_FONT}fill:var(--muted);">Laut Jawa</text>
    <g style="fill:var(--muted);"><path d="M440 26 l-6 12 h12z"/><text x="440" y="20" text-anchor="middle" font-size="10" font-weight="700" style="${CH_FONT}">U</text></g>
    ${bagian.filter(g=>g!==selG).map(poly).join('')}${selG ? poly(selG) : ''}${bagian.map(label).join('')}${inset}
  </svg>
  <div class="flex items-center justify-center gap-2 mt-2 text-[11px]" style="color:var(--muted);"><span>Sedikit</span><span style="width:110px;height:8px;border-radius:999px;background:linear-gradient(90deg,rgba(19,49,92,.2),rgba(19,49,92,1));display:inline-block;"></span><span>Banyak</span><span class="ml-2">· ${m==='potensi'?'jumlah potensi':'jumlah anggota'} per wilayah</span></div>
  <div class="text-[10px] text-center mt-1" style="color:var(--muted);">Peta skematis, batas wilayah berupa perkiraan. Klik wilayah untuk melihat rinciannya.</div>`;
  const urut = POT_PETA.filter(g=>val(agg[g.id])>0).sort((x,y)=>val(agg[y.id])-val(agg[x.id]));
  const daftar = urut.length ? urut.map(g=>{ const a = agg[g.id], v = val(a), sel = potPetaSel===g.id;
    return `<button type="button" onclick="setPotPeta('sel','${g.id}')" class="w-full text-left rounded-lg px-2 py-1.5 mb-1" style="border:1px solid ${sel?'var(--orange-500)':'transparent'};background:${sel?'var(--bg)':'transparent'};cursor:pointer;">
      <div class="flex items-center justify-between gap-2 text-xs"><b>${esc(g.full)}</b><span style="color:var(--muted);">${v} ${satuan}${m==='anggota'?' · '+a.n+' potensi':' · '+(a.sert+a.bel)+' anggota'}</span></div>
      <div class="bartrack mt-1"><div class="barfill" style="width:${Math.max(4, v/max*100).toFixed(0)}%"></div></div></button>`; }).join('')
    : '<div class="text-xs" style="color:var(--muted);">Belum ada potensi yang wilayahnya cocok dengan Jabodetabek. Isi kolom Wilayah/Alamat dengan nama kota/kabupaten/kecamatan.</div>';
  const gSel = POT_PETA.find(g=>g.id===potPetaSel);
  const detail = gSel ? `<div class="mt-3 pt-3" style="border-top:1px solid var(--line);"><div class="text-xs font-semibold mb-1">${esc(gSel.full)}</div>${agg[gSel.id].rows.length ? `<div class="menunggu-scroll" style="max-height:420px;">${agg[gSel.id].rows.map(r=>`<div class="text-sm py-2 px-1" style="border-bottom:1px solid var(--line);"><button type="button" onclick="potPetaDetail('${esc(r.id)}')" class="font-bold text-left" style="cursor:pointer;color:var(--navy-800);text-decoration:underline;text-decoration-style:dotted;" title="Klik untuk melihat informasi lengkap">${esc(r.nama_potensi||'-')}</button> <span style="color:var(--muted);">· ${esc(r.kategori||'-')}</span><div style="color:var(--muted);">${esc(r.alamat||r.wilayah||'-')} · ${n(r.jumlah_tersertifikasi)+n(r.jumlah_belum_sertifikasi)} anggota (${n(r.jumlah_tersertifikasi)} tersertifikasi)</div></div>`).join('')}</div>` : '<div class="text-xs" style="color:var(--muted);">Belum ada potensi di wilayah ini.</div>'}</div>` : '';
  const sisa = unmapped.length ? `<div class="mt-3 text-[11px]" style="color:var(--muted);"><b>${unmapped.length} potensi belum terpetakan</b> (wilayah kosong/umum atau di luar Jabodetabek): ${unmapped.slice(0,4).map(r=>esc((r.nama_potensi||'-')+' ['+(r.wilayah||r.alamat||'wilayah kosong')+']')).join('; ')}${unmapped.length>4?'; dst.':''}</div>` : '';
  const toggle = `<div class="flex gap-1.5"><button type="button" class="notif-tab ${m==='anggota'?'on':''}" onclick="setPotPeta('metrik','anggota')">Jumlah Anggota</button><button type="button" class="notif-tab ${m==='potensi'?'on':''}" onclick="setPotPeta('metrik','potensi')">Jumlah Potensi</button></div>`;
  return `<div class="panel rounded-xl p-4">${head(toggle)}<div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-start"><div class="md:col-span-2 min-w-0">${svg}</div><div class="md:col-span-3 min-w-0">${daftar}${detail}${sisa}</div></div></div>`;
}
function dashChartsHtml(o){
  const p = getPeriode('dashboard');
  const bulanShort = BULAN_NAMA.map(n=>n.slice(0,3));
  const perBulan = Array(12).fill(0);
  (DB.kegiatan||[]).forEach(k=>{ const rp=recPeriode(k); if(!p.y || rp.y===p.y) perBulan[rp.m-1]++; });
  const totalLine = perBulan.reduce((a,b)=>a+b,0);
  const persetujuan = ['Menunggu Persetujuan','Disetujui','Ditolak'].map((st,i)=>({
    label:st, n:o.kegiatanTahun.filter(k=>k.status_persetujuan===st).length,
    color:['var(--warn)','var(--ok)','var(--bad)'][i] }));
  const kats = ['TNI/Polri','Pemerintah','Perusahaan','Organisasi'];
  const potKat = kats.map(k=>o.potensiRows.filter(r=>r.kategori===k));
  const sum = (arr,f)=>arr.reduce((t,r)=>t+(parseFloat(r[f])||0),0);
  const potTotal = sum(o.potensiRows,'jumlah_tersertifikasi') + sum(o.potensiRows,'jumlah_belum_sertifikasi');
  const card = (title, sub, body, span) => `<div class="panel rounded-xl p-4 ${span||''}">
      <div class="mb-2"><h3 class="font-display font-semibold text-sm">${title}</h3><div class="text-[11px]" style="color:var(--muted);">${sub}</div></div>${body}</div>`;
  const periodeTxt = (p.m ? BULAN_NAMA[p.m-1]+' ' : '') + (p.y || 'Semua Tahun');
  // Peralatan per Lokasi (stok: tidak difilter periode)
  const lokasiList = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='lokasi').options;
  const prlRows = DB.peralatan || [];
  const prlKatList = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='kategori').options;
  const prlKatColor = ['var(--navy-800)','var(--orange-500)','var(--ok)','var(--warn)','#7C5CBF'];
  const prlSeries = prlKatList.map((k,i)=>({
    name:k, color:prlKatColor[i%prlKatColor.length],
    values:lokasiList.map(l=>prlRows.filter(r=>(r.lokasi||'KPP Jakarta')===l && r.kategori===k).reduce((t,r)=>t+peralatanJumlah(r),0))
  }));
  const prlTotal = prlSeries.reduce((t,s)=>t+s.values.reduce((a,b)=>a+b,0),0);
  // Pengecekan Siaga Rutin (mengikuti filter Periode dashboard)
  const sgAll = (DB.siaga_rutin || []).filter(r=>inPeriode(r,'dashboard'));
  const sgAlat = sgAll.filter(r=>!siagaIsSarana(r.jenis)), sgSar = sgAll.filter(r=>siagaIsSarana(r.jenis));
  const sgBulanAlat = Array(12).fill(0), sgBulanSar = Array(12).fill(0);
  sgAlat.forEach(r=>{ sgBulanAlat[recPeriode(r).m-1]++; });
  sgSar.forEach(r=>{ sgBulanSar[recPeriode(r).m-1]++; });
  const sgUrut = arr => arr.slice().sort((a,b)=>(String(a.tanggal||'')+String(a.waktu||'')).localeCompare(String(b.tanggal||'')+String(b.waktu||'')));
  const sgAlatLast = sgUrut(sgAlat).pop(), sgSarLast = sgUrut(sgSar).pop();
  const sgNum = v => parseFloat(v)||0;
  const alatKond = sgAlatLast ? [
    {label:'Baik',   n:(sgAlatLast.items||[]).reduce((t,i)=>t+sgNum(i.baik),0),   color:'var(--ok)'},
    {label:'Rusak',  n:(sgAlatLast.items||[]).reduce((t,i)=>t+sgNum(i.rusak),0),  color:'var(--warn)'},
    {label:'Hilang', n:(sgAlatLast.items||[]).reduce((t,i)=>t+sgNum(i.hilang),0), color:'var(--bad)'}] : [];
  const sarKond = sgSarLast ? ['Baik','Rusak Ringan','Rusak Berat','Hilang'].map((k,i)=>({
    label:k, n:(sgSarLast.items||[]).filter(it=>it.kondisi===k).length, color:['var(--ok)','var(--warn)','var(--orange-500)','var(--bad)'][i]})) : [];
  const sgInfo = r => r ? tglDMY(r.tanggal)+' · '+esc(r.jenis==='Peralatan' ? 'Peralatan' : (r.sapras||'Sarana'))+(r.lokasi && r.lokasi!=='Semua Lokasi' ? ' · '+esc(r.lokasi) : '') : '';
  // Riwayat: hanya kendaraan (Sapras Darat/Laut), 24 jam terakhir, semua role
  const sgBatas = Date.now() - 24*3600*1000;
  const sgTs = r => { const t = new Date(String(r.tanggal||'').slice(0,10)+'T'+(r.waktu||'00:00')).getTime(); return isNaN(t) ? 0 : t; };
  const sgTerbaru = sgUrut((DB.siaga_rutin||[]).filter(r=>siagaIsSarana(r.jenis) && sgTs(r)>=sgBatas)).reverse();
  const sgListBody = sgTerbaru.length ? sgTerbaru.map(r=>{
      const sar = siagaIsSarana(r.jenis), its = r.items||[];
      const ring = sar ? its.filter(i=>i.kondisi==='Baik').length+' baik dari '+its.length+' kendaraan'
                       : its.reduce((t,i)=>t+sgNum(i.baik),0)+' baik · '+its.reduce((t,i)=>t+sgNum(i.rusak),0)+' rusak · '+its.reduce((t,i)=>t+sgNum(i.hilang),0)+' hilang';
      let bbmHtml = '';
      if(sar){
        const laut = r.sapras==='Sapras Laut';
        const tersedia = [], habis = [];
        its.forEach(i=>{
          const v = (i.vals||{})[laut ? 'persen_bbm' : 'bbm'];
          const st = siagaBbmStatus(v); if(!st) return;
          const n = parseFloat(String(v).replace(',','.'));
          const row = {nama:i.nama||i.nopol||'-', nopol:i.nopol||'', bbm:n};
          (st==='BBM Tersedia' ? tersedia : habis).push(row);
        });
        const kol = (judul, arr, warna) => `<div class=\"min-w-0\">
          <div class=\"flex items-center justify-between mb-1\"><span class=\"text-xs font-semibold\" style=\"color:${warna};\">${judul}</span><span class=\"badge ${warna==='var(--ok)'?'b-ok':'b-bad'}\">${arr.length}</span></div>
          <div class=\"menunggu-scroll\" style=\"max-height:200px;\">${arr.length ? arr.sort((a,b)=>a.bbm-b.bbm).map(x=>`<div class=\"flex items-center justify-between gap-2 py-1 border-b last:border-0 text-xs\" style=\"border-color:var(--line);\"><span class=\"truncate\">${esc(x.nama)}${x.nopol && x.nopol!==x.nama ? ' <span style=\"color:var(--muted);\">('+esc(x.nopol)+')</span>' : ''}</span><span class=\"font-semibold whitespace-nowrap\" style=\"color:${warna};\">BBM ${esc(String(x.bbm))}%</span></div>`).join('') : '<div class=\"text-xs py-1\" style=\"color:var(--muted);\">Tidak ada.</div>'}</div></div>`;
        if(tersedia.length || habis.length)
          bbmHtml = `<div class=\"grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2\">${kol('BBM Tersedia (≥ 50%)', tersedia, 'var(--ok)')}${kol('BBM Hampir Habis (< 50%)', habis, 'var(--bad)')}</div>`;
      }
      return `<div class=\"py-2 border-b last:border-0\" style=\"border-color:var(--line);\">
        <div class=\"min-w-0\"><div class=\"text-sm font-medium truncate\">${sgInfo(r)}</div>
        <div class=\"text-xs\" style=\"color:var(--muted);\">${esc(ring)}${r.pemeriksa ? ' · '+esc(r.pemeriksa) : ''}</div></div>${bbmHtml}</div>`; }).join('')
    : chEmpty('Tidak ada pengecekan kendaraan dalam 24 jam terakhir.');
  // Peralatan & Kendaraan Rusak (stok saat ini, tidak difilter periode)
  const rsAlat = (DB.peralatan||[]).filter(r=>(parseFloat(r.jumlah_rusak)||0)>0)
    .sort((a,b)=>(parseFloat(b.jumlah_rusak)||0)-(parseFloat(a.jumlah_rusak)||0));
  const rsAlatUnit = rsAlat.reduce((t,r)=>t+(parseFloat(r.jumlah_rusak)||0),0);
  const rsKdr = (DB.kendaraan||[]).filter(r=>r.kondisi==='Unserviceable' || r.kondisi==='Scrap')
    .sort((a,b)=>String(a.kondisi).localeCompare(String(b.kondisi))*-1);
  const rsKol = (judul, jumlahTxt, badgeCls, listHtml, kosong) => `<div class="min-w-0">
      <div class="flex items-center justify-between mb-1"><span class="text-xs font-semibold">${judul}</span><span class="badge ${badgeCls}">${jumlahTxt}</span></div>
      <div class="menunggu-scroll" style="max-height:260px;">${listHtml || '<div class="text-xs py-3" style="color:var(--muted);">'+kosong+'</div>'}</div></div>`;
  const rsRow = (nama, sub, kanan, warna) => `<div class="flex items-center justify-between gap-2 py-1.5 border-b last:border-0 text-xs" style="border-color:var(--line);">
      <div class="min-w-0"><div class="truncate font-medium">${esc(nama)}</div><div class="truncate" style="color:var(--muted);">${sub}</div></div>
      <span class="font-semibold whitespace-nowrap" style="color:${warna};">${kanan}</span></div>`;
  const rsAlatHtml = rsAlat.map(r=>rsRow(r.nama_barang||'-',
      esc((r.kategori||'-')+' · '+(r.lokasi||'KPP Jakarta')+(r.keterangan ? ' · '+r.keterangan : '')),
      (parseFloat(r.jumlah_rusak)||0)+' unit rusak', 'var(--warn)')).join('');
  const rsKdrHtml = rsKdr.map(r=>{
    const laut = saprasKategori(r)==='Sapras Laut';
    const no = r.nomor_polisi ? ' ('+(laut ? 'No Lambung ' : 'No Polisi ')+r.nomor_polisi+')' : '';
    return rsRow((r.nama_kendaraan||'-')+no,
      esc((r.jenis_kendaraan||'-')+' · '+(r.lokasi||'-')+(r.keterangan ? ' · '+r.keterangan : '')),
      esc(r.kondisi), r.kondisi==='Scrap' ? 'var(--bad)' : 'var(--warn)'); }).join('');
  const rsCard = card('Peralatan & Kendaraan Rusak', 'Daftar peralatan dan kendaraan yang kondisinya rusak (data Peralatan & Kendaraan saat ini)',
    `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      ${rsKol('Peralatan Rusak', rsAlat.length+' jenis · '+rsAlatUnit+' unit', rsAlat.length ? 'b-warn' : 'b-ok', rsAlatHtml, 'Tidak ada peralatan yang rusak.')}
      ${rsKol('Kendaraan Rusak', rsKdr.length+' kendaraan', rsKdr.length ? 'b-bad' : 'b-ok', rsKdrHtml, 'Tidak ada kendaraan yang rusak.')}
    </div>`, 'lg:col-span-3');
  // Data Kendaraan per Kategori (Sapras Darat / Sapras Laut) dan Kondisi (Serviceable / Unserviceable)
  const kdrAll = (DB.kendaraan||[]);
  const kdrGrp = (kat, kond) => kdrAll.filter(r=>saprasKategori(r)===kat && r.kondisi===kond)
    .sort((a,b)=>String(a.nama_kendaraan||'').localeCompare(String(b.nama_kendaraan||'')));
  const kdrKol = (kat, kond) => {
    const list = kdrGrp(kat, kond);
    const laut = kat==='Sapras Laut';
    const html = list.map(r=>rsRow(r.nama_kendaraan||'-',
      esc((laut ? 'No Lambung: ' : 'No Polisi: ')+(r.nomor_polisi||'-')+(r.jenis_kendaraan ? ' · '+r.jenis_kendaraan : '')),
      esc(r.kondisi), kond==='Serviceable' ? 'var(--ok)' : 'var(--warn)')).join('');
    return rsKol(kond, list.length+' kendaraan', kond==='Serviceable' ? 'b-ok' : (list.length ? 'b-bad' : 'b-ok'), html, 'Tidak ada data.');
  };
  const kdrKatBlok = kat => `<div class="min-w-0">
      <div class="text-sm font-bold mb-2">${kat}</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">${kdrKol(kat,'Serviceable')}${kdrKol(kat,'Unserviceable')}</div></div>`;
  const kdrKatCard = card('Data Kendaraan per Kategori', 'Nama kendaraan, No Polisi (Sapras Darat) / No Lambung (Sapras Laut) menurut kondisi Serviceable dan Unserviceable',
    `<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">${kdrKatBlok('Sapras Darat')}${kdrKatBlok('Sapras Laut')}</div>`, 'lg:col-span-3');
  // Pengingat Ganti Oli Sapras Darat (interval 3 bulan)
  const OLI_BULAN = 3, OLI_SEGERA_HARI = 14, OLI_MUNCUL_HARI = 30; // muncul lagi 1 bulan (30 hari) sebelum jatuh tempo
  const oliTerakhir = k => {
    let best = String(k.tgl_ganti_oli||'').slice(0,10);
    (DB.pemeliharaan||[]).forEach(p=>{
      if(!pgjAktif(p) || p.jenis_barang!=='Kendaraan' || normNama(p.nama_barang)!==normNama(k.nama_kendaraan) || p.status!=='Selesai') return;
      const adaOli = p.jenis_pemeliharaan==='Ganti Oli' || /oli/i.test((p.suku_cadang||'')+' '+(p.uraian||''));
      const t = String(p.tanggal_selesai||p.tanggal||'').slice(0,10);
      if(adaOli && t > best) best = t;
    });
    return best;
  };
  const oliJatuhTempo = iso => { const d = new Date(iso+'T00:00:00'); const day = d.getDate(); d.setMonth(d.getMonth()+OLI_BULAN); if(d.getDate()!==day) d.setDate(0); const z=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate()); };
  const oliHariSelisih = iso => Math.round((new Date(iso+'T00:00:00') - new Date(todayISO()+'T00:00:00'))/86400000);
  const oliRows = (DB.kendaraan||[]).filter(k=>saprasKategori(k)==='Sapras Darat').map(k=>{
    const last = oliTerakhir(k);
    if(!/^\d{4}-\d{2}-\d{2}$/.test(last)) return {k, last:'', due:'', sisa:-99999, st:'Belum tercatat', cls:'b-info'};
    const due = oliJatuhTempo(last), sisa = oliHariSelisih(due);
    const st = sisa<0 ? 'Terlambat '+(-sisa)+' hari' : sisa<=OLI_SEGERA_HARI ? 'Segera ('+sisa+' hari lagi)' : 'Mendekati ('+sisa+' hari lagi)';
    return {k, last, due, sisa, st, cls: sisa<0 ? 'b-bad' : sisa<=OLI_SEGERA_HARI ? 'b-warn' : 'b-info'};
  }).filter(r=>r.sisa<=OLI_MUNCUL_HARI).sort((x,y)=>x.sisa-y.sisa);
  // Kendaraan yang sudah ganti oli otomatis hilang dari daftar, muncul lagi 30 hari sebelum jatuh tempo.
  const oliAdaDataDarat = (DB.kendaraan||[]).some(k=>saprasKategori(k)==='Sapras Darat');
  const oliPerlu = oliRows.length;
  const oliBody = oliRows.length ? `<div class="tablewrap ${oliRows.length>8?'menunggu-scroll':''}" ${oliRows.length>8?'style="max-height:420px;"':''}><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Kendaraan</th><th class="p-2">Lokasi</th><th class="p-2">KM</th><th class="p-2">Ganti Oli Terakhir</th><th class="p-2">Jatuh Tempo</th><th class="p-2">Status</th></tr></thead><tbody>${oliRows.map(r=>`<tr>
      <td class="p-2"><b>${esc(r.k.nama_kendaraan||'-')}</b>${r.k.nomor_polisi?'<div class="text-xs" style="color:var(--muted)">'+esc(r.k.nomor_polisi)+'</div>':''}</td>
      <td class="p-2">${esc(r.k.lokasi||'-')}</td>
      <td class="p-2">${r.k.km_saat_ini!==undefined&&r.k.km_saat_ini!==''?esc(r.k.km_saat_ini):'-'}</td>
      <td class="p-2">${r.last?tglDMY(r.last):'-'}</td>
      <td class="p-2">${r.due?tglDMY(r.due):'-'}</td>
      <td class="p-2"><span class="badge ${r.cls}">${esc(r.st)}</span></td></tr>`).join('')}</tbody></table></div>` : '<div class="text-sm" style="color:var(--muted)">'+(oliAdaDataDarat ? 'Tidak ada kendaraan yang perlu ganti oli saat ini. Kendaraan akan muncul lagi 1 bulan sebelum jatuh tempo.' : 'Belum ada data Sapras Darat.')+'</div>';
  const oliCard = card('Pengingat Ganti Oli — Sapras Darat', 'Interval ganti oli setiap '+OLI_BULAN+' bulan · '+oliPerlu+' kendaraan perlu perhatian', oliBody, 'lg:col-span-3');
  return `<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    ${card('Kegiatan/Tugas per Bulan', 'Jumlah kegiatan tiap bulan · '+(p.y ? 'Tahun '+p.y : 'Semua tahun digabung'), totalLine ? chLineSvg(bulanShort, perBulan) : chEmpty('Belum ada kegiatan pada tahun ini.'), 'lg:col-span-2')}
    ${card('Status Persetujuan Kegiatan', 'Periode '+esc(periodeTxt), chDonutHtml(persetujuan))}
    ${`<div class="lg:col-span-2 flex flex-col gap-4 min-w-0">${card('Potensi SAR per Kategori', 'Tersertifikasi vs belum tersertifikasi · Periode '+esc(periodeTxt), potTotal ? chBarSvg(kats, [
      {name:'Tersertifikasi', color:'var(--navy-800)', values:potKat.map(a=>sum(a,'jumlah_tersertifikasi'))},
      {name:'Belum', color:'var(--orange-500)', values:potKat.map(a=>sum(a,'jumlah_belum_sertifikasi'))}]) : chEmpty('Belum ada data potensi pada periode ini.'), '')}</div>`}
    ${card('Hasil Uji Periodik', o.ujiNama ? esc(o.ujiNama)+' · '+(o.lulus+o.tidakLulus)+' dari '+o.ujiPeserta+' peserta dinilai' : 'Belum ada kegiatan', chDonutHtml([
      {label:'Lulus', n:o.lulus, color:'var(--ok)'}, {label:'Tidak Lulus', n:o.tidakLulus, color:'var(--bad)'}]))}
    <div class="lg:col-span-3 min-w-0">${potPetaCardHtml(o.potensiRows, periodeTxt)}</div>
    ${card('Peralatan per Kategori di Tiap Lokasi', 'Jumlah unit peralatan (baik + rusak) menurut kategori, per lokasi', prlTotal ? chBarSvg(lokasiList, prlSeries) : chEmpty('Belum ada data peralatan.'), 'lg:col-span-2')}
    ${(()=>{ const kk = kendaraanKondisiChart(); return card('Kendaraan per Kondisi', 'Jumlah kendaraan menurut kondisi · '+(kendaraanLokasiFilter||'Semua Lokasi')+' · total '+kk.total+' kendaraan', kk.body, 'lg:col-span-1'); })()}
    ${card('Pengecekan Siaga Rutin per Bulan', 'Jumlah pengecekan tiap bulan · '+(p.y ? 'Tahun '+p.y : 'Semua tahun digabung')+' · total '+sgAll.length+' pengecekan', sgAll.length ? chBarSvg(bulanShort, [
      {name:'Peralatan', color:'var(--navy-800)', values:sgBulanAlat},
      {name:'Sarana', color:'var(--orange-500)', values:sgBulanSar}]) : chEmpty('Belum ada pengecekan siaga pada periode ini.'), 'lg:col-span-2')}
    ${card('Kondisi Peralatan', sgAlatLast ? 'Pengecekan terakhir · '+sgInfo(sgAlatLast) : 'Hasil pengecekan terakhir', sgAlatLast ? chDonutHtml(alatKond) : chEmpty('Belum ada pengecekan peralatan.'))}
    ${dashSiapHtml()}
    ${(()=>{ // Scroll vertikal bila ada lebih dari 2 pengecekan pada hari (tanggal) yang sama
      const perHari = {}; sgTerbaru.forEach(r=>{ const k = String(r.tanggal||'').slice(0,10); perHari[k] = (perHari[k]||0)+1; });
      const scroll = Object.values(perHari).some(n=>n>2);
      return card('Riwayat Pengecekan Terbaru', 'Pengecekan kendaraan (Sapras Darat/Laut) dalam 24 jam terakhir', scroll ? `<div class="menunggu-scroll" style="max-height:640px;">${sgListBody}</div>` : sgListBody, 'lg:col-span-3'); })()}
    ${bbmTrenCardHtml()}
    ${saprasChartsHtml()}
    ${oliCard}
    ${rsCard}
    ${kdrKatCard}
  </div>`;
}
/* ---- Dashboard: Peralatan & Kendaraan siap digunakan (hasil pengecekan terakhir Siaga Rutin + Pemeliharaan Sumda) ---- */
let siapJenis = '', siapLokasi = '', siapCari = '';
function setSiap(f, v){ if(f==='jenis') siapJenis = v; else if(f==='lokasi') siapLokasi = v; else siapCari = v; render(currentView); }
function dashSiapData(){
  const ts = (tgl, w) => String(tgl||'').slice(0,10) + ' ' + (w||'00:00');
  const evt = {}; // kunci per jenis+nama+lokasi (lintas sumber) -> pengecekan terbaru; data lama dengan nama & lokasi sama otomatis tergantikan
  const taruh = (key, e) => { if(!evt[key] || e.ts >= evt[key].ts) evt[key] = e; };
  const n = v => parseFloat(v) || 0;
  (DB.siaga_rutin||[]).forEach(r=>{
    const sar = siagaIsSarana(r.jenis);
    (r.items||[]).forEach(i=>{
      const jenis = sar ? 'Kendaraan' : 'Peralatan';
      const kend = sar ? (DB.kendaraan||[]).find(k=>k.id===i.ref) : null;
      const lokS = (kend && kend.lokasi) || (r.lokasi && r.lokasi!=='Semua Lokasi' ? r.lokasi : '');
      taruh(jenis+'|'+normNama(i.nama||(kend&&kend.nama_kendaraan)||'')+'|'+sumdaLokNorm(lokS), {ts:ts(r.tanggal,r.waktu), tanggal:r.tanggal, sumber:'Pengecekan Siaga Rutin', jenis,
        id: sar ? i.ref : '', nama: i.nama||'-', lokasi: lokS,
        siap: sar ? (i.kondisi==='Baik' ? 1 : 0) : n(i.baik)});
    });
  });
  (DB.sumda_riwayat||[]).forEach(r=>{
    (r.items||[]).forEach(i=>{
      const jenis = i.jenis || r.jenis || 'Peralatan';
      taruh(jenis+'|'+normNama(i.nama||'')+'|'+sumdaLokNorm(i.lokasi||''), {ts:ts(r.tanggal,r.waktu), tanggal:r.tanggal, sumber:'Pemeliharaan Sumda', jenis,
        id:i.ref, nama:i.nama||'-', lokasi:i.lokasi||'', siap: jenis==='Kendaraan' ? (n(i.baik)>0 ? 1 : 0) : n(i.baik)});
    });
  });
  // Data hanya tampil 1 bulan sejak tanggal pengecekan; bila tidak ada pengecekan baru, data hilang dari dashboard.
  const batas = new Date(todayISO()+'T00:00:00'); const hr = batas.getDate(); batas.setMonth(batas.getMonth()-1); if(batas.getDate()!==hr) batas.setDate(0);
  const z2 = x=>String(x).padStart(2,'0'); const batasISO = batas.getFullYear()+'-'+z2(batas.getMonth()+1)+'-'+z2(batas.getDate());
  return Object.values(evt).filter(e=>e.siap>0 && String(e.tanggal||'').slice(0,10) >= batasISO).sort((a,b)=>b.ts.localeCompare(a.ts));
}
function dashSiapHtml(){
  const semua = dashSiapData();
  const lokOpsi = Array.from(new Set(semua.map(e=>e.lokasi).filter(Boolean))).sort();
  const q = normNama(siapCari);
  const lst = semua.filter(e=>(!siapJenis || e.jenis===siapJenis) && (!siapLokasi || sumdaLokNorm(e.lokasi)===sumdaLokNorm(siapLokasi))
    && (!q || normNama(e.id+' '+e.nama).includes(q)));
  const perLok = semua.filter(e=>!siapLokasi || sumdaLokNorm(e.lokasi)===sumdaLokNorm(siapLokasi)); // angka ringkasan mengikuti Lokasi terpilih
  const tot = j => perLok.filter(e=>e.jenis===j).reduce((t,e)=>t+e.siap,0);
  const tile = (judul, angka, sub, warna) => `<div class="rounded-lg p-3" style="border:1px solid var(--line);"><div class="text-xs" style="color:var(--muted);">${judul}</div><div class="font-display font-semibold" style="font-size:1.6rem;color:${warna};">${angka}</div><div class="text-[11px]" style="color:var(--muted);">${sub}</div></div>`;
  return `<div class="panel rounded-xl p-4 lg:col-span-3">
    <h3 class="font-display font-semibold text-base">Peralatan &amp; Kendaraan Siap Digunakan</h3>
    <p class="text-xs mb-3" style="color:var(--muted);">Berdasarkan pengecekan terakhir di Pengecekan Siaga Rutin dan Pemeliharaan Sumda · tampil 1 bulan sejak pengecekan</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
      ${tile('Peralatan Siap'+(siapLokasi ? ' · '+esc(siapLokasi) : ''), tot('Peralatan')+' unit', perLok.filter(e=>e.jenis==='Peralatan').length+' jenis barang', 'var(--ok)')}
      ${tile('Kendaraan Siap'+(siapLokasi ? ' · '+esc(siapLokasi) : ''), tot('Kendaraan')+' unit', perLok.filter(e=>e.jenis==='Kendaraan').length+' kendaraan', 'var(--ok)')}
    </div>
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <select style="width:auto;" aria-label="Filter Jenis" onchange="setSiap('jenis',this.value)">
        <option value="">Semua Jenis</option><option value="Peralatan" ${siapJenis==='Peralatan'?'selected':''}>Peralatan</option><option value="Kendaraan" ${siapJenis==='Kendaraan'?'selected':''}>Kendaraan</option></select>
      <select style="width:auto;" aria-label="Filter Lokasi" onchange="setSiap('lokasi',this.value)">
        <option value="">Semua Lokasi</option>${lokOpsi.map(o=>`<option value="${esc(o)}" ${sumdaLokNorm(o)===sumdaLokNorm(siapLokasi)?'selected':''}>${esc(o)}</option>`).join('')}</select>
      <input type="text" data-sid="siap-cari" class="!w-56" placeholder="Cari nama / ID..." value="${esc(siapCari)}" oninput="runSearchRender(this, ()=>setSiap('cari', this.value))">
      <span class="text-xs" style="color:var(--muted);">${lst.length} data</span>
    </div>
    <div class="tablewrap menunggu-scroll" style="max-height:360px;"><table>
      <thead><tr><th>Jenis</th><th>ID</th><th>Nama</th><th>Lokasi</th><th>Siap</th><th>Sumber</th><th>Pengecekan Terakhir</th></tr></thead>
      <tbody>${lst.length ? lst.map(e=>`<tr><td>${esc(e.jenis)}</td><td class="font-medium">${esc(e.id||'-')}</td><td>${esc(e.nama)}</td><td>${esc(e.lokasi||'-')}</td>
        <td><span class="badge b-ok">${e.siap} unit</span></td><td>${esc(e.sumber)}</td><td>${esc(tglDMY(e.tanggal))}</td></tr>`).join('')
        : `<tr><td colspan="7" class="text-center py-6" style="color:var(--muted);">Belum ada data siap digunakan. Data muncul setelah ada Pengecekan Siaga Rutin atau Pemeliharaan Sumda.</td></tr>`}</tbody>
    </table></div>
  </div>`;
}
/* Grafik Kendaraan: X = Kondisi, Y = Jumlah (stok, tidak difilter periode) */
let kendaraanLokasiFilter = '';
function kendaraanLokasiOpsi(){
  const set = new Set(LOKASI_KENDARAAN.concat(LOKASI_LAUT_EXTRA));
  (DB.kendaraan||[]).forEach(r=>{ if(r.lokasi) set.add(r.lokasi); });
  return Array.from(set);
}
function setKendaraanLokasi(v){ kendaraanLokasiFilter = v; render(currentView); }
function kendaraanLokasiSelectHtml(){
  return `<div class="flex items-center gap-2 mb-3">
    <span class="text-xs font-medium" style="color:var(--muted);">Lokasi</span>
    <select style="width:auto;flex:1 1 0;min-width:0;max-width:100%;text-overflow:ellipsis;" aria-label="Filter Lokasi Kendaraan" onchange="setKendaraanLokasi(this.value)">
      <option value="">Semua Lokasi</option>
      ${kendaraanLokasiOpsi().map(o=>`<option value="${esc(o)}" ${kendaraanLokasiFilter===o?'selected':''}>${esc(o)}</option>`).join('')}
    </select>
  </div>`;
}
/* Grafik Kendaraan: X = Kondisi, Y = Jumlah (stok, tidak difilter periode; bisa difilter Lokasi) */
function kendaraanKondisiChart(){
  const kondisi = ['Serviceable','Unserviceable','Scrap'];
  const rows = (DB.kendaraan || []).filter(r=>!kendaraanLokasiFilter || r.lokasi===kendaraanLokasiFilter);
  const vals = kondisi.map(k=>rows.filter(r=>r.kondisi===k).length);
  const total = vals.reduce((x,y)=>x+y,0);
  const chart = total
    ? chDonutHtml(kondisi.map((k,i)=>{
        const list = rows.filter(r=>r.kondisi===k).map(r=>{
          const no = r.nomor_polisi || '-';
          return '• '+(r.nama_kendaraan||'-')+' ('+(saprasKategori(r)==='Sapras Laut' ? 'No Lambung ' : 'No Polisi ')+no+')';
        });
        return {label:k, n:vals[i], color:['var(--ok)','var(--warn)','var(--bad)'][i],
          tip: k+': '+vals[i]+(list.length ? '\n'+list.join('\n') : '')};
      }))
    : chEmpty(kendaraanLokasiFilter ? 'Belum ada kendaraan di '+kendaraanLokasiFilter+'.' : 'Belum ada data kendaraan.');
  return {total, body: kendaraanLokasiSelectHtml() + chart};
}
/* ---- Grafik garis Pemakaian BBM Sapras per bulan (gaya sama dengan grafik Kegiatan/Tugas per Bulan) ----
   Pemakaian = total penurunan persen BBM antar pengecekan Siaga Rutin berurutan (kenaikan karena pengisian tidak dihitung). */
let bbmTrenSapras = '', bbmTrenKend = '', bbmTrenMode = 'bulan', bbmTrenTahun = String(new Date().getFullYear()), bbmTrenBulan = String(new Date().getMonth()+1);
function setBbmTren(f, v){
  if(f==='sapras'){ bbmTrenSapras = v; bbmTrenKend = ''; } else if(f==='kend') bbmTrenKend = v;
  else if(f==='mode') bbmTrenMode = v; else if(f==='bulan') bbmTrenBulan = v; else bbmTrenTahun = v;
  const y = window.scrollY; render(currentView); window.scrollTo(0, y);
}
function bbmTrenData(){
  const kend = {};
  (DB.siaga_rutin||[]).filter(r=>siagaIsSarana(r.jenis)).forEach(r=>{
    const laut = r.sapras==='Sapras Laut', sap = laut ? 'Sapras Laut' : 'Sapras Darat';
    const tgl = String(r.tanggal||'').slice(0,10), ts = new Date(tgl+'T'+(r.waktu||'00:00')).getTime();
    if(!tgl || isNaN(ts)) return;
    (r.items||[]).forEach(i=>{
      const raw = (i.vals||{})[laut ? 'persen_bbm' : 'bbm'];
      if(raw===undefined || raw==='' || isNaN(parseFloat(raw))) return;
      const key = sap+'|'+(i.nopol||i.nama||'-');
      const k = kend[key] = kend[key] || {key, nama:(i.nama||i.nopol||'-'), nopol:(i.nopol||''), sapras:sap, pts:{}};
      const old = k.pts[tgl];
      if(!old || ts>=old.ts) k.pts[tgl] = {ts, tgl, v:Math.max(0, Math.min(100, parseFloat(raw)))};  // 1 titik per hari (pengecekan terakhir)
    });
  });
  return Object.values(kend).map(k=>Object.assign(k, {arr:Object.values(k.pts).sort((a,b)=>a.ts-b.ts)}))
    .sort((a,b)=>a.nama.localeCompare(b.nama));
}
function bbmTrenSvg(labels, list, labelStep){
  const M=chMob(), fs=M?9:11, W=M?360:640, H=M?250:290, L=M?32:42, R=M?12:18, T=24, B=34, pw=W-L-R, ph=H-T-B, n=labels.length;
  const warna = ['#F2542D','#13315C','#0F9D58','#B7791F','#7C3AED','#0891B2','#DB2777','#65A30D'];
  const xs = i => L + (n>1 ? pw*i/(n-1) : pw/2), ys = v => T + ph - (v/100)*ph, single = list.length===1;
  let grid=''; [0,25,50,75,100].forEach(v=>{ grid += `<line x1="${L}" y1="${ys(v)}" x2="${W-R}" y2="${ys(v)}" style="stroke:var(--line);"/><text x="${L-6}" y="${ys(v)+4}" text-anchor="end" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${v}%</text>`; });
  grid += `<line x1="${L}" y1="${ys(50)}" x2="${W-R}" y2="${ys(50)}" stroke-dasharray="4 3" style="stroke:var(--bad);opacity:.55;"/><text x="${L+4}" y="${ys(50)-4}" font-size="${fs-1}" style="${CH_FONT}fill:var(--bad);">batas 50%</text>`;
  const xl = labels.map((l,i)=>(labelStep && i%labelStep!==0) ? '' : `<text x="${xs(i)}" y="${H-10}" text-anchor="middle" font-size="${fs}" style="${CH_FONT}fill:var(--muted);">${esc(l)}</text>`).join('');
  const lines = list.map((k,ki)=>{
    const c = warna[ki%warna.length], pts = [];
    k.vals.forEach((v,i)=>{ if(v!==null) pts.push({i,v}); });
    const poly = pts.map(q=>`${xs(q.i)},${ys(q.v)}`).join(' ');
    const area = single && pts.length>1 ? `<polygon points="${xs(pts[0].i)},${ys(0)} ${poly} ${xs(pts[pts.length-1].i)},${ys(0)}" style="fill:${c};opacity:.10;"/>` : '';
    const dots = pts.map(q=>`<g><circle cx="${xs(q.i)}" cy="${ys(q.v)}" r="${single?4:3.2}" style="fill:#fff;stroke:${c};" stroke-width="2"/><title>${esc(k.nama)} · ${esc(labels[q.i])}: BBM ${q.v}%</title>${single?`<text x="${xs(q.i)}" y="${ys(q.v)-9}" text-anchor="middle" font-size="${fs}" font-weight="600" style="${CH_FONT}fill:var(--navy-800);">${q.v}</text>`:''}</g>`).join('');
    return `${area}${pts.length>1?`<polyline points="${poly}" fill="none" stroke="${c}" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>`:''}${dots}`;
  }).join('');
  const legend = !single ? `<div class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-1 text-xs">${list.map((k,ki)=>`<span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:${warna[ki%warna.length]};display:inline-block;"></span><span style="color:var(--muted);">${esc(k.nama)}</span></span>`).join('')}</div>` : '';
  return `<div class="chart-scroll"><svg class="chart-wide" viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Grafik garis pemakaian BBM Sapras">${grid}${lines}${xl}</svg></div>${legend}`;
}
function bbmTrenCardHtml(){
  const semua = bbmTrenData();
  const tersaring = semua.filter(k=>(!bbmTrenSapras || k.sapras===bbmTrenSapras));
  if(bbmTrenKend && !tersaring.some(k=>k.key===bbmTrenKend)) bbmTrenKend = '';
  let list = bbmTrenKend ? tersaring.filter(k=>k.key===bbmTrenKend) : tersaring;
  const thnNow = new Date().getFullYear(), thnSet = new Set([String(thnNow)]);
  list.forEach(k=>k.arr.forEach(pt=>thnSet.add(pt.tgl.slice(0,4))));
  const thnOpsi = Array.from(thnSet).sort().reverse();
  if(!thnSet.has(bbmTrenTahun)) bbmTrenTahun = thnOpsi[0];
  // Sumbu X + fungsi indeks bucket menurut Periode
  let labels, idx, subPer, labelStep = 0, y0 = 0;
  if(bbmTrenMode==='harian'){
    const m = parseInt(bbmTrenBulan,10), y = parseInt(bbmTrenTahun,10), hari = new Date(y, m, 0).getDate();
    labels = Array.from({length:hari}, (_,i)=>String(i+1)); labelStep = chMob() ? 5 : 2;
    idx = t => (t.slice(0,4)===bbmTrenTahun && parseInt(t.slice(5,7),10)===m) ? parseInt(t.slice(8,10),10)-1 : -1;
    subPer = 'Harian · '+BULAN_NAMA[m-1]+' '+bbmTrenTahun;
  } else if(bbmTrenMode==='tahunan'){
    const ys = []; list.forEach(k=>k.arr.forEach(pt=>ys.push(parseInt(pt.tgl.slice(0,4),10)))); ys.push(thnNow);
    y0 = Math.min(...ys); const y1 = Math.max(...ys); labels = []; for(let y=y0;y<=y1;y++) labels.push(String(y));
    idx = t => parseInt(t.slice(0,4),10)-y0; subPer = 'Tahunan';
  } else {
    labels = BULAN_NAMA.map(x=>x.slice(0,3));
    idx = t => t.slice(0,4)===bbmTrenTahun ? parseInt(t.slice(5,7),10)-1 : -1;
    subPer = 'Bulanan · Tahun '+bbmTrenTahun;
  }
  // Nilai tiap kendaraan per bucket = rata-rata BBM (%) hasil pengecekan pada bucket tsb (harian = pengecekan terakhir hari itu)
  let data = list.map(k=>{
    const sum = new Array(labels.length).fill(0), cnt = new Array(labels.length).fill(0); let turun = 0, adaPeriode = false;
    k.arr.forEach((pt,i)=>{ const b = idx(pt.tgl); if(b<0 || b>=labels.length) return; sum[b]+=pt.v; cnt[b]++; adaPeriode = true;
      if(i>0 && pt.v<k.arr[i-1].v) turun += k.arr[i-1].v-pt.v; });
    return Object.assign({}, k, {vals:sum.map((t,i)=>cnt[i] ? Math.round(t/cnt[i]*10)/10 : null), turun, adaPeriode});
  }).filter(k=>k.adaPeriode);
  const dipotong = !bbmTrenKend && data.length>8;
  if(dipotong) data = data.slice().sort((a,b)=>b.vals.filter(v=>v!==null).length-a.vals.filter(v=>v!==null).length || a.nama.localeCompare(b.nama)).slice(0,8);
  const sel = (lbl, aria, fn, opts, cur) => `<label class="flex items-center gap-1.5"><span class="text-xs font-medium" style="color:var(--muted);">${lbl}</span><select style="width:auto;max-width:100%;text-overflow:ellipsis;" aria-label="${aria}" onchange="${fn}(this.value)">${opts.map(o=>`<option value="${esc(o[0])}" ${String(cur)===String(o[0])?'selected':''}>${esc(o[1])}</option>`).join('')}</select></label>`;
  const kendOpsi = tersaring.map(k=>[k.key, k.nama+(k.nopol && k.nopol!==k.nama ? ' ('+k.nopol+')' : '')]);
  const filter = `<div class="flex flex-wrap items-center gap-x-3 gap-y-2 mb-3">
    ${sel('Sapras','Filter Sapras',"setBbmTren.bind(null,'sapras')",[['','Semua'],['Sapras Darat','Sapras Darat'],['Sapras Laut','Sapras Laut']],bbmTrenSapras)}
    ${sel('Kendaraan','Filter Kendaraan',"setBbmTren.bind(null,'kend')",[['','Semua Kendaraan']].concat(kendOpsi),bbmTrenKend)}
    ${sel('Periode','Filter Periode',"setBbmTren.bind(null,'mode')",[['harian','Harian'],['bulan','Bulan'],['tahunan','Tahunan']],bbmTrenMode)}
    ${bbmTrenMode==='harian' ? sel('Bulan','Pilih Bulan',"setBbmTren.bind(null,'bulan')",BULAN_NAMA.map((nm,i)=>[String(i+1),nm]),bbmTrenBulan) : ''}
    ${bbmTrenMode!=='tahunan' ? sel('Tahun','Pilih Tahun',"setBbmTren.bind(null,'tahun')",thnOpsi.map(t=>[t,t]),bbmTrenTahun) : ''}
  </div>`;
  let stat = '';
  if(data.length===1){
    const v = data[0].vals.filter(x=>x!==null), last = v[v.length-1];
    stat = `<div class="flex flex-wrap gap-x-5 gap-y-1 mb-2 text-xs"><span style="color:var(--muted);">BBM terakhir pada periode <strong style="color:${last<50?'var(--bad)':'var(--ok)'};">${last}%</strong></span><span style="color:var(--muted);">Pemakaian (akumulasi penurunan antar pengecekan) <strong style="color:var(--navy-800);">${Math.round(data[0].turun*10)/10}%</strong></span></div>`;
  }
  const body = filter + (data.length
    ? stat + bbmTrenSvg(labels, data, labelStep) + (dipotong ? `<div class="text-[11px] mt-1 text-center" style="color:var(--muted);">Menampilkan 8 kendaraan dengan data terbanyak. Pilih kendaraan pada filter untuk melihat yang lain.</div>` : '')
    : chEmpty('Belum ada data BBM hasil pengecekan pada periode ini.'));
  const sub = 'Persentase BBM hasil pengecekan Siaga Rutin · '+(bbmTrenSapras||'Semua Sapras')+' · '+subPer;
  return `<div class="panel rounded-xl p-4 lg:col-span-3"><div class="mb-2"><h3 class="font-display font-semibold text-sm">Pemakaian BBM Sapras</h3><div class="text-[11px]" style="color:var(--muted);">${esc(sub)}</div></div>${body}</div>`;
}
/* ---- Grafik Sapras: Bar BBM, Bar Endurance, Bar Speed & Range, Weather Indicator (hasil pengecekan Siaga Rutin terakhir per kendaraan) ---- */
function saprasTerakhir(){
  const per = {};
  (DB.siaga_rutin||[]).filter(r=>siagaIsSarana(r.jenis)).forEach(r=>{
    const sap = r.sapras==='Sapras Laut' ? 'Sapras Laut' : 'Sapras Darat';
    const tgl = String(r.tanggal||'').slice(0,10), ts = new Date(tgl+'T'+(r.waktu||'00:00')).getTime();
    if(isNaN(ts)) return;
    (r.items||[]).forEach(i=>{
      const key = sap+'|'+(i.nopol||i.nama||'-');
      const k = per[key] = per[key] || {nama:(i.nama||i.nopol||'-'), sapras:sap, f:{}};
      Object.keys(i.vals||{}).forEach(f=>{
        const v = i.vals[f]; if(v===undefined || v==='' || v===null) return;
        if(!k.f[f] || ts>=k.f[f].ts) k.f[f] = {ts, tgl, v};
      });
    });
  });
  return Object.values(per).sort((a,b)=>a.nama.localeCompare(b.nama));
}
function saprasNum(k, f){ const o = k.f[f]; if(!o) return null; const n = parseFloat(o.v); return isNaN(n) ? null : n; }
const SAPRAS_ARAH = {'utara':0,'u':0,'timur laut':45,'tl':45,'timur':90,'t':90,'tenggara':135,'tg':135,'selatan':180,'s':180,'barat daya':225,'bd':225,'barat':270,'b':270,'barat laut':315,'bl':315};
function saprasArahDeg(t){
  const x = String(t==null?'':t).trim().toLowerCase(); if(!x) return null;
  if(SAPRAS_ARAH[x]!==undefined) return SAPRAS_ARAH[x];
  const n = parseFloat(x); return (!isNaN(n) && /^[\d.,\s°]+(derajat)?$/.test(x)) ? ((n%360)+360)%360 : null;
}
// Ambang indikatif kecepatan angin (knot): < 10 Aman, 10–<20 Waspada, >= 20 Berbahaya
function saprasAnginStatus(v){ return v>=20 ? {t:'Berbahaya', c:'var(--bad)', b:'b-bad'} : v>=10 ? {t:'Waspada', c:'var(--warn)', b:'b-warn'} : {t:'Aman', c:'var(--ok)', b:'b-ok'}; }
function saprasBarCard(title, sub, cats, vals, color, unit, colors){
  const body = cats.length ? chBarSvg(cats, [Object.assign({name:unit, values:vals}, colors ? {colors} : {color})]) : chEmpty('Belum ada data hasil pengecekan.');
  return `<div class="panel rounded-xl p-4 lg:col-span-3"><div class="mb-2"><h3 class="font-display font-semibold text-sm">${esc(title)}</h3><div class="text-[11px]" style="color:var(--muted);">${esc(sub)}</div></div>${body}</div>`;
}
function saprasChartsHtml(){
  const all = saprasTerakhir(), laut = all.filter(k=>k.sapras==='Sapras Laut');
  const ket = 'Hasil pengecekan Siaga Rutin terakhir tiap kendaraan';
  // 1) BBM
  const bbm = all.map(k=>({k, v:saprasNum(k, k.sapras==='Sapras Laut' ? 'persen_bbm' : 'bbm')})).filter(x=>x.v!==null);
  const bbmLegend = `<div class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-1 text-xs"><span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:var(--ok);display:inline-block;"></span><span style="color:var(--muted);">BBM Tersedia (≥ 50%)</span></span><span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:var(--bad);display:inline-block;"></span><span style="color:var(--muted);">BBM Hampir Habis (&lt; 50%)</span></span></div>`;
  let cBbm = saprasBarCard('Bar Chart BBM', ket+' · BBM (%)', bbm.map(x=>x.k.nama), bbm.map(x=>x.v), null, 'BBM (%)', bbm.map(x=>x.v<50?'var(--bad)':'var(--ok)'));
  if(bbm.length) cBbm = cBbm.replace(/<\/div>$/, bbmLegend+'</div>');
  // 2) Endurance
  const en = laut.map(k=>({k, v:saprasNum(k,'endurance')})).filter(x=>x.v!==null);
  const cEn = saprasBarCard('Bar Chart Endurance', 'Sapras Laut · '+ket+' · Jam', en.map(x=>x.k.nama), en.map(x=>x.v), 'var(--navy-800)', 'Endurance (jam)');
  // 3) Speed & Range
  const sp = laut.map(k=>({k, v:saprasNum(k,'speed')})).filter(x=>x.v!==null);
  const rg = laut.map(k=>({k, v:saprasNum(k,'range')})).filter(x=>x.v!==null);
  const mini = (jud, arr, color, unit) => `<div><div class="text-xs font-semibold mb-1" style="color:var(--navy-800);">${jud}</div>${arr.length ? chBarSvg(arr.map(x=>x.k.nama), [{name:unit, color, values:arr.map(x=>x.v)}]) : chEmpty('Belum ada data.')}</div>`;
  const cSR = `<div class="panel rounded-xl p-4 lg:col-span-3"><div class="mb-2"><h3 class="font-display font-semibold text-sm">Bar Chart Speed &amp; Range</h3><div class="text-[11px]" style="color:var(--muted);">Sapras Laut · ${esc(ket)}</div></div><div class="grid grid-cols-1 lg:grid-cols-2 gap-4">${mini('Speed (knot)', sp, 'var(--orange-500)', 'Speed (knot)')}${mini('Range (nm)', rg, 'var(--navy-800)', 'Range (nm)')}</div></div>`;
  // 4) Weather Indicator (angin Sapras Laut)
  const ang = laut.map(k=>({k, v:saprasNum(k,'kecepatan_angin'), arah:(k.f.arah_angin||{}).v||'', ts:Math.max((k.f.kecepatan_angin||{}).ts||0, (k.f.arah_angin||{}).ts||0), tgl:(k.f.kecepatan_angin||k.f.arah_angin||{}).tgl||''}))
    .filter(x=>x.v!==null || x.arah!=='').sort((a,b)=>b.ts-a.ts);
  let cW;
  if(!ang.length){
    cW = `<div class="panel rounded-xl p-4 lg:col-span-3"><div class="mb-2"><h3 class="font-display font-semibold text-sm">Weather Indicator</h3><div class="text-[11px]" style="color:var(--muted);">Kecepatan &amp; arah angin Sapras Laut</div></div>${chEmpty('Belum ada data angin pada pengecekan Sapras Laut.')}</div>`;
  } else {
    const t = ang[0], st = t.v!==null ? saprasAnginStatus(t.v) : null, deg = saprasArahDeg(t.arah);
    const kompas = `<svg viewBox="0 0 180 180" width="100%" style="max-width:190px;display:block;margin:0 auto;" role="img" aria-label="Kompas arah angin">
      <circle cx="90" cy="90" r="62" fill="none" style="stroke:var(--line);" stroke-width="2"/>
      ${[['U',90,24],['T',160,94],['S',90,164],['B',20,94]].map(q=>`<text x="${q[1]}" y="${q[2]}" text-anchor="middle" font-size="12" font-weight="600" style="${CH_FONT}fill:var(--muted);">${q[0]}</text>`).join('')}
      ${deg!==null ? `<g transform="rotate(${deg} 90 90)"><polygon points="90,34 99,90 90,82 81,90" style="fill:${st?st.c:'var(--navy-800)'};"/><polygon points="90,146 99,90 90,98 81,90" style="fill:var(--line);"/></g>` : ''}
      <circle cx="90" cy="90" r="4" style="fill:var(--navy-800);"/>
    </svg>`;
    const baris = ang.map(x=>{ const s2 = x.v!==null ? saprasAnginStatus(x.v) : null;
      return `<div class="flex items-center justify-between gap-2 py-1.5 border-b last:border-0 text-xs" style="border-color:var(--line);"><span class="truncate">${esc(x.k.nama)} <span style="color:var(--muted);">· ${x.v!==null ? esc(String(x.v))+' knot' : '-'}${x.arah ? ' · '+esc(x.arah) : ''}${x.tgl ? ' · '+esc(tglDMY(x.tgl)) : ''}</span></span>${s2 ? `<span class="badge ${s2.b}">${s2.t}</span>` : ''}</div>`; }).join('');
    cW = `<div class="panel rounded-xl p-4 lg:col-span-3"><div class="mb-2"><h3 class="font-display font-semibold text-sm">Weather Indicator</h3><div class="text-[11px]" style="color:var(--muted);">Kecepatan &amp; arah angin dari pengecekan Sapras Laut terakhir · ambang indikatif: &lt; 10 knot Aman, 10–&lt; 20 Waspada, ≥ 20 Berbahaya</div></div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
        <div>${kompas}<div class="text-center mt-2"><div class="font-display" style="font-size:26px;font-weight:700;color:${st?st.c:'var(--navy-800)'};">${t.v!==null ? esc(String(t.v))+' knot' : '-'}</div><div class="text-xs" style="color:var(--muted);">${t.arah ? 'Arah angin: '+esc(t.arah) : 'Arah angin belum diisi'}</div>${st ? `<span class="badge ${st.b} mt-1">${st.t}</span>` : ''}<div class="text-[11px] mt-1" style="color:var(--muted);">${esc(t.k.nama)}${t.tgl ? ' · '+esc(tglDMY(t.tgl)) : ''}</div></div></div>
        <div class="menunggu-scroll" style="max-height:260px;">${baris}</div>
      </div></div>`;
  }
  return cBbm + cEn + cSR + cW;
}
/* ---- Bagikan Dashboard: link lihat-saja tanpa login ---- */
async function bagikanApi(aksi){
  const r = await fetch(API_URL+'?action=bagikan&aksi='+aksi, {method: aksi==='get'?'GET':'POST', headers:{'X-Requested-With':'basarnas'}, cache:'no-store'});
  if(r.status===401){ location.href='login.php'; throw new Error('Sesi berakhir'); }
  const j = await r.json(); if(!r.ok || !j.ok) throw new Error(j.error||('HTTP '+r.status));
  return j.kode||'';
}
function bagikanLink(kode){ return kode ? new URL('index.php?tampil='+kode, location.href).href : ''; }
async function bagikanDashboard(){
  // Satu link saja: ambil link yang sudah ada (atau buat otomatis bila belum ada), lalu langsung disalin.
  try{
    let kode = '';
    try{ kode = await bagikanApi('get'); }catch(e){ kode = ''; }
    if(!kode) kode = await bagikanApi('buat');
    const link = bagikanLink(kode);
    const tampilkan = () => openModal(`
      <div class="p-5">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-display font-semibold text-base">Link Dashboard</h3>
          <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
        </div>
        <input type="text" id="bagikan_link" readonly value="${esc(link)}" onfocus="this.select()">
        <p class="text-xs mt-2" style="color:var(--muted);">Salin link di atas (Ctrl+C).</p>
      </div>`);
    if(navigator.clipboard && navigator.clipboard.writeText){
      navigator.clipboard.writeText(link).then(()=>toast('Link dashboard disalin'), tampilkan);
    } else tampilkan();
  }catch(e){ console.error(e); toast('Gagal mengambil link: '+e.message); }
}
function renderDashboard(){
  const inThn = r => inPeriode(r, 'dashboard');
  const counts = GENERIC_MODULES.map(m=>({label:m.label, n:(DB[m.key]||[]).filter(inThn).length}));
  const kegiatanTahun = (DB.kegiatan||[]).filter(inThn);
  const kegiatanCount = kegiatanTahun.length;
  const anggotaCount = DB.anggota.length;
  const anggotaAktif = DB.anggota.filter(a=>a.status==='Aktif').length;
  const menunggu = kegiatanTahun.filter(k=>k.status_persetujuan==='Menunggu Persetujuan');
  const maxN = Math.max(kegiatanCount, anggotaCount, ...counts.map(c=>c.n), 1);

  const potensiRows = (DB.potensi || []).filter(inThn);
  const potensiTersertifikasi = potensiRows.reduce((s,r)=>s+(parseFloat(r.jumlah_tersertifikasi)||0),0);
  const potensiBelumSertifikasi = potensiRows.reduce((s,r)=>s+(parseFloat(r.jumlah_belum_sertifikasi)||0),0);

  const ujiPeriodikRows = (DB.uji_periodik || []).filter(inThn);
  // Kegiatan uji periodik terbaru (tanggal mulai terbaru; jika sama/kosong, data yang paling akhir diinput)
  const ujiTerbaru = ujiPeriodikRows.reduce((best, r)=>{
    if(!best) return r;
    return String(r.tanggal_mulai||'') >= String(best.tanggal_mulai||'') ? r : best;
  }, null);
  let ujiPeriodikLulus = 0, ujiPeriodikTidakLulus = 0, ujiPesertaTotal = 0;
  if(ujiTerbaru){
    const ujiRekap = ujiPesertaRekap(ujiTerbaru.id);
    ujiPesertaTotal = ujiRekap.length;
    ujiRekap.filter(o=>o.akhir!==null).forEach(o=>{
      if(o.predikat==='Cukup' || o.predikat==='Baik' || o.predikat==='Sangat Baik') ujiPeriodikLulus++;
      else ujiPeriodikTidakLulus++;
    });
  }

  const cardData = [
    {label:'Kegiatan/Tugas', n:kegiatanCount},
    ...counts.filter(c=>c.label!=='Rescuer'),
    {label:'Daftar Anggota', n:anggotaCount},
  ];

  // Peralatan: total unit + rincian per kategori (stok, tidak difilter periode).
  const peralatanRows = DB.peralatan || [];
  const peralatanKatField = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='kategori');
  const peralatanPerKat = peralatanKatField.options.map(k=>({
    label:k,
    n:peralatanRows.filter(r=>r.kategori===k).reduce((s,r)=>s+peralatanJumlah(r),0)
  }));
  const peralatanTotal = peralatanRows.reduce((s,r)=>s+peralatanJumlah(r),0);
  const gridData = cardData.slice(0,-1).concat([{label:'Peralatan', n:peralatanTotal}, {label:'Surat Masuk', n:(DB.surat_masuk||[]).length}, {label:'Surat Keluar', n:suratKeluarSemua().length}], cardData.slice(-1)).filter(c=>!(isAnggotaOnly() && ['Daftar Anggota','Surat Masuk','Surat Keluar'].includes(c.label)));

  document.getElementById('app').innerHTML = `
    <div class="periode-bar">
      ${VIEW_ONLY ? '' : `<button type="button" class="btn btn-primary" onclick="bagikanDashboard()" title="Buat link untuk melihat dashboard tanpa login (hanya lihat)">Bagikan Dashboard</button>`}
      <span class="text-xs font-medium ml-auto" style="color:var(--muted);">Periode</span>
      ${periodeSelectHtml('dashboard')}
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
      ${gridData.map((c,i)=>`
        <div class="panel stat-card rounded-xl p-4">
          <div class="stat-label text-xs mb-1" style="color:var(--muted);">${esc(c.label)}</div>
          <div class="stat-num font-display font-bold text-2xl" style="color:${i%3===0?'var(--orange-500)':'var(--navy-800)'}">${c.n}</div>
          ${c.label==='Peralatan' ? `
            <div class="mt-2 pt-2 border-t text-[11px] space-y-0.5" style="border-color:var(--line);">
              ${peralatanPerKat.map(k=>`
                <div class="flex items-center justify-between gap-2 min-w-0">
                  <span class="truncate" style="color:var(--muted);">${esc(k.label)}</span>
                  <span class="font-semibold" style="color:var(--navy-800)">${k.n}</span>
                </div>`).join('')}
            </div>` : ''}
          ${c.label==='Potensi' ? `
            <div class="stat-foot">
              <span class="sf-item"><span style="color:var(--muted);">Tersertifikasi</span> <span class="font-semibold" style="color:var(--navy-800)">${potensiTersertifikasi}</span></span>
              <span class="sf-sep">|</span>
              <span class="sf-item"><span style="color:var(--muted);">Belum</span> <span class="font-semibold" style="color:var(--orange-500)">${potensiBelumSertifikasi}</span></span>
            </div>` : ''}
          ${c.label==='Uji Periodik' ? `
            <div class="text-[11px] mt-1 truncate" style="color:var(--muted);" title="Kegiatan terbaru">${ujiTerbaru ? esc(ujiTerbaru.nama_uji||'-') : 'Belum ada kegiatan'}</div>
            <div class="text-[11px] mt-0.5" style="color:var(--muted);">Peserta <span class="font-semibold" style="color:var(--navy-800)">${ujiPesertaTotal}</span></div>
            <div class="stat-foot">
              <span class="sf-item"><span style="color:var(--muted);">Lulus</span> <span class="font-semibold" style="color:var(--navy-800)">${ujiPeriodikLulus}</span></span>
              <span class="sf-sep">|</span>
              <span class="sf-item"><span style="color:var(--muted);">Tidak Lulus</span> <span class="font-semibold" style="color:var(--orange-500)">${ujiPeriodikTidakLulus}</span></span>
            </div>` : ''}
        </div>`).join('')}
    </div>

    ${isAnggotaOnly() ? '' : dashNotifHtml(kegiatanTahun)}
    ${dashChartsHtml({kegiatanTahun, potensiRows, lulus:ujiPeriodikLulus, tidakLulus:ujiPeriodikTidakLulus, ujiNama:ujiTerbaru ? (ujiTerbaru.nama_uji||'-') : '', ujiPeserta:ujiPesertaTotal})}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
      <div class="panel rounded-xl p-4 ${isAnggotaOnly() ? 'lg:col-span-5' : 'lg:col-span-3'}">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-display font-semibold text-sm">Jumlah Data per Program</h3>
        </div>
        ${cardData.filter(c=>!(isAnggotaOnly() && c.label==='Daftar Anggota')).map(c=>`
          <div class="barrow">
            <span class="truncate" style="color:var(--muted)">${esc(c.label)}</span>
            <div class="bartrack"><div class="barfill" style="width:${Math.max(4,(c.n/maxN)*100)}%"></div></div>
            <span class="text-right font-semibold">${c.n}</span>
          </div>`).join('')}
      </div>

      ${isAnggotaOnly() ? '' : `
      <div class="panel rounded-xl p-4 lg:col-span-2">
        <div class="flex items-center justify-between mb-3">
          <h3 class="font-display font-semibold text-sm">Menunggu Persetujuan</h3>
          <span class="badge b-warn">${menunggu.length}</span>
        </div>
        ${menunggu.length ? `<div ${menunggu.length > 6 ? 'class="menunggu-scroll"' : ''}>` + menunggu.slice().reverse().map(k=>`
          <div class="flex items-start justify-between gap-2 py-2 border-b last:border-0" style="border-color:var(--line);">
            <div class="min-w-0">
              <div class="text-sm font-medium truncate">${esc(k.nama)}</div>
              <div class="text-xs" style="color:var(--muted);">${esc(k.jenis_label)} · ${esc(tglRentang(k))}</div>
            </div>
            <button class="btn btn-ghost !py-1 !px-2 text-xs flex-shrink-0" onclick="openKegiatanDetail('${k.id}')">Detail</button>
          </div>`).join('') + `</div>` : `<p class="text-sm" style="color:var(--muted);">Tidak ada kegiatan yang menunggu persetujuan.</p>`}
        ${isAnggotaOnly() ? '' : `<div class="mt-3 pt-3 border-t text-xs" style="border-color:var(--line); color:var(--muted);">
          Anggota aktif: <strong style="color:var(--text)">${anggotaAktif}</strong> dari ${anggotaCount}
        </div>`}
      </div>`}
    </div>
    <div class="dash-tables">${dashTablesHtml()}</div>
  `;
}

/* ============ Dashboard tampilan publik (link Bagikan Dashboard) ============
   Layout khusus yang lebih menarik: hero + KPI, kartu program beraikon, ringkasan kegiatan,
   lalu seluruh grafik & tabel yang sama dengan dashboard biasa (tetap lihat saja). */
const PUB_ICONS = {
  flag:'<path d="M4 22V4M4 4h13l-2 4 2 4H4"/>', school:'<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
  wrench:'<path d="M14.7 6.3a4 4 0 0 0 5 5L21 14l-7 7-3-3 7-7"/><path d="m3 21 6-6"/><path d="M10 5 6 9"/>', shield:'<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3z"/><path d="m9 12 2 2 4-4"/>',
  mic:'<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>', award:'<circle cx="12" cy="9" r="6"/><path d="m8.5 14-1.5 8 5-3 5 3-1.5-8"/>',
  users:'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>', heart:'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
  check:'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 14l2 2 4-4"/>', grid:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  box:'<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8"/>', inbox:'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l3.5-7z"/>',
  send:'<path d="m22 2-11 11M22 2l-7 20-4-9-9-4 20-7z"/>', dot:'<circle cx="12" cy="12" r="4"/>', clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', play:'<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>', done:'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'
};
function pubSvg(name, size){ size = size||20; return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${PUB_ICONS[name]||PUB_ICONS.dot}</svg>`; }
function pubIconFor(label){
  const l = String(label||'').toLowerCase();
  if(/surat masuk/.test(l)) return 'inbox'; if(/surat keluar/.test(l)) return 'send';
  if(/kegiatan|tugas/.test(l)) return 'flag'; if(/school|sekolah/.test(l)) return 'school';
  if(/bimtek/.test(l)) return 'wrench'; if(/potensi/.test(l)) return 'shield'; if(/narasumber/.test(l)) return 'mic';
  if(/diklat/.test(l)) return 'award'; if(/pembinaan|anggota|rescuer/.test(l)) return 'users'; if(/pemberdayaan/.test(l)) return 'heart';
  if(/uji/.test(l)) return 'check'; if(/pemeliharaan/.test(l)) return 'wrench'; if(/peralatan/.test(l)) return 'box'; if(/lainnya/.test(l)) return 'grid';
  return 'dot';
}
let pubClockTimer = null;
function pubTickClock(){
  const el = document.getElementById('pubJam'), tg = document.getElementById('pubTgl');
  if(!el || !tg){ if(pubClockTimer){ clearInterval(pubClockTimer); pubClockTimer = null; } return; }
  const d = new Date();
  el.textContent = d.toLocaleTimeString('id-ID',{hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false}).replace(/\./g,':');
  tg.textContent = d.toLocaleDateString('id-ID',{weekday:'long', day:'numeric', month:'long', year:'numeric'});
}
function pubCountUp(){
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('#app [data-count]').forEach(el=>{
    const to = parseFloat(el.getAttribute('data-count')) || 0;
    if(reduce || to <= 0 || to > 100000){ el.textContent = to.toLocaleString('id-ID'); return; }
    const t0 = performance.now(), dur = 800;
    const step = now => { const k = Math.min(1,(now-t0)/dur), e = 1-Math.pow(1-k,3); el.textContent = Math.round(to*e).toLocaleString('id-ID'); if(k<1 && document.body.contains(el)) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  });
}
function renderDashboardPublik(){
  const inThn = r => inPeriode(r, 'dashboard');
  const counts = GENERIC_MODULES.map(m=>({label:m.label, n:(DB[m.key]||[]).filter(inThn).length}));
  const kegiatanTahun = (DB.kegiatan||[]).filter(inThn);
  const kegiatanCount = kegiatanTahun.length;
  const anggotaCount = (DB.anggota||[]).length;
  const anggotaAktif = (DB.anggota||[]).filter(a=>a.status==='Aktif').length;
  const nDirencanakan = kegiatanTahun.filter(k=>k.status_pelaksanaan!=='Dalam Pelaksanaan' && k.status_pelaksanaan!=='Selesai').length;
  const nBerlangsung = kegiatanTahun.filter(k=>k.status_pelaksanaan==='Dalam Pelaksanaan').length;
  const nSelesai = kegiatanTahun.filter(k=>k.status_pelaksanaan==='Selesai').length;
  const nMenunggu = kegiatanTahun.filter(k=>k.status_persetujuan==='Menunggu Persetujuan').length;

  const potensiRows = (DB.potensi || []).filter(inThn);
  const potSert = potensiRows.reduce((t,r)=>t+(parseFloat(r.jumlah_tersertifikasi)||0),0);
  const potBelum = potensiRows.reduce((t,r)=>t+(parseFloat(r.jumlah_belum_sertifikasi)||0),0);

  const ujiRows = (DB.uji_periodik || []).filter(inThn);
  const ujiTerbaru = ujiRows.reduce((best, r)=> !best ? r : (String(r.tanggal_mulai||'') >= String(best.tanggal_mulai||'') ? r : best), null);
  let ujiLulus = 0, ujiTidak = 0, ujiPeserta = 0;
  if(ujiTerbaru){
    const rk = ujiPesertaRekap(ujiTerbaru.id); ujiPeserta = rk.length;
    rk.filter(o=>o.akhir!==null).forEach(o=>{ if(o.predikat==='Cukup' || o.predikat==='Baik' || o.predikat==='Sangat Baik') ujiLulus++; else ujiTidak++; });
  }

  const peralatanRows = DB.peralatan || [];
  const katField = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='kategori');
  const peralatanPerKat = katField.options.map(k=>({label:k, n:peralatanRows.filter(r=>r.kategori===k).reduce((t,r)=>t+peralatanJumlah(r),0)}));
  const peralatanTotal = peralatanRows.reduce((t,r)=>t+peralatanJumlah(r),0);
  const kendaraanTotal = (DB.kendaraan||[]).length;

  const cardData = [{label:'Kegiatan/Tugas', n:kegiatanCount}, ...counts.filter(c=>c.label!=='Rescuer'), {label:'Daftar Anggota', n:anggotaCount}];
  const gridData = cardData.slice(0,-1).concat([{label:'Peralatan', n:peralatanTotal}, {label:'Surat Masuk', n:(DB.surat_masuk||[]).length}, {label:'Surat Keluar', n:suratKeluarSemua().length}], cardData.slice(-1));
  const maxN = Math.max(...gridData.map(c=>c.n), 1);
  const warna = ['#F2542D','#13315C','#0F9D58','#2C5F9E','#B7791F','#7C5CBF'];
  const p = getPeriode('dashboard');
  const periodeTxt = (p.m ? BULAN_NAMA[p.m-1]+' ' : '') + (p.y || 'Semua Tahun');

  const pct = n => kegiatanCount ? (n/kegiatanCount*100) : 0;
  const lblPel = {'Draft':'Direncanakan','Dalam Pelaksanaan':'Berlangsung','Selesai':'Selesai'};
  const terbaru = kegiatanTahun.slice().reverse().slice(0,8);

  const kpi = (ico, label, val, sub) => `<div class="pub-kpi"><div class="l">${pubSvg(ico,15)} ${esc(label)}</div><div class="v font-display" data-count="${val}">${val}</div><div class="s">${esc(sub||'')}</div></div>`;

  document.getElementById('app').innerHTML = `
    <section class="pub-hero">
      <div class="pub-top">
        <div class="min-w-0">
          <span class="pub-chip"><span class="pub-dot"></span> LIVE · MODE LIHAT SAJA</span>
          <h2 class="font-display">Dashboard Program &amp; Kegiatan SAR</h2>
          <p class="sub">Pantauan terpadu kegiatan, kesiapan sarana-prasarana, potensi SAR, dan persuratan BASARNAS. Data diperbarui otomatis.</p>
        </div>
        <div class="pub-clock">
          <div class="jam font-display" id="pubJam">--:--:--</div>
          <div class="tgl" id="pubTgl"></div>
          <div class="text-xs" style="color:rgba(255,255,255,.7);margin-top:.5rem;">Periode data</div>
          ${periodeSelectHtml('dashboard')}
        </div>
      </div>
      <div class="pub-kpis">
        ${kpi('flag','Kegiatan/Tugas', kegiatanCount, periodeTxt)}
        ${kpi('play','Berlangsung', nBerlangsung, 'sedang dilaksanakan')}
        ${kpi('done','Selesai', nSelesai, 'tugas tuntas')}
        ${kpi('box','Peralatan', peralatanTotal, kendaraanTotal+' kendaraan terdata')}
        ${kpi('users','Anggota Aktif', anggotaAktif, 'dari '+anggotaCount+' anggota')}
      </div>
    </section>

    <div class="pub-sec"><h3 class="font-display">Ringkasan Program</h3><small>Periode ${esc(periodeTxt)}</small></div>
    <div class="pub-grid">
      ${gridData.map((c,i)=>`
        <div class="pub-card" style="--c:${warna[i%warna.length]}; animation-delay:${Math.min(i,12)*40}ms;">
          <div class="flex items-start gap-3">
            <div class="pub-ico">${pubSvg(pubIconFor(c.label))}</div>
            <div class="nm min-w-0">${esc(c.label)}</div>
          </div>
          <div class="num font-display" data-count="${c.n}">${c.n}</div>
          <div class="pub-meter"><i style="width:${Math.max(c.n?4:0,(c.n/maxN)*100)}%"></i></div>
          ${c.label==='Peralatan' ? `<div class="pub-sub">${peralatanPerKat.map(k=>`<div><span>${esc(k.label)}</span><b>${k.n}</b></div>`).join('')}</div>` : ''}
          ${c.label==='Potensi' ? `<div class="pub-sub"><div><span>Tersertifikasi</span><b>${potSert}</b></div><div><span>Belum</span><b style="color:var(--orange-500)">${potBelum}</b></div></div>` : ''}
          ${c.label==='Uji Periodik' ? `<div class="pub-sub"><div title="Kegiatan terbaru"><span>${ujiTerbaru ? esc(ujiTerbaru.nama_uji||'-') : 'Belum ada kegiatan'}</span></div><div><span>Peserta</span><b>${ujiPeserta}</b></div><div><span>Lulus</span><b style="color:var(--ok)">${ujiLulus}</b></div><div><span>Tidak Lulus</span><b style="color:var(--bad)">${ujiTidak}</b></div></div>` : ''}
          ${c.label==='Daftar Anggota' ? `<div class="pub-sub"><div><span>Aktif</span><b style="color:var(--ok)">${anggotaAktif}</b></div><div><span>Tidak aktif</span><b>${Math.max(0,anggotaCount-anggotaAktif)}</b></div></div>` : ''}
        </div>`).join('')}
    </div>

    <div class="pub-sec"><h3 class="font-display">Status Kegiatan/Tugas</h3><small>${kegiatanCount} kegiatan · ${nMenunggu} menunggu persetujuan</small></div>
    <div class="pub-two">
      <div class="panel rounded-xl p-4" style="border-radius:1rem;">
        <h4 class="font-display font-semibold text-sm">Progres Pelaksanaan</h4>
        <div class="pub-stack" role="img" aria-label="Komposisi status pelaksanaan kegiatan">
          <i style="width:${pct(nSelesai)}%; background:var(--ok);"></i>
          <i style="width:${pct(nBerlangsung)}%; background:var(--orange-500);"></i>
          <i style="width:${pct(nDirencanakan)}%; background:var(--navy-600);"></i>
        </div>
        <div class="pub-legend">
          <span><span class="d" style="background:var(--ok)"></span>Selesai <b>${nSelesai}</b></span>
          <span><span class="d" style="background:var(--orange-500)"></span>Berlangsung <b>${nBerlangsung}</b></span>
          <span><span class="d" style="background:var(--navy-600)"></span>Direncanakan <b>${nDirencanakan}</b></span>
        </div>
        <div class="mt-4 pt-3 text-xs" style="border-top:1px solid var(--line); color:var(--muted);">
          Tingkat penyelesaian: <b style="color:var(--text)">${kegiatanCount ? Math.round(nSelesai/kegiatanCount*100) : 0}%</b>
          · Menunggu persetujuan: <b style="color:var(--text)">${nMenunggu}</b>
        </div>
      </div>
      <div class="panel rounded-xl p-4" style="border-radius:1rem;">
        <h4 class="font-display font-semibold text-sm mb-1">Kegiatan/Tugas Terbaru</h4>
        ${terbaru.length ? terbaru.map(k=>`
          <div class="pub-item">
            <div class="min-w-0"><div class="t">${esc(k.nama)}</div><div class="m">${esc(k.jenis_label||'-')} · ${esc(tglRentang(k))}${k.lokasi ? ' · '+esc(k.lokasi) : ''}</div></div>
            <div class="flex-shrink-0">${statusBadge(lblPel[k.status_pelaksanaan] || k.status_pelaksanaan || 'Direncanakan')}</div>
          </div>`).join('') : '<p class="text-sm" style="color:var(--muted);">Belum ada kegiatan pada periode ini.</p>'}
      </div>
    </div>

    <div class="pub-sec"><h3 class="font-display">Grafik &amp; Analisis</h3><small>Tren kegiatan, potensi, uji periodik, sarana prasarana, cuaca</small></div>
    ${dashChartsHtml({kegiatanTahun, potensiRows, lulus:ujiLulus, tidakLulus:ujiTidak, ujiNama:ujiTerbaru ? (ujiTerbaru.nama_uji||'-') : '', ujiPeserta})}

    <div class="pub-sec"><h3 class="font-display">Data Rinci</h3><small>Tabel lengkap per modul</small></div>
    <div class="dash-tables">${dashTablesHtml()}</div>
    <div class="pub-foot">BASARNAS · Dashboard Program SAR · Tampilan publik hanya-lihat, diperbarui otomatis tiap 5 menit.</div>
  `;
  pubTickClock();
  if(pubClockTimer) clearInterval(pubClockTimer);
  pubClockTimer = setInterval(pubTickClock, 1000);
  pubCountUp();
}

/* ---- Notifikasi tindak lanjut Kegiatan/Tugas (setelah Surat Masuk/Kegiatan di-ACC) ----
   1) Disetujui tetapi belum ada anggota bertugas yang dipilih.
   2) Anggota sudah dipilih tetapi Sprin belum diupload.
   Kegiatan yang sudah Selesai tidak ditampilkan. Mengikuti filter Periode dashboard. */
function dashNotifPanel(title, items, emptyText, subFn, headExtra, id){
  return `<div class="panel rounded-xl p-4" ${id?`id="${id}"`:''}>
    <div class="flex items-center justify-between gap-2 mb-3">
      <div class="flex items-center gap-2 flex-wrap min-w-0">
        <h3 class="font-display font-semibold text-sm">${esc(title)}</h3>
        ${headExtra||''}
      </div>
      <span class="badge ${items.length ? 'b-warn' : 'b-ok'}">${items.length}</span>
    </div>
    ${items.length ? `<div ${items.length > 6 ? 'class="menunggu-scroll"' : ''}>` + items.slice().reverse().map(k=>`
      <div class="flex items-start justify-between gap-2 py-2 border-b last:border-0" style="border-color:var(--line);">
        <div class="min-w-0">
          <div class="text-sm font-medium truncate">${esc(k.nama)}</div>
          <div class="text-xs" style="color:var(--muted);">${subFn(k)}</div>
        </div>
        <button class="btn btn-ghost !py-1 !px-2 text-xs flex-shrink-0" onclick="openKegiatanDetail('${k.id}')">Detail</button>
      </div>`).join('') + `</div>` : `<p class="text-sm" style="color:var(--muted);">${esc(emptyText)}</p>`}
  </div>`;
}
/* Panel "Sprin Belum Dibuat": filter keterangan (Belum/Sedang Di Proses) + kirim daftar ke grup WhatsApp. */
let sprinPanelFilter = '';
let sprinPanelItems = [];
function sprinPanelFiltered(){
  return sprinPanelFilter ? sprinPanelItems.filter(k=>sprinStatusLabel(k)===sprinPanelFilter) : sprinPanelItems;
}
function sprinPanelHtml(){
  const items = sprinPanelFiltered();
  const sub = k => `${esc(k.jenis_label)} · ${esc(tglRentang(k))} · ${(k.anggota_ids||[]).length} anggota · ${esc(sprinStatusLabel(k))}`;
  const ctrl = `
    <select style="width:auto;" class="!py-1 text-xs" aria-label="Filter keterangan Sprin" onchange="setSprinPanelFilter(this.value)">
      <option value="" ${sprinPanelFilter===''?'selected':''}>Semua</option>
      <option value="Belum Di Proses" ${sprinPanelFilter==='Belum Di Proses'?'selected':''}>Belum Di Proses</option>
      <option value="Sedang Di Proses" ${sprinPanelFilter==='Sedang Di Proses'?'selected':''}>Sedang Di Proses</option>
    </select>
    <button class="btn !py-1 !px-2 text-xs inline-flex items-center gap-1" style="background:#25D366;color:#fff;" title="Buka WhatsApp dan pilih grup" aria-label="Kirim lewat WhatsApp" onclick="kirimSprinKeGrup()" ${items.length?'':'disabled'}>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2zm0 2a8 8 0 1 1-4.2 14.8l-.4-.2-2.4.6.7-2.3-.2-.4A8 8 0 0 1 12 4zm-3 3.500c-.2 0-.5.1-.7.4-.3.300-.9.900-.9 2.100s.9 2.400 1 2.600c.1.200 1.800 2.800 4.400 3.800 2.200.8 2.600.7 3.100.6.5-.1 1.500-.6 1.700-1.200.2-.6.2-1.100.1-1.200-.1-.1-.3-.2-.6-.3l-1.800-.9c-.2-.1-.4-.1-.6.100l-.8 1c-.1.200-.3.200-.5.100-.9-.4-2-1.100-2.800-2.400-.1-.2 0-.3.100-.5l.4-.5.3-.5v-.5l-.8-1.900c-.2-.5-.4-.4-.6-.4z"/></svg>
      WA
    </button>`;
  return dashNotifPanel('Sprin Belum Dibuat', items,
    sprinPanelFilter ? `Tidak ada Sprin berstatus "${sprinPanelFilter}".` : 'Semua kegiatan yang sudah ada anggotanya sudah memiliki Sprin.',
    sub, ctrl, 'panel-sprin-belum');
}
function setSprinPanelFilter(v){
  sprinPanelFilter = v;
  const el = document.getElementById('panel-sprin-belum');
  if(el) el.outerHTML = sprinPanelHtml();
}
/* Membuka WhatsApp (aplikasi/WhatsApp Web) dengan pesan sudah terisi; grup tujuan dipilih manual oleh pengirim. */
function kirimSprinKeGrup(){
  const items = sprinPanelFiltered();
  if(!items.length){ toast('Tidak ada Sprin untuk dikirim'); return; }
  const daftar = arr => arr.slice().reverse().map((k,i)=>`${i+1}. ${k.nama} (${tglRentang(k)})`).join('\n');
  const sedang = items.filter(k=>sprinStatusLabel(k)==='Sedang Di Proses');
  const belum  = items.filter(k=>sprinStatusLabel(k)!=='Sedang Di Proses');
  const blok = [];
  if(sprinPanelFilter==='Belum Di Proses'){
    blok.push(`*Sprin Belum Di Buat*\n\n${daftar(belum)}`);
  }else{
    if(sedang.length) blok.push(`*Sprin Sedang Di Proses*\n${daftar(sedang)}`);
    if(belum.length)  blok.push(`*Sprin Belum Dibuat*\n${daftar(belum)}`);
  }
  const w = window.open('https://wa.me/?text=' + encodeURIComponent(blok.join('\n\n')), '_blank', 'noopener');
  if(!w) toast('Pop-up diblokir browser, izinkan pop-up lalu coba lagi');
}
function dashNotifHtml(kegiatanList){
  const aktif = kegiatanList.filter(k=>k.status_persetujuan==='Disetujui' && k.status_pelaksanaan!=='Selesai');
  const belumAnggota = aktif.filter(k=>!(k.anggota_ids||[]).length);
  sprinPanelItems = aktif.filter(k=>(k.anggota_ids||[]).length && !k.sprin);
  const sub = k => `${esc(k.jenis_label)} · ${esc(tglRentang(k))}`;
  return `<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    ${dashNotifPanel('Belum Ada Anggota Bertugas', belumAnggota, 'Semua kegiatan yang disetujui sudah memiliki anggota bertugas.', sub)}
    ${sprinPanelHtml()}
  </div>`;
}

/* ---- Filter Lokasi Peralatan (dipakai di Dashboard & halaman modul Peralatan) ---- */
let lokasiFilter = '';
function lokasiSelectHtml(){
  const opts = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='lokasi').options;
  return `<select style="width:auto;" aria-label="Filter Lokasi" onchange="setLokasiFilter(this.value)">
    <option value="">Semua Lokasi</option>
    ${opts.map(o=>`<option value="${esc(o)}" ${lokasiFilter===o?'selected':''}>${esc(o)}</option>`).join('')}
  </select>`;
}
function setLokasiFilter(v){ lokasiFilter = v; render(currentView); }
let kategoriFilter = '';
function kategoriSelectHtml(){
  const opts = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='kategori').options;
  return `<select style="width:auto;" aria-label="Filter Kategori" onchange="setKategoriFilter(this.value)">
    <option value="">Semua Kategori</option>
    ${opts.map(o=>`<option value="${esc(o)}" ${kategoriFilter===o?'selected':''}>${esc(o)}</option>`).join('')}
  </select>`;
}
function setKategoriFilter(v){ kategoriFilter = v; render(currentView); }
function peralatanFilterHtml(){ return kategoriSelectHtml() + lokasiSelectHtml(); }
function peralatanCocok(r){
  return (!lokasiFilter || (r.lokasi||'KPP Jakarta')===lokasiFilter) && (!kategoriFilter || r.kategori===kategoriFilter);
}

/* ---- Ringkasan jumlah Peralatan per Kategori (mengikuti filter Lokasi; klik untuk memfilter Kategori) ---- */
function peralatanKategoriRingkasHtml(){
  const kats = EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='kategori').options;
  const rows = (DB.peralatan||[]).filter(r=>!lokasiFilter || (r.lokasi||'KPP Jakarta')===lokasiFilter);
  const num = v => parseFloat(v)||0;
  const items = kats.map(k=>{
    const rs = rows.filter(r=>r.kategori===k);
    return {k, baik:rs.reduce((t,r)=>t+num(r.jumlah_baik),0), rusak:rs.reduce((t,r)=>t+num(r.jumlah_rusak),0), jenis:rs.length};
  });
  const total = items.reduce((t,i)=>t+i.baik+i.rusak,0);
  return `<div class="mb-3">
    <div class="text-[11px] mb-1.5" style="color:var(--muted);">Jumlah peralatan per kategori${lokasiFilter ? ' · '+esc(lokasiFilter) : ' · Semua Lokasi'} — total <strong style="color:var(--navy-800)">${total}</strong> unit. Klik kategori untuk memfilter tabel.</div>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
      ${items.map(i=>{
        const aktif = kategoriFilter===i.k;
        return `<button type="button" onclick="setKategoriFilter(${aktif?"''":"'"+esc(i.k)+"'"})" class="rounded-lg p-2.5 text-left" style="cursor:pointer; border:1px solid ${aktif?'var(--orange-500)':'var(--line)'}; background:${aktif?'var(--orange-100)':'var(--bg)'};">
          <div class="text-[11px]" style="color:var(--muted);">${esc(i.k)}</div>
          <div class="font-display font-bold text-lg" style="color:var(--navy-800);">${i.baik+i.rusak}</div>
          <div class="text-[10px]" style="color:var(--muted);">Baik ${i.baik} · Rusak ${i.rusak} · ${i.jenis} jenis</div>
        </button>`;}).join('')}
    </div>
  </div>`;
}

/* ---- Unduh tabel per modul di Dashboard (PDF / Excel), mengikuti filter yang sedang aktif ---- */
/* Kolom tabel Kendaraan di Dashboard per kategori Sapras (Darat / Laut) */
function kendaraanDashCols(kat){
  const m = TABLE_MODULES.find(x=>x.key==='kendaraan');
  return m.fields.filter(f=>f.type!=='hidden' && (f.col || f.key==='lokasi' || (kat==='Sapras Laut' && f.laut) || (kat==='Sapras Darat' && f.darat)));
}
function dashTableData(key){
  if(key==='kendaraan_darat' || key==='kendaraan_laut'){
    const kat = key==='kendaraan_darat' ? 'Sapras Darat' : 'Sapras Laut';
    const cols = kendaraanDashCols(kat);
    const rows = (DB.kendaraan||[]).filter(r=>saprasKategori(r)===kat && dashLolos(key,r,true)).slice().reverse();
    return {title:kat,
      head:['ID'].concat(cols.map(c=>labelKendaraan(c, kat==='Sapras Laut'))),
      body:rows.map(r=>[r.id].concat(cols.map(c=>dashVal(c,r))))};
  }
  if(key==='siaga_rutin'){
    const rows = (DB.siaga_rutin||[]).filter(r=>dashLolos('siaga_rutin',r,false)).slice().reverse();
    return {title:'Pengecekan Siaga Rutin',
      head:['ID','Jenis','Lokasi','Tanggal','Waktu','Pemeriksa / Tim','Dicek','Kondisi Baik','Bermasalah'],
      body:rows.map(r=>{ const it=r.items||[], bad=it.filter(x=>x.kondisi!=='Baik').length;
        return [r.id, siagaJenisLabel(r), r.lokasi||'-', tglDMY(r.tanggal), r.waktu||'', r.pemeriksa||r.tim||'-', it.length, it.length-bad, bad ? bad+' item' : 'Semua baik']; })};
  }
  if(key==='kegiatan'){
    const rows = (DB.kegiatan||[]).filter(k=>dashLolos('kegiatan',k,false)).slice().reverse();
    return {title:'Kegiatan/Tugas',
      head:['ID','Nama Kegiatan','Jenis','Tanggal','Lokasi','Persetujuan','Pelaksanaan'],
      body:rows.map(k=>[k.id,k.nama,k.jenis_label,tglRentang(k),k.lokasi,k.status_persetujuan,k.status_pelaksanaan])};
  }
  if(key==='surat_masuk'){
    const rows = (DB.surat_masuk||[]).filter(s=>dashLolos('surat_masuk', s, false, {tanggal:s.tanggal_surat})).slice().reverse();
    return {title:'Surat Masuk',
      head:['ID','Nomor Surat','Asal Surat','Perihal','Jenis','Tgl Surat','Persetujuan'],
      body:rows.map(s=>[s.id,s.nomor_surat,s.asal_surat,s.perihal,s.jenis_label,tglDMY(s.tanggal_surat),s.status])};
  }
  if(key==='anggota'){
    const cols = ANGGOTA_FIELDS.filter(f=>f.col && !f.isStatus);
    const sf = ANGGOTA_FIELDS.find(f=>f.isStatus);
    return {title:'Daftar Anggota', head:cols.map(c=>c.label).concat('Status'),
      body:(DB.anggota||[]).filter(r=>dashLolos('anggota',r,true)).map(r=>cols.map(c=>r[c.key]).concat(r[sf.key]))};
  }
  const m = TABLE_MODULES.find(x=>x.key===key); if(!m) return null;
  let rows = (DB[key]||[]).filter(r=>dashLolos(key, r, ['peralatan','kendaraan'].includes(key))).slice().reverse();
  if(key==='peralatan') rows = rows.filter(peralatanCocok);
  const cols = m.fields.filter(f=>f.col && !f.isStatus);
  const sf = m.fields.find(f=>f.isStatus);
  return {title:m.label,
    head:['ID'].concat(cols.map(c=>c.label), sf ? ['Status'] : []),
    body:rows.map(r=>[r.id].concat(cols.map(c=>dashVal(c,r)), sf ? [statusFieldValue(key, sf, r)] : []))};
}
function exportDashTable(key, fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const d = dashTableData(key); if(!d) return;
  if(!d.body.length){ toast('Belum ada data '+d.title+' untuk diunduh'); return; }
  if(key==='surat_masuk' && fmt==='excel'){ exportSuratMasukExcel(); return; }
  const body = d.body.map(r=>r.map(v=>(v===undefined||v===null) ? '' : v));
  const fname = ('Data_'+d.title).replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(fmt==='excel'){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet([d.head, ...body]);
      ws['!cols'] = d.head.map((h,i)=>({wch: Math.min(45, Math.max(String(h).length, ...body.map(r=>String(r[i]).length))+2)}));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, d.title.replace(/[\\/?*\[\]:]/g,'').slice(0,31));
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation: d.head.length>6 ? 'landscape' : 'portrait', unit:'pt', format:'a4'});
      doc.setFontSize(13); doc.text('Data '+d.title, 40, 40);
      doc.setFontSize(9); doc.text('Dicetak '+nowStr(), 40, 54);
      doc.autoTable({
        head:[d.head], body, startY:64,
        styles:{fontSize:8, cellPadding:3, valign:'middle'},
        headStyles:{fillColor:[19,49,92]}
      });
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}

/* ---- Unduh Excel Surat Masuk: format rekap (kuning, 10 kolom, lampiran berupa tautan) ---- */
function exportSuratMasukExcel(){
  if(typeof ExcelJS==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const rows = (DB.surat_masuk||[]).filter(s=>dashLolos('surat_masuk', s, false, {tanggal:s.tanggal_surat})).slice().reverse();
  const tgl = iso => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso||'');
    return m ? (+m[3]) + ' ' + BULAN_NAMA[+m[2]-1] + ' ' + m[1] : '-';
  };
  const jam = w => /^\d{1,2}:\d{2}/.test(w||'') ? w.slice(0,5).replace(':','.') : '00.00';
  const head = ['No.','Tanggal Masuk','Tanggal Naskah','Dari','Batas Waktu','Nomor Surat','Hal','Lampiran Surat Masuk','Status','Tambahan Instruksi KKS'];
  const widths = [6.83,22.83,22.83,32.83,26.83,22.83,32.83,26.83,18.83,26.83];
  const thin = {style:'thin', color:{argb:'FF000000'}};
  const border = {top:thin, left:thin, bottom:thin, right:thin};
  try{
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Surat Masuk');
    ws.columns = widths.map(w=>({width:w}));
    const hr = ws.addRow(head);
    hr.height = 26;
    hr.eachCell(c=>{
      c.font = {name:'Calibri', size:11, bold:true, color:{argb:'FF000000'}};
      c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FFFFFF00'}};
      c.alignment = {horizontal:'center', vertical:'middle', wrapText:true};
      c.border = border;
    });
    rows.forEach((s,i)=>{
      const f = s.file_pdf || null;
      const nama = f ? f.name : '-';
      const url = f && (f.url || f.link || f.path);
      const r = ws.addRow([
        i+1,
        'Tanggal disposisi: ' + tgl(s.tanggal_diterima),
        'Tanggal naskah: ' + tgl(s.tanggal_surat),
        'Dari : ' + (s.asal_surat || '-'),
        'Batas: ' + tgl(s.tanggal_kegiatan) + ' pukul ' + jam(s.waktu) + ' WIB',
        'Nomor naskah: ' + (s.nomor_surat || '-'),
        'Hal : ' + (s.perihal || '-'),
        nama,
        s.status || '-',
        s.keterangan_persetujuan || '-'
      ]);
      r.eachCell({includeEmpty:true}, (c,col)=>{
        const center = col===1 || col===9;
        c.font = {name:'Calibri', size:10.5, color:{argb:'FF000000'}};
        c.alignment = {horizontal:center?'center':'left', vertical:'top', wrapText:true};
        c.border = border;
      });
      const lc = r.getCell(8);
      lc.font = {name:'Calibri', size:10.5, underline:'single', color:{argb:'FF1155CC'}};
      if(f && /^https?:\/\//i.test(url||'')) lc.value = {text:nama, hyperlink:url, tooltip:'Buka PDF Surat Masuk'};
    });
    wb.xlsx.writeBuffer().then(buf=>{
      const blob = new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = 'Rekap_Surat_Masuk.xlsx';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(()=>URL.revokeObjectURL(a.href), 2000);
    }).catch(e=>{ console.error(e); toast('Gagal mengunduh file'); });
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}

/* ---- Tabel data per modul di Dashboard ---- */
function dashTablesHtml(){
  const STOK = ['peralatan','kendaraan']; // stok/inventaris: tidak difilter periode
  const th = t => `<th>${esc(t)}</th>`;
  const wrap = (title, count, goto, head, body, extra, above, bukaJs) => `
    <div class="panel rounded-xl p-4 mb-4">
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <h3 class="font-display font-semibold text-sm">${esc(title)}</h3>
        <span class="badge b-info">${count} data</span>
        <div class="ml-auto dash-toolbar">
          ${dashRangeHtml(goto)}${extra || ''}
          <div class="dd">
            <button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
            <div class="dd-menu">
              <button type="button" class="dd-item" onclick="exportDashTable('${goto}','pdf')">PDF</button>
              <button type="button" class="dd-item" onclick="exportDashTable('${goto}','excel')">Excel</button>
            </div>
          </div>
          <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="${bukaJs || `render('${goto}')`}">Buka Modul</button>
        </div>
      </div>
      ${above || ''}
      <div class="tablewrap" style="max-height:300px; overflow-y:auto;">
        <table><thead><tr>${head}</tr></thead><tbody>${dashRangeAktif(goto) ? String(body).replace('Belum ada data.', 'Tidak ada data pada rentang tanggal tersebut.') : body}</tbody></table>
      </div>
    </div>`;
  const empty = n => `<tr><td colspan="${n}" class="text-center py-5" style="color:var(--muted);">Belum ada data.</td></tr>`;

  // Kegiatan/Tugas
  const keg = (DB.kegiatan||[]).filter(k=>dashLolos('kegiatan',k,false)).slice().reverse();
  let html = wrap('Kegiatan/Tugas', keg.length, 'kegiatan',
    ['ID','Nama Kegiatan','Jenis','Tanggal','Lokasi','Persetujuan','Pelaksanaan'].map(th).join(''),
    keg.length ? keg.map(k=>`<tr style="cursor:pointer;" onclick="openKegiatanDetail('${k.id}')">
      <td class="font-medium">${esc(k.id)}</td><td><div class="clamp3" title="${esc(k.nama)}">${esc(k.nama)}</div></td><td>${esc(k.jenis_label)}</td><td>${esc(tglRentang(k))}</td><td>${esc(k.lokasi)}</td>
      <td>${statusBadge(k.status_persetujuan)}</td><td>${statusBadge(k.status_pelaksanaan)}</td></tr>`).join('') : empty(7));

  // Modul program + inventaris (Rescuer dilewati: datanya diambil dari Daftar Anggota)
  TABLE_MODULES.filter(m=>m.key!=='rescuer').forEach(m=>{
    if(m.key==='kendaraan'){
      ['Sapras Darat','Sapras Laut'].forEach(kat=>{
        const laut = kat==='Sapras Laut';
        const kc = kendaraanDashCols(kat);
        const kr = (DB.kendaraan||[]).filter(r=>saprasKategori(r)===kat && dashLolos(laut ? 'kendaraan_laut' : 'kendaraan_darat', r, true)).slice().reverse();
        html += wrap(kat, kr.length, laut ? 'kendaraan_laut' : 'kendaraan_darat',
          th('ID') + kc.map(c=>th(labelKendaraan(c, laut))).join(''),
          kr.length ? kr.map(r=>`<tr style="cursor:pointer;" onclick="openGenericDetail('kendaraan','${r.id}')">
            <td class="font-medium">${esc(r.id)}</td>${kc.map(c=>`<td>${dashCell('kendaraan',c,r)}</td>`).join('')}</tr>`).join('') : empty(1+kc.length),
          '', '', `saprasFilter='${kat}'; render('kendaraan')`);
      });
      return;
    }
    let rows = (DB[m.key]||[]).filter(r=>dashLolos(m.key, r, STOK.includes(m.key))).slice().reverse();
    if(m.key==='peralatan') rows = rows.filter(peralatanCocok);
    const cols = m.fields.filter(f=>f.col && !f.isStatus);
    const sf = m.fields.find(f=>f.isStatus);
    const n = 1 + cols.length + (sf?1:0);
    html += wrap(m.label, rows.length, m.key,
      th('ID') + cols.map(c=>th(c.label)).join('') + (sf ? th('Status') : ''),
      rows.length ? rows.map(r=>`<tr style="cursor:pointer;" onclick="openGenericDetail('${m.key}','${r.id}')">
        <td class="font-medium">${esc(r.id)}</td>${cols.map(c=>`<td>${dashCell(m.key,c,r)}</td>`).join('')}
        ${sf ? `<td>${statusBadge(statusFieldValue(m.key, sf, r))}</td>` : ''}</tr>`).join('') : empty(n),
      m.key==='peralatan' ? peralatanFilterHtml() : (m.key==='pembinaan' ? waSamaptaBtnHtml() : ''),
      m.key==='peralatan' ? peralatanKategoriRingkasHtml() : '');
  });

  // Pengecekan Siaga Rutin (data dari modul Pengecekan Siaga Rutin)
  const sg = (DB.siaga_rutin||[]).filter(r=>dashLolos('siaga_rutin', r, false)).slice().reverse();
  html += wrap('Pengecekan Siaga Rutin', sg.length, 'siaga_rutin',
    ['ID','Jenis','Lokasi','Tanggal','Waktu','Pemeriksa / Tim','Dicek','Kondisi Baik','Bermasalah'].map(th).join(''),
    sg.length ? sg.map(r=>{ const it=r.items||[], bad=it.filter(x=>x.kondisi!=='Baik').length;
      return `<tr style="cursor:pointer;" onclick="siagaDetail('${esc(r.id)}')"><td class="font-medium">${esc(r.id)}</td><td>${esc(siagaJenisLabel(r))}</td><td>${esc(r.lokasi||'-')}</td><td>${esc(tglDMY(r.tanggal))}</td><td>${esc(r.waktu||'')}</td><td>${esc(r.pemeriksa||r.tim||'-')}</td><td>${it.length}</td><td>${it.length-bad}</td><td>${bad ? `<span class="badge b-bad">${bad} item</span>` : '<span class="badge b-ok">Semua baik</span>'}</td></tr>`; }).join('') : empty(9));

  // Surat Masuk (tepat di atas Daftar Anggota)
  const sml = (DB.surat_masuk||[]).filter(s=>dashLolos('surat_masuk', s, false, {tanggal:s.tanggal_surat})).slice().reverse();
  html += wrap('Surat Masuk', sml.length, 'surat_masuk',
    ['ID','Nomor Surat','Asal Surat','Perihal','Jenis','Tgl Surat','Persetujuan'].map(th).join(''),
    sml.length ? sml.map(sm=>`<tr style="cursor:pointer;" onclick="kegTab='surat'; openSuratDetail('${sm.id}')">
      <td class="font-medium">${esc(sm.id)}</td><td>${esc(sm.nomor_surat)}</td><td>${esc(sm.asal_surat)||'-'}</td><td>${esc(sm.perihal)}</td>
      <td>${esc(sm.jenis_label)}</td><td>${esc(tglDMY(sm.tanggal_surat))||'-'}</td>
      <td>${suratPersetujuanHtml(sm)}<div class="text-[11px]" style="color:var(--muted);">${esc(suratAccInfo(sm))}</div></td></tr>`).join('') : empty(7),
    '', '', `kegTab='surat'; render('kegiatan')`);

  return `<div class="mt-6"><h2 class="font-display font-semibold text-base mb-3">Data per Modul</h2>${html}</div>`;
}

/* ============================= GENERIC CRUD ============================= */
/* Stok Peralatan: Jumlah = Jumlah Baik + Jumlah Rusak; Tersedia = Jumlah - yang sedang dipinjam (otomatis dari modul Peminjaman). */
function normNama(s){ return String(s||'').trim().toLowerCase().replace(/\s+/g,' '); }
function peralatanJumlah(row){ return (parseFloat(row.jumlah_baik)||0) + (parseFloat(row.jumlah_rusak)||0); }
function peralatanDipinjam(nama, exceptId){
  const n = normNama(nama); if(!n) return 0;
  return (DB.peminjaman||[]).reduce((sum,p)=>{
    if(p.jenis_barang!=='Peralatan' || normNama(p.nama_barang)!==n) return sum;
    if(exceptId && p.id===exceptId) return sum;
    if(p.status==='Dikembalikan' || p.status==='Ditolak' || p.tanggal_kembali) return sum; // sudah kembali / ditolak (Menunggu ACC tetap mencadangkan stok)
    return sum + (parseFloat(p.jumlah)||1);
  }, 0);
}
/* Unit Peralatan yang sedang dalam pemeliharaan (status "Sedang Dikerjakan") tidak tersedia untuk dipinjam. */
function peralatanDipelihara(nama, exceptId){
  const n = normNama(nama); if(!n) return 0;
  return (DB.pemeliharaan||[]).reduce((sum,r)=>{
    if(!pgjAktif(r) || r.jenis_barang!=='Peralatan' || normNama(r.nama_barang)!==n || r.status!=='Sedang Dikerjakan') return sum;
    if(exceptId && r.id===exceptId) return sum;
    return sum + (parseFloat(r.jumlah)||1);
  }, 0);
}
function kendaraanDipelihara(nama){
  const n = normNama(nama); if(!n) return false;
  return (DB.pemeliharaan||[]).some(r=>pgjAktif(r) && r.jenis_barang==='Kendaraan' && normNama(r.nama_barang)===n && r.status==='Sedang Dikerjakan');
}
/* Kondisi Kendaraan mengikuti Pemeliharaan: dikerjakan => Unserviceable; selesai & Baik => Serviceable; selain itu Unserviceable. Scrap tidak diubah. */
function syncKendaraanDariPemeliharaan(rec){
  if(!rec || rec.jenis_barang!=='Kendaraan' || !pgjAktif(rec)) return;
  const k = (DB.kendaraan||[]).find(x=>normNama(x.nama_kendaraan)===normNama(rec.nama_barang));
  if(!k || k.kondisi==='Scrap') return;
  let baru = k.kondisi;
  if(rec.status==='Sedang Dikerjakan') baru = 'Unserviceable';
  else if(rec.status==='Selesai') baru = rec.kondisi_setelah==='Baik / Layak Pakai' ? 'Serviceable' : (rec.kondisi_setelah ? 'Unserviceable' : k.kondisi);
  if(baru!==k.kondisi){ k.kondisi = baru; k.diperbarui_oleh = pelaku(); k.diperbarui_waktu = nowStr(); }
}
function peralatanBaik(row){ return parseFloat(row.jumlah_baik)||0; }
/* Keterangan peralatan yang sedang dipinjam (dari modul Peminjaman): daftar peminjam + jumlah. */
function peralatanPinjamList(row){
  const n = normNama(row && row.nama_barang); if(!n) return [];
  return (DB.peminjaman||[]).filter(p=>p.jenis_barang==='Peralatan' && normNama(p.nama_barang)===n && p.status!=='Dikembalikan' && p.status!=='Ditolak' && !p.tanggal_kembali);
}
function peralatanPinjamTeks(row){
  const l = peralatanPinjamList(row); if(!l.length) return '';
  const tot = l.reduce((s,p)=>s+(parseFloat(p.jumlah)||1),0);
  return 'Dipinjam ' + tot + ' unit: ' + l.map(p=>(p.nama_peminjam||'-') + ' ×' + (parseFloat(p.jumlah)||1) + (p.id ? ' (' + p.id + (p.status==='Menunggu' ? ', menunggu ACC' : '') + ')' : '')).join('; ');
}
function peralatanTersedia(row){ return Math.max(0, peralatanBaik(row) - peralatanDipinjam(row.nama_barang) - peralatanDipelihara(row.nama_barang)); } // hanya unit kondisi Baik; unit rusak tidak dihitung
/* Badge warna kondisi Kendaraan: Serviceable hijau, Unserviceable kuning, Scrap merah */
function kondisiKendaraanBadge(v){
  const cls = {'Serviceable':'b-ok','Unserviceable':'b-warn','Scrap':'b-bad'}[v];
  return cls ? `<span class="badge ${cls}">${esc(v)}</span>` : esc(v);
}
function cellHtml(key, f, row){
  if(f.pinjamInfo){
    const l = peralatanPinjamList(row); if(!l.length) return '<span style="color:var(--muted);">—</span>';
    const tot = l.reduce((s,p)=>s+(parseFloat(p.jumlah)||1),0);
    return `<span class="badge b-warn">Dipinjam ${tot} unit</span><div class="text-[11px] mt-1" style="color:var(--muted);">${l.map(p=>esc((p.nama_peminjam||'-') + ' ×' + (parseFloat(p.jumlah)||1) + (p.id ? ' (' + p.id + (p.status==='Menunggu' ? ', menunggu ACC' : '') + ')' : ''))).join('<br>')}</div>`;
  }
  if(key==='pembinaan' && f.key==='kategori_pembinaan') return esc(pbnKat(row));
  if(key==='pembinaan' && f.key==='dokumentasi') return pbnDokDetailHtml(row);
  if(key==='pemeliharaan' && (f.key==='estimasi_biaya' || f.key==='biaya')){ const v = parseFloat(row[f.key]); return isNaN(v) ? '' : esc('Rp ' + v.toLocaleString('id-ID')); }
  if(key==='kendaraan' && f.key==='kondisi') return kondisiKendaraanBadge(row.kondisi);
  if(f.type==='date') return esc(tglDMY(fieldValue(f,row)));
  return esc(fieldValue(f,row));
}
function fieldValue(f, row){
  if(row && f.pinjamInfo) return peralatanPinjamTeks(row);
  if(row && f.key==='kategori_pembinaan') return pbnKat(row);
  if(row && f.type==='computed' && f.computeFrom && f.computeFrom.includes('jumlah_baik')){
    return f.minusBorrowed ? peralatanTersedia(row) : peralatanJumlah(row);
  }
  if(f.derivedFrom && row){
    const ref = (DB[f.derivedFrom.ref]||[]).find(x=>x.id===row[f.derivedFrom.idKey]);
    return ref ? (ref[f.derivedFrom.field] || '') : '';
  }
  // Petugas / Anggota Bertugas: tampilkan nama depan anggota yang ditugaskan di
  // Kegiatan/Tugas asal data ini. Kalau belum ada anggota yang ditugaskan, pakai isian biasa.
  if(row && (f.key==='petugas' || f.key==='anggota_bertugas') && row.dari_kegiatan_id){
    const kg = (DB.kegiatan||[]).find(x=>x.id===row.dari_kegiatan_id);
    const names = kg && Array.isArray(kg.anggota_ids)
      ? kg.anggota_ids.map(aid=>{ const a=(DB.anggota||[]).find(x=>x.id===aid); return a ? namaDepan(a.nama) : ''; }).filter(Boolean)
      : [];
    if(names.length) return names.join(', ');
  }
  const val = row && row[f.key] !== undefined ? row[f.key] : '';
  // Tambahkan satuan (mis. 8 Jam, 40 knot) bila field punya unit dan nilainya terisi
  return (f.unit && val!=='' && val!==null) ? val+' '+f.unit : val;
}
// Nama depan dari nama lengkap: lewati gelar di depan (Dr., Ir., H., dst), huruf awal kapital.
function namaDepan(full){
  const parts = String(full||'').trim().split(/\s+/).filter(Boolean);
  const gelar = /^(dr|drs|dra|ir|h|hj|prof|kh)\.?,?$/i;
  const first = parts.find(t=>!gelar.test(t)) || parts[0] || '';
  const clean = first.replace(/,+$/,'');
  return clean.charAt(0).toUpperCase() + clean.slice(1).toLowerCase();
}
function getAnggotaKegiatanCount(anggotaId){ return (DB.kegiatan||[]).filter(k=>(k.anggota_ids||[]).includes(anggotaId)).length; }
/* ---- Rekap keikutsertaan anggota di modul Rescuer ----
   Kolom "Jumlah Mengikuti" (Samapta, Uji Periodik, Diklat) dihitung per tahun dari data modulnya masing-masing:
   - Samapta       : absensi Pembinaan Tenaga SAR berstatus Hadir (sumbernya data Samapta; absensi dari Pembelajaran tidak dihitung).
   - Uji Periodik  : jumlah Uji Periodik yang diikuti sebagai peserta (data nilai kategori) + sebagai penguji.
   - Diklat        : jumlah Diklat yang diikuti sebagai peserta (Data Peserta Diklat) + sebagai instruktur.
   Penugasan lewat Kegiatan/Tugas ikut dihitung (tanpa hitung ganda untuk kegiatan yang sama). */
let rescuerTahun = String(new Date().getFullYear()); // 'all' = semua tahun
function rcYear(s){ const m = String(s||'').match(/^(\d{4})/); return m ? m[1] : ''; }
function rcOkTahun(tgl){ return rescuerTahun==='all' || rcYear(tgl)===rescuerTahun; }
function rcTahunList(){
  const ys = new Set([String(new Date().getFullYear())]);
  const add = t=>{ const y = rcYear(t); if(y) ys.add(y); };
  (DB.kegiatan||[]).forEach(k=>add(k.tanggal));
  (DB.pembinaan_absensi||[]).forEach(x=>add(x.tanggal));
  (DB.diklat||[]).forEach(r=>add(r.tanggal_mulai));
  (DB.uji_periodik||[]).forEach(r=>add(r.tanggal_mulai));
  return Array.from(ys).sort((a,b)=>b.localeCompare(a));
}
// Baris peserta cocok dengan anggota: lewat anggota_id, NIP (bila ada), atau nama (tanpa spasi/tanda baca).
function rcKunciNama(t){ return String(t==null?'':t).toLowerCase().replace(/[^a-z0-9]/g,''); }
function rcSama(row, a){
  if(!row) return false;
  if(row.anggota_id) return row.anggota_id===a.id;
  const nip = String(a.nip||'').replace(/\D/g,'');
  if(nip.length>=10 && String(row.nipgol||'').replace(/\D/g,'').includes(nip)) return true;
  const n = rcKunciNama(row.nama); return !!n && n===rcKunciNama(a.nama);
}
// Jumlah diklat pada Rekap Diklat Anggota: ceklis kualifikasi (Dikdas, MFR, ...) + Diklat Lain. Rekap tidak punya tanggal, jadi tidak disaring per tahun.
function rcDiklatRekap(a){
  return diklatRekapSaved().filter(r=>rcSama(r, a)).reduce((t,r)=>{
    const cek = DIKLAT_PESERTA_COLS.filter(c=>r[c.key]).length;
    const lain = (Array.isArray(r.diklat_lain_list) ? r.diklat_lain_list : []).filter(x=>x && String(x.teks||'').trim()).length;
    return t + cek + lain;
  }, 0);
}
// Nama anggota disebut di kolom teks bebas (Instruktur / Penguji); boleh lebih dari satu nama.
function rcNamaDisebut(teks, a){
  const n = normSearch(a.nama); if(!n || !teks) return false;
  return String(teks).split(/[;\n]+|\s+dan\s+|\s*&\s*/i).map(normSearch).filter(Boolean)
    .some(t=>t===n || (t.length>=4 && (n.includes(t) || t.includes(n))));
}
function rcKegiatanKeys(a, jenis, set){
  (DB.kegiatan||[]).forEach(k=>{
    if(k.jenis!==jenis || !(k.anggota_ids||[]).includes(a.id)) return;
    const rec = k.linked_record_id ? (DB[jenis]||[]).find(r=>r.id===k.linked_record_id) : null;
    if(rcOkTahun(rec ? rec.tanggal_mulai : k.tanggal)) set.add(k.linked_record_id || k.id);
  });
}
function rcSamapta(a){
  const n = normSearch(a.nama);
  const peserta = (DB.pembinaan_absensi||[]).filter(x=>x.sumber!=='Pembelajaran' && x.kehadiran==='Hadir' && rcOkTahun(x.tanggal) && normSearch(x.nama_anggota)===n).length;
  return {peserta, pengajar:0};
}
function rcUji(a){
  const ids = new Set();
  (DB.uji_kategori||[]).forEach(r=>{
    if(!rcSama(r, a)) return;
    const u = (DB.uji_periodik||[]).find(x=>x.id===r.uji_id);
    if(u && rcOkTahun(u.tanggal_mulai)) ids.add(u.id);
  });
  rcKegiatanKeys(a, 'uji_periodik', ids);
  const pengajar = (DB.uji_periodik||[]).filter(u=>rcOkTahun(u.tanggal_mulai) && rcNamaDisebut(u.penguji, a)).length;
  return {peserta: ids.size, pengajar};
}
function rcDiklat(a){
  const ids = new Set();
  (DB.diklat||[]).forEach(d=>{
    if(rcOkTahun(d.tanggal_mulai) && (d.peserta_matrix||[]).some(p=>rcSama(p, a))) ids.add(d.id);
  });
  rcKegiatanKeys(a, 'diklat', ids);
  const pengajar = (DB.diklat||[]).filter(d=>rcOkTahun(d.tanggal_mulai) && rcNamaDisebut(d.instruktur, a)).length;
  // Rekap Diklat Anggota (tanpa tanggal) dan daftar peserta per diklat bisa memuat orang yang sama: ambil yang terbesar agar tidak terhitung ganda.
  return {peserta: Math.max(ids.size, rcDiklatRekap(a)), pengajar};
}
const RESCUER_IKUT_COLS = [
  {key:'samapta', label:'Samapta', fn:rcSamapta, pengajarLabel:''},
  {key:'uji_periodik', label:'Uji Periodik', fn:rcUji, pengajarLabel:'Penguji'},
  {key:'diklat', label:'Diklat', fn:rcDiklat, pengajarLabel:'Instruktur'},
];
function rescuerIkutCellHtml(a, c){
  const r = c.fn(a), total = r.peserta + r.pengajar;
  const tip = 'Peserta: '+r.peserta + (c.pengajarLabel ? ' · '+c.pengajarLabel+': '+r.pengajar : '');
  return `<td class="text-center no-clamp font-semibold" title="${esc(tip)}">${total}${r.pengajar ? `<div class="text-[10px] font-normal" style="color:var(--muted);">${r.peserta} peserta · ${r.pengajar} ${esc(c.pengajarLabel.toLowerCase())}</div>` : ''}</td>`;
}
// Penugasan lewat Kegiatan/Tugas untuk jenis lain (Samapta, Uji Periodik & Diklat punya kolom sendiri di atas)
const RESCUER_JENIS_COLS = [
  {key:'sgts', label:'SGTS'},
  {key:'bimtek', label:'Bimtek'},
  {key:'narasumber', label:'Narasumber'},
  {key:'pembinaan', label:'Pembinaan Tenaga SAR'},
  {key:'pemberdayaan', label:'PM'},
  {key:'lainnya', label:'Lainnya'},
];
function getAnggotaKegiatanCountByJenis(anggotaId, jenisKey){
  return (DB.kegiatan||[]).filter(k=>k.jenis===jenisKey && rcOkTahun(k.tanggal) && (k.anggota_ids||[]).includes(anggotaId)).length;
}

/* ---- Matrix peserta Diklat (Dikdas..Instruktur + Diklat Lain per orang) ---- */
const DIKLAT_PESERTA_COLS = [
  {key:'dikdas', label:'Dikdas'},
  {key:'mfr', label:'MFR'},
  {key:'jr', label:'JR'},
  {key:'hart', label:'HART'},
  {key:'wr', label:'WR'},
  {key:'cssr', label:'CSSR'},
  {key:'csr', label:'CSR'},
  {key:'uwt', label:'UWT'},
  {key:'var', label:'VAR'},
  {key:'hr', label:'HR'},
  {key:'sarplan', label:'Sarplan'},
  {key:'smc', label:'SMC'},
  {key:'bsg', label:'BSG'},
  {key:'instruktur', label:'Instruktur'},
];
const PESERTA_MODULES = {
  diklat:       {label:'Diklat',       nameKey:'nama_diklat', lainLabel:'Diklat Lain'},
  uji_periodik: {label:'Uji Periodik', nameKey:'nama_uji',    lainLabel:'Nilai Keseluruhan'},
};
let pesertaKey = 'diklat'; // modul yang sedang dipakai editor peserta
let diklatPesertaRows = [];
function newDiklatPesertaRow(){
  const r = {anggota_id:'', nama:'', jabatan:'', diklat_lain_teks:'', diklat_lain_pdf:null};
  getPesertaCols(pesertaKey).forEach(c=>r[c.key]=(pesertaKey==='uji_periodik' ? '' : false));
  return r;
}
function onDiklatPesertaNamaChange(select, i){
  if(!diklatPesertaRows[i]) return;
  const ang = (DB.anggota||[]).find(a=>a.id===select.value);
  diklatPesertaRows[i].anggota_id = select.value;
  diklatPesertaRows[i].nama = ang ? ang.nama : '';
  diklatPesertaRows[i].jabatan = ang ? (ang.jabatan||'') : '';
  refreshDiklatPesertaEditor();
}
function addDiklatPesertaRow(){
  diklatPesertaRows.push(newDiklatPesertaRow());
  refreshDiklatPesertaEditor();
}
function removeDiklatPesertaRow(i){
  diklatPesertaRows.splice(i,1);
  refreshDiklatPesertaEditor();
}
let diklatPesertaContext = 'modal'; // 'modal' (form Tambah/Ubah) atau 'main' (panel halaman utama)
function diklatPesertaWrapId(){ return diklatPesertaContext==='main' ? 'diklat_peserta_wrap_main' : (diklatPesertaContext==='daftar' ? 'diklat_peserta_wrap_daftar' : 'diklat_peserta_wrap'); }
function refreshDiklatPesertaEditor(){
  const wrap = document.getElementById(diklatPesertaWrapId());
  if(wrap) wrap.innerHTML = diklatPesertaEditorHtml();
}
function diklatPesertaEditorHtml(){
  if(pesertaKey==='diklat' && diklatPesertaContext==='main') return diklatRekapEditorHtml();
  return `
    <div class="flex items-center justify-between mb-2">
      <label class="block text-xs font-medium" style="color:var(--muted);">Data Peserta ${esc(PESERTA_MODULES[pesertaKey].label)}</label>
      <button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" onclick="addDiklatPesertaRow()">+ Tambah Peserta</button>
    </div>
    <div class="tablewrap" style="max-height:360px; overflow-y:auto;">
      <table>
        <thead><tr>
          <th>Nama</th><th>Jabatan</th>${pesertaKey==='uji_periodik' ? '<th class="text-center">JK</th>' : ''}
          ${getPesertaCols(pesertaKey).map((c,ci)=> (pesertaKey==='uji_periodik' && !ujiColsLocked)
            ? `<th class="text-center"><button type="button" class="colhead" title="Klik untuk ubah / hapus / tambah kolom" onclick="openColMenu(event,'${pesertaKey}',${ci})">${esc(c.label)} <span aria-hidden="true">&#9662;</span></button></th>`
            : `<th class="text-center">${esc(c.label)}</th>`).join('')}
          <th>${esc(PESERTA_MODULES[pesertaKey].lainLabel)}</th><th></th>
        </tr></thead>
        <tbody>
          ${diklatPesertaRows.length ? diklatPesertaRows.map((r,i)=>`
            <tr>
              <td>
                <select style="min-width:160px;" onchange="onDiklatPesertaNamaChange(this,${i})">
                  <option value="">-- Pilih Anggota --</option>
                  ${(DB.anggota||[]).map(a=>`<option value="${esc(a.id)}" ${r.anggota_id===a.id?'selected':''}>${esc(a.nama)}</option>`).join('')}
                </select>
              </td>
              <td><input type="text" style="min-width:120px;" value="${esc(r.jabatan)}" oninput="diklatPesertaRows[${i}].jabatan=this.value"></td>
              ${pesertaKey==='uji_periodik' ? `<td class="text-center"><select style="min-width:4.5rem;" onchange="diklatPesertaRows[${i}].jk=this.value">
                <option value="" ${!r.jk?'selected':''}>-</option>
                <option value="L" ${r.jk==='L'?'selected':''}>L</option>
                <option value="P" ${r.jk==='P'?'selected':''}>P</option>
              </select></td>` : ''}
              ${getPesertaCols(pesertaKey).map(c=>`<td class="text-center" style="min-width:5.5rem;">${pesertaCellHtml(r,i,c)}</td>`).join('')}
              <td id="dkl_lain_${diklatPesertaContext}_${i}" style="min-width:${pesertaKey==='diklat'?'250px':'auto'};">${pesertaLainHtml(r,i)}</td>
              <td><button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="removeDiklatPesertaRow(${i})">Hapus</button></td>
            </tr>`).join('') : `<tr><td colspan="${getPesertaCols(pesertaKey).length+(pesertaKey==='uji_periodik'?5:4)}" class="text-center py-4" style="color:var(--muted);">Belum ada peserta. Klik "+ Tambah Peserta".</td></tr>`}
        </tbody>
      </table>
    </div>`;
}
/* ---- Kolom peserta: Uji Periodik bisa ubah nama / hapus / tambah kolom ---- */
// Kolom Uji Periodik bersifat PER DATA UJI: tiap data punya kolomnya sendiri.
// Setelah "Simpan Data Peserta", kolom data tersebut terkunci dan tidak ikut berubah
// ketika kolom data uji lain diubah.
let ujiWorkCols = null;      // kolom yang sedang dipakai editor
let ujiColsLocked = false;   // true bila kolom data yang dibuka sudah tersimpan
function defaultUjiCols(){
  return DIKLAT_PESERTA_COLS.map(c=>({key:c.key, label:c.label}));
}
function loadUjiCols(row){
  if(row && Array.isArray(row.kolom_peserta) && row.kolom_peserta.length){
    ujiWorkCols = JSON.parse(JSON.stringify(row.kolom_peserta));
    ujiColsLocked = true;
  } else if(row && Array.isArray(row.peserta_matrix) && row.peserta_matrix.length){
    // data lama yang sudah tersimpan sebelum kolom dibuat per-data: pakai kolom lama, kunci
    const legacy = DB.kolom_peserta && Array.isArray(DB.kolom_peserta.uji_periodik) && DB.kolom_peserta.uji_periodik.length
      ? DB.kolom_peserta.uji_periodik : defaultUjiCols();
    ujiWorkCols = JSON.parse(JSON.stringify(legacy));
    ujiColsLocked = true;
  } else {
    ujiWorkCols = defaultUjiCols();
    ujiColsLocked = false;
  }
}
function getPesertaCols(key){
  if(key==='uji_periodik'){
    if(!ujiWorkCols) loadUjiCols(null);
    return ujiWorkCols;
  }
  return DIKLAT_PESERTA_COLS;
}
let colMenuState = null;
function ensureColMenu(){
  let m = document.getElementById('colMenu');
  if(m) return m;
  m = document.createElement('div');
  m.id = 'colMenu';
  m.addEventListener('click', e=>e.stopPropagation());
  document.body.appendChild(m);
  document.addEventListener('click', closeColMenu);
  document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeColMenu(); });
  window.addEventListener('resize', closeColMenu);
  return m;
}
function closeColMenu(){
  const m = document.getElementById('colMenu');
  if(m) m.classList.remove('show');
  colMenuState = null;
}
function openColMenu(ev, key, idx, anchorEl){
  if(ev) ev.stopPropagation();
  if(ujiColsLocked){ toast('Kolom data ini sudah disimpan dan tidak dapat diubah'); return; }
  const cols = getPesertaCols(key);
  const c = cols[idx]; if(!c) return;
  const m = ensureColMenu();
  colMenuState = {key, idx};
  m.innerHTML = `
    <label class="text-[11px] font-medium" style="color:var(--muted);">Nama kolom</label>
    <input type="text" id="colMenuInput" value="${esc(c.label)}" maxlength="30" onkeydown="if(event.key==='Enter'){ colMenuRename(); }">
    <button type="button" class="btn btn-primary !py-1 !px-2 text-xs" style="justify-content:center;" onclick="colMenuRename()">Simpan nama</button>
    <button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" style="justify-content:center;" onclick="colMenuAdd()">+ Tambah kolom di sebelah kanan</button>
    <button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" style="justify-content:center; color:var(--bad);" onclick="colMenuDelete()">Hapus kolom</button>`;
  const el = anchorEl || (ev && ev.currentTarget);
  const r = el.getBoundingClientRect();
  m.classList.add('show');
  const w = m.offsetWidth;
  m.style.left = Math.max(8, Math.min(r.left + r.width/2 - w/2, window.innerWidth - w - 8)) + 'px';
  m.style.top = Math.min(r.bottom + 6, window.innerHeight - m.offsetHeight - 8) + 'px';
  const inp = document.getElementById('colMenuInput');
  if(inp){ inp.focus(); inp.select(); }
}
function colMenuRename(){
  if(!colMenuState) return;
  const {key, idx} = colMenuState;
  const v = (document.getElementById('colMenuInput').value||'').trim();
  if(!v){ toast('Nama kolom tidak boleh kosong'); return; }
  getPesertaCols(key)[idx].label = v;
  closeColMenu(); refreshDiklatPesertaEditor();
  toast('Nama kolom diubah (klik Simpan Data Peserta untuk menyimpan)');
}
function colMenuAdd(){
  if(!colMenuState) return;
  const {key, idx} = colMenuState;
  const cols = getPesertaCols(key);
  cols.splice(idx+1, 0, {key:'x_'+Date.now().toString(36), label:'Kolom Baru'});
  closeColMenu(); refreshDiklatPesertaEditor();
  // langsung buka menu kolom baru agar bisa diberi nama
  const ths = document.querySelectorAll('#'+diklatPesertaWrapId()+' .colhead');
  if(ths[idx+1]) openColMenu(null, key, idx+1, ths[idx+1]);
}
function colMenuDelete(){
  if(!colMenuState) return;
  const {key, idx} = colMenuState;
  const cols = getPesertaCols(key);
  if(cols.length<=1){ toast('Minimal harus ada satu kolom'); return; }
  if(!confirm('Hapus kolom "'+cols[idx].label+'"? Ceklis pada kolom ini tidak akan ditampilkan lagi.')) return;
  cols.splice(idx,1);
  closeColMenu(); refreshDiklatPesertaEditor();
  toast('Kolom dihapus (klik Simpan Data Peserta untuk menyimpan data peserta)');
}

/* ---- Ceklis kompetensi + upload PDF per kolom ---- */
function ujiNilaiRataRata(r, cols){
  const nums = (cols || getPesertaCols('uji_periodik'))
    .map(c=>r[c.key])
    .filter(v=>typeof v!=='boolean' && v!=null && String(v).trim()!=='')
    .map(v=>parseFloat(String(v).replace(',','.')))
    .filter(n=>isFinite(n));
  if(!nums.length) return null;
  return nums.reduce((a,b)=>a+b,0)/nums.length;
}
function ujiNilaiRataRataText(r, cols){
  const a = ujiNilaiRataRata(r, cols);
  return a===null ? '-' : a.toLocaleString('id-ID',{maximumFractionDigits:2});
}
function onUjiNilaiInput(el, i, key){
  let v = el.value.replace(/[^0-9.,]/g,'');
  const m = v.search(/[.,]/);
  if(m>=0) v = v.slice(0,m+1) + v.slice(m+1).replace(/[.,]/g,'');
  if(v!==el.value) el.value = v;
  if(diklatPesertaRows[i]) diklatPesertaRows[i][key] = v;
  const avg = document.getElementById(`uji_avg_${diklatPesertaContext}_${i}`);
  if(avg && diklatPesertaRows[i]) avg.textContent = ujiNilaiRataRataText(diklatPesertaRows[i]);
}
function pesertaCellHtml(r, i, c){
  if(pesertaKey==='uji_periodik'){
    const val = (typeof r[c.key]==='boolean' || r[c.key]==null) ? '' : r[c.key];
    return `<input type="text" inputmode="decimal" placeholder="0" style="width:4.5rem; text-align:center;" value="${esc(val)}" oninput="onUjiNilaiInput(this,${i},'${c.key}')">`;
  }
  const pdf = r[c.key+'_pdf'];
  const id = `dkl_cell_${diklatPesertaContext}_${i}_${c.key}`;
  return `<div id="${id}" style="display:flex; flex-direction:column; align-items:center; gap:.3rem;">
    <input type="checkbox" style="width:1rem;height:1rem;" ${r[c.key]?'checked':''} onchange="onPesertaCheck(this,${i},'${c.key}')">
    ${(pesertaKey==='diklat' && r[c.key]) ? `
      <input type="file" accept="application/pdf,.pdf" style="display:none;" onchange="handlePesertaColPdf(this,${i},'${c.key}')">
      <button type="button" class="btn btn-ghost !py-0.5 !px-2 text-[11px]" style="border-radius:999px;" onclick="this.previousElementSibling.click()">Upload PDF</button>
      ${pdf ? `<a href="#" class="text-[11px]" style="color:var(--orange-500); text-decoration:underline;" title="${esc(pdf.name)}" onclick="viewPesertaColPdf(event,${i},'${c.key}')">Lihat PDF</a>` : ''}
    ` : ''}
  </div>`;
}
function rerenderPesertaCell(i, key){
  const r = diklatPesertaRows[i]; if(!r) return;
  const c = getPesertaCols(pesertaKey).find(x=>x.key===key);
  const el = document.getElementById(`dkl_cell_${diklatPesertaContext}_${i}_${key}`);
  if(el && el.parentElement) el.parentElement.innerHTML = pesertaCellHtml(r, i, c);
}
function onPesertaCheck(cb, i, key){
  const r = diklatPesertaRows[i]; if(!r) return;
  r[key] = cb.checked;
  if(!cb.checked) r[key+'_pdf'] = null; // PDF ikut dihapus bila ceklis dilepas
  rerenderPesertaCell(i, key);
}
function handlePesertaColPdf(input, i, key){
  const file = input.files && input.files[0];
  const r = diklatPesertaRows[i];
  if(!file || !r) return;
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){
    toast('File harus berformat PDF'); input.value=''; return;
  }
  if(file.size > 10*1024*1024){
    toast('Ukuran file maksimal 10MB'); input.value=''; return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    r[key+'_pdf'] = { name:file.name, size:file.size, data:reader.result };
    rerenderPesertaCell(i, key);
    toast('PDF terpilih: '+file.name+' (jangan lupa klik Simpan Data Peserta)');
  };
  reader.onerror = () => toast('Gagal membaca file PDF');
  reader.readAsDataURL(file);
}
function openPdfDataUrl(dataUrl){
  try{
    const b64 = dataUrl.split(',')[1];
    const bin = atob(b64); const arr = new Uint8Array(bin.length);
    for(let k=0;k<bin.length;k++) arr[k]=bin.charCodeAt(k);
    window.open(URL.createObjectURL(new Blob([arr],{type:'application/pdf'})), '_blank');
  }catch(e){ window.open(dataUrl, '_blank'); }
}
function viewPesertaColPdf(ev, i, key){
  ev.preventDefault();
  const pdf = diklatPesertaRows[i] && diklatPesertaRows[i][key+'_pdf'];
  if(pdf) openPdfDataUrl(pdf.data);
}
function viewPesertaColPdfSaved(ev, rowId, pKey, idx, key){
  ev.preventDefault(); ev.stopPropagation();
  const row = (DB[pKey]||[]).find(x=>x.id===rowId);
  const pdf = row && row.peserta_matrix && row.peserta_matrix[idx] && row.peserta_matrix[idx][key+'_pdf'];
  if(pdf) openPdfDataUrl(pdf.data);
}
/* ---- Diklat Lain: satu orang bisa punya banyak isian + PDF (khusus modul Diklat) ---- */
function getDiklatLainList(r){
  if(!Array.isArray(r.diklat_lain_list)){
    r.diklat_lain_list = (r.diklat_lain_teks || r.diklat_lain_pdf)
      ? [{teks:r.diklat_lain_teks||'', pdf:r.diklat_lain_pdf||null}]
      : [{teks:'', pdf:null}];
  }
  if(!r.diklat_lain_list.length) r.diklat_lain_list.push({teks:'', pdf:null});
  return r.diklat_lain_list;
}
// Daftar Diklat Lain: bila lebih dari 2 entri, tinggi area dibatasi tepat 2 entri lalu scroll vertikal
function fitDiklatLainScroll(){
  document.querySelectorAll('.dkl-lain-scroll').forEach(el=>{
    el.style.maxHeight = 'none';
    const k = el.children;
    if(k.length<3) return;
    const h = k[1].getBoundingClientRect().bottom - k[0].getBoundingClientRect().top;
    if(h>0) el.style.maxHeight = Math.ceil(h) + 'px';
  });
}
window.addEventListener('resize', fitDiklatLainScroll);
function pesertaLainHtml(r, i){
  if(pesertaKey==='uji_periodik'){
    return `<span id="uji_avg_${diklatPesertaContext}_${i}" class="font-semibold" style="display:inline-block; min-width:4rem; text-align:center;">${ujiNilaiRataRataText(r)}</span>`;
  }
  if(pesertaKey!=='diklat'){
    return `
      <input type="text" placeholder="Nama ${esc(PESERTA_MODULES[pesertaKey].lainLabel.toLowerCase())}" style="min-width:150px;" value="${esc(r.diklat_lain_teks)}" oninput="diklatPesertaRows[${i}].diklat_lain_teks=this.value">
      <input type="file" accept="application/pdf,.pdf" style="margin-top:.3rem; font-size:11px;" onchange="handleDiklatPesertaPdfChange(this,${i})">
      <div id="dkl_peserta_pdf_info_${diklatPesertaContext}_${i}" class="text-[11px] mt-0.5" style="color:var(--muted);">${r.diklat_lain_pdf ? `Terpilih: ${esc(r.diklat_lain_pdf.name)}` : ''}</div>`;
  }
  const list = getDiklatLainList(r);
  if(list.length>2) setTimeout(fitDiklatLainScroll, 0);
  return `<div style="display:flex; flex-direction:column; gap:.55rem;">
    <div ${list.length>2?'class="dkl-lain-scroll"':''} style="display:flex; flex-direction:column; gap:.55rem; ${list.length>2?'overflow-y:auto; padding-right:.35rem;':''}">
    ${list.map((it,j)=>`
      <div style="display:flex; flex-direction:column; gap:.3rem; ${j>0?'padding-top:.55rem; border-top:1px dashed var(--line);':''}">
        <div style="display:flex; gap:.35rem; align-items:center;">
          <input type="text" placeholder="Nama diklat lain" style="min-width:150px;" value="${esc(it.teks)}" oninput="diklatPesertaRows[${i}].diklat_lain_list[${j}].teks=this.value">
          <button type="button" class="btn btn-ghost !py-0.5 !px-2 text-[11px]" style="color:var(--bad);" title="Hapus isian ini" onclick="removeDiklatLain(${i},${j})">Hapus</button>
        </div>
        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
          <input type="file" accept="application/pdf,.pdf" style="display:none;" onchange="handleDiklatLainPdf(this,${i},${j})">
          <button type="button" class="btn btn-ghost !py-0.5 !px-2 text-[11px]" style="border-radius:999px;" onclick="this.previousElementSibling.click()">Upload PDF</button>
          ${it.pdf ? `<a href="#" class="text-[11px]" style="color:var(--orange-500); text-decoration:underline;" title="${esc(it.pdf.name)}" onclick="viewDiklatLainPdf(event,${i},${j})">Lihat PDF</a>` : ''}
        </div>
      </div>`).join('')}
    </div>
    <div><button type="button" class="btn btn-ghost !py-0.5 !px-2 text-[11px]" onclick="addDiklatLain(${i})">+ Tambah Diklat Lain</button></div>
  </div>`;
}
function rerenderDiklatLain(i){
  const el = document.getElementById(`dkl_lain_${diklatPesertaContext}_${i}`);
  if(el && diklatPesertaRows[i]) el.innerHTML = pesertaLainHtml(diklatPesertaRows[i], i);
}
function addDiklatLain(i){
  const r = diklatPesertaRows[i]; if(!r) return;
  getDiklatLainList(r).push({teks:'', pdf:null});
  rerenderDiklatLain(i);
}
function removeDiklatLain(i, j){
  const r = diklatPesertaRows[i]; if(!r) return;
  getDiklatLainList(r).splice(j,1);
  rerenderDiklatLain(i);
}
function handleDiklatLainPdf(input, i, j){
  const file = input.files && input.files[0];
  const r = diklatPesertaRows[i];
  if(!file || !r) return;
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){
    toast('File harus berformat PDF'); input.value=''; return;
  }
  if(file.size > 10*1024*1024){
    toast('Ukuran file maksimal 10MB'); input.value=''; return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    const list = getDiklatLainList(r);
    if(!list[j]) return;
    list[j].pdf = { name:file.name, size:file.size, data:reader.result };
    rerenderDiklatLain(i);
    toast('PDF terpilih: '+file.name+' (jangan lupa klik Simpan Data Peserta)');
  };
  reader.onerror = () => toast('Gagal membaca file PDF');
  reader.readAsDataURL(file);
}
function viewDiklatLainPdf(ev, i, j){
  ev.preventDefault();
  const r = diklatPesertaRows[i];
  const it = r && getDiklatLainList(r)[j];
  if(it && it.pdf) openPdfDataUrl(it.pdf.data);
}
function viewDiklatLainPdfSaved(ev, rowId, pKey, idx, j){
  ev.preventDefault(); ev.stopPropagation();
  const row = (DB[pKey]||[]).find(x=>x.id===rowId);
  const r = row && row.peserta_matrix && row.peserta_matrix[idx];
  const it = r && getDiklatLainList(r)[j];
  if(it && it.pdf) openPdfDataUrl(it.pdf.data);
}
function diklatLainTeksJoined(r){
  if((pesertaKey||'diklat')==='diklat' && Array.isArray(r.diklat_lain_list))
    return r.diklat_lain_list.map(x=>(x.teks||'').trim()).filter(Boolean).join('; ');
  return r.diklat_lain_teks||'';
}
function handleDiklatPesertaPdfChange(input, i){
  const infoEl = document.getElementById(`dkl_peserta_pdf_info_${diklatPesertaContext}_${i}`);
  const file = input.files && input.files[0];
  if(!diklatPesertaRows[i]) return;
  if(!file){ diklatPesertaRows[i].diklat_lain_pdf = null; if(infoEl) infoEl.textContent=''; return; }
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){
    toast('File harus berformat PDF');
    input.value = '';
    return;
  }
  if(file.size > 10*1024*1024){
    toast('Ukuran file maksimal 10MB');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    diklatPesertaRows[i].diklat_lain_pdf = { name: file.name, size: file.size, data: reader.result };
    if(infoEl) infoEl.textContent = `Terpilih: ${file.name} (${(file.size/1024).toFixed(0)} KB)`;
  };
  reader.onerror = () => { toast('Gagal membaca file PDF'); };
  reader.readAsDataURL(file);
}

let diklatMainSelectedIds = {};
function onDiklatMainSelectChange(id){
  diklatPesertaContext = 'main';
  diklatMainSelectedIds[pesertaKey] = id;
  const row = (DB[pesertaKey]||[]).find(r=>r.id===id);
  diklatPesertaRows = row && Array.isArray(row.peserta_matrix) ? JSON.parse(JSON.stringify(row.peserta_matrix)) : [];
  if(pesertaKey==='uji_periodik') loadUjiCols(row);
  refreshDiklatPesertaEditor();
}
function saveDiklatMainPeserta(){ if(guardRO('diklat')) return;
  if(pesertaKey==='diklat'){ saveDiklatRekap(); toast('Rekap diklat anggota disimpan'); return; }
  const row = (DB[pesertaKey]||[]).find(r=>r.id===diklatMainSelectedIds[pesertaKey]);
  if(!row){ toast('Pilih data '+PESERTA_MODULES[pesertaKey].label+' terlebih dahulu'); return; }
  row.peserta_matrix = diklatPesertaRows.map(r=>Object.assign({}, r));
  if(pesertaKey==='uji_periodik'){
    row.kolom_peserta = JSON.parse(JSON.stringify(getPesertaCols('uji_periodik')));
    ujiColsLocked = true;
  }
  persist();
  toast('Data peserta '+PESERTA_MODULES[pesertaKey].label.toLowerCase()+' disimpan');
  if(pesertaKey==='uji_periodik') refreshDiklatPesertaEditor();
}

/* ============ Rekap Peserta Diklat (seperti Excel) + Import CSV ============
   Tabel awalnya kosong. Nama peserta berasal dari file CSV/Excel yang diimpor,
   disimpan di DB.diklat_rekap = {rows:[{nama, jabatan, nipgol, <ceklis>, diklat_lain_list}]}. */
let diklatRekapQuery = '';
let _rekapRowsRef = null; // baris editor Rekap Diklat Anggota (dipisah dari editor Daftar Peserta Diklat)
function diklatNipGol(r){ return r.nipgol || ''; }
function diklatRekapSaved(){
  const d = DB.diklat_rekap;
  return (d && !Array.isArray(d) && Array.isArray(d.rows)) ? d.rows : [];
}
function diklatRekapRows(){
  pesertaKey = 'diklat';
  return diklatRekapSaved().map(x=>Object.assign(newDiklatPesertaRow(), JSON.parse(JSON.stringify(x))));
}
function ensureDiklatRekapRows(){
  if(pesertaKey!=='diklat' || diklatPesertaContext!=='main'){
    pesertaKey = 'diklat'; diklatPesertaContext = 'main';
    diklatPesertaRows = diklatRekapRows();
  }
}
function serializeRekapRows(src){
  return src.map(r=>{
    const o = {nama:r.nama||'', jabatan:r.jabatan||'', nipgol:r.nipgol||''};
    if(r.anggota_id) o.anggota_id = r.anggota_id;
    DIKLAT_PESERTA_COLS.forEach(c=>{
      o[c.key] = !!r[c.key];
      if(r[c.key] && r[c.key+'_pdf']) o[c.key+'_pdf'] = r[c.key+'_pdf'];
    });
    const lain = (Array.isArray(r.diklat_lain_list) ? r.diklat_lain_list : []).filter(x=>(x.teks && String(x.teks).trim()) || x.pdf);
    if(lain.length) o.diklat_lain_list = lain;
    return o;
  });
}
function saveDiklatRekap(){ if(guardRO('diklat')) return;
  ensureDiklatRekapRows();
  DB.diklat_rekap = {rows: serializeRekapRows(diklatPesertaRows)};
  persist();
}
function diklatRekapRowText(r){ return normSearch([r.nama, r.jabatan, r.nipgol].join(' ')); }
function diklatRekapEditorHtml(){
  const cols = DIKLAT_PESERTA_COLS;
  const q = normSearch(diklatRekapQuery);
  return `
    <div class="tablewrap" style="max-height:520px; overflow-y:auto;">
      <table>
        <thead>
          <tr>
            <th rowspan="2" class="text-center">No</th>
            <th rowspan="2">Nama</th>
            <th rowspan="2">Jabatan</th>
            <th rowspan="2">NIP/Golongan</th>
            <th colspan="${cols.length}" class="text-center">Kualifikasi (Diklat Internal/Tahun)</th>
            <th rowspan="2">Diklat Lain</th>
            <th rowspan="2" class="text-center">Aksi</th>
          </tr>
          <tr>${cols.map(c=>`<th class="text-center">${esc(c.label)}</th>`).join('')}</tr>
        </thead>
        <tbody>
          ${diklatPesertaRows.length ? diklatPesertaRows.map((r,i)=>{
            const hide = q && !diklatRekapRowText(r).includes(q);
            return `<tr data-dkl-row="${i}" ${hide?'style="display:none;"':''}>
              <td class="text-center">${i+1}</td>
              <td class="font-medium" style="min-width:180px;">${esc(r.nama)}</td>
              <td style="min-width:200px;">${esc(r.jabatan)||'-'}</td>
              <td style="min-width:210px;">${esc(diklatNipGol(r))||'-'}</td>
              ${cols.map(c=>`<td class="text-center" style="min-width:5.5rem;">${pesertaCellHtml(r,i,c)}</td>`).join('')}
              <td id="dkl_lain_main_${i}" style="min-width:250px;">${pesertaLainHtml(r,i)}</td>
              <td class="text-center"><button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="hapusRekapPeserta(${i})">Hapus</button></td>
            </tr>`;}).join('') : `<tr><td colspan="${cols.length+6}" class="text-center py-6" style="color:var(--muted);">Belum ada data peserta. Klik "Import Excel" untuk memasukkan data dari file Excel.</td></tr>`}
        </tbody>
      </table>
    </div>`;
}
function hapusRekapPeserta(i){ if(guardRO('diklat')) return;
  useRekapDiklat();
  const r = diklatPesertaRows[i]; if(!r) return;
  if(!confirm('Hapus "' + (r.nama||'peserta ini') + '" dari Rekap Diklat Anggota?')) return;
  diklatPesertaRows.splice(i,1);
  DB.diklat_rekap = {rows: serializeRekapRows(diklatPesertaRows)};
  persist();
  const wrap = document.getElementById('diklat_peserta_wrap_main');
  if(wrap) wrap.innerHTML = diklatRekapEditorHtml();
  const cnt = document.getElementById('rekap_count'); if(cnt) cnt.textContent = diklatPesertaRows.length + ' peserta';
  toast('Peserta dihapus dari Rekap Diklat Anggota');
}
function filterDiklatRekap(v){
  diklatRekapQuery = v;
  const q = normSearch(v);
  document.querySelectorAll('#diklat_peserta_wrap_main tr[data-dkl-row]').forEach(tr=>{
    const r = diklatPesertaRows[+tr.dataset.dklRow];
    tr.style.display = (!q || (r && diklatRekapRowText(r).includes(q))) ? '' : 'none';
  });
}
function diklatRekapPanelHtml(){
  pesertaKey = 'diklat'; diklatPesertaContext = 'main';
  diklatPesertaRows = diklatRekapRows();
  _rekapRowsRef = diklatPesertaRows;
  return `
    <div class="panel rounded-xl p-4 mt-4" id="rekap_panel_diklat">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-display font-semibold text-sm">Rekap Diklat Anggota</span>
          <input type="search" placeholder="Cari nama / jabatan..." style="min-width:200px;" value="${esc(diklatRekapQuery)}" oninput="filterDiklatRekap(this.value)">
          <span class="text-xs" id="rekap_count" style="color:var(--muted);">${diklatPesertaRows.length} peserta</span>
        </div>
        <div class="flex items-center gap-2">
          ${isSuper() ? `<input type="file" id="diklat_import_file" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleDiklatImportFile(this)">
          <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="document.getElementById('diklat_import_file').click()">Import Excel</button>` : ''}
          <div class="dd">
            <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
            <div class="dd-menu">
              <button type="button" class="dd-item" onclick="pesertaKey='diklat'; exportPeserta('pdf')">PDF</button>
              <button type="button" class="dd-item" onclick="pesertaKey='diklat'; exportPeserta('excel')">Excel</button>
            </div>
          </div>
          <button class="btn btn-primary !py-1.5 !px-3 text-xs" onclick="pesertaKey='diklat'; saveDiklatMainPeserta()">Simpan Rekap</button>
        </div>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">${isSuper() ? 'Daftar peserta muncul setelah Anda mengimpor file Excel (.xlsx) (kolom: NO, NAMA, JABATAN, NIP/GOLONGAN, DIKDAS … INSTRUKTUR, DIKLAT LAIN; tanda ceklis berupa √ / V / ✓).' : 'Daftar peserta rekap diklat diimpor oleh Admin Utama.'}</p>
      <div id="diklat_peserta_wrap_main">${diklatRekapEditorHtml()}</div>
    </div>`;
}

/* ---- Daftar Peserta Diklat: editor peserta langsung di tabel, per Nama Diklat ----
   Editor memakai fungsi editor peserta yang sama dengan form Diklat (konteks 'daftar'),
   tetapi barisnya terpisah dari Rekap Diklat Anggota. Konteks aktif diganti otomatis saat
   pengguna berinteraksi di salah satu panel (lihat listener di bawah). Perubahan tersimpan otomatis. */
let daftarRows = [];
let _daftarRowId = '', _daftarDirty = false, _daftarTimer = null;
function daftarDiklatSelId(){
  const rows = DB.diklat || [];
  if(!rows.length) return '';
  if(!diklatMainSelectedIds.diklat || !rows.some(r=>r.id===diklatMainSelectedIds.diklat)) diklatMainSelectedIds.diklat = rows[rows.length-1].id;
  return diklatMainSelectedIds.diklat;
}
function useDaftarDiklat(){ pesertaKey = 'diklat'; diklatPesertaContext = 'daftar'; diklatPesertaRows = daftarRows; }
function useRekapDiklat(){ if(!_rekapRowsRef) return; pesertaKey = 'diklat'; diklatPesertaContext = 'main'; diklatPesertaRows = _rekapRowsRef; }
function daftarLoadRows(){
  const row = (DB.diklat||[]).find(r=>r.id===daftarDiklatSelId());
  pesertaKey = 'diklat';
  daftarRows = row && Array.isArray(row.peserta_matrix) ? JSON.parse(JSON.stringify(row.peserta_matrix)) : [];
  if(!daftarRows.length) daftarRows.push(newDiklatPesertaRow()); // baris kosong siap diisi
  _daftarRowId = row ? row.id : ''; _daftarDirty = false;
}
function syncDaftarToDb(){
  clearTimeout(_daftarTimer);
  if(!_daftarDirty) return;
  const row = (DB.diklat||[]).find(r=>r.id===_daftarRowId);
  if(!row) return;
  const rows = daftarRows.filter(r=>r.anggota_id || (r.nama && String(r.nama).trim()));
  row.peserta_matrix = rows.map(r=>Object.assign({}, r));
  row.jumlah_peserta = rows.length;
  row.diperbarui_oleh = pelaku(); row.diperbarui_waktu = nowStr();
  _daftarDirty = false;
  persist();
}
function setDaftarDiklat(id){
  syncDaftarToDb();
  diklatMainSelectedIds.diklat = id;
  daftarLoadRows();
  const el = document.getElementById('daftar_diklat_body');
  if(el) el.innerHTML = daftarDiklatBodyHtml();
  useDaftarDiklat();
  if(typeof enhanceTableScroll==='function') enhanceTableScroll();
}
function daftarDiklatBodyHtml(){
  const row = (DB.diklat||[]).find(r=>r.id===daftarDiklatSelId());
  if(!row) return '';
  useDaftarDiklat();
  const tgl = row.tanggal_mulai ? (tglDMY(row.tanggal_mulai) + (row.tanggal_selesai && row.tanggal_selesai!==row.tanggal_mulai ? ' s.d. '+tglDMY(row.tanggal_selesai) : '')) : '-';
  return `
    <p class="text-xs mb-3" style="color:var(--muted);">Tanggal: <strong style="color:var(--text);">${esc(tgl)}</strong> · Lokasi: <strong style="color:var(--text);">${esc(row.lokasi)||'-'}</strong> · Status: ${statusBadge(statusFieldValue('diklat', (DIKLAT_STATUS_FIELD()||{}), row))||'-'}</p>
    <p class="text-[11px] mb-2" style="color:var(--muted);">Pilih nama anggota, lalu centang kualifikasi. Klik <strong>Simpan Data</strong> agar peserta otomatis masuk / diperbarui di Rekap Diklat Anggota.</p>
    <div id="diklat_peserta_wrap_daftar">${diklatPesertaEditorHtml()}</div>`;
}
function DIKLAT_STATUS_FIELD(){ const m = TABLE_MODULES.find(x=>x.key==='diklat'); return m ? m.fields.find(f=>f.isStatus) : null; }
function daftarDiklatPanelHtml(){
  const rows = DB.diklat || [];
  if(!rows.length){
    return `<div class="panel rounded-xl p-4 mt-4"><p class="text-sm font-display font-semibold mb-1">Daftar Peserta Diklat</p><p class="text-sm" style="color:var(--muted);">Belum ada data Diklat. Tambahkan data Diklat terlebih dahulu.</p></div>`;
  }
  const sel = daftarDiklatSelId();
  daftarLoadRows();
  return `
    <div class="panel rounded-xl p-4 mt-4" id="daftar_panel_diklat">
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <span class="font-display font-semibold text-sm">Daftar Peserta Diklat</span>
        <select id="daftar_diklat_select" style="width:auto; min-width:220px;" aria-label="Pilih Nama Diklat" onchange="setDaftarDiklat(this.value)">
          ${rows.map(r=>`<option value="${esc(r.id)}" ${r.id===sel?'selected':''}>${esc(r.nama_diklat||r.id)}</option>`).join('')}
        </select>
        <button type="button" class="btn btn-primary !py-1.5 !px-3 text-xs ml-auto" onclick="simpanDaftarDiklat()">Simpan Data</button>
      </div>
      <div id="daftar_diklat_body">${daftarDiklatBodyHtml()}</div>
    </div>`;
}
/* ---- Simpan Data: simpan Daftar Peserta Diklat + gabungkan otomatis ke Rekap Diklat Anggota ----
   - nama sudah ada di rekap (dicocokkan lewat nama) -> kualifikasi yang dicentang ditambahkan / diperbarui
   - nama belum ada -> baris baru dibuat otomatis di rekap
   Nama diklat TIDAK dimasukkan ke rekap (hanya data peserta & kualifikasi). */
function golonganAnggota(a){
  const m = /Golongan:\s*([^;]+)/i.exec((a && a.keterangan) || '');
  return m ? m[1].trim() : '';
}
function gabungDaftarKeRekap(diklatRow, list){
  if(!_rekapRowsRef) _rekapRowsRef = diklatRekapRows();
  const rekap = _rekapRowsRef;
  const namaDiklat = String(diklatRow.nama_diklat || diklatRow.id || '').trim();
  let diperbarui = 0, ditambah = 0;
  list.forEach(d=>{
    const ang = (DB.anggota||[]).find(a=>a.id===d.anggota_id);
    const nama = String((ang ? ang.nama : d.nama) || '').trim();
    if(!nama) return;
    const kunci = normSearch(nama);
    let r = rekap.find(x=>normSearch(x.nama)===kunci);
    if(r){ diperbarui++; }
    else {
      pesertaKey = 'diklat';
      r = newDiklatPesertaRow();
      r.nama = nama;
      rekap.push(r); ditambah++;
    }
    if(ang && !r.anggota_id) r.anggota_id = ang.id;
    if(!String(r.jabatan||'').trim()) r.jabatan = (ang && ang.jabatan) || d.jabatan || '';
    if(!String(r.nipgol||'').trim() && ang){
      const gol = golonganAnggota(ang);
      r.nipgol = (ang.nip||'') + (gol ? ' / '+gol.toUpperCase() : '');
    }
    // kualifikasi: yang dicentang di daftar peserta ditambahkan ke rekap (tidak mencabut yang sudah ada dari diklat lain)
    DIKLAT_PESERTA_COLS.forEach(c=>{
      if(d[c.key]){
        r[c.key] = true;
        if(d[c.key+'_pdf']) r[c.key+'_pdf'] = d[c.key+'_pdf'];
      }
    });
    // Diklat Lain: gabungkan isian peserta + nama diklat ini, tanpa duplikat
    const lain = (Array.isArray(r.diklat_lain_list) ? r.diklat_lain_list : []).filter(x=>(x.teks && String(x.teks).trim()) || x.pdf);
    const ada = t => lain.some(x=>String(x.teks||'').trim().toLowerCase()===String(t||'').trim().toLowerCase());
    const dari = Array.isArray(d.diklat_lain_list) ? d.diklat_lain_list.filter(x=>(x.teks && String(x.teks).trim()) || x.pdf) : [];
    dari.forEach(x=>{
      if(x.teks && String(x.teks).trim()){
        const ex = lain.find(y=>String(y.teks||'').trim().toLowerCase()===String(x.teks).trim().toLowerCase());
        if(ex){ if(!ex.pdf && x.pdf) ex.pdf = x.pdf; } else lain.push({teks:x.teks, pdf:x.pdf||null});
      } else if(x.pdf && !lain.some(y=>y.pdf && y.pdf.name===x.pdf.name)) lain.push({teks:'', pdf:x.pdf});
    });
    r.diklat_lain_list = lain.length ? lain : [{teks:'', pdf:null}];
  });
  DB.diklat_rekap = {rows: serializeRekapRows(rekap)};
  return {diperbarui, ditambah};
}
function simpanDaftarDiklat(){ if(guardRO('diklat')) return;
  const row = (DB.diklat||[]).find(r=>r.id===_daftarRowId);
  if(!row){ toast('Pilih data Diklat terlebih dahulu'); return; }
  const list = daftarRows.filter(r=>r.anggota_id || (r.nama && String(r.nama).trim()));
  if(!list.length){ toast('Belum ada peserta yang dipilih'); return; }
  _daftarDirty = true; syncDaftarToDb();               // simpan daftar peserta ke data Diklat
  const hasil = gabungDaftarKeRekap(row, list);         // gabungkan ke Rekap Diklat Anggota
  persist();
  // segarkan tabel Rekap di halaman tanpa memuat ulang
  const wrap = document.getElementById('diklat_peserta_wrap_main');
  if(wrap && _rekapRowsRef){
    useRekapDiklat();
    wrap.innerHTML = diklatRekapEditorHtml();
    const cnt = document.getElementById('rekap_count'); if(cnt) cnt.textContent = _rekapRowsRef.length + ' peserta';
    useDaftarDiklat();
  }
  toast('Data disimpan. Rekap Diklat Anggota: ' + hasil.diperbarui + ' diperbarui, ' + hasil.ditambah + ' ditambahkan');
}
/* Aktifkan konteks editor yang benar SEBELUM handler inline berjalan (fase capture),
   lalu simpan otomatis perubahan Daftar Peserta Diklat setelah interaksi (fase bubble). */
(function(){
  const activate = ev => {
    const t = ev.target; if(!t || !t.closest) return;
    if(t.closest('#daftar_panel_diklat')){
      useDaftarDiklat();
      // tandai perubahan di fase capture: elemen bisa dibuang saat editor dirender ulang oleh handler-nya
      if(['input','change','click'].includes(ev.type) && t.id!=='daftar_diklat_select' && !(ev.type==='click' && !t.closest('button'))){
        _daftarDirty = true;
        clearTimeout(_daftarTimer);
        _daftarTimer = setTimeout(syncDaftarToDb, (t.type==='file') ? 2500 : 600);
      }
    }
    else if(t.closest('#rekap_panel_diklat')) useRekapDiklat();
  };
  ['pointerdown','focusin','keydown','input','change','click'].forEach(n=>document.addEventListener(n, activate, true));
})();

/* ---- Import CSV ---- */
function decodeCsvBuffer(buf){
  try{ return new TextDecoder('utf-8', {fatal:true}).decode(buf); }
  catch(e){ return new TextDecoder('windows-1252').decode(buf); }
}
function parseCsvText(text){
  text = text.replace(/^﻿/, '');
  const sample = text.split(/\r?\n/).slice(0, 15).join('\n');
  const delim = [',',';','\t'].map(d=>({d, n:sample.split(d).length})).sort((a,b)=>b.n-a.n)[0].d;
  const rows = []; let row = [], cur = '', q = false;
  for(let i=0;i<text.length;i++){
    const ch = text[i];
    if(q){
      if(ch==='"'){ if(text[i+1]==='"'){ cur += '"'; i++; } else q = false; }
      else cur += ch;
    } else if(ch==='"'){ q = true; }
    else if(ch===delim){ row.push(cur); cur = ''; }
    else if(ch==='\n' || ch==='\r'){
      if(ch==='\r' && text[i+1]==='\n') i++;
      row.push(cur); cur = ''; rows.push(row); row = [];
    } else cur += ch;
  }
  if(cur!=='' || row.length){ row.push(cur); rows.push(row); }
  return rows;
}
function csvTruthy(v){
  const t = String(v==null?'':v).trim().toLowerCase();
  return !(t==='' || ['-','0','false','tidak','no','n'].includes(t));
}
function normLabel(s){ return String(s||'').toUpperCase().replace(/[^A-Z0-9]/g,''); }
function handleDiklatImportFile(input){
  const file = input.files && input.files[0];
  input.value = '';
  if(!file) return;
  if(!isSuper()){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const isCsv = /\.csv$/i.test(file.name);
  if(!isCsv && typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    try{
      let rows;
      if(isCsv) rows = parseCsvText(decodeCsvBuffer(reader.result));
      else {
        const wb = XLSX.read(reader.result, {type:'array'});
        // pakai sheet pertama yang memiliki kolom NAMA
        let ws = null;
        for(const n of wb.SheetNames){
          const r = XLSX.utils.sheet_to_json(wb.Sheets[n], {header:1, defval:'', raw:false});
          if(r.some(x=>x.some(c=>normLabel(c)==='NAMA'))){ ws = r; break; }
        }
        rows = ws || XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header:1, defval:'', raw:false});
      }
      prepareDiklatImport(rows);
    }catch(e){ console.error(e); toast('Gagal membaca file Excel'); }
  };
  reader.onerror = () => toast('Gagal membaca file');
  reader.readAsArrayBuffer(file);
}
let _diklatImportPending = null;
function prepareDiklatImport(rows){
  const hi = rows.findIndex(r=>r.some(c=>normLabel(c)==='NAMA') && r.some(c=>/^(NIP|JABATAN)/.test(normLabel(c))));
  if(hi<0){ toast('Header kolom NAMA tidak ditemukan di file Excel'); return; }
  const keyByLabel = {}; DIKLAT_PESERTA_COLS.forEach(c=>keyByLabel[normLabel(c.label)] = c.key);
  const known = r => (r||[]).some(c=>keyByLabel[normLabel(c)]);
  const sub = (rows[hi+1] && known(rows[hi+1])) ? rows[hi+1] : null;
  const width = Math.max(rows[hi].length, sub ? sub.length : 0);
  const colKey = {}; let iNama=-1, iJab=-1, iNip=-1, iLain=-1;
  for(let j=0;j<width;j++){
    [normLabel(rows[hi][j]), normLabel(sub ? sub[j] : '')].forEach(l=>{
      if(!l) return;
      if(l==='NAMA' && iNama<0) iNama = j;
      else if(l==='JABATAN' && iJab<0) iJab = j;
      else if(l.startsWith('NIP') && iNip<0) iNip = j;
      else if(l.startsWith('DIKLATLAIN')) iLain = j;
      else if(keyByLabel[l]) colKey[j] = keyByLabel[l];
    });
  }
  if(iNama<0){ toast('Kolom NAMA tidak ditemukan'); return; }
  if(!Object.keys(colKey).length){ toast('Kolom kualifikasi (DIKDAS, MFR, ...) tidak ditemukan'); return; }
  const out = [];
  for(let i=hi+1+(sub?1:0); i<rows.length; i++){
    const r = rows[i];
    const nama = String(r[iNama]||'').trim();
    if(!nama || /^\d*$/.test(nama)) continue;
    const o = {nama, jabatan: iJab>=0 ? String(r[iJab]||'').trim() : '', nipgol: iNip>=0 ? String(r[iNip]||'').replace(/\s*\/\s*/,' / ').trim() : ''};
    Object.keys(colKey).forEach(j=>{ o[colKey[j]] = csvTruthy(r[j]); });
    o.lain = iLain>=0 ? String(r[iLain]||'').split(/[,;\n]+/).map(x=>x.trim()).filter(Boolean) : [];
    out.push(o);
  }
  if(!out.length){ toast('Tidak ada data peserta di file Excel'); return; }
  _diklatImportPending = out;
  const sudahAda = diklatRekapSaved().length;
  openModal(`
    <div class="p-5">
      <h3 class="font-display font-semibold text-base mb-2">Import Excel Rekap Diklat Anggota</h3>
      <p class="text-sm mb-2">Ditemukan <b>${out.length}</b> peserta di file Excel.</p>
      <p class="text-xs mb-4" style="color:var(--muted);">${sudahAda ? `Daftar peserta saat ini (${sudahAda} orang) akan diganti dengan isi Excel. PDF yang sudah diupload tetap dipertahankan untuk nama yang sama.` : 'Daftar peserta akan dibuat dari isi Excel.'}</p>
      <div class="flex justify-end gap-2">
        <button class="btn btn-ghost" onclick="_diklatImportPending=null; closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="applyDiklatImport()">Impor ${out.length} Peserta</button>
      </div>
    </div>`);
}
function applyDiklatImport(){ if(guardRO('diklat')) return;
  if(!isSuper()){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const list = _diklatImportPending; if(!list) return;
  const oldMap = {};
  diklatRekapSaved().forEach(x=>{ oldMap[normSearch(x.nama)] = x; });
  pesertaKey = 'diklat'; diklatPesertaContext = 'main';
  diklatPesertaRows = _rekapRowsRef = list.map(o=>{
    const r = Object.assign(newDiklatPesertaRow(), {nama:o.nama, jabatan:o.jabatan, nipgol:o.nipgol});
    const old = oldMap[normSearch(o.nama)] || {};
    DIKLAT_PESERTA_COLS.forEach(c=>{
      r[c.key] = !!o[c.key];
      if(r[c.key] && old[c.key+'_pdf']) r[c.key+'_pdf'] = old[c.key+'_pdf'];
    });
    const oldLain = Array.isArray(old.diklat_lain_list) ? old.diklat_lain_list : [];
    r.diklat_lain_list = o.lain.length
      ? o.lain.map(t=>{ const m = oldLain.find(x=>x.pdf && String(x.teks||'').trim().toLowerCase()===t.toLowerCase()); return {teks:t, pdf:m?m.pdf:null}; })
      : [{teks:'', pdf:null}];
    return r;
  });
  saveDiklatRekap();
  _diklatImportPending = null;
  closeModal();
  diklatRekapQuery = '';
  render(currentView);
  toast('Berhasil mengimpor '+list.length+' peserta diklat');
}

/* ---- Unduh data peserta (PDF / Excel) ---- */
function toggleUnduhMenu(ev, btn){
  ev.stopPropagation();
  const dd = btn.closest('.dd');
  const wasOpen = dd.classList.contains('open');
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  if(!wasOpen) dd.classList.add('open');
}
document.addEventListener('click', ()=>document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open')));

function exportPeserta(fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const M = PESERTA_MODULES[pesertaKey];
  const sel = (DB[pesertaKey]||[]).find(r=>r.id===diklatMainSelectedIds[pesertaKey]);
  const rekap = (pesertaKey==='diklat');
  const namaKegiatan = rekap ? 'Kantor Pencarian dan Pertolongan Jakarta' : (sel ? (sel[M.nameKey]||'') : '');
  if(rekap) ensureDiklatRekapRows();
  if(!diklatPesertaRows.length){ toast('Belum ada data peserta untuk diunduh'); return; }
  const head = ['No','Nama','Jabatan', ...(rekap?['NIP/Golongan']:[]), ...getPesertaCols(pesertaKey).map(c=>c.label), M.lainLabel];
  const isX = fmt==='excel';
  const body = diklatPesertaRows.map((r,i)=>[
    i+1, r.nama||'', r.jabatan||'', ...(rekap?[diklatNipGol(r)]:[]),
    ...getPesertaCols(pesertaKey).map(c=> r[c.key] ? (isX?'✓':'V') : '-'),
    diklatLainTeksJoined(r)
  ]);
  const judul = rekap ? 'Rekap Diklat Anggota' : 'Data Peserta '+M.label;
  const fname = (rekap ? judul : 'Data Peserta '+M.label+' - '+namaKegiatan).trim().replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(isX){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const aoa = [[judul], [namaKegiatan], [], head, ...body];
      const ws = XLSX.utils.aoa_to_sheet(aoa);
      ws['!cols'] = head.map((h,i)=>({wch: i===0?5 : i===1?26 : i===2?24 : (rekap&&i===3)?34 : i===head.length-1?30 : 10}));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Peserta');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      doc.setFontSize(13); doc.text(judul, 40, 40);
      doc.setFontSize(10); doc.text(namaKegiatan, 40, 56);
      doc.autoTable({
        head:[head], body, startY:68,
        styles:{fontSize:7, cellPadding:3, valign:'middle'},
        headStyles:{fillColor:[19,49,92], halign:'center'},
        columnStyles:Object.assign({0:{halign:'center', cellWidth:24}},
          ...getPesertaCols(pesertaKey).map((c,i)=>({[i+(rekap?4:3)]:{halign:'center'}})))
      });
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
/* ---- Card kategori Uji Periodik (di antara tabel data & Data Peserta) ---- */
const UJI_KATEGORI = [
  {label:'Teknis SAR',              desc:'Kemampuan teknis pencarian & pertolongan',
   icon:'<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.6-.6-.6-2.6 2.6-2.2z"/>'},
  {label:'Pengetahuan Umum',        desc:'Wawasan dan pengetahuan dasar SAR',
   icon:'<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5z"/><path d="M8 7h7M8 11h7"/>'},
  {label:'Kebugaran Jasmani',       desc:'Kondisi fisik dan daya tahan tubuh',
   icon:'<path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 0 0-7.1 7.1L12 21l8.8-8.3a5 5 0 0 0 0-7.1z"/>'},
  {label:'Renang & Water Treading', desc:'Kemampuan berenang dan bertahan di air',
   icon:'<path d="M2 16c2 0 2-1.5 5-1.5S9 16 12 16s3-1.5 5-1.5 3 1.5 5 1.5"/><path d="M2 20c2 0 2-1.5 5-1.5S9 20 12 20s3-1.5 5-1.5 3 1.5 5 1.5"/><circle cx="14" cy="6" r="2"/><path d="M6 12l4-3 3 2 3-1"/>'},
];
function ujiKategoriCardsHtml(){
  return `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    ${UJI_KATEGORI.map(k=>`
      <div class="panel rounded-xl p-4 flex items-start gap-3" style="cursor:pointer;" role="button" tabindex="0" onclick="openUjiKategori(${UJI_KATEGORI.indexOf(k)})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openUjiKategori(${UJI_KATEGORI.indexOf(k)})}">
        <div style="flex:none; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:var(--orange-100); color:var(--orange-500);">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${k.icon}</svg>
        </div>
        <div class="min-w-0">
          <div class="font-display font-semibold text-sm" style="color:var(--navy-800);">${esc(k.label)}</div>
          <div class="text-xs mt-0.5" style="color:var(--muted);">${esc(k.desc)}</div>
        </div>
      </div>`).join('')}
  </div>`;
}
/* ---- Form input per kategori Uji Periodik (popup, gaya detail kegiatan) ---- */
const UJK_NILAI = [{key:'mfr',label:'MFR'},{key:'hart',label:'HART'},{key:'jr',label:'JR'}]; // kolom bawaan
// Kategori dengan kolom tetap sendiri (tanpa MFR/HART/JR dan tanpa tambah/ubah/hapus kolom)
const UJK_FIXED = {'Pengetahuan Umum':[{key:'nilai',label:'Nilai'}]};
// Kolom bawaan khusus per kategori (masih bisa diubah nama / dihapus / ditambah)
const UJK_DEFAULTS = {'Renang & Water Treading':[{key:'gb100',label:'Gaya Bebas 100M'},{key:'gd100',label:'Gaya Dada 100 M'},{key:'wt',label:'Water Treading'}]};
function ujkBaseDefaults(kat){ return UJK_FIXED[kat] || UJK_DEFAULTS[kat] || UJK_NILAI; }
// Kolom tambahan (opsional) disimpan per kategori: DB.uji_kategori_cols[kategori] = [{key,label}]
function ujkExtraCols(kat){ if(UJK_FIXED[kat]) return []; return ((DB.uji_kategori_cols||{})[kat]) || []; }
// Kolom bawaan (MFR/HART/JR) juga bisa diubah nama / dihapus: DB.uji_kategori_base[kat] = {hidden:[key], labels:{key:label}}
function ujkBase(kat){ return ((DB.uji_kategori_base||{})[kat]) || {hidden:[], labels:{}}; }
function ujkBaseCols(kat){
  const b = ujkBase(kat);
  if(UJK_FIXED[kat]) return UJK_FIXED[kat];
  return ujkBaseDefaults(kat).filter(c=>!(b.hidden||[]).includes(c.key)).map(c=>({key:c.key, label:(b.labels||{})[c.key]||c.label}));
}
function ujkBaseSet(kat, fn){
  if(!DB.uji_kategori_base) DB.uji_kategori_base = {};
  const b = DB.uji_kategori_base[kat] || {hidden:[], labels:{}};
  if(!b.hidden) b.hidden = [];
  if(!b.labels) b.labels = {};
  fn(b); DB.uji_kategori_base[kat] = b; persist();
}
function ujkCols(kat){ return ujkBaseCols(kat).concat(ujkExtraCols(kat)); }
// Kurang < 60 (termasuk < 50), Cukup >= 60, Baik >= 70, Sangat Baik >= 85
function ujkPredikat(avg){
  if(avg===null || isNaN(avg)) return '';
  if(avg>=85) return 'Sangat Baik';
  if(avg>=70) return 'Baik';
  if(avg>=60) return 'Cukup';
  return 'Kurang';
}
function ujkPredikatBadge(p){
  if(!p) return '<span style="color:var(--muted);">-</span>';
  const cls = p==='Kurang' ? 'b-bad' : (p==='Cukup' ? 'b-warn' : 'b-ok');
  return `<span class="badge ${cls}">${esc(p)}</span>`;
}
// Baris hasil Import Excel menyimpan satu nilai per kategori di 'nilai_import' (dipakai langsung sebagai rata-rata)
function ujkAvgRow(r, cols){
  const n = parseFloat(r.nilai_import);
  if(r.nilai_import!==undefined && r.nilai_import!=='' && !isNaN(n)) return n;
  return ujkRataRata(cols.map(c=>r[c.key]));
}
function ujkRataRata(vals){
  const nums = vals.filter(v=>v!=='' && v!==null && v!==undefined && !isNaN(parseFloat(v))).map(v=>parseFloat(v));
  return nums.length ? nums.reduce((x,y)=>x+y,0)/nums.length : null;
}
function fmtNilai(n){ return (n===null || n===undefined || n==='' || isNaN(n)) ? '-' : String(Math.round(parseFloat(n)*100)/100).replace('.',','); }
function ujkList(kat){ const u = ujiPesertaSelId(); return (DB.uji_kategori||[]).filter(x=>x.kategori===kat && (!u || x.uji_id===u)); }
function ujkRowsHtml(kat){
  const rows = ujkList(kat).slice().reverse();
  const cols = ujkCols(kat);
  if(!rows.length) return `<p class="text-sm" style="color:var(--muted);">Belum ada data ${esc(kat)}.</p>`;
  return `<div class="tablewrap"><table>
    <thead><tr><th>Nama Anggota</th><th>Jabatan</th><th class="text-center">JK</th><th class="text-center">Usia</th>${cols.map(c=>`<th>${esc(c.label)}</th>`).join('')}<th>Rata Rata</th><th>Predikat</th><th>Aksi</th></tr></thead>
    <tbody>${rows.map(r=>{
      const avg = ujkAvgRow(r, cols);
      return `<tr>
      <td class="font-medium">${esc(r.nama)||'-'}</td><td>${esc(r.jabatan)||'-'}</td><td class="text-center">${esc(r.jk)||'-'}</td><td class="text-center">${(r.usia===undefined||r.usia===null||r.usia==='')?'-':esc(r.usia)}</td>
      ${cols.map(c=>`<td>${fmtNilai(r[c.key])}</td>`).join('')}
      <td class="font-semibold">${fmtNilai(avg)}</td><td>${ujkPredikatBadge(ujkPredikat(avg))}</td>
      <td class="whitespace-nowrap"><button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="editUjiKategori('${esc(r.id)}')">Ubah</button> <button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteUjiKategori('${esc(r.id)}')">Hapus</button></td>
    </tr>`;}).join('')}</tbody></table></div>`;
}
function ujkLbl(t){ return `<label class="block text-xs font-medium mb-1" style="color:var(--muted);">${t}</label>`; }
function ujkNilaiInputsHtml(kat){
  return ujkCols(kat).map(c=>`<div>
      <div class="flex items-center justify-between">${ujkLbl(esc(c.label))}
        ${!UJK_FIXED[kat] ? `<span class="whitespace-nowrap" style="margin-top:-4px;">
          <button type="button" class="btn btn-ghost !py-0 !px-1.5 text-xs" title="Ubah nama kolom" onclick="renameUjkKolom('${esc(c.key)}')">✎</button>
          <button type="button" class="btn btn-ghost !py-0 !px-1.5 text-xs" style="color:var(--bad);" title="Hapus kolom" onclick="deleteUjkKolom('${esc(c.key)}')">✕</button>
        </span>` : ''}
      </div>
      <input type="number" id="ujk_${esc(c.key)}" step="any" min="0" inputmode="decimal" placeholder="0,00" oninput="ujkHitung()">
    </div>`).join('');
}
// Render ulang blok kolom nilai tanpa menghilangkan angka yang sudah diketik
function ujkRefreshCols(){
  const kat = document.getElementById('ujk_kat').value;
  const old = {};
  document.querySelectorAll('#ujk_cols input[type=number]').forEach(i=>{ old[i.id]=i.value; });
  document.getElementById('ujk_cols').innerHTML = ujkNilaiInputsHtml(kat);
  Object.keys(old).forEach(id=>{ const el=document.getElementById(id); if(el) el.value = old[id]; });
  document.getElementById('ujk_list').innerHTML = ujkRowsHtml(kat);
  ujkRefreshPesertaPanel();
  ujkHitung();
}
let ujkEditId = null;
function openUjiKategori(idx){ if(guardRO('uji_periodik')) return;
  const k = UJI_KATEGORI[idx]; if(!k) return;
  if(!(DB.uji_periodik||[]).length){ toast('Tambahkan data Uji Periodik terlebih dahulu'); return; }
  ujiMigrasiNilaiLama();
  ujkEditId = null;
  const angOpts = '<option value="">- Pilih Anggota -</option>' + (DB.anggota||[]).map(a=>`<option value="${esc(a.id)}">${esc(a.nama)}</option>`).join('');
  openModal(`
    <div class="p-5 max-h-[88vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-1">
        <div>
          <h3 class="font-display font-semibold text-base">${esc(k.label)}</h3>
          <p class="text-xs" style="color:var(--muted);">Uji Periodik · ${esc(k.desc)}</p>
        </div>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <input type="hidden" id="ujk_kat" value="${esc(k.label)}">
      <div class="mt-4">${ujkLbl('Nama Uji Periodik')}<select id="ujk_uji_select" onchange="onUjiPesertaSelectChange(this.value)">${ujiOptionsHtml()}</select></div>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <div>${ujkLbl('Nama Anggota')}<select id="ujk_anggota" onchange="ujkPilihAnggota(this.value)">${angOpts}</select></div>
        <div>${ujkLbl('Jabatan')}<input type="text" id="ujk_jabatan" readonly placeholder="Terisi otomatis" style="opacity:.8;"></div>
        <div>${ujkLbl('Jenis Kelamin')}<select id="ujk_jk"><option value="">- Pilih -</option><option value="L">L</option><option value="P">P</option></select></div>
        <div>${ujkLbl('Usia')}<input type="number" id="ujk_usia" min="0" max="120" step="1" inputmode="numeric" placeholder="Tahun"></div>
      </div>
      <div id="ujk_cols" class="grid sm:grid-cols-2 gap-3 mt-3">${ujkNilaiInputsHtml(k.label)}</div>
      <div class="grid sm:grid-cols-2 gap-3 mt-3">
        <div>${ujkLbl('Rata Rata')}<input type="text" id="ujk_rata" readonly placeholder="Terisi otomatis" style="opacity:.8;"></div>
        <div>${ujkLbl('Predikat')}<div id="ujk_pred" class="pt-2">${ujkPredikatBadge('')}</div></div>
      </div>
      ${UJK_FIXED[k.label] ? '' : `<div class="flex items-end gap-2 mt-3 p-3 rounded-lg" style="background:var(--bg, #f3f4f8);">
        <div class="flex-1">${ujkLbl('Kolom / Inputan Baru')}<input type="text" id="ujk_kolom_baru" placeholder="Nama kolom, mis. Simulasi" onkeydown="if(event.key==='Enter'){event.preventDefault();addUjkKolom();}"></div>
        <button type="button" class="btn btn-ghost" onclick="addUjkKolom()">+ Tambah Kolom</button>
      </div>`}
      <div class="flex justify-end gap-2 mt-4">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" id="ujk_save_btn" onclick="saveUjiKategori()">Simpan</button>
      </div>
      <div class="mt-5 pt-4 border-t" style="border-color:var(--line);">
        <div class="flex items-center justify-between mb-2">
          <div class="font-display font-semibold text-sm">Data ${esc(k.label)}</div>
          <div class="dd">
            <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
            <div class="dd-menu">
              <button type="button" class="dd-item" onclick="exportUjiKategori('pdf')">PDF</button>
              <button type="button" class="dd-item" onclick="exportUjiKategori('excel')">Excel</button>
            </div>
          </div>
        </div>
        <div id="ujk_list">${ujkRowsHtml(k.label)}</div>
      </div>
    </div>`, true);
}
function exportUjiKategori(fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const kat = document.getElementById('ujk_kat').value;
  const rows = ujkList(kat);
  if(!rows.length){ toast('Belum ada data '+kat+' untuk diunduh'); return; }
  const cols = ujkCols(kat);
  const uji = (DB.uji_periodik||[]).find(r=>r.id===ujiPesertaSelId());
  const namaUji = uji ? (uji.nama_uji||uji.nama||'') : '';
  const head = ['No','Nama Anggota','Jabatan','JK','Usia', ...cols.map(c=>c.label), 'Rata Rata','Predikat'];
  const isX = fmt==='excel';
  const body = rows.map((r,i)=>{
    const avg = ujkAvgRow(r, cols);
    const num = v => (v===undefined||v===null||v==='') ? '' : (isX ? parseFloat(v) : fmtNilai(v));
    return [i+1, r.nama||'', r.jabatan||'', r.jk||'-', (r.usia===undefined||r.usia===null||r.usia==='')?'':r.usia, ...cols.map(c=>num(r[c.key])),
      avg===null ? '' : (isX ? Math.round(avg*100)/100 : fmtNilai(avg)), ujkPredikat(avg)||'-'];
  });
  const title = 'Data '+kat;
  const fname = (title+(namaUji?' - '+namaUji:'')).trim().replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(isX){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet([[title],[namaUji],[],head,...body]);
      ws['!cols'] = head.map((h,i)=>({wch: i===0?5 : i===1?30 : i===2?34 : i<=4?6 : 12}));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, kat.replace(/[\\/?*\[\]:]/g,'').slice(0,31));
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      doc.setFontSize(13); doc.text(title, 40, 40);
      doc.setFontSize(10); doc.text(namaUji, 40, 56);
      doc.autoTable({
        head:[head], body, startY:68,
        styles:{fontSize:8, cellPadding:3, valign:'middle'},
        headStyles:{fillColor:[19,49,92], halign:'center'},
        columnStyles:{0:{halign:'center', cellWidth:24}, 3:{halign:'center', cellWidth:28}, 4:{halign:'center', cellWidth:28}}
      });
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
function addUjkKolom(){
  const kat = document.getElementById('ujk_kat').value;
  const inp = document.getElementById('ujk_kolom_baru');
  const label = inp.value.trim();
  if(!label){ toast('Nama kolom wajib diisi'); return; }
  if(ujkCols(kat).some(c=>c.label.toLowerCase()===label.toLowerCase())){ toast('Nama kolom sudah ada'); return; }
  if(!DB.uji_kategori_cols) DB.uji_kategori_cols = {};
  if(!DB.uji_kategori_cols[kat]) DB.uji_kategori_cols[kat] = [];
  let n = Date.now().toString(36);
  DB.uji_kategori_cols[kat].push({key:'x_'+n, label});
  persist();
  inp.value = '';
  ujkRefreshCols();
  toast('Kolom "'+label+'" ditambahkan');
}
function renameUjkKolom(key){
  const kat = document.getElementById('ujk_kat').value;
  const c = ujkCols(kat).find(x=>x.key===key); if(!c) return;
  const nv = (prompt('Ubah nama kolom:', c.label)||'').trim();
  if(!nv || nv===c.label) return;
  if(ujkCols(kat).some(x=>x.key!==key && x.label.toLowerCase()===nv.toLowerCase())){ toast('Nama kolom sudah ada'); return; }
  if(ujkBaseDefaults(kat).some(x=>x.key===key)) ujkBaseSet(kat, b=>{ b.labels[key] = nv; });
  else { c.label = nv; persist(); }
  ujkRefreshCols();
}
function deleteUjkKolom(key){
  const kat = document.getElementById('ujk_kat').value;
  const c = ujkCols(kat).find(x=>x.key===key); if(!c) return;
  if(ujkCols(kat).length<=1){ toast('Minimal harus ada satu kolom nilai'); return; }
  if(!confirm('Hapus kolom "'+c.label+'"? Nilai kolom ini tidak akan ikut dihitung lagi.')) return;
  if(ujkBaseDefaults(kat).some(x=>x.key===key)) ujkBaseSet(kat, b=>{ if(!b.hidden.includes(key)) b.hidden.push(key); });
  else { DB.uji_kategori_cols[kat] = DB.uji_kategori_cols[kat].filter(x=>x.key!==key); persist(); }
  ujkRefreshCols();
}
function editUjiKategori(id){
  const r = (DB.uji_kategori||[]).find(x=>x.id===id); if(!r) return;
  ujkEditId = id;
  const sel = document.getElementById('ujk_anggota');
  sel.value = r.anggota_id; ujkPilihAnggota(r.anggota_id);
  document.getElementById('ujk_jk').value = r.jk || '';
  document.getElementById('ujk_usia').value = (r.usia===undefined||r.usia===null) ? '' : r.usia;
  ujkCols(r.kategori).forEach(c=>{ const el = document.getElementById('ujk_'+c.key); if(el) el.value = (r[c.key]===undefined || r[c.key]==='') ? '' : r[c.key]; });
  ujkHitung();
  document.getElementById('ujk_save_btn').textContent = 'Simpan Perubahan';
  sel.scrollIntoView({behavior:'smooth', block:'center'});
  toast('Mengubah data '+(r.nama||id));
}
function ujkPilihAnggota(id){
  const a = (DB.anggota||[]).find(x=>x.id===id);
  document.getElementById('ujk_jabatan').value = a ? (a.jabatan||'') : '';
  const jkEl = document.getElementById('ujk_jk');
  if(jkEl && !ujkEditId){
    const prev = (DB.uji_kategori||[]).slice().reverse().find(x=>x.anggota_id===id && x.jk);
    jkEl.value = prev ? prev.jk : '';
  }
  const usEl = document.getElementById('ujk_usia');
  if(usEl && !ujkEditId){
    const pu = (DB.uji_kategori||[]).slice().reverse().find(x=>x.anggota_id===id && x.usia!==undefined && x.usia!=='');
    usEl.value = pu ? pu.usia : '';
  }
}
function ujkHitung(){
  const kat = document.getElementById('ujk_kat').value;
  const avg = ujkRataRata(ujkCols(kat).map(c=>{ const el=document.getElementById('ujk_'+c.key); return el ? el.value : ''; }));
  document.getElementById('ujk_rata').value = avg===null ? '' : fmtNilai(avg);
  document.getElementById('ujk_pred').innerHTML = ujkPredikatBadge(ujkPredikat(avg));
}
function saveUjiKategori(){ if(guardRO('uji_periodik')) return;
  const kat = document.getElementById('ujk_kat').value;
  const angId = document.getElementById('ujk_anggota').value;
  const ang = (DB.anggota||[]).find(x=>x.id===angId);
  if(!ang){ toast('Nama anggota wajib dipilih'); return; }
  const jk = document.getElementById('ujk_jk').value;
  const usiaRaw = document.getElementById('ujk_usia').value;
  const usia = usiaRaw==='' ? '' : parseInt(usiaRaw,10);
  if(usia!=='' && (isNaN(usia) || usia<0 || usia>120)){ toast('Usia tidak valid'); return; }
  const cols = ujkCols(kat);
  const vals = {};
  cols.forEach(c=>{ const v = document.getElementById('ujk_'+c.key).value; vals[c.key] = v==='' ? '' : parseFloat(v); });
  const avg = ujkRataRata(Object.values(vals));
  if(avg===null){ toast('Isi minimal satu nilai'); return; }
  if(!DB.uji_kategori) DB.uji_kategori = [];
  if(ujkEditId){
    const ex = DB.uji_kategori.find(x=>x.id===ujkEditId);
    if(ex){
      delete ex.nilai_import; delete ex.sumber;
      Object.assign(ex, vals, {uji_id: ex.uji_id || ujiPesertaSelId(), anggota_id:ang.id, nama:ang.nama, jabatan:ang.jabatan||'', jk, usia, rata_rata:Math.round(avg*100)/100, predikat:ujkPredikat(avg), diperbarui:nowStr()});
      persist(); ujkRefreshPesertaPanel(); toast('Data '+kat+' diperbarui');
      openUjiKategori(UJI_KATEGORI.findIndex(x=>x.label===kat));
      return;
    }
  }
  DB.uji_kategori.push(Object.assign({
    id: uid('UKT', DB.uji_kategori), kategori: kat, uji_id: ujiPesertaSelId(),
    anggota_id: ang.id, nama: ang.nama, jabatan: ang.jabatan||'', jk, usia,
    rata_rata: Math.round(avg*100)/100, predikat: ujkPredikat(avg), dibuat: nowStr(),
  }, vals));
  persist(); ujkRefreshPesertaPanel();
  toast('Data '+kat+' disimpan');
  openUjiKategori(UJI_KATEGORI.findIndex(x=>x.label===kat));
}
function deleteUjiKategori(id){ if(guardRO('uji_periodik')) return;
  const r = (DB.uji_kategori||[]).find(x=>x.id===id); if(!r) return;
  if(!confirm('Hapus data '+r.kategori+' '+(r.nama||id)+'?')) return;
  DB.uji_kategori = DB.uji_kategori.filter(x=>x.id!==id);
  persist(); ujkRefreshPesertaPanel();
  document.getElementById('ujk_list').innerHTML = ujkRowsHtml(r.kategori);
  toast('Data dihapus');
}
/* ---- Panel "Data Peserta Uji Periodik": otomatis dari 4 card kategori ---- */

// Nilai Akhir = Teknis SAR 25% + PU 10% + Kebugaran Jasmani 40% + Renang 25%

const UJI_BOBOT = [

  {kat:'Teknis SAR',              head:'Teknis SAR',        w:0.25},

  {kat:'Pengetahuan Umum',        head:'PU',                w:0.10},

  {kat:'Kebugaran Jasmani',       head:'Kebugaran Jasmani', w:0.40},

  {kat:'Renang & Water Treading', head:'Renang',            w:0.25},

];

// Uji Periodik yang sedang dipilih (dropdown Data Peserta & form kategori)
let ujiPesertaSelectedId = '';
function ujiPesertaSelId(){
  const rows = DB.uji_periodik || [];
  if(!rows.length) return '';
  if(!ujiPesertaSelectedId || !rows.some(r=>r.id===ujiPesertaSelectedId)) ujiPesertaSelectedId = rows[rows.length-1].id;
  return ujiPesertaSelectedId;
}
// Data nilai lama (sebelum ada dropdown) belum punya uji_id -> masukkan ke uji yang sedang terpilih
function ujiMigrasiNilaiLama(){
  const id = ujiPesertaSelId(); if(!id) return;
  let changed = false;
  (DB.uji_kategori||[]).forEach(r=>{ if(!r.uji_id){ r.uji_id = id; changed = true; } });
  if(changed) persist();
}
function ujiOptionsHtml(){
  const rows = DB.uji_periodik || [];
  const sel = ujiPesertaSelId();
  return rows.map(r=>`<option value="${esc(r.id)}" ${r.id===sel?'selected':''}>${esc(r.nama_uji||r.id)}</option>`).join('');
}
function onUjiPesertaSelectChange(id){
  ujiPesertaSelectedId = id;
  const a = document.getElementById('ujk_peserta_select'); if(a && a.value!==id) a.value = id;
  const b = document.getElementById('ujk_uji_select');     if(b && b.value!==id) b.value = id;
  ujkRefreshPesertaPanel();
  const kat = document.getElementById('ujk_kat');
  const list = document.getElementById('ujk_list');
  if(kat && list) list.innerHTML = ujkRowsHtml(kat.value);
}

function ujiPesertaRekap(ujiId){
  const selId = ujiId || ujiPesertaSelId();
  const all = (DB.uji_kategori || []).filter(r=>r.uji_id===selId);
  const map = {};
  all.forEach(r=>{
    const id = r.anggota_id || r.nama; if(!id) return;
    if(!map[id]) map[id] = {id, nama:r.nama, jabatan:r.jabatan, jk:'', kat:{}};
    if(r.jk) map[id].jk = r.jk;
    const cols = ujkCols(r.kategori);
    const avg = ujkAvgRow(r, cols);
    if(avg===null) return;
    (map[id].kat[r.kategori] = map[id].kat[r.kategori] || []).push(avg);
  });
  return Object.values(map).map(o=>{
    const ang = (DB.anggota||[]).find(a=>a.id===o.id);
    if(ang){ o.nama = ang.nama; o.jabatan = ang.jabatan||o.jabatan; }
    o.nilai = {};
    UJI_BOBOT.forEach(b=>{ const a = o.kat[b.kat]; o.nilai[b.kat] = a && a.length ? a.reduce((x,y)=>x+y,0)/a.length : null; });
    const lengkap = UJI_BOBOT.every(b=>o.nilai[b.kat]!==null);
    o.akhir = lengkap ? UJI_BOBOT.reduce((t,b)=>t+o.nilai[b.kat]*b.w,0) : null;
    o.predikat = ujkPredikat(o.akhir);
    return o;
  }).sort((a,b)=>String(a.nama||'').localeCompare(String(b.nama||'')));
}
/* ---- IMT: kuesioner kesiapan fisik per peserta (Ya / Tidak), disimpan di DB.uji_imt ---- */
const IMT_PERTANYAAN = [
  'Pernahkah dokter mengatakan bahwa anda menderita suatu kelainan jantung ?',
  'Apakah anda sering mengalami nyeri dada atau nyeri di jantung anda ?',
  'Seringkah anda merasa akan pingsan atau mengeluh rasa pusing kepala yang agak parah ?',
  'Pernahkan dokter memberitahukan kepada anda bahwa tekanan darah anda terlalu tinggi ?',
  'Pernahkah dokter memberitahu kepada anda bahwa anda mengidap suatu masalah persendian atau tulang?',
  'Apakah anda membawa serta obat-obat berdasarkan resep, seperti obat untuk kelainan jantung, tekanan darah tinggi, diabetes ?',
  'Apakah terdapat suatu alasan fisik yang belum disebutkan diatas bahwa anda seharusnya tidak boleh mengikuti suatu program latihan fisik?'
];
function imtKey(pesertaId){ return ujiPesertaSelId() + '|' + pesertaId; }
function imtGet(pesertaId){
  const r = (DB.uji_imt || {})[imtKey(pesertaId)];
  return (r && Array.isArray(r.jawaban)) ? r.jawaban : [];
}
function imtRec(pesertaId){ return (DB.uji_imt || {})[imtKey(pesertaId)] || {}; }
function imtHitungNilai(tb, bb){
  tb = parseFloat(String(tb).replace(',','.')); bb = parseFloat(String(bb).replace(',','.'));
  if(!(tb>0) || !(bb>0)) return null;
  const m = tb/100;
  return bb/(m*m);
}
function imtKategori(v){
  if(v===null || v===undefined || isNaN(v)) return '';
  if(v < 18.5) return 'Underweight';
  if(v < 25)   return 'Normal';
  if(v < 30)   return 'Obese Class I';
  return 'Obese Class II';
}
function imtHitung(){
  const g = id => document.getElementById(id);
  const v = imtHitungNilai(g('imt_tb').value, g('imt_bb').value);
  g('imt_imt').value = v===null ? '' : String(Math.round(v*10)/10).replace('.',',');
  g('imt_kat').value = imtKategori(v);
}
function imtLengkap(pesertaId){
  const j = imtGet(pesertaId);
  return IMT_PERTANYAAN.every((_,i)=> j[i]==='ya' || j[i]==='tidak');
}
let imtDraft = [];
let imtPesertaId = '';
function openImtDetail(pesertaId){ if(guardRO('uji_periodik')) return;
  const o = ujiPesertaRekap().find(x=>String(x.id)===String(pesertaId));
  const uji = (DB.uji_periodik||[]).find(r=>r.id===ujiPesertaSelId());
  imtPesertaId = pesertaId;
  imtDraft = IMT_PERTANYAAN.map((_,i)=> imtGet(pesertaId)[i] || '');
  const rec = imtRec(pesertaId);
  openModal(`
    <div class="p-5 max-h-[88vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-1">
        <div>
          <h3 class="font-display font-semibold text-base">IMT — ${esc(o ? o.nama : '-')}</h3>
          <p class="text-xs" style="color:var(--muted);">${esc(uji ? (uji.nama_uji||uji.id) : '')}${o && o.jabatan ? ' · '+esc(o.jabatan) : ''}</p>
        </div>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3 my-3">
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Usia</label>
          <input type="number" step="any" min="0" id="imt_usia" value="${esc(rec.usia)}" placeholder="Tahun"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tinggi Badan</label>
          <input type="number" step="any" min="0" id="imt_tb" value="${esc(rec.tb)}" placeholder="cm" oninput="imtHitung()"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Berat Badan</label>
          <input type="number" step="any" min="0" id="imt_bb" value="${esc(rec.bb)}" placeholder="kg" oninput="imtHitung()"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">IMT</label>
          <input type="text" id="imt_imt" readonly value="" placeholder="Otomatis" style="background:var(--bg);"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tekanan Darah</label>
          <input type="number" step="any" min="0" id="imt_td" value="${esc(rec.td)}" placeholder="mmHg"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Denyut Nadi</label>
          <input type="number" step="any" min="0" id="imt_nadi" value="${esc(rec.nadi)}" placeholder="x/menit"></div>
        <div class="sm:col-span-2"><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Kategori</label>
          <input type="text" id="imt_kat" readonly value="" placeholder="Otomatis setelah IMT terisi" style="background:var(--bg);"></div>
      </div>
      <div class="mb-3 text-sm" style="color:var(--muted);">Pilih salah satu jawaban (Ya / Tidak) untuk setiap pertanyaan.</div>
      <div id="imt_list">${imtListHtml()}</div>
      <div class="flex justify-end gap-2 mt-4">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-navy" onclick="saveImt()">Simpan</button>
      </div>
    </div>`, true);
  imtHitung();
}
function imtListHtml(){
  return IMT_PERTANYAAN.map((q,i)=>`
    <div class="rounded-lg p-3 mb-2" style="background:var(--bg);">
      <p class="text-sm mb-2"><span class="font-semibold">${i+1}.</span> ${esc(q)}</p>
      <div class="flex items-center gap-5 text-sm">
        <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" style="width:1rem;height:1rem;" ${imtDraft[i]==='ya'?'checked':''} onchange="imtPilih(${i},'ya',this.checked)"> Ya</label>
        <label class="inline-flex items-center gap-2 cursor-pointer"><input type="checkbox" style="width:1rem;height:1rem;" ${imtDraft[i]==='tidak'?'checked':''} onchange="imtPilih(${i},'tidak',this.checked)"> Tidak</label>
      </div>
    </div>`).join('');
}
function imtPilih(i, val, checked){
  imtDraft[i] = checked ? val : '';
  document.getElementById('imt_list').innerHTML = imtListHtml();
}
function saveImt(){ if(guardRO('uji_periodik')) return;
  if(!imtDraft.every(v=>v==='ya' || v==='tidak')){
    toast('Lengkapi semua pertanyaan (pilih Ya atau Tidak)'); return;
  }
  if(!DB.uji_imt) DB.uji_imt = {};
  const gv = id => (document.getElementById(id).value || '').trim();
  const imtVal = imtHitungNilai(gv('imt_tb'), gv('imt_bb'));
  DB.uji_imt[imtKey(imtPesertaId)] = {
    jawaban: imtDraft.slice(),
    usia: gv('imt_usia'), tb: gv('imt_tb'), bb: gv('imt_bb'),
    imt: imtVal===null ? '' : Math.round(imtVal*10)/10,
    kategori: imtKategori(imtVal),
    td: gv('imt_td'), nadi: gv('imt_nadi'),
    tanggal: (()=>{ const d=new Date(); const z=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+z(d.getMonth()+1)+'-'+z(d.getDate()); })(),
    diperbarui: nowStr()
  };
  persist();
  closeModal();
  ujkRefreshPesertaPanel();
  toast('Data IMT disimpan');
}
function ujiPesertaPanelInner(){
  if(!(DB.uji_periodik||[]).length){
    return `<p class="text-sm" style="color:var(--muted);">Belum ada data Uji Periodik. Tambahkan data Uji Periodik terlebih dahulu.</p>`;
  }
  const rows = ujiPesertaRekap();
  const headNum = UJI_BOBOT.map(b=>b.head);
  const nCol = 4 + headNum.length + 3;
  return `<div class="tablewrap"><table class="rekap-uji no-clamp">
    <thead><tr>
      <th class="text-center">No</th><th>Nama</th><th>Jabatan</th><th class="c-num">JK</th>
      ${headNum.map(h=>`<th class="c-num">${esc(h)}</th>`).join('')}
      <th class="c-num">Nilai Akhir</th><th class="c-num">Predikat</th><th class="c-num">IMT</th>
    </tr></thead>
    <tbody>${rows.length ? rows.map((o,i)=>`<tr>
      <td class="c-no">${i+1}</td>
      <td class="c-nama">${esc(o.nama)||'-'}</td>
      <td class="c-jab">${esc(o.jabatan)||'-'}</td>
      <td class="c-num">${esc(o.jk)||'-'}</td>
      ${UJI_BOBOT.map(b=>`<td class="c-num">${fmtNilai(o.nilai[b.kat])}</td>`).join('')}
      <td class="c-num c-akhir">${o.akhir===null ? '<span class="text-xs" style="color:var(--muted);font-weight:400;">Belum lengkap</span>' : fmtNilai(o.akhir)}</td>
      <td class="c-num">${ujkPredikatBadge(o.predikat)}</td>
      <td class="c-num"><input type="checkbox" style="width:1rem;height:1rem;cursor:pointer;" title="Klik untuk mengisi / melihat kuesioner IMT" ${imtLengkap(o.id)?'checked':''} onclick="event.preventDefault(); openImtDetail('${esc(o.id)}')"></td>
    </tr>`).join('') : `<tr><td colspan="${nCol}" class="text-center py-6" style="color:var(--muted);">Belum ada data peserta untuk uji periodik ini. Isi nilai lewat card Teknis SAR, Pengetahuan Umum, Kebugaran Jasmani, atau Renang &amp; Water Treading.</td></tr>`}
    </tbody></table></div>
    <p class="text-[11px] mt-2" style="color:var(--muted);">${rows.length} peserta · Nilai Akhir = Teknis SAR (25%) + PU (10%) + Kebugaran Jasmani (40%) + Renang (25%). Dihitung otomatis dan tampil setelah keempat nilai terisi.</p>`;
}
function exportUjiPeserta(fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const rows = ujiPesertaRekap();
  if(!rows.length){ toast('Belum ada data peserta untuk diunduh'); return; }
  const uji = (DB.uji_periodik||[]).find(r=>r.id===ujiPesertaSelId());
  const namaUji = uji ? (uji.nama_uji||uji.id||'') : '';
  // Semester & tahun: dari nama UDIK (mis. "UDIK S1 2026"), cadangan dari tanggal mulai
  let semester = '';
  const ms = /\bS\s*([12])\b/i.exec(namaUji);
  if(ms) semester = ms[1]==='1' ? 'I' : 'II';
  else if(uji && /^\d{4}-(\d{2})/.test(uji.tanggal_mulai||'')) semester = parseInt(uji.tanggal_mulai.slice(5,7),10)<=6 ? 'I' : 'II';
  let tahun = '';
  const my = /\b(20\d{2})\b/.exec(namaUji);
  if(my) tahun = my[1];
  else if(uji && /^\d{4}/.test(uji.tanggal_mulai||'')) tahun = uji.tanggal_mulai.slice(0,4);
  else tahun = String(new Date().getFullYear());
  const isX = fmt==='excel';
  // Urutan kolom sesuai format rekap: Teknis SAR, Pengetahuan Umum, Kemampuan Renang, Kebugaran Jasmani
  const kolom = [
    {kat:'Teknis SAR',              head:'Teknis SAR'},
    {kat:'Pengetahuan Umum',        head:'Pengetahuan Umum'},
    {kat:'Renang & Water Treading', head:'Kemampuan Renang'},
    {kat:'Kebugaran Jasmani',       head:'Kebugaran Jasmani'}
  ].map(k=>({...k, w:(UJI_BOBOT.find(b=>b.kat===k.kat)||{w:0}).w}));
  const f1 = v => (v===null||v===undefined) ? '' : (isX ? Math.round(v*10)/10 : (Math.round(v*10)/10).toFixed(1).replace('.',','));
  const body = rows.map((o,i)=>[
    i+1, o.nama||'', o.jk||'', o.jabatan||'',
    ...kolom.map(k=>f1(o.nilai[k.kat])),
    f1(o.akhir),
    o.predikat||''
  ]);
  const t1 = 'REKAPITULASI DATA';
  const t2 = 'UJI PERIODIK TENAGA SAR'+(semester?' SEMESTER '+semester:'')+' TAHUN '+tahun;
  const t3 = 'KANTOR PENCARIAN DAN PERTOLONGAN JAKARTA';
  const head1 = ['NO','Nama','Jenis Kelamin','Jabatan', ...kolom.map(k=>k.head), 'Nilai Akhir','predikat'];
  const head2 = ['','','','', ...kolom.map(k=>Math.round(k.w*100)+'%'), '',''];
  const fname = ('Rekapitulasi Data Uji Periodik'+(namaUji?' - '+namaUji:'')).trim().replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(isX){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet([[t1],[t2],[t3],[],head1,head2,...body]);
      const last = head1.length-1;
      ws['!merges'] = [0,1,2].map(r=>({s:{r,c:0}, e:{r,c:last}}))
        .concat([0,1,2,3,8,9].map(c=>({s:{r:4,c}, e:{r:5,c}})));
      ws['!cols'] = [5,34,9,40,11,14,14,14,11,12].map(w=>({wch:w}));
      for(let r=6; r<6+body.length; r++){
        [4,5,6,7,8].forEach(c=>{ const cell = ws[XLSX.utils.encode_cell({r,c})]; if(cell && typeof cell.v==='number') cell.z = '0.0'; });
      }
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Rekapitulasi');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      const pw = doc.internal.pageSize.getWidth();
      doc.setFont(undefined,'bold'); doc.setFontSize(10);
      doc.text(t1, pw/2, 40, {align:'center'});
      doc.text(t2, pw/2, 54, {align:'center'});
      doc.text(t3, pw/2, 68, {align:'center'});
      doc.setFont(undefined,'normal');
      doc.autoTable({
        head:[head1, head2], body, startY:84, theme:'grid',
        styles:{fontSize:8, cellPadding:2.5, valign:'middle', halign:'center', lineColor:[0,0,0], lineWidth:0.5, textColor:[0,0,0]},
        headStyles:{fillColor:[255,255,255], textColor:[0,0,0], fontStyle:'bold', halign:'center'},
        columnStyles:{0:{cellWidth:26}, 1:{halign:'left'}, 3:{halign:'left'}}
      });
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
function exportImt(fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const uji = (DB.uji_periodik||[]).find(r=>r.id===ujiPesertaSelId());
  const namaUji = uji ? (uji.nama_uji||uji.id||'') : '';
  const tglFmt = iso => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso||''); return m ? m[3]+'/'+m[2]+'/'+m[1] : ''; };
  let periode = '';
  const ms = /\bS\s*([12])\b/i.exec(namaUji);
  if(ms) periode = ms[1]==='1' ? 'Semester I' : 'Semester II';
  else if(uji && /^\d{4}-(\d{2})/.test(uji.tanggal_mulai||'')) periode = parseInt(uji.tanggal_mulai.slice(5,7),10)<=6 ? 'Semester I' : 'Semester II';
  const isX = fmt==='excel';
  const list = [];
  ujiPesertaRekap().forEach(o=>{
    const rec = imtRec(o.id);
    const v = imtHitungNilai(rec.tb, rec.bb);
    if(v===null) return;
    list.push([
      list.length+1,
      tglFmt(rec.tanggal) || tglFmt(uji && uji.tanggal_mulai),
      'Kantor SAR Jakarta',
      periode,
      o.nama||'',
      o.jabatan||'',
      o.jk==='L' ? 'Laki-laki' : (o.jk==='P' ? 'Perempuan' : (o.jk||'')),
      rec.usia!=='' && rec.usia!==undefined ? parseFloat(rec.usia) : '',
      isX ? Math.round(v*100)/100 : v.toFixed(2),
      imtKategori(v)
    ]);
  });
  if(!list.length){ toast('Belum ada data IMT (isi Tinggi & Berat Badan) untuk diunduh'); return; }
  const title = 'DATABASE PESERTA PENGUKURAN IMT BASARNAS';
  const head = ['No','Tanggal','Kantor SAR','Periode','Nama Peserta','Jabatan','Jenis Kelamin','Usia','Hasil IMT','Kategori'];
  const fname = ('Database_Peserta_Pengukuran_IMT'+(namaUji?' - '+namaUji:'')).trim().replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(isX){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet([[title],[],head,...list]);
      ws['!merges'] = [{s:{r:0,c:0}, e:{r:0,c:head.length-1}}];
      ws['!cols'] = [5,12,20,12,28,40,14,7,10,14].map(w=>({wch:w}));
      for(let r=3; r<3+list.length; r++){ const c = ws[XLSX.utils.encode_cell({r, c:8})]; if(c) c.z = '0.00'; }
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'IMT');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      const pw = doc.internal.pageSize.getWidth();
      doc.setFont(undefined,'bold'); doc.setFontSize(15);
      doc.text(title, pw/2, 44, {align:'center'});
      doc.setFont(undefined,'normal');
      doc.autoTable({
        head:[head], body:list, startY:66, theme:'grid',
        styles:{fontSize:8, cellPadding:4, valign:'middle', halign:'center', lineColor:[210,214,222], lineWidth:0.5, textColor:[40,40,40]},
        headStyles:{fillColor:[26,115,232], textColor:255, halign:'center', fontStyle:'bold'},
        columnStyles:{4:{halign:'left'}, 5:{halign:'left'}}
      });
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
/* ---- Unduh Berita Acara hasil pelaksanaan Uji Periodik (Word) ---- */
// Keseluruhan = jumlah anggota (Daftar Anggota) per kelompok Tenaga SAR berdasarkan jabatan.
// Hadir = anggota yang namanya sudah masuk ke tabel Data Peserta Uji Periodik; selain itu Tidak Hadir.
const BA_KATEGORI = [
  {label:'Pranata Pencarian dan Pertolongan',        re:/^pranata pencarian dan pertolongan/},
  {label:'Penata Kelola Pencarian dan Pertolongan',  re:/^penata kelola pencarian dan pertolongan/},
  {label:'Pengelola Pencarian dan Pertolongan',      re:/^pengelola pencarian dan pertolongan/},
  {label:'Perawat',                                  re:/^perawat/},
  {label:'Pengelola Layanan Kesehatan',              re:/^pengelola layanan kesehatan/},
  {label:'Awak Sarana laut',                         re:/^(nakhoda|nakhkoda|mualim|kepala kamar mesin|kkm|juru minyak|juru mudi|kelasi|markonis|mandor mesin|abk|bosun|koki)/},
  {label:'Awak Sarana Darat',                        re:/^(pengemudi|sopir|mekanik|teknisi)/},
  {label:'Operator Layanan Operasional',             re:/^operator layanan operasional/}
];
function exportBA(){
  const uji = (DB.uji_periodik||[]).find(r=>r.id===ujiPesertaSelId());
  if(!uji){ toast('Belum ada data Uji Periodik'); return; }
  const rekap = ujiPesertaRekap();
  if(!rekap.length){ toast('Belum ada peserta di tabel Data Peserta Uji Periodik'); return; }
  const namaUji = uji.nama_uji||uji.id||'';

  const BLN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  const HARI = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
  const ROM = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
  const tglIndo = iso => { const m=/^(\d{4})-(\d{2})-(\d{2})/.exec(iso||''); return m ? parseInt(m[3],10)+' '+BLN[parseInt(m[2],10)-1]+' '+m[1] : ''; };

  let semester = '';
  const ms = /\bS\s*([12])\b/i.exec(namaUji);
  if(ms) semester = ms[1]==='1' ? 'I' : 'II';
  else if(/^\d{4}-(\d{2})/.test(uji.tanggal_mulai||'')) semester = parseInt(uji.tanggal_mulai.slice(5,7),10)<=6 ? 'I' : 'II';
  let tahun = '';
  const my = /\b(20\d{2})\b/.exec(namaUji);
  if(my) tahun = my[1];
  else if(/^\d{4}/.test(uji.tanggal_mulai||'')) tahun = uji.tanggal_mulai.slice(0,4);
  else tahun = String(new Date().getFullYear());

  // Hitung jumlah peserta per kelompok Tenaga SAR
  const hadirIds = new Set(rekap.map(o=>String(o.id)));
  const katOf = j => { const t=String(j||'').trim().toLowerCase(); return BA_KATEGORI.findIndex(k=>k.re.test(t)); };
  const rows = BA_KATEGORI.map(k=>({label:k.label, total:0, hadir:0, absen:[]}));
  const lain = {label:'Lainnya', total:0, hadir:0, absen:[]};
  const anggotaIds = new Set();
  (DB.anggota||[]).forEach(a=>{
    anggotaIds.add(String(a.id));
    const i = katOf(a.jabatan);
    const hadir = hadirIds.has(String(a.id));
    if(i<0 && !hadir) return; // jabatan di luar kelompok Tenaga SAR & tidak ikut uji: bukan peserta
    const r = i>=0 ? rows[i] : lain;
    r.total++;
    if(hadir) r.hadir++; else r.absen.push(a.nama);
  });
  rekap.forEach(o=>{
    if(anggotaIds.has(String(o.id))) return;
    const i = katOf(o.jabatan); const r = i>=0 ? rows[i] : lain;
    r.total++; r.hadir++;
  });
  const list = rows.concat(lain.total ? [lain] : []);
  const totalPeserta = list.reduce((t,r)=>t+r.total,0);

  const now = new Date();
  const tglBA = now.getDate()+' '+BLN[now.getMonth()]+' '+now.getFullYear();
  const hariBA = HARI[now.getDay()];
  const tglUji = tglIndo(uji.tanggal_mulai);
  const tglUjiSelesai = tglIndo(uji.tanggal_selesai);
  const pelaksanaan = tglUji ? (tglUjiSelesai && tglUjiSelesai!==tglUji ? tglUji+' s.d. '+tglUjiSelesai : tglUji) : '..........';
  const nomor = 'B.A/....../UJI PERIODIK/'+ROM[now.getMonth()]+'/BNG.02.03/BSN-'+tahun;
  const judulSem = 'SEMESTER '+(semester||'..')+' TAHUN '+tahun;
  const teksSem = 'Semester '+(semester||'..')+' Tahun '+tahun;

  const ket = r => {
    const n = r.total - r.hadir;
    if(n<=0) return '';
    return n+' orang'+(n<=3 ? ' An.'+r.absen.map(esc).join(', ') : '');
  };
  const trs = list.map((r,i)=>{
    const tdk = r.total - r.hadir;
    return `<tr>
      <td align="center">${i+1}</td>
      <td>${esc(r.label)}</td>
      <td align="center">${r.total}</td>
      <td align="center">${r.hadir}</td>
      <td align="center">${tdk>0 ? tdk : ''}</td>
      <td>${ket(r)}</td>
    </tr>`;
  }).join('');

  const html = `<\x68tml xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<\x68ead><meta charset="utf-8"><title>Berita Acara Uji Periodik<\/title>
<style>
  @page Section1 { size:21.0cm 29.7cm; margin:2.0cm 2.2cm 2.0cm 2.2cm; }
  div.Section1 { page:Section1; }
  body { font-family:"Times New Roman",serif; font-size:12pt; }
  p { margin:0 0 8pt 0; text-align:justify; line-height:150%; }
  td, th { font-family:"Times New Roman",serif; font-size:11pt; vertical-align:middle; }
  .c { text-align:center; }
<\/style><\/head>
<\x62ody><div class="Section1">
  <p class="c" style="margin:0;"><b style="font-size:14pt;">BERITA ACARA<\/b><\/p>
  <p class="c" style="margin:0;"><b>HASIL PELAKSANAAN UJI PERIODIK TENAGA SAR<\/b><\/p>
  <p class="c" style="margin:0;"><b>${esc(judulSem)}<\/b><\/p>
  <p class="c" style="margin:0 0 4pt 0;"><b>NOMOR:${esc(nomor)}<\/b><\/p>
  <hr style="border:0; border-top:2.25pt solid #000; margin:0 0 14pt 0;">
  <p style="text-indent:1.2cm;">Pada hari ini, ${hariBA} tanggal ${tglBA} bertempat di Kantor Pencarian dan Pertolongan Jakarta telah dilaksanakan Uji Periodik Tenaga SAR ${esc(teksSem)}. Pelaksanaan dilakukan pada tanggal ${esc(pelaksanaan)} dan diikuti sebanyak ${totalPeserta} peserta, dengan detail penjelasan sebagai berikut:<\/p>
  <table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse; width:100%; margin-bottom:10pt;">
    <tr>
      <td rowspan="2" align="center" width="6%">No<\/td>
      <td rowspan="2" align="center" width="33%">Tenaga SAR<\/td>
      <td colspan="3" align="center">Jumlah Peserta<\/td>
      <td rowspan="2" align="center" width="27%">Keterangan<\/td>
    <\/tr>
    <tr>
      <td align="center" width="12%">Keseluruhan<\/td>
      <td align="center" width="9%">Hadir<\/td>
      <td align="center" width="12%">Tidak Hadir<\/td>
    <\/tr>
    ${trs}
  <\/table>
  <p style="text-indent:1.2cm;">Hasil rekapitulasi uji periodik tenaga pencarian dan pertolongan semester ${esc(semester||'..')} tahun ${esc(tahun)} dengan penjelasan terlampir.<\/p>
  <p style="text-indent:1.2cm;">Demikian Berita Acara ini dibuat dengan sebenar-benarnya, untuk digunakan sebagaimana mestinya.<\/p>
  <p style="text-align:right; margin-top:14pt;">Jakarta, ${tglBA}<\/p>
  <p class="c" style="margin:6pt 0 4pt 0;"><b>Mengetahui,<\/b><\/p>
  <table cellspacing="0" cellpadding="3" style="width:100%; border-collapse:collapse;">
    <tr>
      <td align="center" width="33%" valign="top"><b>Kasi Sumber Daya<\/b><\/td>
      <td align="center" width="34%" valign="top"><b>Kepala Kantor SAR<br>Jakarta<\/b><\/td>
      <td align="center" width="33%" valign="top"><b>Ketua Supervisi<\/b><\/td>
    <\/tr>
    <tr><td colspan="3" style="height:60pt;">&nbsp;<\/td><\/tr>
    <tr>
      <td align="center" valign="top"><b><u>Didit Permana, S.Kom<\/u><\/b><br><b>Penata Tk.I (III/d)<\/b><\/td>
      <td align="center" valign="top"><b><u>Desiana Kartika Bahari, S.E., M.H.<\/u><\/b><br><b>Pembina Tk.I(IV/b)<\/b><\/td>
      <td align="center" valign="top"><b><u>Fery Yulianto, S.S., M.Si.<\/u><\/b><br><b>Pembina Tk.I(IV/b)<\/b><\/td>
    <\/tr>
  <\/table>
<\/div><\/body><\/html>`;

  try{
    const blob = new Blob(['\ufeff'+html], {type:'application/msword'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = ('Berita_Acara_Uji_Periodik'+(namaUji?' - '+namaUji:'')).trim().replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_')+'.doc';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 2000);
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
function ujiPesertaPanelHtml(){
  ujiMigrasiNilaiLama();
  const ada = (DB.uji_periodik||[]).length > 0;
  return `<div class="panel rounded-xl p-4 mt-4">
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <span class="font-display font-semibold text-sm">Data Peserta Uji Periodik</span>
      ${ada ? `<div class="dd">
        <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
        <div class="dd-menu">
          <button type="button" class="dd-item" onclick="exportUjiPeserta('pdf')">PDF</button>
          <button type="button" class="dd-item" onclick="exportUjiPeserta('excel')">Excel</button>
        </div>
      </div>
      <div class="dd">
        <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh IMT <span aria-hidden="true">&#9662;</span></button>
        <div class="dd-menu">
          <button type="button" class="dd-item" onclick="exportImt('pdf')">PDF</button>
          <button type="button" class="dd-item" onclick="exportImt('excel')">Excel</button>
        </div>
      </div>
      <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" title="Unduh Berita Acara (Word)" onclick="exportBA()">Unduh BA</button>` : ''}
      ${ada ? `<select id="ujk_peserta_select" style="width:auto; min-width:14rem; max-width:100%; font-size:.85rem;" onchange="onUjiPesertaSelectChange(this.value)">${ujiOptionsHtml()}</select>` : ''}
    </div>
    <div id="ujk_peserta_panel">${ujiPesertaPanelInner()}</div>
  </div>`;
}
function ujkRefreshPesertaPanel(){
  const el = document.getElementById('ujk_peserta_panel');
  if(el){ el.innerHTML = ujiPesertaPanelInner(); if(typeof enhanceTableScroll==='function') enhanceTableScroll(); }
}

/* ============ Import Excel Uji Periodik (khusus Admin Utama) ============
   Format = sama dengan hasil Unduh Excel "Rekapitulasi Data": NO, Nama, Jenis Kelamin, Jabatan, Teknis SAR,
   Pengetahuan Umum, Kemampuan Renang, Kebugaran Jasmani (Nilai Akhir & Predikat dihitung ulang otomatis).
   Hasil dipratinjau dulu, lalu disimpan sebagai data Uji Periodik baru + nilai per kategori. */
const UJI_IMPORT_KAT = [
  {kat:'Teknis SAR',              alias:['TEKNISSAR','TEKNIS']},
  {kat:'Pengetahuan Umum',        alias:['PENGETAHUANUMUM','PU']},
  {kat:'Kebugaran Jasmani',       alias:['KEBUGARANJASMANI','KEBUGARAN']},
  {kat:'Renang & Water Treading', alias:['KEMAMPUANRENANG','RENANG','RENANGWATERTREADING']},
];
let _ujiImportPending = null;
function ujiImportBtnHtml(){
  return `<input type="file" id="uji_import_file" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleUjiImportFile(this)">
    <button type="button" class="btn btn-ghost ml-auto" title="Import hasil Uji Periodik dari Excel (khusus Admin Utama)" onclick="document.getElementById('uji_import_file').click()">Import Excel</button>`;
}
function handleUjiImportFile(input){
  const file = input.files && input.files[0];
  input.value = '';
  if(!file) return;
  if(!isSuper()){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const isCsv = /\.csv$/i.test(file.name);
  if(!isCsv && typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    try{
      let rows;
      if(isCsv) rows = parseCsvText(decodeCsvBuffer(reader.result));
      else {
        const wb = XLSX.read(reader.result, {type:'array'});
        let ws = null;
        for(const n of wb.SheetNames){
          const r = XLSX.utils.sheet_to_json(wb.Sheets[n], {header:1, defval:'', raw:false});
          if(r.some(x=>x.some(c=>normLabel(c)==='NAMA'))){ ws = r; break; }
        }
        rows = ws || XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], {header:1, defval:'', raw:false});
      }
      prepareUjiImport(rows, file.name);
    }catch(e){ console.error(e); toast('Gagal membaca file Excel'); }
  };
  reader.onerror = () => toast('Gagal membaca file');
  reader.readAsArrayBuffer(file);
}
function prepareUjiImport(rows, fileName){
  const hi = rows.findIndex(r=>r.some(c=>normLabel(c)==='NAMA'));
  if(hi<0){ toast('Header kolom Nama tidak ditemukan di file Excel'); return; }
  const sub = rows[hi+1] || [];
  const width = Math.max(rows[hi].length, sub.length);
  let iNama=-1, iJk=-1, iJab=-1; const katCol = {};
  for(let j=0;j<width;j++){
    [normLabel(rows[hi][j]), normLabel(sub[j])].forEach(l=>{
      if(!l) return;
      if(l==='NAMA' && iNama<0) iNama = j;
      else if(['JENISKELAMIN','JK','KELAMIN','LP'].includes(l) && iJk<0) iJk = j;
      else if(l==='JABATAN' && iJab<0) iJab = j;
      else { const k = UJI_IMPORT_KAT.find(x=>x.alias.includes(l)); if(k && !(j in katCol) && !Object.values(katCol).includes(k.kat)) katCol[j] = k.kat; }
    });
  }
  if(iNama<0){ toast('Kolom Nama tidak ditemukan'); return; }
  if(!Object.keys(katCol).length){ toast('Kolom nilai (Teknis SAR, Pengetahuan Umum, Kemampuan Renang, Kebugaran Jasmani) tidak ditemukan'); return; }
  const num = v => {
    const t = String(v==null?'':v).trim().replace('%','').replace(',','.');
    if(t==='' || t==='-') return null;
    const n = parseFloat(t);
    return (isNaN(n) || n<0 || n>100) ? NaN : n;
  };
  const out = []; let dilewati = 0, tidakValid = 0;
  for(let i=hi+1; i<rows.length; i++){
    const r = rows[i];
    const nama = String(r[iNama]||'').trim();
    if(!nama || /^[\d.,%\s]*$/.test(nama)) continue;           // baris kosong / nomor / baris persen bobot
    const o = {nama, jabatan: iJab>=0 ? String(r[iJab]||'').trim() : '', jk:'', nilai:{}};
    if(iJk>=0){ const c = String(r[iJk]||'').trim().charAt(0).toUpperCase(); o.jk = (c==='L'||c==='P') ? c : ''; }
    let ada = false;
    Object.keys(katCol).forEach(j=>{
      const v = num(r[j]);
      if(Number.isNaN(v)){ tidakValid++; o.nilai[katCol[j]] = null; }
      else { o.nilai[katCol[j]] = v; if(v!==null) ada = true; }
    });
    if(!ada){ dilewati++; continue; }
    const ang = (DB.anggota||[]).find(a=>normNama(a.nama)===normNama(nama));
    o.anggota_id = ang ? ang.id : '';
    if(ang && !o.jabatan) o.jabatan = ang.jabatan||'';
    const lengkap = UJI_BOBOT.every(b=>o.nilai[b.kat]!==null && o.nilai[b.kat]!==undefined);
    o.akhir = lengkap ? UJI_BOBOT.reduce((t,b)=>t+o.nilai[b.kat]*b.w,0) : null;
    out.push(o);
  }
  if(!out.length){ toast('Tidak ada data peserta bernilai di file Excel'); return; }
  // Nama uji bawaan dari judul file: "... SEMESTER I TAHUN 2026" -> "UDIK S1 2026"
  const judul = rows.slice(0,hi).map(r=>r.join(' ')).join(' ');
  const ms = /SEMESTER\s+(II|I|1|2)\b/i.exec(judul), my = /\b(20\d{2})\b/.exec(judul);
  const namaDef = (ms && my) ? 'UDIK S'+(/^(II|2)$/i.test(ms[1]) ? 2 : 1)+' '+my[1] : String(fileName||'').replace(/\.[^.]+$/,'');
  _ujiImportPending = out;
  const katCols = UJI_BOBOT.map(b=>b);
  openModal(`
    <div class="p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-2">
        <h3 class="font-display font-semibold text-base">Hasil Import Excel — Uji Periodik</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="_ujiImportPending=null; closeModal()">✕</button>
      </div>
      <p class="text-sm mb-1">Ditemukan <b>${out.length}</b> peserta bernilai${dilewati ? ` · ${dilewati} baris tanpa nilai dilewati` : ''}${tidakValid ? ` · ${tidakValid} nilai tidak valid (di luar 0–100) dikosongkan` : ''}.</p>
      <p class="text-xs mb-3" style="color:var(--muted);">Periksa tabel di bawah. Nilai Akhir &amp; Predikat dihitung ulang otomatis dari bobot (Teknis SAR 25% + PU 10% + Kebugaran 40% + Renang 25%).</p>
      <div class="grid sm:grid-cols-2 gap-3 mb-3">
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Nama Uji Periodik</label><input type="text" id="ujiImp_nama" value="${esc(namaDef)}"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tanggal Mulai</label><input type="date" id="ujiImp_tgl" value="${esc(todayISO())}"></div>
      </div>
      <div class="tablewrap" style="max-height:320px; overflow-y:auto;"><table>
        <thead><tr><th>No</th><th>Nama</th><th class="text-center">JK</th><th>Jabatan</th>${katCols.map(b=>`<th>${esc(b.head)}</th>`).join('')}<th>Nilai Akhir</th><th>Predikat</th><th>Anggota</th></tr></thead>
        <tbody>${out.map((o,i)=>`<tr>
          <td>${i+1}</td><td class="font-medium">${esc(o.nama)}</td><td class="text-center">${esc(o.jk)||'-'}</td><td>${esc(o.jabatan)||'-'}</td>
          ${katCols.map(b=>`<td>${fmtNilai(o.nilai[b.kat])}</td>`).join('')}
          <td class="font-semibold">${o.akhir===null ? '<span class="text-xs" style="color:var(--muted);font-weight:400;">Belum lengkap</span>' : fmtNilai(o.akhir)}</td>
          <td>${ujkPredikatBadge(ujkPredikat(o.akhir))}</td>
          <td>${o.anggota_id ? '<span class="badge b-ok">Terdaftar</span>' : '<span class="badge b-warn">Belum terdaftar</span>'}</td>
        </tr>`).join('')}</tbody></table></div>
      <div class="flex justify-end gap-2 mt-4">
        <button class="btn btn-ghost" onclick="_ujiImportPending=null; closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="applyUjiImport()">Simpan ${out.length} Peserta</button>
      </div>
    </div>`, true);
}
function applyUjiImport(){
  if(!isSuper()){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const list = _ujiImportPending; if(!list) return;
  const nama = (document.getElementById('ujiImp_nama').value||'').trim();
  const tgl = document.getElementById('ujiImp_tgl').value || todayISO();
  if(!nama){ toast('Nama Uji Periodik wajib diisi'); return; }
  if((DB.uji_periodik||[]).some(r=>normNama(r.nama_uji)===normNama(nama))){ toast('Nama Uji Periodik sudah ada, gunakan nama lain'); return; }
  if(!DB.uji_periodik) DB.uji_periodik = [];
  if(!DB.uji_kategori) DB.uji_kategori = [];
  const ujiId = uid('UPR', DB.uji_periodik);
  DB.uji_periodik.push({id:ujiId, nama_uji:nama, jenis_uji:'Uji Periodik', tanggal_mulai:tgl, tanggal_selesai:tgl,
    jumlah_peserta:list.length, status:'Selesai', sumber:'Import Excel', dibuat:nowStr()});
  list.forEach(o=>{
    UJI_BOBOT.forEach(b=>{
      const v = o.nilai[b.kat]; if(v===null || v===undefined) return;
      DB.uji_kategori.push({id:uid('UKT', DB.uji_kategori), kategori:b.kat, uji_id:ujiId,
        anggota_id:o.anggota_id||'', nama:o.nama, jabatan:o.jabatan||'', jk:o.jk||'',
        nilai_import:v, rata_rata:Math.round(v*100)/100, predikat:ujkPredikat(v), sumber:'Import Excel', dibuat:nowStr()});
    });
  });
  persist();
  _ujiImportPending = null;
  ujiPesertaSelectedId = ujiId;
  closeModal();
  render(currentView);
  toast('Berhasil mengimpor '+list.length+' peserta ke '+nama);
  setTimeout(()=>{ const el = document.getElementById('ujk_peserta_panel'); if(el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 60);
}

/* ============ Import Excel umum (khusus Admin / Admin Utama) ============
   Modul: Kegiatan/Tugas, Pembinaan Tenaga SAR (tab aktif), Potensi, Narasumber, Peralatan, Kendaraan,
   Peminjaman, Pengajuan (tab aktif). Header kolom dicocokkan dengan label/nama kolom modul, file
   dipratinjau dulu (semua baris ditampilkan), baru disimpan sebagai data baru. */
let _impExcel = null;
function canImportExcel(key){ return isSuper(); } // semua modul: khusus Admin Utama
const IMP_KEGIATAN_FIELDS = [
  {key:'nama', label:'Nama Kegiatan', type:'text', required:true, alias:['NAMA','NAMAKEGIATAN','NAMATUGAS','KEGIATAN','NAMAKEGIATANTUGAS']},
  {key:'jenis', label:'Jenis', type:'text', alias:['JENIS','JENISKEGIATAN']},
  {key:'tanggal', label:'Tanggal', type:'date', alias:['TANGGAL','TANGGALMULAI','TGLMULAI','TANGGALKEGIATAN']},
  {key:'tanggal_selesai', label:'Tanggal Selesai', type:'date', alias:['TANGGALSELESAI','TGLSELESAI','SAMPAI','SAMPAITANGGAL']},
  {key:'waktu', label:'Waktu', type:'time', alias:['WAKTU','JAM']},
  {key:'lokasi', label:'Lokasi', type:'text', alias:['LOKASI','TEMPAT','TEMPATKEGIATAN','LOKASIKEGIATAN']},
  {key:'penanggung_jawab', label:'Penanggung Jawab', type:'text', alias:['PENANGGUNGJAWAB','PJ']},
  {key:'no_hp', label:'No. HP', type:'text', alias:['NOHP','NOMORHP','HP','TELEPON','NOTELEPON']},
  {key:'deskripsi', label:'Deskripsi', type:'text', alias:['DESKRIPSI','KETERANGAN','KET','URAIAN']},
  {key:'jumlah_dibutuhkan', label:'Anggota Dibutuhkan', type:'number', hideImp:true, alias:['JUMLAHDIBUTUHKAN','ANGGOTADIBUTUHKAN','JUMLAHANGGOTA','JUMLAH','JUMLAHPESERTA','PESERTA']},
  {key:'dokumentasi', label:'Link Eviden', type:'text', alias:['LINKEVIDEN','EVIDEN','LINKDOKUMENTASI','DOKUMENTASI']},
];
const IMP_SURAT_FIELDS = [
  {key:'nomor_surat', label:'Nomor Surat', type:'text', required:true, alias:['NOMORSURAT','NOSURAT','NOMOR','NO']},
  {key:'asal_surat', label:'Asal Surat', type:'text', alias:['ASALSURAT','PENGIRIM','DARI','ASAL']},
  {key:'tanggal_surat', label:'Tanggal Surat', type:'date', alias:['TANGGALSURAT','TGLSURAT','TANGGALNASKAH','TGLNASKAH']},
  {key:'tanggal_diterima', label:'Tanggal Diterima', type:'date', alias:['TANGGALDITERIMA','TGLDITERIMA','TANGGALMASUK','TGLMASUK']},
  {key:'perihal', label:'Perihal', type:'text', alias:['PERIHAL','HAL']},
  {key:'jenis', label:'Jenis', type:'text', alias:['JENIS','JENISKEGIATAN','SUMBERCLASS']},
  {key:'tanggal_kegiatan', label:'Tanggal Kegiatan', type:'date', alias:['TANGGALKEGIATAN','TGLKEGIATAN','TANGGALPELAKSANAAN']},
  {key:'waktu', label:'Waktu', type:'time', alias:['WAKTU','JAM']},
  {key:'lokasi', label:'Lokasi', type:'text', alias:['LOKASI','TEMPAT']},
  {key:'penanggung_jawab', label:'Penanggung Jawab', type:'text', alias:['PENANGGUNGJAWAB','PJ']},
  {key:'no_hp', label:'No. HP', type:'text', alias:['NOHP','NOMORHP','HP','TELEPON','NOTELEPON']},
  {key:'jumlah_dibutuhkan', label:'Anggota Dibutuhkan', type:'number', alias:['JUMLAHDIBUTUHKAN','ANGGOTADIBUTUHKAN','JUMLAHANGGOTA','JUMLAH']},
  {key:'deskripsi', label:'Isi Ringkas', type:'text', alias:['ISIRINGKAS','DESKRIPSI','KETERANGAN','KET','URAIAN']},
];
function impBtnHtml(key, cls){
  if(!canImportExcel(key)) return '';
  return `<input type="file" id="imp_excel_file" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleImpExcelFile(this,'${key}')">
    <button type="button" class="btn btn-ghost ${cls||''}" title="Import data dari Excel (khusus Admin Utama)" onclick="document.getElementById('imp_excel_file').click()">Import Excel</button>`;
}
function impKonteks(key){
  if(key==='pembinaan') return {label:'Pembinaan Tenaga SAR — '+pembinaanTab, tab:pembinaanTab};
  if(key==='pemeliharaan') return {label:'Pengajuan '+pengajuanTab, tab:pengajuanTab};
  if(key==='kegiatan') return {label:'Kegiatan/Tugas', tab:''};
  if(key==='surat_masuk') return {label:'Surat Masuk', tab:''};
  if(key==='anggota') return {label:'Daftar Anggota', tab:''};
  const m = TABLE_MODULES.find(x=>x.key===key);
  return {label:m ? m.label : key, tab:''};
}
function impFieldDefs(key){
  if(key==='kegiatan') return IMP_KEGIATAN_FIELDS;
  if(key==='surat_masuk') return IMP_SURAT_FIELDS;
  if(key==='anggota'){
    const extra = {nip:['NIP','NIPID','NRP','NOMORINDUK','NIPNRP','NOMORINDUKPEGAWAI'], whatsapp:['WHATSAPP','WA','NOWA','NOHP','NOMORHP','HP','NOMORWHATSAPP','NOTELEPON','NOMORWA','NOWHATSAPP'],
      nama:['NAMA','NAMALENGKAP','NAMAPEGAWAI','NAMAANGGOTA'], status:['STATUS','STATUSANGGOTA'], keterangan:['KET','KETERANGAN'],
      jabatan:['JABATANUNITORGANISASI','JABATANDANUNIT','JABATANUNIT','JABATANUNITKERJA','NAMAJABATAN'], unit:['UNITORGANISASI','UNITKERJA','SATUANKERJA']};
    return ANGGOTA_FIELDS.map(f=>{
      const al = [f.label, f.key].concat(extra[f.key]||[]).map(normLabel).filter(Boolean);
      // WhatsApp tidak wajib saat import (banyak data anggota belum punya nomor); hanya Nama yang wajib
      return Object.assign({}, f, {required: f.key==='nama', alias:Array.from(new Set(al))});
    });
  }
  const m = TABLE_MODULES.find(x=>x.key===key); if(!m) return [];
  const tab = impKonteks(key).tab;
  const defs = m.fields.filter(f=>{
    if(['computed','hidden'].includes(f.type) || f.noForm) return false;
    if(key==='pembinaan') return pbnKatOk(f, tab);
    if(key==='pemeliharaan') return !f.kat || [].concat(f.kat).includes(tab);
    return true;
  }).map(f=>{
    const al = [f.label, f.key, f.labelK].concat(Object.values(f.labels||{})).map(normLabel).filter(Boolean);
    if(f.key==='keterangan') al.push('KET','KETERANGAN');
    return Object.assign({}, f, {alias:Array.from(new Set(al))});
  });
  if(key==='kendaraan') defs.unshift({key:'sapras', label:'Sapras', type:'text', alias:['SAPRAS','KATEGORISAPRAS']});
  return defs;
}
// ---- Pembersih khusus import Daftar Anggota (file kepegawaian) ----
function impBersihNip(v){
  // buang tanda ` / ' pembuka (trik Excel agar NIP tidak jadi angka), spasi & karakter tak terlihat
  let t = String(v==null?'':v).replace(/[\u00A0\u200B-\u200D\uFEFF]/g,' ').trim().replace(/^[`'’‘´]+/,'').replace(/\s+/g,'');
  if(/^\d{15,18}$/.test(t)) return t;   // NIP/NRP angka
  return /^\d+$/.test(t) ? t : '';       // "Magang"/"-" dan teks non-angka bukan NIP -> kosong (dedup pakai nama)
}
function impBersihWa(v){
  let t = String(v==null?'':v).replace(/\.0+$/,'').replace(/\D/g,'');
  if(!t) return '';
  if(t.startsWith('62')) t = '0'+t.slice(2);       // 62812.. -> 0812..
  else if(!t.startsWith('0')) t = '0'+t;            // 812.. (nol hilang dari Excel) -> 0812..
  return t.length>=9 && t.length<=15 ? t : '';
}
function impPisahJabatanUnit(txt){
  const t = String(txt==null?'':txt).replace(/\s+/g,' ').trim();
  if(!t) return {jabatan:'', unit:''};
  const re = /(?:^|[\s,])((?:Seksi|Subbagian|Sub Bagian|Bagian|Bidang|Kantor)\s.*)$/g;
  let m;
  while((m = re.exec(t))){
    const start = m.index + m[0].length - m[1].length;
    const before = t.slice(0, start).replace(/[\s,]+$/,'');
    if(!before || /(^|\s)Kepala$/i.test(before)){ re.lastIndex = m.index + 1; continue; } // "Kepala Seksi ..." = bagian jabatan
    return {jabatan:before, unit:t.slice(start).trim()};
  }
  return {jabatan:t, unit:''};
}
function impTgl(v){
  const s = String(v==null?'':v).trim(); if(!s) return '';
  let m = /^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/.exec(s);
  let y,mo,d;
  if(m){ y=+m[1]; mo=+m[2]; d=+m[3]; }
  else if((m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/.exec(s))){ d=+m[1]; mo=+m[2]; y=+m[3]; if(y<100) y+=2000; }
  else if((m = /^(\d{1,2})\s+([A-Za-z]+)\.?\s+(\d{4})/.exec(s))){
    const i = BULAN_NAMA.findIndex(b=>b.toLowerCase().slice(0,3)===m[2].toLowerCase().slice(0,3));
    if(i<0) return null; d=+m[1]; mo=i+1; y=+m[3];
  } else return null;
  if(mo<1||mo>12||d<1||d>31) return null;
  return y+'-'+String(mo).padStart(2,'0')+'-'+String(d).padStart(2,'0');
}
function impNum(v){
  let t = String(v==null?'':v).trim().replace(/^rp\.?\s*/i,'').replace(/\s/g,''); if(!t) return '';
  if(/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(t)) t = t.replace(/\./g,'').replace(',','.');
  else t = t.replace(',','.');
  return /^-?\d+(\.\d+)?$/.test(t) ? t : null;
}

/* ---- Helper khusus Import Kegiatan/Tugas: tanggal tanpa tahun / rentang, jenis, jumlah peserta ---- */
let _impTahun = new Date().getFullYear();
function impTglRentang(raw){
  const s = String(raw==null?'':raw).replace(/ /g,' ').trim(); if(!s) return {a:'', b:'', bad:false};
  const iso = (y,mo,d)=> (mo>=1&&mo<=12&&d>=1&&d<=31) ? y+'-'+String(mo).padStart(2,'0')+'-'+String(d).padStart(2,'0') : '';
  const bln = t => { const k = String(t||'').toLowerCase().replace(/\./g,''); return BLN_IMPOR[k] || (BULAN_NAMA.findIndex(b=>b.toLowerCase().slice(0,3)===k.slice(0,3))+1) || 0; };
  let m;
  // "21 s.d 23 Januari [2026]" / "13-14 Agustus" / "25 - 26 April"
  if((m = /^(\d{1,2})\s*(?:-|–|s\.?\s*d\.?|sd|sampai|hingga|dan|&)\s*(\d{1,2})\s+([A-Za-z]+)\.?(?:\s+(\d{4}))?/i.exec(s))){
    const mo = bln(m[3]), y = m[4] ? +m[4] : _impTahun;
    if(mo){ const a = iso(y,mo,+m[1]), b = iso(y,mo,+m[2]); if(a) return {a, b: b||a, bad:false}; }
  }
  // "30 Januari s.d 2 Februari [2026]"
  if((m = /^(\d{1,2})\s+([A-Za-z]+)\.?\s*(?:-|–|s\.?\s*d\.?|sd|sampai|hingga)\s*(\d{1,2})\s+([A-Za-z]+)\.?(?:\s+(\d{4}))?/i.exec(s))){
    const m1 = bln(m[2]), m2 = bln(m[4]), y = m[5] ? +m[5] : _impTahun;
    if(m1 && m2){ const a = iso(y,m1,+m[1]), b = iso(y,m2,+m[3]); if(a) return {a, b:b||a, bad:false}; }
  }
  // "11 Januari" / "11 Januari 2026" / "Sabtu, 11 Januari 2026"
  if((m = /(\d{1,2})\s+([A-Za-z]+)\.?(?:\s+(\d{4}))?/.exec(s))){
    const mo = bln(m[2]);
    if(mo){ const a = iso(m[3] ? +m[3] : _impTahun, mo, +m[1]); if(a) return {a, b:a, bad:false}; }
  }
  const t = impTgl(s);   // ISO / dd-mm-yyyy
  if(t) return {a:t, b:t, bad:false};
  if(/^\d{5}(\.\d+)?$/.test(s)){ // serial Excel
    const d = new Date(Math.round((parseFloat(s)-25569)*86400000));
    const v = d.getUTCFullYear()+'-'+String(d.getUTCMonth()+1).padStart(2,'0')+'-'+String(d.getUTCDate()).padStart(2,'0');
    return {a:v, b:v, bad:false};
  }
  return {a:'', b:'', bad:true};
}
function impJumlahPeserta(raw){
  const m = /\d[\d.]*/.exec(String(raw==null?'':raw).replace(/ /g,' '));
  return m ? String(parseInt(m[0].replace(/\./g,''),10)||'') : '';
}
// Jenis dari Excel -> kunci modul. WORKSHOP SAR COMMUNITY masuk modul Narasumber.
function impJenisKegiatan(txt){
  const n = normLabel(txt);
  const byKey = GENERIC_MODULES.find(m=>normLabel(m.key)===n || normLabel(m.label)===n);
  if(byKey) return byKey;
  const pick = k => GENERIC_MODULES.find(m=>m.key===k);
  if(n.includes('WORKSHOP') || n.includes('NARASUMBER') || n.includes('SARCOMMUNITY')) return pick('narasumber');
  if(n.includes('SCHOOL') || n.includes('SEKOLAH') || n.includes('CAMPUS') || n.includes('KAMPUS') || n.includes('SGT')) return pick('sgts');
  if(n.includes('BIMTEK')) return pick('bimtek');
  if(n.includes('DIKLAT') || n.includes('PELATIHAN')) return pick('diklat');
  if(n.includes('SAKA') || n.includes('POTENSI')) return pick('potensi');
  if(n.includes('PEMBERDAYAAN')) return pick('pemberdayaan');
  if(n.includes('UJI')) return pick('uji_periodik');
  return pick('lainnya');
}
function impVal(f, raw){
  const s = String(raw==null?'':raw).trim();
  if(!s) return {v:''};
  if(f.type==='date'){ const t = impTgl(s); return t===null ? {v:'', bad:1} : {v:t}; }
  if(f.type==='number'){ const t = impNum(s); return t===null ? {v:'', bad:1} : {v:t}; }
  if(f.type==='time'){ const m = /^(\d{1,2})[:.](\d{2})/.exec(s); return {v: m ? String(m[1]).padStart(2,'0')+':'+m[2] : s}; }
  if(f.type==='select' && f.options){
    const o = f.options.find(x=>normLabel(x)===normLabel(s));
    if(o) return {v:o};
    return f.isStatus ? {v:f.options[0], bad:1} : {v:s};
  }
  return {v:s};
}
function handleImpExcelFile(input, key){
  const file = input.files && input.files[0];
  input.value = '';
  if(!file) return;
  if(!canImportExcel(key)){ toast('Import Excel hanya untuk Admin Utama'); return; }
  if(guardRO(key)) return;
  if(key==='surat_masuk'){ impSuratBaca(file); return; }
  const isCsv = /\.csv$/i.test(file.name);
  if(!isCsv && typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    try{
      let sheets = [];
      if(isCsv) sheets = [parseCsvText(decodeCsvBuffer(reader.result))];
      else {
        const wb = XLSX.read(reader.result, {type:'array'});
        sheets = wb.SheetNames.map(n=>XLSX.utils.sheet_to_json(wb.Sheets[n], {header:1, defval:'', raw:false, dateNF:'yyyy-mm-dd'}));
      }
      if(key==='peralatan' && prepareImpPeralatan(sheets, file.name)) return;
      if(key==='kendaraan' && prepareImpKendaraan(sheets, file.name)) return;
      prepareImpExcel(sheets, key, file.name);
    }catch(e){ console.error(e); toast('Gagal membaca file Excel'); }
  };
  reader.onerror = () => toast('Gagal membaca file');
  reader.readAsArrayBuffer(file);
}
/* ---- Parser khusus Import Excel Peralatan (format laporan: bagian Darat / Perairan / Udara) ----
   - Bagian "Peralatan ... Darat" -> Palsar Darat, "... Perairan" -> Palsar Air, "... Udara" -> Palsar Udara.
   - Nama barang yang sama di LOKASI yang sama (dan kategori sama) digabung menjadi 1 baris, jumlahnya dijumlahkan.
   - Nama barang yang sama tetapi LOKASI berbeda tetap diinput sebagai baris terpisah.
   - Kolom bergeser antar bagian / baris: posisi kolom dibaca dari baris header tiap bagian. */
function impPrlKategori(txt){
  const m = /peralatan\s+(?:pencarian\s+dan\s+pertolongan\s+)?(darat|perairan|air|laut|udara)\b/i.exec(txt||'');
  if(!m) return null;
  const k = m[1].toLowerCase();
  return k==='darat' ? 'Palsar Darat' : (k==='udara' ? 'Palsar Udara' : 'Palsar Air');
}
function impPrlLokasi(v){
  const s = normLabel(v);
  if(!s) return '';
  if(s.includes('BOGOR')) return 'USS Bogor';
  if(s.includes('BEKASI')) return 'USS Bekasi';
  if(s.includes('SUKABUMI')) return 'Pos Sukabumi';
  if(s.includes('JAKARTA') || s.includes('KPP')) return 'KPP Jakarta';
  return '';
}
function impPrlNum(v){
  const s = String(v==null?'':v).replace(/\u00a0/g,' ').trim().replace(',', '.');
  if(!/^-?\d+(\.\d+)?$/.test(s)) return null;
  return parseFloat(s);
}
function impPrlTeks(v){ return String(v==null?'':v).replace(/[\u00a0\s]+/g,' ').trim(); }
function impPeralatanParse(sheets){
  const hasil = new Map(); // kategori|nama -> record
  let baris = 0, kosong = 0, tidakAdaHeader = true;
  sheets.forEach(rows=>{
    let kat = null, H = null, grpLok = '';
    rows.forEach((r, ri)=>{
      const cells = r.map(impPrlTeks);
      const gabung = cells.filter(Boolean).join(' ');
      if(!gabung) return;
      // judul bagian
      const kt = cells[0] && !/^NO$/i.test(cells[0]) ? impPrlKategori(cells[0]) : null;
      if(kt){ kat = kt; H = null; grpLok = ''; return; }
      // baris header tabel
      if(/^NO$/i.test(cells[0]) && cells.some(c=>normLabel(c)==='NAMABARANG')){
        H = {nama:-1, lokasi:-1, jumlah:-1, ket:-1, b:-1, rr:-1, rb:-1};
        cells.forEach((c,j)=>{
          const l = normLabel(c);
          if(l==='NAMABARANG') H.nama = j;
          else if(l==='LOKASI') H.lokasi = j;
          else if(l==='JUMLAH') H.jumlah = j;
          else if(l==='KET' || l==='KETERANGAN') H.ket = j;
          else if(l==='KONDISI'){ H.b = j; H.rr = j+1; H.rb = j+2; }
        });
        const nx = (rows[ri+1]||[]).map(impPrlTeks); // sub-header B / RR / RB
        nx.forEach((c,j)=>{ const l = normLabel(c); if(l==='B') H.b = j; else if(l==='RR') H.rr = j; else if(l==='RB') H.rb = j; });
        tidakAdaHeader = false;
        return;
      }
      if(!H || !kat) return;
      const c0 = cells[0];
      // sub-judul (A., B., ... atau "   B")
      if(/^[A-Za-z]\.?$/.test(c0)){ grpLok = impPrlLokasi(cells[H.lokasi]); return; }
      // baris item harus diawali nomor urut
      if(!/^\d/.test(c0)) return;
      const nama = cells[H.nama];
      if(!nama || /^\d+$/.test(nama) || /^[….\-–\s]+$/.test(nama)) return; // baris angka penunjuk kolom / baris kosong (….)
      baris++;
      let lokasi = impPrlLokasi(cells[H.lokasi]);
      if(!lokasi){ // kolom bergeser: cari nama lokasi di sel lain pada baris yang sama
        for(let j=2;j<cells.length && !lokasi;j++) lokasi = impPrlLokasi(cells[j]);
      }
      if(!lokasi) lokasi = grpLok || 'KPP Jakarta';
      let jml = impPrlNum(cells[H.jumlah]);
      const b = impPrlNum(cells[H.b]), rr = impPrlNum(cells[H.rr]), rb = impPrlNum(cells[H.rb]);
      const rusak = (rr||0) + (rb||0);
      if(jml==null){ jml = (b!=null || rr!=null || rb!=null) ? (b||0)+rusak : 0; }
      if(!jml) kosong++;
      const baik = (b!=null) ? b : Math.max(jml - rusak, 0);
      const ket = H.ket>=0 ? cells[H.ket] : '';
      const key = kat+'|'+normNama(nama)+'|'+normNama(lokasi);
      let rec = hasil.get(key);
      if(!rec){ rec = {nama_barang:nama, kategori:kat, baik:0, rusak:0, lok:{}, lokUrut:[], ket:[], n:0}; hasil.set(key, rec); }
      rec.n++; rec.baik += baik; rec.rusak += rusak;
      if(!(lokasi in rec.lok)){ rec.lok[lokasi] = 0; rec.lokUrut.push(lokasi); }
      rec.lok[lokasi] += (baik+rusak);
      if(ket && !/^[….\-–\s]+$/.test(ket) && !rec.ket.includes(ket)) rec.ket.push(ket);
    });
  });
  if(tidakAdaHeader || !hasil.size) return null;
  const list = [];
  hasil.forEach(rec=>{
    const lokasi = rec.lokUrut[0] || 'KPP Jakarta';
    list.push({nama_barang:rec.nama_barang, kode_rak:'', lokasi, kategori:rec.kategori, jumlah_baik:rec.baik, jumlah_rusak:rec.rusak, keterangan:rec.ket.join('; '), _n:rec.n});
  });
  return {list, baris, kosong};
}

/* ---- Parser khusus Import Excel Kendaraan Darat (format laporan: bagian A. RESCUE TRUCK, B. RESCUE CAR, dst.) ----
   Kolom: NO, JENIS KENDARAAN, NO. POL, MERK/TYPE, TAHUN PEMBUATAN, KM, KONDISI (Baik/Rusak), POSISI, PEMELIHARAAN ..., KET.
   Semua data masuk sebagai Sapras Darat. Tahun pembuatan & pemeliharaan dicatat di kolom Ket. */
function impKdrLokasi(v){
  const s = normLabel(v);
  if(!s) return 'KPP Jakarta';
  if(s.includes('BOGOR')) return 'USS Bogor';
  if(s.includes('BEKASI')) return 'USS Bekasi';
  if(s.includes('SUKABUMI')) return 'Pos SAR Sukabumi';
  if(s.includes('JAKARTA') || s.includes('KPP')) return 'KPP Jakarta';
  return impPrlTeks(v);
}
function impKdrJenis(sec, nama){
  const t = normLabel(sec+' '+nama);
  if(/MOTOR|ATV|ALLTERRAIN|KAWASAKI|BEACHPATROL/.test(t)) return 'Motor';
  if(/TRUCK|TRUK/.test(t)) return 'Truk';
  return 'Mobil';
}
function impKendaraanParse(sheets){
  const list = []; let ada = false;
  sheets.forEach(rows=>{
    let H = null, sec = '';
    rows.forEach((r, ri)=>{
      const c = r.map(impPrlTeks);
      if(!c.some(Boolean)) return;
      if(/^NO\.?$/i.test(c[0]) && c.some(x=>normLabel(x)==='JENISKENDARAAN')){
        H = {};
        c.forEach((x,j)=>{
          const l = normLabel(x);
          if(l==='JENISKENDARAAN') H.nama = j;
          else if(l==='NOPOL' || l==='NOMORPOLISI') H.nopol = j;
          else if(l==='MERKTYPE' || l==='MERKTIPE') H.merk = j;
          else if(l==='TAHUNPEMBUATAN') H.tahun = j;
          else if(l==='KM') H.km = j;
          else if(l==='KONDISI') H.kond = j;
          else if(l==='POSISI') H.pos = j;
          else if(l.startsWith('PEMELIHARAANYANGSUDAH')) H.sudah = j;
          else if(l.startsWith('PEMELIHARAANYANGAKAN')) H.akan = j;
          else if(l==='KET' || l==='KETERANGAN') H.ket = j;
        });
        if(H.kond==null){ H.kond = -1; }
        ada = true; return;
      }
      if(!H) return;
      const c0 = c[0].replace(/[\s ]/g,'');
      if(/^[A-Za-z]\.?$/.test(c0)){ sec = c[H.nama]; return; }          // judul bagian (A, B, C ...)
      if(!/^\d+$/.test(c0)) return;                                       // baris item harus diawali nomor
      const nama = (c[H.nama]||'').replace(/\s+/g,' ').trim();
      if(!nama || /^\d+$/.test(nama)) return;                             // baris penunjuk nomor kolom (1,2,3...)
      const bersih = v => { const t = (v||'').trim(); return /^[….\-–\s]*$/.test(t) ? '' : t; };
      let nopol = bersih(c[H.nopol]);
      const km = H.km!=null ? impPrlNum((c[H.km]||'').replace(/\.(?=\d{3}\b)/g,'')) : null;
      const baik = H.kond>=0 ? bersih(c[H.kond]) : '', rusak = H.kond>=0 ? bersih(c[H.kond+1]) : '';
      const ket = bersih(c[H.ket]), sudah = bersih(c[H.sudah]), akan = bersih(c[H.akan]), tahun = bersih(c[H.tahun]);
      const kondisi = (rusak || /perbaikan|rusak/i.test(ket)) ? 'Unserviceable' : 'Serviceable';
      const kt = [];
      if(tahun) kt.push('Tahun pembuatan '+tahun);
      if(sudah) kt.push('Pemeliharaan dilaksanakan: '+sudah);
      if(akan) kt.push('Pemeliharaan akan datang: '+akan);
      if(ket) kt.push(ket);
      list.push({nama_kendaraan:nama, sapras:'Sapras Darat', jenis_kendaraan:impKdrJenis(sec, nama), nomor_polisi:nopol,
        merk_tipe:bersih(c[H.merk]), kondisi, lokasi:impKdrLokasi(c[H.pos]), km_saat_ini:(km==null?'':km), keterangan:kt.join('; '), _sec:sec});
    });
  });
  return ada && list.length ? list : null;
}
/* Sheet Sapras Laut (RIB/RBB): kolom KELAS, NO. LAMBUNG, TAHUN, MATERIAL, MERK, PANJANG, LOKASI PENEMPATAN, KONDISI (S / US), PEMELIHARAAN, KET */
function impKendaraanLautParse(sheets){
  const list = [];
  sheets.forEach(rows=>{
    let L = -1;
    rows.forEach(r=>{
      const c = r.map(impPrlTeks);
      if(!c.some(Boolean)) return;
      const hi = c.findIndex(x=>normLabel(x)==='NOLAMBUNG');
      if(hi>=0){ L = hi; return; }
      if(L<0) return;
      let kelas = c[L-1]||'', lamb = c[L]||'', tahun = c[L+1]||'', material = c[L+2]||'', merk = c[L+3]||'', panjang = c[L+4]||'';
      if(L===2 && /^\d{4}$/.test(c[2]) && !/^kelas/i.test(c[1]) && /[A-Za-z]/.test(c[1])){ // baris tanpa kolom Kelas: kolom awal bergeser 1
        kelas = ''; lamb = c[1]; tahun = c[2]; merk = c[3]; panjang = c[4]; material = '';
      }
      if(!/[A-Za-z]/.test(lamb) || !/^[\d\s]*$/.test(c[0]||'')) return; // lewati baris header/penunjuk kolom
      const bersih = v => { const t = (v||'').replace(/[\s ]+/g,' ').trim(); return /^[….\-–\s]*$/.test(t) ? '' : t; };
      const adaS = bersih(c[L+6]), adaUS = bersih(c[L+7]);
      const kt = [];
      if(kelas) kt.push(kelas);
      if(tahun) kt.push('Tahun pembuatan '+tahun);
      if(material) kt.push('Material '+material);
      if(panjang) kt.push('Panjang '+panjang);
      const sudah = bersih(c[L+8]), akan = bersih(c[L+9]), ket = bersih(c[L+10]);
      if(sudah) kt.push('Pemeliharaan dilaksanakan: '+sudah);
      if(akan) kt.push('Pemeliharaan akan datang: '+akan);
      if(ket) kt.push(ket);
      list.push({nama_kendaraan:lamb.trim(), sapras:'Sapras Laut', jenis_kendaraan:'RIB / Perahu Karet', nomor_polisi:lamb.trim(),
        merk_tipe:bersih(merk), kondisi:(adaUS && !adaS) ? 'Unserviceable' : 'Serviceable', lokasi:bersih(c[L+5]) || 'KPP Jakarta', keterangan:kt.join('; ')});
    });
  });
  return list;
}
function prepareImpKendaraan(sheets, fileName){
  const darat = impKendaraanParse(sheets) || [], laut = impKendaraanLautParse(sheets);
  const list = darat.concat(laut);
  if(!list.length) return false; // bukan format laporan Kendaraan Darat -> pakai import biasa (template)
  const existing = DB.kendaraan || [];
  const out = list.map(o=>{
    const dup = existing.some(e=> o.nomor_polisi
      ? normNama(e.nomor_polisi)===normNama(o.nomor_polisi)
      : (normNama(e.nama_kendaraan)===normNama(o.nama_kendaraan) && normNama(e.lokasi)===normNama(o.lokasi) && !e.nomor_polisi));
    return {o, dup, bad:0};
  });
  _impExcel = {key:'kendaraan', rows:out, cols:[], fileName};
  const baru = out.filter(x=>!x.dup).length;
  openModal(`
    <div class="p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-2">
        <h3 class="font-display font-semibold text-base">Hasil Import Excel — Kendaraan</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="_impExcel=null; closeModal()">✕</button>
      </div>
      <p class="text-sm mb-1">File <b>${esc(fileName)}</b>: ditemukan <b>${out.length}</b> kendaraan (Sapras Darat: <b>${darat.length}</b> · Sapras Laut: <b>${laut.length}</b>)${baru!==out.length ? ` · <b>${out.length-baru}</b> sudah ada di modul` : ''}.</p>
      <p class="text-xs mb-3" style="color:var(--muted);">Sheet laporan Darat masuk sebagai Sapras Darat, sheet No. Lambung (RIB/RBB) sebagai Sapras Laut. Kondisi: kolom Rusak / US terisi / Ket "perbaikan" = Unserviceable, selain itu Serviceable. Tahun pembuatan & pemeliharaan dicatat di Ket. Periksa tabel, lalu klik Simpan.</p>
      <div class="tablewrap" style="max-height:340px; overflow-y:auto;"><table>
        <thead><tr><th>No</th><th>Sapras</th><th>Nama Kendaraan</th><th>Jenis</th><th>No. Polisi / Lambung</th><th>Merk / Tipe</th><th>KM</th><th>Kondisi</th><th>Lokasi</th><th>Ket</th><th>Status</th></tr></thead>
        <tbody>${out.map((x,i)=>`<tr>
          <td>${i+1}</td><td>${esc(x.o.sapras)}</td><td>${esc(x.o.nama_kendaraan)}</td><td>${esc(x.o.jenis_kendaraan)}</td><td>${esc(x.o.nomor_polisi)||'-'}</td><td>${esc(x.o.merk_tipe)||'-'}</td>
          <td>${esc(x.o.km_saat_ini===''?'-':x.o.km_saat_ini)}</td><td>${esc(x.o.kondisi)}</td><td>${esc(x.o.lokasi)}</td>
          <td style="max-width:240px;">${esc(x.o.keterangan)||'-'}</td>
          <td>${x.dup ? '<span class="badge b-warn">Sudah ada</span>' : '<span class="badge b-ok">Baru</span>'}</td>
        </tr>`).join('')}</tbody></table></div>
      <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" id="imp_skip_dup" ${out.some(x=>x.dup)?'checked':''} style="width:auto;"> Lewati data yang sudah ada</label>
      <div class="flex flex-wrap justify-between gap-2 mt-4">
        <button class="btn btn-ghost" onclick="unduhTemplateImp('kendaraan')">Unduh Template</button>
        <div class="flex gap-2">
          <button class="btn btn-ghost" onclick="_impExcel=null; closeModal()">Batal</button>
          <button class="btn btn-primary" onclick="applyImpExcel()">Simpan Data</button>
        </div>
      </div>
    </div>`, true);
  return true;
}
function prepareImpPeralatan(sheets, fileName){
  const res = impPeralatanParse(sheets);
  if(!res) return false; // bukan format laporan Darat/Perairan/Udara -> pakai import biasa (template)
  const existing = DB.peralatan || [];
  const out = res.list.map(o=>{
    const dup = existing.some(e=>normNama(e.nama_barang)===normNama(o.nama_barang) && (e.kategori||'Palsar Darat')===o.kategori && normNama(e.lokasi)===normNama(o.lokasi));
    return {o, dup, bad:0};
  });
  _impExcel = {key:'peralatan', rows:out, cols:[], fileName};
  const baru = out.filter(x=>!x.dup).length;
  const per = k => out.filter(x=>x.o.kategori===k).length;
  openModal(`
    <div class="p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-2">
        <h3 class="font-display font-semibold text-base">Hasil Import Excel — Peralatan</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="_impExcel=null; closeModal()">✕</button>
      </div>
      <p class="text-sm mb-1">File <b>${esc(fileName)}</b>: ${res.baris} baris barang dibaca → menjadi <b>${out.length}</b> data (nama barang + lokasi)${baru!==out.length ? ` · <b>${out.length-baru}</b> sudah ada di modul` : ''}.</p>
      <p class="text-xs mb-1" style="color:var(--muted);">Palsar Darat: <b>${per('Palsar Darat')}</b> · Palsar Air: <b>${per('Palsar Air')}</b> · Palsar Udara: <b>${per('Palsar Udara')}</b>. Nama barang yang sama di lokasi yang sama digabung dan jumlahnya dijumlahkan${res.kosong ? ` · ${res.kosong} baris tanpa jumlah di Excel dihitung 0` : ''}.</p>
      <p class="text-xs mb-3" style="color:var(--muted);">Nama barang yang sama tetapi lokasinya berbeda dimasukkan sebagai baris terpisah per lokasi. Periksa tabel di bawah, lalu klik Simpan.</p>
      <div class="tablewrap" style="max-height:340px; overflow-y:auto;"><table>
        <thead><tr><th>No</th><th>Nama Barang</th><th>Kategori</th><th>Lokasi</th><th>Baik</th><th>Rusak</th><th>Jumlah</th><th>Ket</th><th>Status</th></tr></thead>
        <tbody>${out.map((x,i)=>`<tr>
          <td>${i+1}</td><td>${esc(x.o.nama_barang)}</td><td>${esc(x.o.kategori)}</td><td>${esc(x.o.lokasi)}</td>
          <td>${x.o.jumlah_baik}</td><td>${x.o.jumlah_rusak}</td><td>${x.o.jumlah_baik + x.o.jumlah_rusak}</td>
          <td style="max-width:240px;">${esc(x.o.keterangan)||'-'}</td>
          <td>${x.dup ? '<span class="badge b-warn">Sudah ada</span>' : '<span class="badge b-ok">Baru</span>'}</td>
        </tr>`).join('')}</tbody></table></div>
      <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" id="imp_skip_dup" ${out.some(x=>x.dup)?'checked':''} style="width:auto;"> Lewati data yang sudah ada</label>
      <div class="flex flex-wrap justify-between gap-2 mt-4">
        <button class="btn btn-ghost" onclick="unduhTemplateImp('peralatan')">Unduh Template</button>
        <div class="flex gap-2">
          <button class="btn btn-ghost" onclick="_impExcel=null; closeModal()">Batal</button>
          <button class="btn btn-primary" onclick="applyImpExcel()">Simpan Data</button>
        </div>
      </div>
    </div>`, true);
  return true;
}
function prepareImpExcel(sheets, key, fileName){
  const defs = impFieldDefs(key), kon = impKonteks(key);
  const req = defs.filter(f=>f.required);
  // pilih sheet & baris header dengan kecocokan kolom terbanyak
  let best = null;
  sheets.forEach(rows=>{
    rows.slice(0,20).forEach((r,hi)=>{
      const map = {}, used = new Set();
      r.forEach((c,j)=>{
        const l = normLabel(c); if(!l) return;
        const f = defs.find(d=>!used.has(d.key) && d.alias.includes(l));
        if(f){ map[j] = f; used.add(f.key); }
      });
      const n = Object.keys(map).length;
      if(n && req.every(f=>used.has(f.key)) && (!best || n>best.n)) best = {rows, hi, map, n, header:r};
    });
  });
  if(!best){ toast('Header kolom tidak cocok. Pastikan ada kolom: ' + req.map(f=>f.label).join(', ') + ' (gunakan "Unduh Template")'); return; }
  const {rows, hi, map, header} = best;
  if(key==='kegiatan'){ // tahun default dari judul sheet (mis. "TAHUN 2026")
    _impTahun = new Date().getFullYear();
    for(let i=0;i<hi;i++){ const mm = /\b(20\d{2})\b/.exec(rows[i].join(' ')); if(mm){ _impTahun = +mm[1]; break; } }
  }
  const cols = Object.keys(map).map(Number).sort((a,b)=>a-b);
  const diabaikan = header.map((c,j)=>[c,j]).filter(([c,j])=>String(c||'').trim() && !(j in map) && !((key==='kegiatan' || key==='anggota') && ['NO','NOMOR','TRUE','FALSE'].includes(normLabel(c)))).map(([c])=>String(c).trim());
  const existing = key==='kegiatan' ? (DB.kegiatan||[]) : (DB[key]||[]);
  const nameF = req[0], tglF = defs.find(f=>f.key==='tanggal');
  const out = []; let kosong = 0, tanpaNama = 0, nilaiBad = 0;
  for(let i=hi+1; i<rows.length; i++){
    const r = rows[i];
    if(!r.some(c=>String(c==null?'':c).trim())) { kosong++; continue; }
    const o = {}; let bad = 0;
    cols.forEach(j=>{
      const f = map[j];
      if(key==='kegiatan' && f.key==='tanggal'){ const t = impTglRentang(r[j]); o.tanggal = t.a; if(t.b && t.b!==t.a && !o.tanggal_selesai) o.tanggal_selesai = t.b; if(t.bad) bad++; return; }
      if(key==='kegiatan' && f.key==='tanggal_selesai'){ const t = impTglRentang(r[j]); if(t.a) o.tanggal_selesai = t.a; else if(t.bad) bad++; return; }
      if(key==='kegiatan' && f.key==='jumlah_dibutuhkan'){ o.jumlah_dibutuhkan = impJumlahPeserta(r[j]); return; }
      if(key==='kegiatan' && f.key==='dokumentasi'){ const v = String(r[j]==null?'':r[j]).trim(); o.dokumentasi = /^(https?:\/\/|www\.)/i.test(v) ? v : ''; return; }
      const x = impVal(f, r[j]); o[f.key] = x.v; if(x.bad) bad++;
    });
    if(key==='anggota'){
      o.nama = String(o.nama||'').replace(/\s+/g,' ').trim();
      o.nip = impBersihNip(o.nip);
      o.whatsapp = impBersihWa(o.whatsapp);
      if(o.jabatan && !o.unit){ const pj = impPisahJabatanUnit(o.jabatan); o.jabatan = pj.jabatan; o.unit = pj.unit; }
      if(!o.status) o.status = 'Aktif';
    }
    if(!String(o[nameF.key]||'').trim()){ tanpaNama++; continue; }
    nilaiBad += bad;
    const dup = existing.some(e => {
      if(key==='anggota'){
        const nipB = String(o.nip||'').replace(/\s/g,'');
        return nipB ? String(e.nip||'').replace(/\s/g,'')===nipB : normNama(e.nama)===normNama(o.nama);
      }
      const en = key==='kegiatan' ? e.nama : e[nameF.key];
      return normNama(en)===normNama(o[nameF.key]) && (!tglF || String(e.tanggal||'')===String(o.tanggal||''))
        && (key!=='pembinaan' || pbnKat(e)===kon.tab) && (key!=='pemeliharaan' || pgjKat(e)===kon.tab)
        && (key!=='peralatan' || normNama(e.lokasi||'KPP Jakarta')===normNama(o.lokasi||'KPP Jakarta'))
        && (key!=='kendaraan' || normNama(e.nomor_polisi)===normNama(o.nomor_polisi))
        && (key!=='peminjaman' || normNama(e.nama_barang)===normNama(o.nama_barang));
    });
    out.push({o, dup, bad});
  }
  if(!out.length){ toast('Tidak ada data yang dapat diimpor dari file Excel'); return; }
  _impExcel = {key, rows:out, cols:cols.map(j=>map[j]), fileName};
  const allShow = _impExcel.cols.filter(f=>!f.hideImp), show = allShow.slice(0, 8);
  const baru = out.filter(x=>!x.dup).length;
  openModal(`
    <div class="p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-2">
        <h3 class="font-display font-semibold text-base">Hasil Import Excel — ${esc(kon.label)}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="_impExcel=null; closeModal()">✕</button>
      </div>
      <p class="text-sm mb-1">File <b>${esc(fileName)}</b>: ditemukan <b>${out.length}</b> baris data${baru!==out.length ? ` · <b>${out.length-baru}</b> sudah ada di modul` : ''}${tanpaNama ? ` · ${tanpaNama} baris tanpa ${esc(nameF.label)} dilewati` : ''}${nilaiBad ? ` · ${nilaiBad} nilai tidak valid dikosongkan` : ''}.</p>
      ${diabaikan.length ? `<p class="text-xs mb-1" style="color:var(--muted);">Kolom tidak dikenali (diabaikan): ${esc(diabaikan.join(', '))}</p>` : ''}
      <p class="text-xs mb-3" style="color:var(--muted);">Periksa tabel di bawah${allShow.length>show.length ? ` (menampilkan ${show.length} dari ${allShow.length} kolom, semuanya tetap disimpan)` : ''}, lalu klik Simpan. Data masuk sebagai data baru.</p>
      <div class="tablewrap" style="max-height:340px; overflow-y:auto;"><table>
        <thead><tr><th>No</th>${show.map(f=>`<th>${esc(f.label)}</th>`).join('')}<th>Keterangan</th></tr></thead>
        <tbody>${out.map((x,i)=>`<tr>
          <td>${i+1}</td>${show.map(f=>`<td>${esc(f.type==='date' ? tglDMY(x.o[f.key]) : x.o[f.key])||'-'}</td>`).join('')}
          <td>${x.dup ? '<span class="badge b-warn">Sudah ada</span>' : '<span class="badge b-ok">Baru</span>'}${x.bad ? ' <span class="badge b-warn">Nilai tidak valid</span>' : ''}</td>
        </tr>`).join('')}</tbody></table></div>
      <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" id="imp_skip_dup" ${out.some(x=>x.dup)?'checked':''} style="width:auto;"> Lewati data yang sudah ada</label>
      <div class="flex flex-wrap justify-between gap-2 mt-4">
        <button class="btn btn-ghost" onclick="unduhTemplateImp('${key}')">Unduh Template</button>
        <div class="flex gap-2">
          <button class="btn btn-ghost" onclick="_impExcel=null; closeModal()">Batal</button>
          <button class="btn btn-primary" onclick="applyImpExcel()">Simpan Data</button>
        </div>
      </div>
    </div>`, true);
}
function unduhTemplateImp(key){
  if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const defs = impFieldDefs(key).filter(f=>(f.key!=='sapras' || key==='kendaraan') && !f.hideImp);
  const ws = XLSX.utils.aoa_to_sheet([defs.map(f=>f.label)]);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Template');
  XLSX.writeFile(wb, 'Template_Import_'+impKonteks(key).label.replace(/[^A-Za-z0-9]+/g,'_')+'.xlsx');
}
function applyImpExcel(){
  const P = _impExcel; if(!P) return;
  if(!canImportExcel(P.key)){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const key = P.key, kon = impKonteks(key);
  const skipDup = !!(document.getElementById('imp_skip_dup') && document.getElementById('imp_skip_dup').checked);
  const list = P.rows.filter(x=>!(skipDup && x.dup));
  if(!list.length){ toast('Tidak ada data baru untuk disimpan'); return; }
  const now = new Date();
  let ok = 0, stok = 0;
  list.forEach(x=>{
    const d = Object.assign({}, x.o);
    if(key==='surat_masuk'){
      if(!DB.surat_masuk) DB.surat_masuk = [];
      const jk = normLabel(d.jenis);
      const gm = GENERIC_MODULES.find(m=>normLabel(m.key)===jk || normLabel(m.label)===jk) || GENERIC_MODULES.find(m=>m.key==='lainnya') || GENERIC_MODULES[GENERIC_MODULES.length-1];
      DB.surat_masuk.push({
        id: uid('SM', DB.surat_masuk),
        nomor_surat: d.nomor_surat, asal_surat: d.asal_surat||'',
        tanggal_surat: d.tanggal_surat||'', tanggal_diterima: d.tanggal_diterima || d.tanggal_surat || todayISO(),
        perihal: d.perihal || '-', jenis: gm.key, jenis_label: gm.label,
        tanggal_kegiatan: d.tanggal_kegiatan||'', waktu: d.waktu||'', lokasi: d.lokasi||'',
        penanggung_jawab: d.penanggung_jawab||'', no_hp: d.no_hp||'',
        jumlah_dibutuhkan: parseInt(d.jumlah_dibutuhkan)||1, deskripsi: d.deskripsi||'',
        file_pdf: null, status: 'Selesai', acc: [], tanpa_acc: true, keterangan_persetujuan: 'Data hasil Import Excel — tidak memerlukan persetujuan atasan.', kegiatan_id: '',
        diajukan_oleh: pelaku(), sumber: 'Import Excel', dibuat: nowStr(), diperbarui: nowStr(),
      });
      ok++; return;
    }
    if(key==='kegiatan'){
      DB.kegiatan = DB.kegiatan || [];
      const gm = impJenisKegiatan(d.jenis);
      const tgl = d.tanggal || todayISO();
      const row = {
        id: uid('KGT', DB.kegiatan),
        nomor: (DB.kegiatan.length+1).toString().padStart(3,'0') + '/KGT/' + now.getFullYear(),
        jenis: gm.key, jenis_label: gm.label,
        nama: d.nama, tanggal: tgl, tanggal_selesai: (d.tanggal_selesai && d.tanggal_selesai>=tgl) ? d.tanggal_selesai : tgl, waktu: d.waktu||'',
        lokasi: d.lokasi||'', penanggung_jawab: d.penanggung_jawab||'', no_hp: d.no_hp||'',
        deskripsi: d.deskripsi||'', jumlah_dibutuhkan: parseInt(d.jumlah_dibutuhkan)||1,
        status_persetujuan:'Disetujui', keterangan_persetujuan:'Data hasil Import Excel — tidak memerlukan persetujuan atasan.', tanpa_acc:true, anggota_ids:[],
        diajukan_oleh: pelaku(),
        status_pelaksanaan:'Selesai', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:d.dokumentasi||'',
        file_pdf: null, sumber:'Import Excel',
        dibuat: nowStr(), diperbarui: nowStr(),
      };
      DB.kegiatan.push(row);
      const linkedId = autoFillModuleFromKegiatan(gm.key, row);
      if(linkedId){
        row.linked_record_id = linkedId;
        const rec = (DB[gm.key]||[]).find(r=>r.id===linkedId);
        if(rec){
          if(gm.key==='narasumber') rec.kegiatan_terkait = row.nama;
          if(d.dokumentasi && gm.fields.some(f=>f.key==='dokumentasi')) rec.dokumentasi = d.dokumentasi;
          if(d.jumlah_dibutuhkan && gm.fields.some(f=>f.key==='jumlah_peserta')) rec.jumlah_peserta = parseInt(d.jumlah_dibutuhkan)||'';
        }
        syncModuleStatusFromKegiatan(row); // status modul ikut Selesai
      }
      ok++; return;
    }
    const m = key==='anggota' ? {prefix:'AG', fields:ANGGOTA_FIELDS} : TABLE_MODULES.find(z=>z.key===key);
    const rows = DB[key] || (DB[key]=[]);
    if(key==='pembinaan') d.kategori_pembinaan = kon.tab;
    if(key==='kendaraan'){
      const s = normLabel(d.sapras);
      d.sapras = s.includes('LAUT') ? 'Sapras Laut' : (s.includes('DARAT') ? 'Sapras Darat'
        : (saprasFilter || Object.keys(SAPRAS_JENIS).find(k=>SAPRAS_JENIS[k].includes(d.jenis_kendaraan)) || 'Sapras Darat'));
    }
    if(key==='peminjaman'){
      if(!d.jumlah) d.jumlah = 1;
      if(d.tanggal_kembali) d.status = 'Dikembalikan';
      else if(!d.status || d.status==='Dikembalikan') d.status = 'Dipinjam';
      if(!d.jenis_barang) d.jenis_barang = 'Peralatan';
      if(d.jenis_barang==='Peralatan' && d.status!=='Dikembalikan'){
        const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(d.nama_barang));
        if(alat){
          const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang) - peralatanDipelihara(alat.nama_barang);
          if((parseFloat(d.jumlah)||1) > sisa){ stok++; return; }
        }
      }
    }
    if(key==='pemeliharaan'){
      d.kategori_pengajuan = kon.tab;
      if(!d.jenis_barang) d.jenis_barang = 'Peralatan';
      if(kon.tab==='Kebutuhan'){ if(!d.jumlah_kebutuhan) d.jumlah_kebutuhan = 1; }
      else {
        if(d.tanggal_selesai) d.status = 'Selesai';
        else if(!d.status) d.status = 'Dijadwalkan';
        if(d.jenis_barang!=='Kendaraan') d.km_saat_ini = '';
        if(d.jenis_barang==='Kendaraan') d.jumlah = '';
        else {
          if(!d.jumlah) d.jumlah = 1;
          if(d.status==='Sedang Dikerjakan'){
            const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(d.nama_barang));
            if(alat){
              const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang) - peralatanDipelihara(alat.nama_barang);
              if((parseFloat(d.jumlah)||1) > sisa){ stok++; return; }
            }
          }
        }
      }
      d.status_persetujuan = 'Menunggu Persetujuan'; d.diajukan_oleh = pelaku(); d.diajukan_waktu = nowStr();
    }
    m.fields.filter(f=>f.isStatus && !d[f.key] && f.options && !(key==='pemeliharaan' && kon.tab==='Kebutuhan')).forEach(f=>{ d[f.key] = f.options[0]; });
    d.id = uid(m.prefix, rows);
    d.tahun_input = now.getFullYear(); d.bulan_input = now.getMonth()+1;
    d.tanggal_input = now.toLocaleDateString('sv-SE');
    d.diperbarui_oleh = pelaku(); d.diperbarui_waktu = nowStr(); d.sumber = 'Import Excel';
    rows.push(d);
    if(key==='pemeliharaan') syncKendaraanDariPemeliharaan(d);
    if(key==='pembinaan' && ABS_SUMBER_AUTO.includes(kon.tab)) pbnAbsSync(DB, d);
    ok++;
  });
  if(!ok){ toast(stok ? 'Tidak ada data tersimpan: stok peralatan tidak mencukupi' : 'Tidak ada data tersimpan'); return; }
  persist();
  _impExcel = null;
  closeModal();
  if(key==='kegiatan') kegTab = 'kegiatan';
  if(key==='surat_masuk') kegTab = 'surat';
  render(currentView);
  toast('Berhasil mengimpor '+ok+' data ke '+kon.label + (stok ? ' ('+stok+' dilewati: stok tidak cukup)' : ''));
}

/* ============ Import Excel Persuratan: Surat Masuk + Surat Keluar (khusus Admin Utama) ============
   Dipakai dari Kegiatan/Tugas (tab Surat Masuk) dan Persuratan (tab Surat Masuk / Surat Keluar).
   - Membaca SEMUA sheet & SEMUA baris/kolom file (sheet "Surat Masuk" -> Surat Masuk, sheet "Surat Keluar" -> Surat Keluar).
   - Kolom yang tidak dikenali / tanpa judul tetap disimpan (tidak ada data yang dibuang).
   - Hanya menambah data baru: data yang sudah terinput tidak diubah/dihapus; baris yang sudah ada dilewati.
   - Data hasil import tidak memerlukan persetujuan atasan (tanpa ACC, tidak membuat Kegiatan otomatis). */
let _impSurat = null;
const IMP_KET_TANPA_ACC = 'Data hasil Import Excel — tidak memerlukan persetujuan atasan.';
const BLN_IMPOR = {januari:1,jan:1,februari:2,feb:2,pebruari:2,maret:3,mar:3,april:4,apr:4,mei:5,juni:6,jun:6,juli:7,jul:7,
  agustus:8,agu:8,agt:8,ags:8,september:9,sep:9,sept:9,oktober:10,okt:10,november:11,nov:11,nopember:11,desember:12,des:12};
function impBersih(v){ return String(v==null?'':v).replace(/[\u00a0\u200b]/g,' ').replace(/\s+/g,' ').trim(); }
function impBuangLabel(v, labels){
  const re = new RegExp('^(?:' + labels.join('|') + ')\\s*:\\s*', 'i');
  return impBersih(v).replace(re, '').trim();
}
function impHurufKolom(j){ let n = j+1, t = ''; while(n>0){ const r = (n-1)%26; t = String.fromCharCode(65+r) + t; n = Math.floor((n-1)/26); } return t; }
/* "Rabu, 7 Januari 2026 pukul 14.42 WIB" -> {iso:'2026-01-07', jam:'14:42'} */
function impTglIndo(v){
  const s = impBersih(v); if(!s) return {iso:'', jam:'', bad:false};
  let iso = '';
  const m = /(\d{1,2})\s+([A-Za-z]+)\.?\s+(\d{4})/.exec(s);
  if(m){ const mo = BLN_IMPOR[m[2].toLowerCase()]; if(mo) iso = m[3] + '-' + String(mo).padStart(2,'0') + '-' + String(+m[1]).padStart(2,'0'); }
  if(!iso){ const t = impTgl(s); if(t) iso = t; }
  const j = /pukul\s*(\d{1,2})[.:](\d{2})/i.exec(s) || /\b(\d{1,2}):(\d{2})(?::\d{2})?\b/.exec(s);
  return {iso, jam: j ? String(j[1]).padStart(2,'0') + ':' + j[2] : '', bad: !iso};
}
function impSuratBtnHtml(cls){
  if(!canImportExcel('surat_masuk')) return '';
  return `<input type="file" id="imp_surat_file" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleImpSuratFile(this)">
    <button type="button" class="btn btn-ghost ${cls||''}" title="Import Surat Masuk & Surat Keluar dari Excel (khusus Admin Utama)" onclick="document.getElementById('imp_surat_file').click()">Import Excel</button>`;
}
function handleImpSuratFile(input){
  const file = input.files && input.files[0];
  input.value = '';
  if(file) impSuratBaca(file);
}
function impSuratBaca(file){
  if(!canImportExcel('surat_masuk')){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const isCsv = /\.csv$/i.test(file.name);
  if(!isCsv && typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    try{
      let sheets = [];
      if(isCsv) sheets = [{name: file.name, rows: parseCsvText(decodeCsvBuffer(reader.result))}];
      else {
        const wb = XLSX.read(reader.result, {type:'array'});
        sheets = wb.SheetNames.map(n=>({name:n, rows: XLSX.utils.sheet_to_json(wb.Sheets[n], {header:1, defval:'', raw:false, dateNF:'yyyy-mm-dd'})}));
      }
      impSuratSiapkan(sheets, file.name);
    }catch(e){ console.error(e); toast('Gagal membaca file Excel'); }
  };
  reader.onerror = () => toast('Gagal membaca file');
  reader.readAsArrayBuffer(file);
}
const IMP_SM_ALIAS = {
  no:['NO','NOURUT','NOMORURUT'],
  tgl_masuk:['TANGGALMASUK','TGLMASUK','TANGGALDITERIMA','TGLDITERIMA','TANGGALDISPOSISI','TGLDISPOSISI'],
  tgl_naskah:['TANGGALNASKAH','TGLNASKAH','TANGGALSURAT','TGLSURAT'],
  dari:['DARI','ASALSURAT','ASAL','PENGIRIM'],
  batas:['BATASWAKTU','BATAS'],
  nomor:['NOMORSURAT','NOMORNASKAH','NOSURAT','NONASKAH'],
  hal:['HAL','PERIHAL'],
  status:['STATUS','STATUSSURAT'],
  instruksi:['TAMBAHANINSTRUKSIKKS','INSTRUKSIKKS','INSTRUKSI','TAMBAHANINSTRUKSI'],
};
const IMP_SK_ALIAS = {
  no:['NO','NOURUT','NOMORURUT'],
  tgl:['TANGGAL','TGL','TANGGALSURAT','TGLSURAT'],
  nomor:['NOMORSURAT','NOSURAT','NOMOR'],
  uraian:['URAIANKEGIATAN','URAIAN','PERIHAL','HAL'],
  tujuan:['TUJUAN','KEPADA'],
  status:['STATUS','STATUSKIRIM','KETERANGAN'],
};
function impPetaKolom(rows, alias, wajib){
  let best = null;
  rows.slice(0, 25).forEach((r, hi)=>{
    const map = {}, used = new Set();
    r.forEach((c, j)=>{
      const l = normLabel(c); if(!l) return;
      const k = Object.keys(alias).find(x=>!used.has(x) && alias[x].includes(l));
      if(k){ map[k] = j; used.add(k); }
    });
    const n = Object.keys(map).length;
    if(wajib.every(k=>k in map) && (!best || n>best.n)) best = {hi, map, n, header:r};
  });
  return best;
}
/* Kolom di luar peta (judul tidak dikenal atau tanpa judul) yang berisi data tetap diambil. */
function impKolomLain(rows, best){
  const dipakai = new Set(Object.values(best.map));
  const out = [];
  const lebar = Math.max(best.header.length, ...rows.slice(best.hi+1).map(r=>r.length), 0);
  for(let j=0; j<lebar; j++){
    if(dipakai.has(j)) continue;
    const ada = rows.slice(best.hi+1).some(r=>impBersih(r[j]));
    if(!ada) continue;
    const judul = impBersih(best.header[j]);
    out.push({j, key:'kolom_' + impHurufKolom(j).toLowerCase(), label: judul || ('Kolom ' + impHurufKolom(j) + ' (tanpa judul)')});
  }
  return out;
}
function impSuratSiapkan(sheets, fileName){
  const masuk = [], keluar = [], info = [];
  let kosong = 0, tanpaIsi = 0, nilaiBad = 0;
  const kolomLabel = {};
  const sudahMasuk = new Set((DB.surat_masuk||[]).map(e=>impKunciMasuk(e.nomor_surat, e.tanggal_diterima, e.jam_diterima, e.perihal)));
  const sudahKeluar = new Set((DB.surat_keluar||[]).map(e=>impKunciKeluar(e.nomor_surat, e.perihal)));
  sheets.forEach(sh=>{
    const rows = sh.rows || [];
    const nm = String(sh.name||'').toLowerCase();
    let tipe = /keluar/.test(nm) ? 'keluar' : (/masuk/.test(nm) ? 'masuk' : '');
    let best = null;
    if(tipe!=='keluar'){ const b = impPetaKolom(rows, IMP_SM_ALIAS, ['nomor','hal']); if(b && (tipe==='masuk' || ('dari' in b.map) || ('tgl_naskah' in b.map))){ best = b; tipe = 'masuk'; } }
    if(!best && tipe!=='masuk'){ const b = impPetaKolom(rows, IMP_SK_ALIAS, ['nomor','uraian']); if(b){ best = b; tipe = 'keluar'; } }
    if(!best){ info.push('Sheet "' + sh.name + '" dilewati (kolom Nomor Surat / Hal tidak ditemukan)'); return; }
    const c = best.map, lain = impKolomLain(rows, best);
    const g = (r, k) => (k in c) ? r[c[k]] : '';
    const dataRows = rows.slice(best.hi + 1);
    let jml = 0;
    dataRows.forEach(r=>{
      if(!r.some(x=>impBersih(x))){ kosong++; return; }
      if(tipe==='masuk'){
        const dis = impTglIndo(impBuangLabel(g(r,'tgl_masuk'), ['Tanggal disposisi','Tanggal masuk','Tanggal diterima']));
        const nas = impTglIndo(impBuangLabel(g(r,'tgl_naskah'), ['Tanggal naskah','Tanggal surat']));
        const bat = impTglIndo(impBuangLabel(g(r,'batas'), ['Batas waktu','Batas']));
        const o = {
          no: impBersih(g(r,'no')),
          tanggal_diterima: dis.iso, jam_diterima: dis.jam, disposisi_teks: impBuangLabel(g(r,'tgl_masuk'), ['Tanggal disposisi','Tanggal masuk','Tanggal diterima']),
          tanggal_surat: nas.iso, naskah_teks: impBuangLabel(g(r,'tgl_naskah'), ['Tanggal naskah','Tanggal surat']),
          batas_waktu: bat.iso, batas_jam: (bat.jam && bat.jam!=='00:00') ? bat.jam : '', batas_teks: impBuangLabel(g(r,'batas'), ['Batas waktu','Batas']),
          asal_surat: impBuangLabel(g(r,'dari'), ['Dari']),
          nomor_surat: impBuangLabel(g(r,'nomor'), ['Nomor naskah','Nomor surat']),
          perihal: impBuangLabel(g(r,'hal'), ['Hal','Perihal']),
          status_surat: impBersih(g(r,'status')), instruksi_kks: impBersih(g(r,'instruksi')),
          lain: {}
        };
        if(!o.nomor_surat && !o.perihal){ tanpaIsi++; return; }
        lain.forEach(k=>{ const v = impBersih(r[k.j]); if(v){ o.lain[k.key] = v; kolomLabel[k.key] = k.label; } });
        const bad = (dis.bad?1:0) + (nas.bad?1:0) + (bat.bad?1:0);
        nilaiBad += bad;
        const kunci = impKunciMasuk(o.nomor_surat, o.tanggal_diterima, o.jam_diterima, o.perihal);
        const dup = sudahMasuk.has(kunci); // hanya dibandingkan dengan data yang sudah ada di aplikasi (baris berulang di dalam file tetap diimpor semua)
        masuk.push({o, dup, bad}); jml++;
      } else {
        const tg = impTglIndo(g(r,'tgl'));
        const o = {
          no: impBersih(g(r,'no')), tanggal_surat: tg.iso, tanggal_teks: impBersih(g(r,'tgl')),
          nomor_surat: impBersih(g(r,'nomor')), perihal: impBersih(g(r,'uraian')), tujuan: impBersih(g(r,'tujuan')),
          kirim: '', lain: {}
        };
        if(!o.nomor_surat && !o.perihal){ tanpaIsi++; return; }
        if('status' in c) o.kirim = impBersih(g(r,'status'));
        lain.forEach(k=>{ const v = impBersih(r[k.j]); if(v){ o.lain[k.key] = v; kolomLabel[k.key] = k.label; } });
        nilaiBad += tg.bad ? 1 : 0;
        const kunci = impKunciKeluar(o.nomor_surat, o.perihal);
        const dup = sudahKeluar.has(kunci);
        keluar.push({o, dup, bad: tg.bad ? 1 : 0}); jml++;
      }
    });
    // Surat Keluar: kolom tanpa judul yang isinya status pengiriman (TERKIRIM/BELUM) dibaca sebagai Status Kirim
    if(tipe==='keluar' && !('status' in c)){
      const baru = keluar.slice(keluar.length - jml);
      const kunci = lain.find(k=>{ const vs = baru.map(x=>x.o.lain[k.key]).filter(Boolean); return vs.length && vs.every(v=>/^(TERKIRIM|DIKIRIM|BELUM|BELUM TERKIRIM|SUDAH|SELESAI)$/i.test(v)); });
      if(kunci){ baru.forEach(x=>{ x.o.kirim = x.o.lain[kunci.key] || ''; delete x.o.lain[kunci.key]; }); delete kolomLabel[kunci.key]; }
    }
    info.push('Sheet "' + sh.name + '" dibaca sebagai Surat ' + (tipe==='masuk' ? 'Masuk' : 'Keluar') + ': ' + jml + ' baris');
  });
  if(!masuk.length && !keluar.length){ toast('Tidak ada data surat yang dapat diimpor. Pastikan ada kolom Nomor Surat dan Hal/Uraian (gunakan "Unduh Template")'); return; }
  _impSurat = {masuk, keluar, fileName, kolomLabel};
  impSuratPratinjau(info, {kosong, tanpaIsi, nilaiBad});
}
function impKunciMasuk(nomor, tgl, jam, perihal){ return normLabel(nomor) + '|' + String(tgl||'') + '|' + String(jam||'') + '|' + normLabel(perihal); }
function impKunciKeluar(nomor, perihal){ return normLabel(nomor) + '|' + normLabel(perihal); }
function impSuratPratinjau(info, st){
  const P = _impSurat;
  const bm = P.masuk.filter(x=>!x.dup).length, bk = P.keluar.filter(x=>!x.dup).length;
  const ada = P.masuk.length - bm + P.keluar.length - bk;
  const kolLain = Object.keys(P.kolomLabel);
  const badge = x => (x.dup ? '<span class="badge b-warn">Sudah ada</span>' : '<span class="badge b-ok">Baru</span>') + (x.bad ? ' <span class="badge b-warn">Tanggal tidak terbaca</span>' : '');
  const tglJam = (t, j) => (tglDMY(t) || '-') + (j ? ' ' + j : '');
  const tblMasuk = P.masuk.length ? `
    <p class="font-medium text-sm mt-3 mb-1">Surat Masuk — ${P.masuk.length} baris (${bm} baru)</p>
    <div class="tablewrap" style="max-height:300px; overflow-y:auto;"><table>
      <thead><tr><th>No</th><th>Tgl Diterima</th><th>Tgl Surat</th><th>Asal Surat</th><th>Batas</th><th>Nomor Surat</th><th>Perihal</th><th>Status</th><th>Instruksi KKS</th>${kolLain.filter(k=>P.masuk.some(x=>k in x.o.lain)).map(k=>`<th>${esc(P.kolomLabel[k])}</th>`).join('')}<th>Keterangan</th></tr></thead>
      <tbody>${P.masuk.map((x,i)=>`<tr>
        <td>${esc(x.o.no)||i+1}</td><td class="whitespace-nowrap">${esc(tglJam(x.o.tanggal_diterima, x.o.jam_diterima))}</td><td class="whitespace-nowrap">${esc(tglDMY(x.o.tanggal_surat))||'-'}</td>
        <td style="min-width:180px;">${esc(x.o.asal_surat)||'-'}</td><td class="whitespace-nowrap">${esc(tglJam(x.o.batas_waktu, x.o.batas_jam))}</td>
        <td style="min-width:150px;">${esc(x.o.nomor_surat)||'-'}</td><td style="min-width:220px;">${esc(x.o.perihal)||'-'}</td>
        <td>${esc(x.o.status_surat)||'-'}</td><td>${esc(x.o.instruksi_kks)||'-'}</td>
        ${kolLain.filter(k=>P.masuk.some(y=>k in y.o.lain)).map(k=>`<td>${esc(x.o.lain[k])||'-'}</td>`).join('')}
        <td>${badge(x)}</td></tr>`).join('')}</tbody></table></div>` : '';
  const tblKeluar = P.keluar.length ? `
    <p class="font-medium text-sm mt-4 mb-1">Surat Keluar — ${P.keluar.length} baris (${bk} baru)</p>
    <div class="tablewrap" style="max-height:300px; overflow-y:auto;"><table>
      <thead><tr><th>No</th><th>Tanggal</th><th>Nomor Surat</th><th>Uraian Kegiatan</th><th>Status Kirim</th>${kolLain.filter(k=>P.keluar.some(x=>k in x.o.lain)).map(k=>`<th>${esc(P.kolomLabel[k])}</th>`).join('')}<th>Keterangan</th></tr></thead>
      <tbody>${P.keluar.map((x,i)=>`<tr>
        <td>${esc(x.o.no)||i+1}</td><td class="whitespace-nowrap">${esc(tglDMY(x.o.tanggal_surat))||'-'}</td><td style="min-width:170px;">${esc(x.o.nomor_surat)||'-'}</td>
        <td style="min-width:260px;">${esc(x.o.perihal)||'-'}</td><td>${esc(x.o.kirim)||'-'}</td>
        ${kolLain.filter(k=>P.keluar.some(y=>k in y.o.lain)).map(k=>`<td>${esc(x.o.lain[k])||'-'}</td>`).join('')}
        <td>${badge(x)}</td></tr>`).join('')}</tbody></table></div>` : '';
  openModal(`
    <div class="p-5 max-h-[92vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-2">
        <h3 class="font-display font-semibold text-base">Hasil Import Excel — Persuratan</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="_impSurat=null; closeModal()">✕</button>
      </div>
      <p class="text-sm mb-1">File <b>${esc(P.fileName)}</b>: <b>${P.masuk.length}</b> surat masuk dan <b>${P.keluar.length}</b> surat keluar terbaca${ada ? ` · <b>${ada}</b> sudah ada di aplikasi` : ''}${st.tanpaIsi ? ` · ${st.tanpaIsi} baris tanpa nomor & perihal dilewati` : ''}${st.nilaiBad ? ` · ${st.nilaiBad} tanggal tidak terbaca dikosongkan` : ''}.</p>
      ${info.length ? `<p class="text-xs mb-1" style="color:var(--muted);">${info.map(esc).join(' · ')}</p>` : ''}
      <p class="text-xs mb-1" style="color:var(--muted);">Data yang sudah terinput tidak diubah atau dihapus — import hanya menambah data baru. Data hasil import <b>tidak memerlukan persetujuan atasan</b>.</p>
      ${tblMasuk}${tblKeluar}
      <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" id="imp_surat_skip" checked style="width:auto;"> Lewati data yang sudah ada (cegah data ganda)</label>
      <div class="flex flex-wrap justify-between gap-2 mt-4">
        <button class="btn btn-ghost" onclick="unduhTemplateSurat()">Unduh Template</button>
        <div class="flex gap-2">
          <button class="btn btn-ghost" onclick="_impSurat=null; closeModal()">Batal</button>
          <button class="btn btn-primary" onclick="applyImpSurat()">Simpan Data</button>
        </div>
      </div>
    </div>`, true);
}
function unduhTemplateSurat(){
  if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['No.','Tanggal Masuk','Tanggal Naskah','Dari','Batas Waktu','Nomor Surat','Hal','Status','Tambahan Instruksi KKS']]), 'Surat Masuk');
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['No.','Tanggal','Nomor Surat','Uraian Kegiatan','Status']]), 'Surat Keluar');
  XLSX.writeFile(wb, 'Template_Import_Persuratan.xlsx');
}
function applyImpSurat(){
  const P = _impSurat; if(!P) return;
  if(!canImportExcel('surat_masuk')){ toast('Import Excel hanya untuk Admin Utama'); return; }
  const skip = !!(document.getElementById('imp_surat_skip') && document.getElementById('imp_surat_skip').checked);
  const lm = P.masuk.filter(x=>!(skip && x.dup)), lk = P.keluar.filter(x=>!(skip && x.dup));
  if(!lm.length && !lk.length){ toast('Tidak ada data baru untuk disimpan'); return; }
  if(!Array.isArray(DB.surat_masuk)) DB.surat_masuk = [];
  if(!Array.isArray(DB.surat_keluar)) DB.surat_keluar = [];
  const gm = GENERIC_MODULES.find(m=>m.key==='lainnya') || GENERIC_MODULES[GENERIC_MODULES.length-1];
  const label = {}; // label kolom tambahan (judul kolom di Excel) disimpan bersama data
  lm.forEach(x=>{
    const o = x.o;
    const row = {
      id: uid('SM', DB.surat_masuk),
      nomor_surat: o.nomor_surat || '-', asal_surat: o.asal_surat || '',
      tanggal_surat: o.tanggal_surat || '', tanggal_diterima: o.tanggal_diterima || o.tanggal_surat || todayISO(),
      jam_diterima: o.jam_diterima || '', batas_waktu: o.batas_waktu || '', batas_jam: o.batas_jam || '',
      perihal: o.perihal || '-', jenis: gm.key, jenis_label: gm.label,
      tanggal_kegiatan: '', waktu: '', lokasi: '', penanggung_jawab: '', no_hp: '', jumlah_dibutuhkan: 1, deskripsi: '',
      status_surat: o.status_surat || '', instruksi_kks: o.instruksi_kks || '', no_urut_excel: o.no || '',
      disposisi_teks: o.disposisi_teks || '', naskah_teks: o.naskah_teks || '', batas_teks: o.batas_teks || '',
      file_pdf: null,
      status: 'Selesai', acc: [], tanpa_acc: true, keterangan_persetujuan: IMP_KET_TANPA_ACC, kegiatan_id: '',
      diajukan_oleh: pelaku(), sumber: 'Import Excel', dibuat: nowStr(), diperbarui: nowStr(),
    };
    const lbl = {};
    Object.keys(o.lain||{}).forEach(k=>{ row[k] = o.lain[k]; lbl[k] = P.kolomLabel[k] || k; });
    if(Object.keys(lbl).length) row.kolom_label = lbl;
    DB.surat_masuk.push(row);
  });
  lk.forEach(x=>{
    const o = x.o;
    const kirim = /^(TERKIRIM|DIKIRIM|SUDAH|SELESAI)$/i.test(o.kirim) ? 'Terkirim' : (o.kirim ? 'Belum Terkirim' : '');
    const row = {
      id: uid('SK', DB.surat_keluar),
      nomor_surat: o.nomor_surat || '-', tujuan: o.tujuan || '', perihal: o.perihal || '-', sifat: '',
      tanggal_surat: o.tanggal_surat || '', tgl_upload: o.tanggal_surat || '',
      status_kirim: kirim, status_proses: 'Belum Di Proses', no_urut_excel: o.no || '', tanggal_teks: o.tanggal_teks || '',
      file_pdf: null, keterangan: '', sumber: 'Import Excel',
      dibuat_oleh: pelaku(), dibuat: nowStr(), diperbarui: nowStr(),
    };
    const lbl = {};
    Object.keys(o.lain||{}).forEach(k=>{ row[k] = o.lain[k]; lbl[k] = P.kolomLabel[k] || k; });
    if(Object.keys(lbl).length) row.kolom_label = lbl;
    DB.surat_keluar.push(row);
  });
  persist();
  _impSurat = null;
  closeModal();
  if(currentView==='persuratan') psTab = (lm.length || !lk.length) ? 'masuk' : 'keluar'; else kegTab = 'surat';
  render(currentView);
  toast('Berhasil mengimpor ' + lm.length + ' surat masuk dan ' + lk.length + ' surat keluar (tanpa persetujuan)');
}
/* Rincian data import (batas waktu, status, instruksi KKS, kolom tambahan) pada detail Surat Masuk */
function suratImportDetailHtml(sm){
  if(sm.sumber!=='Import Excel') return '';
  const lbl = sm.kolom_label || {};
  const lain = Object.keys(sm).filter(k=>/^kolom_[a-z]+$/.test(k) && sm[k]!=='' && sm[k]!=null);
  const row = (t, v) => `<div><span style="color:var(--muted);">${esc(t)}:</span> ${esc(v)||'-'}</div>`;
  return row('Waktu Diterima', sm.jam_diterima ? sm.jam_diterima + ' WIB' : '')
    + row('Batas Waktu', (tglDMY(sm.batas_waktu) || '') + (sm.batas_jam ? ' ' + sm.batas_jam : ''))
    + row('Status Surat', sm.status_surat) + row('Tambahan Instruksi KKS', sm.instruksi_kks)
    + lain.map(k=>row(lbl[k] || k, sm[k])).join('')
    + (sm.no_urut_excel ? row('No. pada Excel', sm.no_urut_excel) : '');
}

function diklatReadOnlyPanelHtml(){
  const rows = diklatRekapSaved();
  const cols = DIKLAT_PESERTA_COLS;
  return `<div class="panel rounded-xl p-4 mt-4">
    <div class="flex items-center gap-2 mb-3"><span class="font-display font-semibold text-sm">Rekap Diklat Anggota</span><span class="text-xs" style="color:var(--muted);">${rows.length} peserta</span></div>
    <div class="tablewrap" style="max-height:520px; overflow-y:auto;"><table>
      <thead><tr><th class="text-center">No</th><th>Nama</th><th>Jabatan</th><th>NIP/Golongan</th>${cols.map(c=>`<th class="text-center">${esc(c.label)}</th>`).join('')}<th>Diklat Lain</th></tr></thead>
      <tbody>${rows.length ? rows.map((r,i)=>`<tr>
        <td class="text-center">${i+1}</td><td class="font-medium">${esc(r.nama)}</td><td>${esc(r.jabatan)||'-'}</td><td>${esc(r.nipgol)||'-'}</td>
        ${cols.map(c=>`<td class="text-center">${r[c.key]?'✓':'-'}</td>`).join('')}
        <td>${esc((Array.isArray(r.diklat_lain_list)?r.diklat_lain_list:[]).map(x=>x.teks).filter(Boolean).join('; '))||'-'}</td></tr>`).join('') : `<tr><td colspan="${cols.length+5}" class="text-center py-6" style="color:var(--muted);">Belum ada data peserta.</td></tr>`}</tbody>
    </table></div></div>`;
}
function diklatMainPanelHtml(key){
  if(key==='diklat' && modulReadOnly('diklat')) return diklatReadOnlyPanelHtml();
  if(key==='uji_periodik') return ujiPesertaPanelHtml();
  if(key==='diklat') return daftarDiklatPanelHtml() + diklatRekapPanelHtml();
  pesertaKey = key;
  const M = PESERTA_MODULES[key];
  const rows = DB[key] || [];
  if(!rows.length){
    return `<div class="panel rounded-xl p-4 mt-4"><p class="text-sm font-display font-semibold mb-1">Data Peserta ${esc(M.label)}</p><p class="text-sm" style="color:var(--muted);">Belum ada data ${esc(M.label)}. Tambahkan data ${esc(M.label)} terlebih dahulu.</p></div>`;
  }
  if(!diklatMainSelectedIds[key] || !rows.find(r=>r.id===diklatMainSelectedIds[key])){
    diklatMainSelectedIds[key] = rows[rows.length-1].id;
  }
  const selected = rows.find(r=>r.id===diklatMainSelectedIds[key]);
  diklatPesertaContext = 'main';
  diklatPesertaRows = Array.isArray(selected.peserta_matrix) ? JSON.parse(JSON.stringify(selected.peserta_matrix)) : [];
  if(key==='uji_periodik') loadUjiCols(selected);
  return `
    <div class="panel rounded-xl p-4 mt-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div class="flex items-center gap-2">
          <span class="font-display font-semibold text-sm">Data Peserta ${esc(M.label)}</span>
          <select onchange="pesertaKey='${key}'; onDiklatMainSelectChange(this.value)">
            ${rows.map(r=>`<option value="${esc(r.id)}" ${r.id===diklatMainSelectedIds[key]?'selected':''}>${esc(r[M.nameKey])}</option>`).join('')}
          </select>
        </div>
        <div class="flex items-center gap-2">
          ${key==='diklat' ? `<div class="dd">
            <button type="button" class="btn btn-ghost !py-1.5 !px-3 text-xs" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
            <div class="dd-menu">
              <button type="button" class="dd-item" onclick="pesertaKey='${key}'; exportPeserta('pdf')">PDF</button>
              <button type="button" class="dd-item" onclick="pesertaKey='${key}'; exportPeserta('excel')">Excel</button>
            </div>
          </div>` : ''}
          <button class="btn btn-primary !py-1.5 !px-3 text-xs" onclick="pesertaKey='${key}'; saveDiklatMainPeserta()">Simpan Data Peserta</button>
        </div>
      </div>
      <div id="diklat_peserta_wrap_main">${diklatPesertaEditorHtml()}</div>
    </div>`;
}

function statusDariKegiatan(key, rec){
  const map = {'Draft':'Direncanakan', 'Dalam Pelaksanaan':'Berlangsung', 'Selesai':'Selesai'};
  const k = kegiatanTerkaitRecord(key, rec).find(x=>map[x.status_pelaksanaan]);
  if(k) return map[k.status_pelaksanaan];
  return ['Direncanakan','Berlangsung','Selesai'].includes(rec && rec.status) ? rec.status : 'Direncanakan';
}
// Status data modul mengikuti Kegiatan/Tugas terkaitnya (terkunci, tidak bisa diubah manual).
function statusDariKegiatanAktif(key, f, rec){
  if(f.autoFromKegiatan) return true;
  return !!(f.isStatus && f.options && f.options.includes('Direncanakan') && rec && kegiatanTerkaitRecord(key, rec).length);
}
function statusFieldValue(key, f, rec){
  return statusDariKegiatanAktif(key, f, rec) ? statusDariKegiatan(key, rec) : (rec ? rec[f.key] : undefined);
}
function statusBadge(val){
  if(val===undefined || val==='') return '';
  const s = String(val);
  let cls='b-info';
  if(/selesai|aktif$|disetujui|layak$|terkirim|baik$|tersedia|siap operasi|dikembalikan/i.test(s) && !/tidak layak|nonaktif/i.test(s)) cls='b-ok';
  else if(/menunggu|draft|direncanakan|dipinjam|perawatan|dikerjakan/i.test(s)) cls='b-warn';
  else if(/ditolak|nonaktif|tidak layak|gagal|rusak|terlambat/i.test(s)) cls='b-bad';
  return `<span class="badge ${cls}">${esc(s)}</span>`;
}

function enhanceTableScroll(){
  document.querySelectorAll('.tablewrap').forEach(wrap=>{
    const table = wrap.querySelector('table');
    if(!table) return;
    const top = document.createElement('div');
    top.className = 'scrolltop';
    const inner = document.createElement('div');
    top.appendChild(inner);
    wrap.parentNode.insertBefore(top, wrap);
    function sync(){
      inner.style.width = table.scrollWidth + 'px';
      top.classList.toggle('hide', table.scrollWidth <= wrap.clientWidth + 1);
    }
    let guard = false;
    top.addEventListener('scroll', ()=>{ if(guard) return; guard=true; wrap.scrollLeft = top.scrollLeft; guard=false; });
    wrap.addEventListener('scroll', ()=>{ if(guard) return; guard=true; top.scrollLeft = wrap.scrollLeft; guard=false; });
    sync();
    window.addEventListener('resize', sync);
  });
}

/* ---- Pencarian: frasa utuh, lintas kolom, fokus input tetap terjaga ---- */
function normSearch(t){ return String(t==null?'':t).toLowerCase().replace(/\s+/g,' ').trim(); }
function rowSearchText(r, fields){
  const parts = [];
  Object.values(r).forEach(v=>{
    if(v==null || typeof v==='object') return;
    parts.push(v);
  });
  if(Array.isArray(r.peserta_matrix)) r.peserta_matrix.forEach(p=>{ if(p){ parts.push(p.nama, p.jabatan); } });
  if(fields) fields.forEach(f=>{ if(f.derivedFrom){ try{ parts.push(fieldValue(f,r)); }catch(e){} } });
  return normSearch(parts.filter(x=>x!=null && x!=='').join(' | '));
}
function matchSearch(r, q, fields){
  const nq = normSearch(q);
  return !nq || rowSearchText(r, fields).includes(nq);
}
function runSearchRender(inputEl, fn){
  const sid = inputEl.getAttribute('data-sid');
  const pos = inputEl.selectionStart;
  fn();
  const n = document.querySelector('[data-sid="'+sid+'"]');
  if(n){ n.focus(); try{ n.setSelectionRange(pos,pos); }catch(e){} }
}
/* ---- Filter Sapras (Kendaraan): Darat / Laut berdasarkan Jenis Kendaraan; klik lagi untuk menampilkan semua ---- */
const SAPRAS_JENIS = {
  'Sapras Darat':['Mobil','Motor','Truk','Ambulans'],
  'Sapras Laut':['Kapal','RIB / Perahu Karet']
};
let saprasFilter = '';
function labelKendaraan(f, laut){ return (f.key==='nomor_polisi' && laut) ? 'No Lambung' : f.label; }
function saprasKategori(r){ return r.sapras || Object.keys(SAPRAS_JENIS).find(k=>SAPRAS_JENIS[k].includes(r.jenis_kendaraan)) || ''; }
function saprasCocok(r){ return !saprasFilter || saprasKategori(r)===saprasFilter; }
function setSaprasFilter(v){ saprasFilter = (saprasFilter===v) ? '' : v; render(currentView); }
function saprasButtonsHtml(){
  const rows = DB.kendaraan || [];
  return `<div class="flex flex-wrap items-center gap-2 mb-3">
    ${Object.keys(SAPRAS_JENIS).map(k=>{
      const n = rows.filter(r=>saprasKategori(r)===k).length;
      return `<button type="button" class="btn ${saprasFilter===k?'btn-primary':'btn-ghost'}" onclick="setSaprasFilter('${k}')">${k} <span class="badge ${saprasFilter===k?'':'b-info'}" style="${saprasFilter===k?'background:rgba(255,255,255,.25);color:#fff;':''}">${n}</span></button>`;
    }).join('')}
    ${saprasFilter ? `<span class="text-xs" style="color:var(--muted);">Klik tombol aktif lagi untuk menampilkan semua kendaraan</span>` : ''}
  </div>`;
}
/* ---- Form Kendaraan: tombol Sapras Darat / Sapras Laut menentukan pilihan Jenis Kendaraan ---- */
const SAPRAS_FORM_JENIS = {
  'Sapras Darat':['Mobil','Motor','Truk','Ambulans'],
  'Sapras Laut':['Kapal','RIB / Perahu Karet']
};
// Jenis tambahan buatan pengguna (lewat kolom "Nama jenis" + tombol +), disimpan per kategori Sapras
function jenisKendaraanOpsi(sapras){
  const custom = ((DB.jenis_kendaraan_custom||{})[sapras]) || [];
  const hidden = ((DB.jenis_kendaraan_hidden||{})[sapras]) || [];
  return (SAPRAS_FORM_JENIS[sapras]||[]).filter(j=>!hidden.includes(j)).concat(custom);
}
function hapusJenisKendaraan(){
  const hid = document.querySelector('[data-field="sapras"]');
  const sel = document.querySelector('[data-field="jenis_kendaraan"]');
  if(!hid || !sel) return;
  const sapras = hid.value, nama = sel.value;
  if(!nama){ toast('Pilih jenis yang ingin dihapus'); return; }
  if(sel.options.length<=1){ toast('Minimal harus ada satu jenis kendaraan'); return; }
  const dipakai = (DB.kendaraan||[]).filter(r=>r.jenis_kendaraan===nama).length;
  const pesan = 'Hapus jenis "'+nama+'" dari pilihan?' + (dipakai ? '\n\n'+dipakai+' data kendaraan yang sudah memakai jenis ini tidak ikut terhapus, jenisnya tetap tercatat.' : '');
  if(!confirm(pesan)) return;
  const custom = (DB.jenis_kendaraan_custom||{})[sapras] || [];
  if(custom.includes(nama)){
    DB.jenis_kendaraan_custom[sapras] = custom.filter(j=>j!==nama);
  } else {
    if(!DB.jenis_kendaraan_hidden) DB.jenis_kendaraan_hidden = {};
    if(!DB.jenis_kendaraan_hidden[sapras]) DB.jenis_kendaraan_hidden[sapras] = [];
    DB.jenis_kendaraan_hidden[sapras].push(nama);
  }
  persist();
  sel.value = ''; // supaya jenis yang dihapus tidak dimunculkan lagi
  pilihSaprasForm(sapras);
  toast('Jenis "'+nama+'" dihapus');
}
function tambahJenisKendaraan(){
  const hid = document.querySelector('[data-field="sapras"]');
  const sel = document.querySelector('[data-field="jenis_kendaraan"]');
  const inp = document.getElementById('jenis_baru');
  if(!hid || !sel || !inp) return;
  const nama = inp.value.trim().replace(/\s+/g,' ');
  if(!nama){ toast('Isi Nama jenis terlebih dahulu'); return; }
  const sapras = hid.value;
  const ada = [...sel.options].find(o=>o.value.toLowerCase()===nama.toLowerCase());
  if(ada){ sel.value = ada.value; inp.value=''; toast('Jenis sudah ada, dipilih'); return; }
  if(!DB.jenis_kendaraan_custom) DB.jenis_kendaraan_custom = {};
  if(!DB.jenis_kendaraan_custom[sapras]) DB.jenis_kendaraan_custom[sapras] = [];
  const hd = (DB.jenis_kendaraan_hidden||{})[sapras] || [];
  const asli = (SAPRAS_FORM_JENIS[sapras]||[]).find(j=>j.toLowerCase()===nama.toLowerCase());
  if(asli && hd.includes(asli)) DB.jenis_kendaraan_hidden[sapras] = hd.filter(j=>j!==asli); // jenis bawaan yang pernah dihapus: dimunculkan lagi
  else DB.jenis_kendaraan_custom[sapras].push(nama);
  persist();
  pilihSaprasForm(sapras);
  if(![...sel.options].some(o=>o.value===nama)) sel.add(new Option(nama, nama));
  sel.value = nama;
  inp.value = '';
  toast('Jenis "'+nama+'" ditambahkan');
}
// Pilihan Lokasi / Pangkalan: Sapras Laut punya tambahan dermaga/pelabuhan
const LOKASI_KENDARAAN = ['KPP Jakarta','USS Bekasi','USS Bogor','Pos SAR Sukabumi'];
const LOKASI_LAUT_EXTRA = ['Dermaga Pantai Mutiara','Pel. T.Priok Ekinggom'];
function saprasDariRow(row){
  if(row && row.sapras) return row.sapras;
  if(row){ const k = Object.keys(SAPRAS_JENIS).find(k=>SAPRAS_JENIS[k].includes(row.jenis_kendaraan)); if(k) return k; }
  return 'Sapras Darat';
}
function kendaraanSaprasPickerHtml(row){
  const cur = saprasDariRow(row);
  return `<div class="sm:col-span-2 flex flex-wrap gap-2" id="sapras_picker">
    ${Object.keys(SAPRAS_FORM_JENIS).map(k=>`<button type="button" class="btn ${cur===k?'btn-primary':'btn-ghost'}" data-sapras="${k}" onclick="pilihSaprasForm('${k}')">${k}</button>`).join('')}
  </div>`;
}
function pilihSaprasForm(val){
  const hid = document.querySelector('[data-field="sapras"]');
  const sel = document.querySelector('[data-field="jenis_kendaraan"]');
  if(!hid || !sel) return;
  hid.value = val;
  const prev = sel.value;
  const opts = jenisKendaraanOpsi(val);
  if(prev && !opts.includes(prev) && !Object.keys(SAPRAS_FORM_JENIS).some(k=>k!==val && jenisKendaraanOpsi(k).includes(prev))) opts.push(prev); // nilai lama tetap tampil
  sel.innerHTML = opts.map(o=>`<option value="${esc(o)}" ${o===prev?'selected':''}>${esc(o)}</option>`).join('');
  const lok = document.querySelector('[data-field="lokasi"]');
  if(lok){
    const pl = lok.value;
    const lopts = LOKASI_KENDARAAN.concat(val==='Sapras Laut' ? LOKASI_LAUT_EXTRA : []);
    if(pl && !lopts.includes(pl) && !LOKASI_LAUT_EXTRA.includes(pl)) lopts.push(pl); // nilai lama tetap tampil
    lok.innerHTML = lopts.map(o=>`<option value="${esc(o)}" ${o===pl?'selected':''}>${esc(o)}</option>`).join('');
  }
  const lblNp = document.getElementById('lbl_nomor_polisi');
  if(lblNp) lblNp.textContent = (val==='Sapras Laut') ? 'No Lambung' : 'Nomor Polisi / Registrasi';
  document.querySelectorAll('.sapras-laut-only').forEach(el=>{ el.style.display = (val==='Sapras Laut') ? '' : 'none'; });
  document.querySelectorAll('#sapras_picker [data-sapras]').forEach(b=>{
    const on = b.getAttribute('data-sapras')===val;
    b.classList.toggle('btn-primary', on); b.classList.toggle('btn-ghost', !on);
  });
}
function wireKendaraanForm(row){
  if(!document.getElementById('sapras_picker')) return;
  const tg = document.querySelector('[data-field="tanggal"]');
  if(tg && !tg.value) tg.value = todayISO();
  const hid = document.querySelector('[data-field="sapras"]');
  const sel = document.querySelector('[data-field="jenis_kendaraan"]');
  const cur = saprasDariRow(row);
  const prev = row ? row.jenis_kendaraan : '';
  if(prev && !sel.querySelector('option[value="'+prev.replace(/"/g,'&quot;')+'"]')) sel.add(new Option(prev, prev));
  sel.value = prev || sel.value;
  pilihSaprasForm(cur);
  if(prev && [...sel.options].some(o=>o.value===prev)) sel.value = prev;
  if(!document.getElementById('jenis_baru')){
    const box = document.createElement('div');
    box.className = 'flex gap-2 mt-2';
    box.innerHTML = '<input type="text" id="jenis_baru" placeholder="Nama jenis" maxlength="40" onkeydown="if(event.key===\'Enter\'){event.preventDefault();tambahJenisKendaraan();}">'
      + '<button type="button" class="btn btn-ghost" style="font-size:1rem;line-height:1;padding:.4rem .8rem;" title="Tambah jenis baru" onclick="tambahJenisKendaraan()">+</button>'
      + '<button type="button" class="btn btn-ghost" style="font-size:1rem;line-height:1;padding:.4rem .8rem;color:var(--bad);" title="Hapus jenis yang sedang dipilih" onclick="hapusJenisKendaraan()">&minus;</button>';
    sel.insertAdjacentElement('afterend', box);
  }
}
/* ---- Pemeliharaan Sumda: pilih Peralatan / Kendaraan dari modul terkait ---- */
function sumdaBarangList(){
  const out = [];
  (DB.peralatan||[]).forEach(r=>out.push({id:r.id, jenis:'Peralatan', nama:r.nama_barang||'', lokasi:r.lokasi||''}));
  (DB.kendaraan||[]).forEach(r=>out.push({id:r.id, jenis:'Kendaraan', nama:(r.nama_kendaraan||'') + (r.nomor_polisi ? ' ('+r.nomor_polisi+')' : ''), lokasi:r.lokasi||'', sapras:saprasKategori(r)}));
  out.forEach(o=>{ o.label = o.id + ' — ' + o.nama + (o.lokasi ? ' · ' + o.lokasi : ''); });
  return out;
}
function sumdaBarangById(id){ return id ? (sumdaBarangList().find(o=>o.id===id) || null) : null; }
function sumdaBarangCari(teks){
  const t = String(teks||'').trim(); if(!t) return null;
  const lst = sumdaBarangList();
  let o = lst.find(x=>x.label===t) || lst.find(x=>x.id.toLowerCase()===t.toLowerCase());
  if(o) return o;
  const m = lst.filter(x=>normNama(x.nama)===normNama(t));
  return m.length===1 ? m[0] : null;
}
/* ---- Pemeliharaan Sumda: form seperti Pengecekan Siaga Rutin (daftar dari Peralatan / Kendaraan) ---- */
let sumdaDraft = null;
function sumdaLokNorm(v){ return String(v||'').toLowerCase().replace(/\bsar\b/g,'').replace(/[^a-z0-9]/g,''); }
function sumdaLokasiOpsi(jenis, sapras){
  if(jenis==='Kendaraan') return siagaLokasiSarana(sapras || 'Sapras Darat'); // sama seperti Pengecekan Siaga Rutin (Sarana)
  const set = [];
  EXTRA_MODULES.find(m=>m.key==='peralatan').fields.find(f=>f.key==='lokasi').options.forEach(o=>set.push(o));
  (DB.peralatan||[]).forEach(r=>{ if(r.lokasi && !set.some(x=>sumdaLokNorm(x)===sumdaLokNorm(r.lokasi))) set.push(r.lokasi); });
  return set;
}
function sumdaDraftSiap(){
  if(!sumdaDraft) sumdaDraft = {jenis:'Peralatan', sapras:'Sapras Darat', lokasi:'', tanggal:todayISO(), petugas:'', tim:'', rows:[]};
  return sumdaDraft;
}
/* Daftar pilihan pencarian: sesuai Jenis (& Sapras untuk Kendaraan) & Lokasi, tanpa yang sudah ada di tabel form */
function sumdaPilihan(){
  const d = sumdaDraftSiap();
  return sumdaBarangList().filter(o=>o.jenis===d.jenis
    && (d.jenis!=='Kendaraan' || o.sapras===d.sapras)
    && (!d.lokasi || sumdaLokNorm(o.lokasi)===sumdaLokNorm(d.lokasi))
    && !d.rows.some(r=>r.id===o.id));
}
function sumdaUlang(){ renderGenericTable('uji_pemeliharaan', window.__lastFields, 'Pemeliharaan Sumda', false); }
function sumdaGanti(field, v){
  const d = sumdaDraftSiap();
  if(field==='jenis' || field==='sapras'){
    if(d[field]!==v && d.rows.length && !confirm('Mengganti ' + (field==='jenis' ? 'Jenis Pemeliharaan' : 'Sapras') + ' akan mengosongkan tabel form. Lanjutkan?')){ sumdaUlang(); return; }
    if(d[field]!==v){ d.rows = []; d.lokasi = ''; }
  }
  d[field] = v;
  sumdaUlang();
}
function sumdaHariIso(iso){ return typeof siagaHari==='function' ? siagaHari(iso) : ''; }
function sumdaGantiTanggal(v){ const d = sumdaDraftSiap(); d.tanggal = v; d.rows.forEach(r=>{ if(r.form) r.form.tglPeriksa = v; }); }
/* ---- Form Pemeriksaan Peralatan (per peralatan yang dipilih) ---- */
const SUMDA_KES = ['Baik','Butuh Perbaikan','Rusak Tidak Bisa Diperbaiki'];
const SUMDA_CEK_GENERIK = ['Periksa kondisi fisik dan kebersihan','Periksa kelengkapan bagian / aksesoris','Periksa fungsi / uji coba','Periksa kabel, selang, dan sambungan'];
const SUMDA_CEK_CHAINSAW = ['Periksa keausan brake band pada chain brake','Periksa keausan pusat kopling, bantalan kopling dan pegas kopling','Bersihkan busi dan periksa jarak elektroda (0,02 inchi)','Periksa filter tanki dan selang bahan bakar','Bersihkan bagian luar karburator','Kosongkan dan bersihkan tanki bahan bakar','Kosongkan dan bersihkan tanki oli mesin','Periksa kabel dan sambungan'];
function sumdaFormAlatBaru(nama){
  const daftar = /chain\s*saw/i.test(nama||'') ? SUMDA_CEK_CHAINSAW : SUMDA_CEK_GENERIK;
  return {merk:'', pn:'', sn:'', tahun:'', tglBeli:'', tglPakai:'', tglPeriksa:'', jenisPeriksa:'', catatan:'',
    items:daftar.map(t=>({t:t, v:''})), ket:'', kesimpulan:'', jml:1, diperiksa:''};
}
function sumdaFSet(i, f, v){ const r = sumdaDraftSiap().rows[i]; if(!r || !r.form) return; r.form[f] = f==='jml' ? Math.max(1, parseInt(v,10) || 1) : v; if(f==='kesimpulan' || f==='jml') sumdaFEfek(i); }
function sumdaFItem(i, j, f, v){ const r = sumdaDraftSiap().rows[i]; if(r && r.form && r.form.items[j]) r.form.items[j][f] = v; }
function sumdaFTambah(i){ const r = sumdaDraftSiap().rows[i]; if(r && r.form){ r.form.items.push({t:'', v:''}); sumdaBukaForm(i); } }
function sumdaFHapus(i, j){ const r = sumdaDraftSiap().rows[i]; if(r && r.form){ r.form.items.splice(j,1); sumdaBukaForm(i); } }
// Popup form pemeriksaan (bergaya Detail Kegiatan); menutup popup menyegarkan tabel ringkasan
function sumdaBukaForm(i){
  const r = sumdaDraftSiap().rows[i]; if(!r || !r.form) return;
  const sc = document.querySelector('#modalRoot .overflow-y-auto'), top = sc ? sc.scrollTop : 0;
  openModal(sumdaFormAlatHtml(r, i, modulReadOnly('uji_pemeliharaan')), true);
  _modalCloseHook = sumdaUlang;
  const n = document.querySelector('#modalRoot .overflow-y-auto'); if(n) n.scrollTop = top;
}
function sumdaRingkasAlatHtml(r, i, ro){
  const f = r.form || {};
  const cls = {'Baik':'b-ok','Butuh Perbaikan':'b-warn','Rusak Tidak Bisa Diperbaiki':'b-bad'}[f.kesimpulan] || 'b-info';
  return `<tr>
    <td>${i+1}</td><td class="font-medium">${esc(r.id)}</td><td>${esc(r.nama)}</td><td>${esc(r.lokasi||'-')}</td>
    <td><span class="badge ${cls}">${esc(f.kesimpulan ? (f.kesimpulan==='Rusak Tidak Bisa Diperbaiki' ? 'Rusak tidak bisa diperbaiki' : f.kesimpulan) : 'Belum diisi')}</span></td>
    <td>${f.jml||1}</td><td>${esc(f.ket||'')}</td>
    <td class="whitespace-nowrap"><button type="button" class="btn btn-ghost !px-2 !py-1" onclick="sumdaBukaForm(${i})">${ro?'Lihat':'Isi / Ubah Form'}</button>
      ${ro ? '' : `<button type="button" class="btn btn-ghost !px-2 !py-1" title="Hapus baris" onclick="sumdaHapusBaris(${i})">✕</button>`}</td>
  </tr>`;
}
function sumdaFEfekTeks(r){
  const f = r.form, rec = (DB.peralatan||[]).find(x=>x.id===r.id) || {};
  if(f.kesimpulan==='Baik') return 'Kesimpulan Baik: tidak ada perubahan pada modul Peralatan.';
  if(!f.kesimpulan) return 'Pilih Kesimpulan. Baik = modul Peralatan tidak berubah; Butuh Perbaikan / Rusak = modul Peralatan ikut berubah.';
  const baik = parseInt(rec.jumlah_baik,10) || 0;
  return 'Modul Peralatan: Jumlah Baik '+baik+' → '+Math.max(0, baik - f.jml)+', Jumlah Rusak '+(parseInt(rec.jumlah_rusak,10)||0)+' → '+((parseInt(rec.jumlah_rusak,10)||0) + f.jml)+'.';
}
function sumdaFEfek(i){ const r = sumdaDraftSiap().rows[i], el = document.getElementById('sumda_efek_'+i); if(r && r.form && el) el.textContent = sumdaFEfekTeks(r); }
function sumdaFormAlatHtml(r, i, ro){
  const f = r.form, dis = ro ? 'disabled' : '';
  const rec = (DB.peralatan||[]).find(x=>x.id===r.id) || {};
  const inp = (lbl, key, type) => `<div class="flex items-center gap-2 py-1" style="border-bottom:1px solid var(--line,#e5e7eb);"><label class="text-sm whitespace-nowrap" style="min-width:9rem;">${lbl}</label><input type="${type||'text'}" value="${esc(f[key])}" ${dis} style="flex:1;min-width:6rem;" oninput="sumdaFSet(${i},'${key}',this.value)"></div>`;
  const radio = (j, v, lab) => `<td class="text-center"><input type="radio" name="sumda_cek_${i}_${j}" ${f.items[j].v===v?'checked':''} ${dis} aria-label="${lab}" onchange="sumdaFItem(${i},${j},'v','${v}')" style="width:auto;"></td>`;
  return `<div class="p-5 max-h-[85vh] overflow-y-auto">
    <div class="flex items-center justify-between mb-3">
      <div><h3 class="font-display font-semibold text-base">Form Pemeriksaan Peralatan</h3>
        <div class="text-xs" style="color:var(--muted);">${esc(r.nama)} · ${esc(r.id)} · ${esc(r.lokasi||'-')} · Jumlah Baik ${parseInt(rec.jumlah_baik,10)||0} unit</div></div>
      <button type="button" class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
    </div>
    <div class="grid md:grid-cols-2 gap-x-6 mb-4">
      <div>${inp('Merk/Type :','merk')}${inp('P/N :','pn')}${inp('S/N :','sn')}${inp('Tahun Pembuatan :','tahun')}${inp('Tgl Pembelian :','tglBeli','date')}${inp('Tgl mulai digunakan :','tglPakai','date')}</div>
      <div>${inp('Tgl Pemeriksaan :','tglPeriksa','date')}${inp('Jenis Pemeriksaan :','jenisPeriksa')}</div>
    </div>
    <div class="mb-4"><label class="block text-sm mb-1">1. Catatan Selama Penggunaan <span class="text-xs" style="color:var(--muted);">(kondisi penggunaan atau kejadian luar biasa, mis. jatuh, penyimpanan pada suhu ekstrim, modifikasi di luar fasilitas pabrikan)</span></label>
      <textarea rows="2" ${dis} oninput="sumdaFSet(${i},'catatan',this.value)">${esc(f.catatan)}</textarea></div>
    <div class="tablewrap mb-4"><table>
      <thead><tr><th>No.</th><th>Pemeriksaan</th><th class="text-center">Baik</th><th class="text-center">Butuh Perbaikan</th><th class="text-center">Rusak</th>${ro?'':'<th></th>'}</tr></thead>
      <tbody>${f.items.map((it,j)=>`<tr>
        <td>${j+2}</td>
        <td><input type="text" value="${esc(it.t)}" ${dis} style="min-width:18rem;" placeholder="Butir pemeriksaan..." oninput="sumdaFItem(${i},${j},'t',this.value)"></td>
        ${radio(j,'Baik','Baik')}${radio(j,'Butuh Perbaikan','Butuh Perbaikan')}${radio(j,'Rusak','Rusak')}
        ${ro?'':`<td><button type="button" class="btn btn-ghost !px-2 !py-1" title="Hapus butir" onclick="sumdaFHapus(${i},${j})">✕</button></td>`}
      </tr>`).join('') || `<tr><td colspan="6" class="text-center py-3" style="color:var(--muted);">Belum ada butir pemeriksaan.</td></tr>`}</tbody>
    </table></div>
    ${ro ? '' : `<button type="button" class="btn btn-ghost mb-4" onclick="sumdaFTambah(${i})">+ Tambah Butir Pemeriksaan</button>`}
    <div class="mb-4"><label class="block text-sm mb-1">Keterangan <span class="text-xs" style="color:var(--muted);">(rinci setiap cacat yang ditemukan pada produk berdasarkan hasil pemeriksaan)</span></label>
      <textarea rows="3" ${dis} oninput="sumdaFSet(${i},'ket',this.value)">${esc(f.ket)}</textarea></div>
    <div class="grid md:grid-cols-2 gap-4">
      <div><div class="text-sm font-medium mb-1">Kesimpulan</div>
        ${SUMDA_KES.map(k=>`<label class="flex items-center gap-2 text-sm py-0.5"><input type="radio" name="sumda_kes_${i}" ${f.kesimpulan===k?'checked':''} ${dis} style="width:auto;" onchange="sumdaFSet(${i},'kesimpulan','${k}')"> ${k==='Rusak Tidak Bisa Diperbaiki'?'Rusak tidak bisa diperbaiki':k}</label>`).join('')}
        <div class="flex items-center gap-2 mt-2"><label class="text-sm" for="sumda_jml_${i}">Jumlah unit yang diperiksa:</label>
          <input id="sumda_jml_${i}" type="number" min="1" step="1" value="${f.jml}" ${dis} style="width:5rem;text-align:center;" oninput="sumdaFSet(${i},'jml',this.value)"></div>
        <div id="sumda_efek_${i}" class="text-[11px] mt-2" style="color:var(--muted);">${esc(sumdaFEfekTeks(r))}</div></div>
      <div><label class="block text-sm mb-1">Diperiksa oleh (Nama):</label><input type="text" value="${esc(f.diperiksa)}" ${dis} placeholder="Nama pemeriksa" oninput="sumdaFSet(${i},'diperiksa',this.value)"></div>
    </div>
    <div class="flex justify-between gap-2 mt-5">
      ${ro ? '<span></span>' : `<button type="button" class="btn btn-ghost" style="color:var(--bad);" onclick="closeModal(); sumdaHapusBaris(${i})">Hapus dari Daftar</button>`}
      <button type="button" class="btn btn-primary" onclick="closeModal()">Simpan Form</button>
    </div>
  </div>`;
}
function sumdaFormBacaHtml(x){
  const f = x.form; if(!f) return '';
  const v = t => esc(t||'-');
  return `<details class="mt-3"><summary class="cursor-pointer text-sm font-medium">Form Pemeriksaan — ${esc(x.nama)} (${esc(x.ref)}) · ${esc(f.kesimpulan||'-')}</summary>
    <div class="text-sm mt-2 grid sm:grid-cols-2 gap-x-6 gap-y-1">
      <div>Merk/Type: ${v(f.merk)}</div><div>Tgl Pemeriksaan: ${esc(tglDMY(f.tglPeriksa)||'-')}</div>
      <div>P/N: ${v(f.pn)}</div><div>Jenis Pemeriksaan: ${v(f.jenisPeriksa)}</div>
      <div>S/N: ${v(f.sn)}</div><div>Diperiksa oleh: ${v(f.diperiksa)}</div>
      <div>Tahun Pembuatan: ${v(f.tahun)}</div><div>Jumlah unit diperiksa: ${f.jml||1}</div>
      <div>Tgl Pembelian: ${esc(tglDMY(f.tglBeli)||'-')}</div><div>Tgl mulai digunakan: ${esc(tglDMY(f.tglPakai)||'-')}</div>
    </div>
    <div class="text-sm mt-2"><b>1. Catatan Selama Penggunaan:</b> ${v(f.catatan)}</div>
    <div class="tablewrap mt-2"><table><thead><tr><th>No.</th><th>Pemeriksaan</th><th>Hasil</th></tr></thead>
      <tbody>${(f.items||[]).map((it,j)=>`<tr><td>${j+2}</td><td>${esc(it.t)}</td><td>${esc(it.v||'-')}</td></tr>`).join('')}</tbody></table></div>
    <div class="text-sm mt-2"><b>Keterangan:</b> ${v(f.ket)}</div>
  </details>`;
}
function sumdaCariInput(el){
  const o = sumdaPilihan().find(x=>x.label===el.value); if(!o) return;
  const d = sumdaDraftSiap();
  const n = v => parseInt(v,10) || 0;
  let baris;
  if(o.jenis==='Kendaraan'){
    // Seperti Pengecekan Siaga Rutin (Sarana): satu Kondisi per kendaraan + isian khusus Sapras Darat / Laut
    const k = (DB.kendaraan||[]).find(x=>x.id===o.id) || {};
    const kolom = siagaSaprasKolom(o.sapras || d.sapras);
    const vals = {}; kolom.forEach(f=>{ vals[f.key] = f.type==='number' ? 0 : ''; });
    const kond = SIAGA_KOND_KE_CEK[k.kondisi] || 'Baik';
    baris = {kondisi:kond, k0:kond, nopol:k.nomor_polisi||'', jenisK:k.jenis_kendaraan||'', sapras:o.sapras||d.sapras, bersih:'Ya', vals:vals, v0:Object.assign({}, vals), total:1};
  }else{
    const k = (DB.peralatan||[]).find(x=>x.id===o.id) || {};
    baris = {baik:n(k.jumlah_baik), rr:0, rb:0, hilang:0, total:n(k.jumlah_baik), form:sumdaFormAlatBaru(o.nama)};
    baris.form.tglPeriksa = d.tanggal; baris.form.diperiksa = d.petugas || '';
  }
  d.rows.push(Object.assign({id:o.id, jenis:o.jenis, nama:o.nama, lokasi:o.lokasi, ket:''}, baris));
  sumdaUlang();
  if(o.jenis==='Peralatan'){ sumdaBukaForm(d.rows.length-1); return; }
  const c = document.getElementById('sumda_cari'); if(c) c.focus();
}
function sumdaHapusBaris(i){ sumdaDraftSiap().rows.splice(i,1); sumdaUlang(); }
function sumdaSet(i, f, v){
  const r = sumdaDraftSiap().rows[i]; if(!r) return;
  r[f] = (f==='ket' || f==='kondisi' || f==='bersih') ? v : Math.max(0, parseInt(v,10) || 0);
}
function sumdaSetVal(i, key, v){ const r = sumdaDraftSiap().rows[i]; if(r){ r.vals = r.vals || {}; r.vals[key] = v; } }
/* Kondisi Kendaraan (satu pilihan) -> hitungan Baik / Rusak Ringan / Rusak Berat / Hilang untuk riwayat & Dashboard */
function sumdaKondisiAngka(kond){
  return {baik:kond==='Baik'?1:0, rr:kond==='Rusak Ringan'?1:0, rb:kond==='Rusak Berat'?1:0, hilang:kond==='Hilang'?1:0};
}
function sumdaFormHtml(){
  const d = sumdaDraftSiap();
  const ro = modulReadOnly('uji_pemeliharaan');
  const pil = sumdaPilihan();
  const kend = d.jenis==='Kendaraan';
  const laut = kend && d.sapras==='Sapras Laut';
  const kolom = kend ? siagaSaprasKolom(d.sapras) : [];
  const num = (i,f,v) => `<input type="number" min="0" step="1" value="${v}" style="width:5.5rem;text-align:center;" oninput="sumdaSet(${i},'${f}',this.value)" ${ro?'disabled':''}>`;
  const opt = (list, v) => list.map(o=>`<option value="${esc(o)}" ${o===v?'selected':''}>${esc(o)}</option>`).join('');
  const inputKolom = (f, i, r) => {
    const isNum = f.type==='number';
    const v = r.vals && r.vals[f.key]!==undefined ? r.vals[f.key] : '';
    const pct = (f.key==='bbm' && !laut) || f.key==='persen_bbm';
    const inp = `<input type="${isNum?'number':(f.type==='date'?'date':'text')}" ${isNum?`step="${f.decimal?'any':'1'}" min="0"${pct?' max="100"':''}`:''} style="min-width:${isNum?'6':(f.type==='date'?'10':'8')}rem;${pct?'padding-right:2rem;':''}" placeholder="${esc(f.placeholder||'')}" aria-label="${esc(f.label)} ${esc(r.nama)}" value="${esc(v)}" oninput="sumdaSetVal(${i},'${f.key}',this.value)" ${ro?'disabled':''}>`;
    return `<td>${pct ? `<div style="position:relative;">${inp}<span aria-hidden="true" style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--muted);">%</span></div>` : inp}</td>`;
  };
  const head = kend
    ? `<th>No.</th><th>ID</th><th>Nama Kendaraan</th><th>${laut?'No Lambung':'Nomor Polisi / Registrasi'}</th><th>Jenis Kendaraan</th><th>Lokasi</th>${kolom.map(f=>`<th>${esc(f.label)}</th>`).join('')}${laut?'':'<th>Kebersihan</th>'}<th>Kondisi</th><th>Keterangan/Masalah</th>${ro?'':'<th></th>'}`
    : `<th>No.</th><th>ID</th><th>Peralatan</th><th>Lokasi</th><th>Kondisi Baik</th><th>Kondisi Rusak Ringan</th><th>Kondisi Rusak Berat</th><th>Hilang</th><th>Keterangan</th>${ro?'':'<th></th>'}`;
  const nCol = kend ? (6 + kolom.length + (laut?0:1) + 2 + (ro?0:1)) : (9 + (ro?0:1));
  const hapusBtn = i => ro ? '' : `<td><button type="button" class="btn btn-ghost !px-2 !py-1" title="Hapus baris" onclick="sumdaHapusBaris(${i})">✕</button></td>`;
  const barisKend = (r,i) => `<tr>
        <td>${i+1}</td><td class="font-medium">${esc(r.id)}</td><td class="font-medium">${esc(r.nama.replace(/\s*\([^)]*\)\s*$/,''))}</td><td>${esc(r.nopol||'-')}</td><td>${esc(r.jenisK||'-')}</td><td>${esc(r.lokasi||'-')}</td>
        ${kolom.map(f=>inputKolom(f, i, r)).join('')}
        ${laut ? '' : `<td><select style="width:auto;min-width:5rem;" aria-label="Kebersihan ${esc(r.nama)}" onchange="sumdaSet(${i},'bersih',this.value)" ${ro?'disabled':''}><option value="Ya" ${(r.bersih||'Ya')==='Ya'?'selected':''}>Ya</option><option value="Tidak" ${r.bersih==='Tidak'?'selected':''}>Tidak</option></select></td>`}
        <td><select style="width:auto;min-width:8rem;" aria-label="Kondisi ${esc(r.nama)}" onchange="sumdaSet(${i},'kondisi',this.value)" ${ro?'disabled':''}>${opt(SIAGA_KONDISI, r.kondisi)}</select></td>
        <td><input type="text" placeholder="Opsional..." style="min-width:12rem;" aria-label="Keterangan ${esc(r.nama)}" value="${esc(r.ket)}" oninput="sumdaSet(${i},'ket',this.value)" ${ro?'disabled':''}></td>
        ${hapusBtn(i)}
      </tr>`;
  const barisAlat = (r,i) => `<tr>
        <td>${i+1}</td><td class="font-medium">${esc(r.id)}</td><td>${esc(r.nama)}</td><td>${esc(r.lokasi||'-')}</td>
        <td>${num(i,'baik',r.baik)}</td><td>${num(i,'rr',r.rr)}</td><td>${num(i,'rb',r.rb)}</td><td>${num(i,'hilang',r.hilang)}</td>
        <td><input type="text" placeholder="Opsional..." style="min-width:12rem;" value="${esc(r.ket)}" oninput="sumdaSet(${i},'ket',this.value)" ${ro?'disabled':''}></td>
        ${hapusBtn(i)}
      </tr>`;
  return `<div class="panel rounded-xl p-4 mb-4">
    <h3 class="font-display font-semibold text-base mb-3">Form Pemeliharaan Sumda</h3>
    <div class="flex flex-wrap items-end gap-3 mb-3">
      <div><label class="block text-sm mb-1" for="sumda_jenis">Jenis Pemeliharaan:</label>
        <select id="sumda_jenis" style="width:auto;min-width:12rem;" onchange="sumdaGanti('jenis',this.value)" ${ro?'disabled':''}>
          <option value="Peralatan" ${d.jenis==='Peralatan'?'selected':''}>Peralatan</option>
          <option value="Kendaraan" ${d.jenis==='Kendaraan'?'selected':''}>Kendaraan</option>
        </select></div>
      ${kend ? `<div>
        <div class="block text-sm mb-1">Sarana:</div>
        <div class="flex flex-wrap gap-2">${SIAGA_SAPRAS.map(k=>`<button type="button" class="btn ${d.sapras===k?'btn-primary':'btn-ghost'}" onclick="sumdaGanti('sapras','${k}')" ${ro?'disabled':''}>${k}</button>`).join('')}</div>
      </div>` : ''}
      <div><label class="block text-sm mb-1" for="sumda_lokasi">Lokasi:</label>
        <select id="sumda_lokasi" style="width:auto;min-width:12rem;" onchange="sumdaGanti('lokasi',this.value)" ${ro?'disabled':''}>
          <option value="">Semua Lokasi</option>
          ${sumdaLokasiOpsi(d.jenis, d.sapras).map(o=>`<option value="${esc(o)}" ${sumdaLokNorm(o)===sumdaLokNorm(d.lokasi)?'selected':''}>${esc(o)}</option>`).join('')}
        </select></div>
      <div><label class="block text-sm mb-1" for="sumda_tanggal">Tanggal:</label>
        <input id="sumda_tanggal" type="date" value="${esc(d.tanggal)}" onchange="sumdaGantiTanggal(this.value)" ${ro?'disabled':''}></div>
      ${kend ? `<div><label class="block text-sm mb-1" for="sumda_tim">Tim:</label>
        <input id="sumda_tim" type="text" placeholder="Mis. Tim 1" value="${esc(d.tim||'')}" oninput="sumdaDraft.tim=this.value" style="max-width:9rem;" ${ro?'disabled':''}></div>` : ''}
      <div style="flex:1;min-width:12rem;"><label class="block text-sm mb-1" for="sumda_petugas">Petugas / Pemeriksa:</label>
        <input id="sumda_petugas" type="text" placeholder="Nama petugas" value="${esc(d.petugas)}" oninput="sumdaDraft.petugas=this.value" ${ro?'disabled':''}></div>
    </div>
    ${ro ? '' : `<div class="mb-3">
      <label class="block text-sm mb-1" for="sumda_cari">Cari ${kend?'Kendaraan':'Peralatan'}:</label>
      <input id="sumda_cari" type="text" list="sumda_pilihan" autocomplete="off" placeholder="Ketik nama / ID, lalu pilih dari daftar untuk menambahkan ke tabel..." style="max-width:34rem;" oninput="sumdaCariInput(this)">
      <datalist id="sumda_pilihan">${pil.map(o=>`<option value="${esc(o.label)}"></option>`).join('')}</datalist>
      <div class="text-[11px] mt-1" style="color:var(--muted);">Sumber: modul ${d.jenis}${kend ? ' ('+d.sapras+')' : ''} (${pil.length} data tersedia${d.lokasi ? ' di '+esc(d.lokasi) : ''}).</div>
    </div>`}
    ${kend ? `<div class="tablewrap"><table>
      <thead><tr>${head}</tr></thead>
      <tbody>${d.rows.length ? d.rows.map((r,i)=>barisKend(r,i)).join('') : `<tr><td colspan="${nCol}" class="text-center py-6" style="color:var(--muted);">Belum ada data. Cari kendaraan di kolom pencarian untuk menambahkannya.</td></tr>`}</tbody>
    </table></div>` : `<div class="tablewrap"><table>
      <thead><tr><th>No.</th><th>ID</th><th>Peralatan</th><th>Lokasi</th><th>Kesimpulan</th><th>Jumlah Unit</th><th>Keterangan</th><th>Form</th></tr></thead>
      <tbody>${d.rows.length ? d.rows.map((r,i)=>sumdaRingkasAlatHtml(r,i,ro)).join('') : `<tr><td colspan="8" class="text-center py-6" style="color:var(--muted);">Belum ada data. Cari peralatan di kolom pencarian; popup form pemeriksaan muncul setelah peralatan dipilih.</td></tr>`}</tbody>
    </table></div>`}
    ${ro ? '' : `<div class="flex justify-end mt-4"><button class="btn btn-primary" onclick="sumdaSimpan()">Simpan Pemeliharaan</button></div>`}
  </div>`;
}
function sumdaRiwayatHtml(){
  const ro = modulReadOnly('uji_pemeliharaan');
  const riwayat = (DB.sumda_riwayat||[]).slice().reverse();
  return `<div class="panel rounded-xl p-4">
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <h3 class="font-display font-semibold text-base">Riwayat Pengecekan</h3>
      <span class="text-xs" style="color:var(--muted);">${riwayat.length} data</span>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th>ID</th><th>Jenis</th><th>Lokasi</th><th>Tanggal</th><th>Waktu</th><th>Petugas</th><th>Dicek</th><th>Kondisi Baik</th><th>Bermasalah</th><th></th></tr></thead>
      <tbody>${riwayat.length ? riwayat.map(r=>{
        const it = r.items||[], bad = it.filter(x=>(x.rr||0)+(x.rb||0)+(x.hilang||0)>0).length;
        return `<tr style="cursor:pointer;" onclick="sumdaDetail('${esc(r.id)}')">
          <td class="font-medium">${esc(r.id)}</td><td>${esc(r.jenis||'')}</td><td>${esc(r.lokasi||'-')}</td><td>${esc(tglDMY(r.tanggal))}</td><td>${esc(r.waktu||'')}</td><td>${esc(r.petugas||'-')}</td>
          <td>${it.length}</td><td>${it.length-bad}</td>
          <td>${bad ? `<span class="badge b-bad">${bad} item</span>` : `<span class="badge b-ok">Semua baik</span>`}</td>
          <td class="whitespace-nowrap" onclick="event.stopPropagation()">
            <button class="btn btn-ghost !px-2 !py-1" onclick="sumdaDetail('${esc(r.id)}')">Detail</button>
            ${(isApprover() && !ro) ? `<button class="btn btn-ghost !px-2 !py-1" onclick="sumdaHapusRiwayat('${esc(r.id)}')">Hapus</button>` : ''}
          </td></tr>`;
      }).join('') : `<tr><td colspan="10" class="text-center py-6" style="color:var(--muted);">Belum ada riwayat pengecekan.</td></tr>`}</tbody>
    </table></div>
  </div>`;
}
function sumdaDetail(id){
  const r = (DB.sumda_riwayat||[]).find(x=>x.id===id); if(!r) return;
  const it = r.items||[];
  const kend = r.jenis==='Kendaraan';
  const laut = kend && r.sapras==='Sapras Laut';
  const kolom = kend ? siagaSaprasKolom(r.sapras || 'Sapras Darat') : [];
  const thKend = `<th>No.</th><th>ID</th><th>Nama Kendaraan</th><th>${laut?'No Lambung':'Nomor Polisi / Registrasi'}</th><th>Jenis Kendaraan</th><th>Lokasi</th>${kolom.map(f=>`<th>${esc(f.label)}</th>`).join('')}${laut?'':'<th>Kebersihan</th>'}<th>Kondisi</th><th>Keterangan/Masalah</th>`;
  const trKend = (x,i) => `<tr><td>${i+1}</td><td class="font-medium">${esc(x.ref)}</td><td>${esc(String(x.nama||'').replace(/\s*\([^)]*\)\s*$/,''))}</td><td>${esc(x.nopol||'-')}</td><td>${esc(x.jenisK||'-')}</td><td>${esc(x.lokasi||'-')}</td>${kolom.map(f=>`<td>${esc((x.vals&&x.vals[f.key]!==undefined)?x.vals[f.key]:'')}</td>`).join('')}${laut?'':`<td>${esc(x.bersih||'-')}</td>`}<td>${siagaKondisiBadge(x.kondisi||(x.hilang?'Hilang':x.rb?'Rusak Berat':x.rr?'Rusak Ringan':'Baik'))}</td><td>${esc(x.ket||'')}</td></tr>`;
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-display font-semibold text-base">Detail Pengecekan ${esc(r.id)}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-4 gap-3 text-sm mb-4">
        <div><div class="text-xs" style="color:var(--muted);">Jenis</div>${esc(r.jenis||'-')}${r.sapras ? ' — '+esc(r.sapras) : ''}</div>
        <div><div class="text-xs" style="color:var(--muted);">Lokasi</div>${esc(r.lokasi||'-')}</div>
        <div><div class="text-xs" style="color:var(--muted);">Petugas / Pemeriksa</div>${esc(r.petugas||'-')}</div>
        <div><div class="text-xs" style="color:var(--muted);">Tanggal</div>${r.hari ? esc(r.hari)+', ' : ''}${esc(tglDMY(r.tanggal))} ${esc(r.waktu||'')}</div>
        ${r.tim ? `<div><div class="text-xs" style="color:var(--muted);">Tim</div>${esc(r.tim)}</div>` : ''}
      </div>
      <div class="tablewrap"><table>
        <thead><tr>${kend ? thKend : '<th>No.</th><th>ID</th><th>Peralatan</th><th>Lokasi</th><th>Kondisi Baik</th><th>Kondisi Rusak Ringan</th><th>Kondisi Rusak Berat</th><th>Hilang</th><th>Kesimpulan</th><th>Keterangan</th>'}</tr></thead>
        <tbody>${it.map((x,i)=> kend ? trKend(x,i) : `<tr><td>${i+1}</td><td class="font-medium">${esc(x.ref)}</td><td>${esc(x.nama)}</td><td>${esc(x.lokasi||'-')}</td><td>${x.baik||0}</td><td>${x.rr||0}</td><td>${x.rb||0}</td><td>${x.hilang||0}</td><td>${esc(x.kesimpulan||'-')}</td><td>${esc(x.ket||'')}</td></tr>`).join('')}</tbody>
      </table></div>
      ${kend ? '' : it.map(sumdaFormBacaHtml).join('')}
      <div class="flex justify-end mt-4"><button class="btn btn-ghost" onclick="closeModal()">Tutup</button></div>
    </div>`, true);
}
function sumdaHapusRiwayat(id){ if(guardRO('uji_pemeliharaan')) return;
  if(!confirm('Hapus riwayat pengecekan '+id+'? (Data Peralatan / Kendaraan yang sudah berubah tidak dikembalikan)')) return;
  DB.sumda_riwayat = (DB.sumda_riwayat||[]).filter(x=>x.id!==id);
  DB.uji_pemeliharaan = (DB.uji_pemeliharaan||[]).filter(x=>x.sesi_id!==id);
  persist(DB); toast('Riwayat dihapus'); render(currentView);
}
function renderSumda(){
  document.getElementById('app').innerHTML = sumdaFormHtml() + sumdaRiwayatHtml();
  enhanceTableScroll();
}
/* Terapkan hasil pemeliharaan ke modul Peralatan / Kendaraan (rusak / hilang) */
function sumdaTerapkanKeModul(d){
  const tgl = tglDMY(d.tanggal);
  const kolom = d.jenis==='Kendaraan' ? siagaSaprasKolom(d.sapras) : [];
  d.rows.forEach(r=>{
    const rec = (DB[r.jenis==='Kendaraan' ? 'kendaraan' : 'peralatan']||[]).find(x=>x.id===r.id); if(!rec) return;
    let cat;
    if(r.jenis==='Kendaraan'){
      // Sama seperti Pengecekan Siaga Rutin: Baik -> Serviceable; Rusak Ringan/Berat/Hilang -> Unserviceable
      if(r.kondisi!==r.k0) rec.kondisi = (r.kondisi==='Baik') ? 'Serviceable' : 'Unserviceable';
      kolom.forEach(f=>{
        const baru = r.vals ? r.vals[f.key] : '', lama = r.v0 ? r.v0[f.key] : '';
        if(String(baru)===String(lama) || baru==='' || baru==null) return;
        if(f.type==='number'){ const num = parseFloat(baru); if(!(num>0)) return; rec[f.key] = num; }
        else rec[f.key] = String(baru||'').trim();
      });
      cat = '[Pemeliharaan Sumda '+tgl+'] Kondisi '+r.kondisi + (String(r.ket||'').trim() ? ' — '+String(r.ket).trim() : '');
    }else{
      // Kesimpulan Baik: modul Peralatan tidak diubah. Butuh Perbaikan / Rusak: unit berpindah dari Jumlah Baik ke Jumlah Rusak.
      if(!r.form || r.form.kesimpulan==='Baik') return;
      rec.jumlah_baik = r.baik; rec.jumlah_rusak = (parseInt(rec.jumlah_rusak,10) || 0) + r.form.jml; rec.jumlah = rec.jumlah_baik + rec.jumlah_rusak;
      cat = '[Pemeliharaan Sumda '+tgl+'] '+(r.form.kesimpulan==='Butuh Perbaikan' ? 'Butuh Perbaikan' : 'Rusak tidak bisa diperbaiki')+' '+r.form.jml+' unit' + (String(r.ket||'').trim() ? ' — '+String(r.ket).trim() : '');
    }
    if(!String(rec.keterangan||'').includes(cat)) rec.keterangan = (rec.keterangan ? rec.keterangan+'\n' : '') + cat;
  });
}
function sumdaSimpan(){
  if(guardRO('uji_pemeliharaan')) return;
  const d = sumdaDraftSiap();
  const kend = d.jenis==='Kendaraan';
  if(!d.tanggal){ toast('Tanggal wajib diisi'); return; }
  if(!d.rows.length){ toast('Belum ada data. Cari dan tambahkan dulu di kolom pencarian'); return; }
  if(!kend){
    for(const r of d.rows){
      const f = r.form, rec = (DB.peralatan||[]).find(x=>x.id===r.id);
      if(!f || !rec){ toast('Data peralatan '+r.nama+' tidak ditemukan di modul Peralatan'); return; }
      if(!f.kesimpulan){ toast('Pilih Kesimpulan pada form '+r.nama+' ('+r.id+')'); return; }
      const baik = parseInt(rec.jumlah_baik,10) || 0;
      if(f.kesimpulan!=='Baik' && f.jml>baik){ toast('Jumlah unit '+r.nama+' ('+f.jml+') melebihi Jumlah Baik di modul Peralatan ('+baik+')'); return; }
      const bad = f.kesimpulan!=='Baik';
      r.baik = baik - (bad ? f.jml : 0); r.rr = f.kesimpulan==='Butuh Perbaikan' ? f.jml : 0; r.rb = f.kesimpulan==='Rusak Tidak Bisa Diperbaiki' ? f.jml : 0; r.hilang = 0;
      r.ket = String(f.ket||'').trim();
      if(!String(f.diperiksa||'').trim()) f.diperiksa = String(d.petugas||'').trim();
      if(!f.tglPeriksa) f.tglPeriksa = d.tanggal;
    }
  }
  const rows = DB.uji_pemeliharaan || (DB.uji_pemeliharaan = []);
  if(!Array.isArray(DB.sumda_riwayat)) DB.sumda_riwayat = [];
  const now = new Date();
  const sid = uid('SMD', DB.sumda_riwayat);
  // Kendaraan: satu Kondisi -> hitungan Baik/Rusak Ringan/Rusak Berat/Hilang (dipakai tabel riwayat & Dashboard)
  const angka = r => kend ? sumdaKondisiAngka(r.kondisi) : {baik:r.baik, rr:r.rr, rb:r.rb, hilang:r.hilang};
  DB.sumda_riwayat.push(Object.assign({id:sid, jenis:d.jenis, lokasi:d.lokasi || 'Semua Lokasi', tanggal:d.tanggal,
    waktu:String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0'), petugas:String(d.petugas||'').trim(),
    items:d.rows.map(r=>Object.assign({ref:r.id, jenis:r.jenis, nama:r.nama, lokasi:r.lokasi, ket:String(r.ket||'').trim()}, angka(r),
      kend ? {kondisi:r.kondisi, nopol:r.nopol, jenisK:r.jenisK, bersih:r.bersih||'Ya', vals:Object.assign({}, r.vals)} : {kesimpulan:r.form.kesimpulan, jml:r.form.jml, form:JSON.parse(JSON.stringify(r.form))}))},
    kend ? {sapras:d.sapras, tim:String(d.tim||'').trim()} : {}));
  d.rows.forEach(r=>{
    const a = angka(r);
    rows.push({id:uid('UPM', rows), sesi_id:sid, barang_id:r.id, barang_jenis:r.jenis, nama_peralatan:r.nama, lokasi:r.lokasi, tanggal_pemeriksaan:d.tanggal,
      kondisi_baik:a.baik, kondisi_rusak_ringan:a.rr, kondisi_rusak_berat:a.rb, hilang:a.hilang, petugas:d.petugas, keterangan:r.ket,
      tahun_input:now.getFullYear(), bulan_input:now.getMonth()+1, tanggal_input:now.toLocaleDateString('sv-SE'),
      diperbarui_oleh:pelaku(), diperbarui_waktu:nowStr()});
  });
  sumdaTerapkanKeModul(d);
  persist();
  toast(d.rows.length + ' data pemeliharaan disimpan, modul ' + (kend ? 'Kendaraan' : 'Peralatan') + ' ikut diperbarui');
  d.rows = [];
  render(currentView);
}
function renderGenericTable(key, fields, label, restricted){
  if(key==='uji_pemeliharaan'){ window.__lastFields = fields; return renderSumda(); }
  if(key==='pemeliharaan') return renderPengajuan();
  if(key==='pembinaan' && pembinaanTab==='Absensi') return renderPbnAbsensi(label, restricted);
  if(key==='pembinaan' && pembinaanTab==='IMT') return renderPbnImt(label, restricted);
  const rows = key==='pembinaan' ? (DB[key] || []).filter(r=>pbnKat(r)===pembinaanTab) : (DB[key] || []);
  const q = (search[key]||'').toLowerCase();
  const periodeOn = key!=='anggota' && key!=='rescuer';
  const bySearch = q ? rows.filter(r => matchSearch(r, q, fields)) : rows;
  const isSmp = key==='pembinaan' && pembinaanTab==='Samapta';
  const byPeriode = isSmp ? bySearch.filter(pbnSamaptaInRange) : (periodeOn ? bySearch.filter(r=>inPeriode(r, key)) : bySearch);
  const filtered = key==='peralatan' ? byPeriode.filter(peralatanCocok)
    : (key==='kendaraan' ? byPeriode.filter(saprasCocok) : byPeriode);
  // Kendaraan + filter Sapras Laut: tampilkan juga kolom data khusus laut (Endurance, Speed, dst.)
  const cols = key==='pembinaan'
    ? pbnTabCols(pembinaanTab)
    : fields.filter(f=>f.col || (key==='kendaraan' && saprasFilter==='Sapras Laut' && f.laut) || (key==='kendaraan' && saprasFilter==='Sapras Darat' && f.darat));
  const canEdit = key==='rescuer' ? false : (modulReadOnly(key) ? false : (!restricted || isApprover()));
  const pmjAksiTampil = key==='peminjaman' && !modulReadOnly(key) && canAccPeminjaman() && rows.some(r=>pmjSP(r)==='Menunggu Persetujuan');
  // Tombol "+ Tambah" hanya tersedia di modul berikut (Kegiatan punya halaman sendiri)
  const ADD_ALLOWED = ['anggota','potensi','uji_periodik','uji_pemeliharaan','lainnya','peralatan','kendaraan','peminjaman','pemeliharaan'];
  // Pembinaan Tenaga SAR: tab "Pembinaan" terisi dari Kegiatan/Tugas; tab Pembelajaran & Samapta diinput langsung.
  const canAdd = canEdit && key!=='uji_pemeliharaan' && (ADD_ALLOWED.includes(key) || key==='pembinaan');
  const addLabel = key==='pembinaan' ? pembinaanTab : label;
  const bulkOn = canEdit && !isAnggotaOnly() && BULK_DELETE_MODULES.includes(key); // checkbox hapus massal disembunyikan untuk role Anggota
  if(bulkOn){
    bulkVisible[key] = filtered.map(r=>r.id);
    const ex = new Set((DB[key]||[]).map(r=>r.id));
    bulkSel[key] = new Set([...(bulkSel[key]||[])].filter(id=>ex.has(id)));
  }

  if(key==='rescuer'){
    // Rescuer menampilkan SEMUA anggota dari Daftar Anggota (bukan dataset terpisah),
    // lengkap dengan jumlah keikutsertaan (Samapta, Uji Periodik, Diklat) dan penugasan kegiatan per tahun.
    const anggotaRows = DB.anggota || [];
    const filteredAnggota = q ? anggotaRows.filter(a => matchSearch(a, q)) : anggotaRows;
    const nIkut = RESCUER_IKUT_COLS.length, nJenis = RESCUER_JENIS_COLS.length;
    const rerender = `renderGenericTable('${key}', window.__lastFields, '${esc(label)}', ${!!restricted})`;
    document.getElementById('app').innerHTML = `
      <div class="panel rounded-xl p-4">
        <div class="flex flex-wrap items-center gap-2 mb-4">
          <input type="text" placeholder="Cari data ${esc(label)}..." value="${esc(search[key]||'')}" class="!w-56" data-sid="search-${key}" oninput="runSearchRender(this, ()=>{ search['${key}']=this.value; ${rerender}; })">
          <select style="width:auto;" aria-label="Tahun" onchange="rescuerTahun=this.value; ${rerender};">
            <option value="all" ${rescuerTahun==='all'?'selected':''}>Semua Tahun</option>
            ${rcTahunList().map(y=>`<option value="${y}" ${rescuerTahun===y?'selected':''}>${y}</option>`).join('')}
          </select>
          <span class="text-xs ml-1" style="color:var(--muted);">${filteredAnggota.length} data</span>
          <span class="ml-auto text-xs badge b-info">Lihat saja</span>
        </div>
        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>NIP/ID</th>
                ${RESCUER_IKUT_COLS.map(c=>`<th class="text-center">${esc(c.label)}</th>`).join('')}
                ${RESCUER_JENIS_COLS.map(c=>`<th class="text-center">${esc(c.label)}</th>`).join('')}
              </tr>
            </thead>
            <tbody>
              ${filteredAnggota.length ? filteredAnggota.map(a=>`
                <tr style="cursor:pointer;" onclick="openGenericDetail('anggota','${a.id}')">
                  <td class="font-medium whitespace-nowrap">${esc(a.nama)}</td>
                  <td class="whitespace-nowrap">${esc(a.nip)}</td>
                  ${RESCUER_IKUT_COLS.map(c=>rescuerIkutCellHtml(a, c)).join('')}
                  ${RESCUER_JENIS_COLS.map(c=>`<td class="text-center">${getAnggotaKegiatanCountByJenis(a.id, c.key)}</td>`).join('')}
                </tr>`).join('') : `<tr><td colspan="${2+nIkut+nJenis}" class="text-center py-6" style="color:var(--muted);">Belum ada data.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>`;
    window.__lastFields = fields;
    enhanceTableScroll();
    return;
  }

  document.getElementById('app').innerHTML = `
    ${key==='uji_pemeliharaan' ? sumdaFormHtml() : ''}
    <div class="panel rounded-xl p-4">
      ${key==='kendaraan' ? saprasButtonsHtml() : ''}
      ${key==='pembinaan' ? pembinaanTabHtml() : ''}
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <input type="text" placeholder="Cari data ${esc(label)}..." value="${esc(search[key]||'')}" class="!w-56" data-sid="search-${key}" oninput="runSearchRender(this, ()=>{ search['${key}']=this.value; renderGenericTable('${key}', window.__lastFields, '${esc(label)}', ${!!restricted}); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${filtered.length} data</span>
        ${isSmp ? pbnSamaptaRangeHtml() : (periodeOn ? periodeSelectHtml(key) : '')}
        ${key==='peralatan' ? peralatanFilterHtml() : ''}
        ${key==='pembinaan' && pembinaanTab==='Samapta' ? `<div class="dd ml-auto">
          <button type="button" class="btn btn-ghost" onclick="toggleUnduhMenu(event, this)">Unduh Laporan <span aria-hidden="true">&#9662;</span></button>
          <div class="dd-menu">
            <button type="button" class="dd-item" onclick="exportSamapta('pdf')">PDF</button>
            <button type="button" class="dd-item" onclick="exportSamapta('excel')">Excel</button>
          </div>
        </div>
        <button type="button" class="btn btn-ghost" title="Kirim laporan Kesamaptaan ke WA Grup" onclick="openWaSamapta()">Kirim WA Grup</button>` : ''}
        ${key==='uji_periodik' && isSuper() ? ujiImportBtnHtml() : ''}
        ${bulkOn ? `<button type="button" id="bulkDelBtn" class="btn btn-ghost" style="display:none;color:var(--bad);" onclick="deleteBulk('${key}','${esc(label)}')">Hapus Terpilih (0)</button>` : ''}
        ${(['anggota','pembinaan','potensi','narasumber','peralatan','kendaraan','peminjaman'].includes(key) && !(key==='pembinaan' && pembinaanTab==='Absensi')) ? impBtnHtml(key, (isSmp || !canAdd) ? '' : 'ml-auto') : ''}
        ${canAdd ? `<button class="btn btn-primary ${(isSmp || (key==='uji_periodik' && isSuper()) || (canImportExcel(key) && ['anggota','pembinaan','potensi','narasumber','peralatan','kendaraan','peminjaman'].includes(key))) ? '' : 'ml-auto'}" onclick="openGenericForm('${key}')">+ Tambah ${esc(addLabel)}</button>` : (canEdit ? '' :`<span class="ml-auto text-xs badge b-info">Lihat saja</span>`)}
      </div>
      <div class="tablewrap">
        <table>
          <thead><tr>
            ${bulkOn ? `<th style="width:2.2rem;text-align:center;"><input type="checkbox" id="bulkAllCb" title="Pilih semua data" onchange="toggleBulkAll('${key}',this.checked)"></th>` : ''}
            ${key!=='anggota' && key!=='rescuer' && key!=='uji_pemeliharaan' ? '<th>ID</th>' : ''}
            ${cols.filter(c=>!c.isStatus).map(c=>`<th>${esc(key==='kendaraan' ? labelKendaraan(c, saprasFilter==='Sapras Laut') : (key==='pembinaan' ? pbnLabel(c, pembinaanTab) : c.label))}</th>`).join('')}
            ${fields.some(f=>f.isStatus) && !(key==='pembinaan' && pembinaanTab==='Samapta') ? '<th>Status</th>' : ''}
            ${key==='anggota' ? '<th>Kegiatan Diikuti</th>' : ''}
            ${(canEdit || pmjAksiTampil) ? '<th>Aksi</th>' : ''}
          </tr></thead>
          <tbody>
            ${filtered.length ? filtered.slice().reverse().map(r=>`
              <tr style="cursor:pointer;" onclick="openGenericDetail('${key}','${r.id}')">
                ${bulkOn ? `<td style="text-align:center;" onclick="event.stopPropagation();"><input type="checkbox" class="bulkCb" data-id="${esc(r.id)}" ${(bulkSel[key]&&bulkSel[key].has(r.id)) ? 'checked' : ''} onchange="toggleBulkRow('${key}',this.dataset.id,this.checked)"></td>` : ''}
                ${key!=='anggota' && key!=='rescuer' && key!=='uji_pemeliharaan' ? `<td class="font-medium">${esc(r.id)}</td>` : ''}
                ${cols.filter(c=>!c.isStatus).map(c=>`<td>${cellHtml(key,c,r)}</td>`).join('')}
                ${fields.some(f=>f.isStatus) && !(key==='pembinaan' && pembinaanTab==='Samapta') ? `<td>${statusBadge(statusFieldValue(key, fields.find(f=>f.isStatus), r))}${key==='peminjaman' ? pmjStatusExtraHtml(r) : ''}</td>` : ''}
                ${key==='anggota' ? `<td class="text-center font-semibold">${getAnggotaKegiatanCount(r.id)}</td>` : ''}
                ${(canEdit || pmjAksiTampil) ? `<td class="whitespace-nowrap" onclick="event.stopPropagation();">
                  <div style="display:flex; flex-direction:column; align-items:flex-start; gap:.35rem;">
                    ${key==='peminjaman' ? pmjAksiHtml(r) : ''}
                    ${canEdit ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openGenericForm('${key}','${r.id}')">Ubah</button>
                    <button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteGeneric('${key}','${r.id}','${esc(label)}')">Hapus</button>` : ''}
                  </div>
                </td>` : ''}
              </tr>`).join('') : `<tr><td colspan="10" class="text-center py-6" style="color:var(--muted);">Belum ada data.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>
    ${key==='uji_periodik' && !modulReadOnly(key) ? ujiKategoriCardsHtml() : ''}
    ${PESERTA_MODULES[key] ? diklatMainPanelHtml(key) : ''}`;
  window.__lastFields = fields;
  enhanceTableScroll();
  if(bulkOn) bulkRefreshUI(key);
}

/* ---- Hapus massal (checkbox pilih data + pilih semua) ---- */
const BULK_DELETE_MODULES = ['peralatan','kendaraan'];
const bulkSel = {}, bulkVisible = {};
function bulkRefreshUI(key){
  const sel = bulkSel[key] || new Set(), vis = bulkVisible[key] || [];
  const n = vis.filter(id=>sel.has(id)).length;
  const btn = document.getElementById('bulkDelBtn');
  if(btn){ btn.style.display = n ? '' : 'none'; btn.textContent = 'Hapus Terpilih (' + n + ')'; }
  const all = document.getElementById('bulkAllCb');
  if(all){ all.checked = vis.length>0 && n===vis.length; all.indeterminate = n>0 && n<vis.length; }
  document.querySelectorAll('.bulkCb').forEach(cb=>{ cb.checked = sel.has(cb.dataset.id); });
}
function toggleBulkRow(key, id, on){
  if(guardRO(key)) { bulkRefreshUI(key); return; }
  const s = bulkSel[key] || (bulkSel[key] = new Set());
  if(on) s.add(id); else s.delete(id);
  bulkRefreshUI(key);
}
function toggleBulkAll(key, on){
  if(guardRO(key)) { bulkRefreshUI(key); return; }
  const s = bulkSel[key] || (bulkSel[key] = new Set());
  (bulkVisible[key]||[]).forEach(id=>{ if(on) s.add(id); else s.delete(id); });
  bulkRefreshUI(key);
}
function deleteBulk(key, label){
  if(guardRO(key) || isAnggotaOnly()) return;
  const sel = bulkSel[key] || new Set();
  const ids = (bulkVisible[key]||[]).filter(id=>sel.has(id));
  if(!ids.length){ toast('Belum ada data yang dipilih'); return; }
  const idSet = new Set(ids);
  const recs = (DB[key]||[]).filter(r=>idSet.has(r.id));
  const terkaitAll = new Map();
  recs.forEach(r=>kegiatanTerkaitRecord(key, r).forEach(k=>terkaitAll.set(k.id, k)));
  const semua = ids.length === (DB[key]||[]).length;
  let pesan = `Hapus ${ids.length} data ${label}${semua ? ' (SELURUH data)' : ''}?\n\nTindakan ini tidak dapat dibatalkan.`;
  if(terkaitAll.size) pesan += `\n\nKegiatan/Tugas terkait (${[...terkaitAll.keys()].join(', ')}) juga akan ikut terhapus.`;
  if(!confirm(pesan)) return;
  DB[key] = (DB[key]||[]).filter(r=>!idSet.has(r.id));
  if(terkaitAll.size){
    const hapusIds = new Set(terkaitAll.keys());
    (DB.kegiatan||[]).filter(k=>hapusIds.has(k.id)).forEach(k=>{ (k.hasil_files||[]).forEach(f=>fdbDel(f.fid)); (k.bukti_files||[]).forEach(f=>fdbDel(f.fid)); lampiranWaList(k).forEach(f=>fdbDel(f.fid)); if(k.sprin) fdbDel(k.sprin.fid); });
    DB.kegiatan = (DB.kegiatan||[]).filter(k=>!hapusIds.has(k.id));
  }
  bulkSel[key] = new Set();
  persist();
  toast(ids.length + ' data dihapus');
  render(currentView);
}

/* ============================= PENGAJUAN (Kebutuhan & Pemeliharaan) =============================
   Pengajuan dibuat siapa saja, lalu di-ACC / ditolak oleh KKS, KSO, KSD, Admin, atau Admin Utama.
   Pemeliharaan baru berpengaruh ke stok Peralatan / kondisi Kendaraan setelah Disetujui. */
const PENGAJUAN_TABS = ['Kebutuhan','Pemeliharaan'];
const PENGAJUAN_ACC_ROLES = ['KKS','KSO','KSD','Admin'];
let pengajuanTab = 'Kebutuhan', pengajuanKatForm = 'Kebutuhan';
function pgjKat(r){ return (r && r.kategori_pengajuan) || 'Pemeliharaan'; }
function pgjSP(r){ return (r && r.status_persetujuan) || 'Disetujui'; }
function pgjLabel(f, kat){ return (kat==='Kebutuhan' && f.labelK) ? f.labelK : f.label; }
function pgjAktif(r){ return pgjKat(r)==='Pemeliharaan' && pgjSP(r)==='Disetujui'; }
function canAccPengajuan(){ return isSuper() || PENGAJUAN_ACC_ROLES.includes(currentRole); }
function canManagePengajuan(r){ return !r || pgjSP(r)!=='Disetujui' || canAccPengajuan(); }
/* ---- Peminjaman: ACC / Tolak ----
   Peminjaman baru berstatus "Menunggu"; penyetuju (Firman) memutuskan lewat WhatsApp -> "Dipinjam" / "Ditolak" (+ alasan).
   Dashboard juga bisa ACC/Tolak selama belum diputuskan; keputusan pertama (WhatsApp atau dashboard) final.
   Data lama / hasil Import Excel tanpa status_persetujuan dianggap sudah disetujui. */
function pmjSP(r){ return (r && r.status_persetujuan) || 'Disetujui'; }
function canAccPeminjaman(){ return canAccPengajuan(); }
function pmjStatusExtraHtml(r){
  const sp = pmjSP(r);
  if(sp==='Ditolak') return r.alasan_penolakan ? `<div class="text-[11px]" style="color:var(--muted);">Alasan: ${esc(r.alasan_penolakan)}</div>` : '';
  if(sp==='Disetujui' && r.acc_nama) return `<div class="text-[11px]" style="color:var(--muted);">ACC ${esc(r.acc_nama)}${r.acc_via ? ' · via ' + esc(r.acc_via) : ''}</div>`;
  if(sp==='Menunggu Persetujuan') return `<div class="text-[11px]" style="color:var(--muted);">Menunggu ACC</div>`;
  return '';
}
function pmjAksiHtml(r){
  if(pmjSP(r)!=='Menunggu Persetujuan' || !canAccPeminjaman()) return '';
  return `<div class="flex gap-1"><button class="btn btn-ok !py-1 !px-2 text-xs" onclick="accPeminjaman('${r.id}')">ACC</button><button class="btn btn-bad !py-1 !px-2 text-xs" onclick="tolakPeminjaman('${r.id}')">Tolak</button></div>`;
}
function peminjamanApprovalHtml(row){
  if(!row.status_persetujuan) return ''; // data lama / Import Excel: tidak melalui persetujuan
  const sp = pmjSP(row);
  return `<div class="sm:col-span-2 rounded-lg p-3" style="background:var(--bg);">
    <div class="text-xs font-medium mb-1" style="color:var(--muted);">Persetujuan Peminjaman</div>
    <div class="flex flex-wrap items-center gap-2">${statusBadge(sp)}<span class="text-xs" style="color:var(--muted);">Diajukan oleh ${row.diajukan_oleh ? esc(pelakuTeks(row.diajukan_oleh)) : '-'}${row.diajukan_waktu ? ' · ' + esc(row.diajukan_waktu) : ''}</span></div>
    ${row.keterangan_persetujuan ? `<p class="text-xs mt-2">${esc(row.keterangan_persetujuan)}${row.acc_via ? ' (via ' + esc(row.acc_via) + ')' : ' (via Dashboard)'}</p>` : ''}
    ${sp==='Ditolak' && row.alasan_penolakan ? `<p class="text-xs mt-1"><b>Alasan ditolak:</b> ${esc(row.alasan_penolakan)}</p><p class="text-[11px] mt-1" style="color:var(--muted);">Klik "Ubah Data" lalu simpan untuk mengajukan ulang.</p>` : ''}
    ${sp==='Menunggu Persetujuan' ? (canAccPeminjaman()
      ? `<div class="flex gap-2 mt-3"><button class="btn btn-ok" onclick="accPeminjaman('${row.id}')">ACC</button><button class="btn btn-bad" onclick="tolakPeminjaman('${row.id}')">Tolak</button></div><p class="text-[11px] mt-2" style="color:var(--muted);">Penyetuju peminjaman juga dapat memutuskan lewat WhatsApp. Keputusan pertama (dashboard atau WhatsApp) bersifat final.</p>`
      : `<p class="text-[11px] mt-2" style="color:var(--muted);">Menunggu ACC penyetuju peminjaman (lewat WhatsApp) atau ${PENGAJUAN_ACC_ROLES.join(' / ')} / Admin Utama di dashboard.</p>`) : ''}
  </div>`;
}
function accPeminjaman(id){
  const r = (DB.peminjaman||[]).find(x=>x.id===id);
  if(!r || pmjSP(r)!=='Menunggu Persetujuan'){ toast('Status persetujuan sudah final dan tidak dapat diubah'); return; }
  if(!canAccPeminjaman()){ toast('Hanya ' + PENGAJUAN_ACC_ROLES.join('/') + ' atau Admin Utama yang dapat ACC'); return; }
  r.status_persetujuan = 'Disetujui'; r.status = 'Dipinjam';
  r.acc_oleh = currentRole; r.acc_nama = currentUserName; r.acc_waktu = nowStr(); delete r.acc_via;
  r.keterangan_persetujuan = `Disetujui oleh ${currentRole} (${currentUserName}) pada ${nowStr()}.`;
  r.alasan_penolakan = '';
  r.diperbarui_oleh = pelaku(); r.diperbarui_waktu = nowStr();
  persist();
  toast('Peminjaman ' + id + ' disetujui — status Dipinjam');
  closeModal(); render('peminjaman');
}
function tolakPeminjaman(id){
  const r = (DB.peminjaman||[]).find(x=>x.id===id);
  if(!r || pmjSP(r)!=='Menunggu Persetujuan'){ toast('Status persetujuan sudah final dan tidak dapat diubah'); return; }
  if(!canAccPeminjaman()){ toast('Hanya ' + PENGAJUAN_ACC_ROLES.join('/') + ' atau Admin Utama yang dapat menolak'); return; }
  const alasan = prompt('Alasan penolakan (wajib diisi):');
  if(alasan===null) return;
  if(!alasan.trim()){ toast('Alasan penolakan wajib diisi'); return; }
  r.status_persetujuan = 'Ditolak'; r.status = 'Ditolak';
  r.alasan_penolakan = alasan.trim();
  r.acc_oleh = currentRole; r.acc_nama = currentUserName; r.acc_waktu = nowStr(); delete r.acc_via;
  r.keterangan_persetujuan = `Ditolak oleh ${currentRole} (${currentUserName}) pada ${nowStr()}: ${r.alasan_penolakan}`;
  r.diperbarui_oleh = pelaku(); r.diperbarui_waktu = nowStr();
  persist();
  toast('Peminjaman ' + id + ' ditolak');
  closeModal(); render('peminjaman');
}
/* ---- Pembinaan Tenaga SAR: tab Pembinaan / Pembelajaran / Samapta ---- */
let pembinaanTab = 'Pembelajaran', pembinaanKatForm = 'Pembelajaran';
function pbnMeta(){ return TABLE_MODULES.find(m=>m.key==='pembinaan'); }
function pbnKat(r){ const k = r && r.kategori_pembinaan; return pbnMeta().tabs.includes(k) ? k : 'Pembelajaran'; }
function pbnLabel(f, kat){ return (f.labels && f.labels[kat]) || f.label; }
function pbnTabCols(tab){ const m = pbnMeta(); return (m.tabCols[tab]||[]).map(k=>m.fields.find(f=>f.key===k)).filter(Boolean); }
function pbnKatOk(f, kat){ return !f.kat || [].concat(f.kat).includes(kat); }
function pembinaanTabHtml(){
  const rows = DB.pembinaan || [];
  // Tab IMT (di antara Samapta dan Absensi) bukan kategori data Pembinaan, jadi tidak masuk pbnMeta().tabs
  const tabs = pbnMeta().tabs.slice(), iSmp = tabs.indexOf('Samapta');
  tabs.splice(iSmp>=0 ? iSmp+1 : tabs.length, 0, 'IMT');
  return `<div class="flex flex-wrap gap-2 mb-4">${tabs.concat('Absensi').map(t=>{
    const n = t==='Absensi' ? (DB.pembinaan_absensi||[]).length : (t==='IMT' ? pbImtTerisiCount() : rows.filter(r=>pbnKat(r)===t).length);
    return `<button class="btn ${pembinaanTab===t?'btn-primary':'btn-ghost'}" onclick="pembinaanTab='${t}'; render('pembinaan')">${t} <span class="badge b-info">${n} data</span></button>`;
  }).join('')}</div>`;
}
/* ---- Unduh Laporan tab Samapta (PDF / Excel), mengikuti pencarian + filter Bulan/Tahun yang aktif ---- */
/* Filter rentang tanggal tab Samapta (Dari - Sampai); menggantikan filter Bulan/Tahun di tab ini */
let pbnSmpRange = {from:'', to:''};
function pbnSamaptaInRange(r){
  const g = pbnSmpRange; if(!g.from && !g.to) return true;
  const t = /^\d{4}-\d{2}-\d{2}/.test(String(r.tanggal||'')) ? String(r.tanggal).slice(0,10) : '';
  if(!t) return false;
  return (!g.from || t >= g.from) && (!g.to || t <= g.to);
}
function pbnSamaptaRangeHtml(){
  const g = pbnSmpRange;
  return `<span class="inline-flex items-center gap-1.5 flex-wrap">
    <span class="text-xs" style="color:var(--muted);">Dari</span>
    <input type="date" style="width:auto;" aria-label="Dari tanggal" value="${esc(g.from)}" ${g.to?`max="${esc(g.to)}"`:''} onchange="setPbnSmpRange('from',this.value)">
    <span class="text-xs" style="color:var(--muted);">sampai</span>
    <input type="date" style="width:auto;" aria-label="Sampai tanggal" value="${esc(g.to)}" ${g.from?`min="${esc(g.from)}"`:''} onchange="setPbnSmpRange('to',this.value)">
    ${(g.from||g.to) ? `<button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" onclick="resetPbnSmpRange()">Reset</button>` : ''}
  </span>`;
}
function setPbnSmpRange(field, val){
  const g = pbnSmpRange; g[field] = val || '';
  if(g.from && g.to && g.from > g.to){ const t = g.from; g.from = g.to; g.to = t; }
  render('pembinaan');
}
function resetPbnSmpRange(){ pbnSmpRange = {from:'', to:''}; render('pembinaan'); }
function pbnSamaptaRows(){
  const key = 'pembinaan';
  const q = (search[key]||'').toLowerCase();
  let rows = (DB.pembinaan||[]).filter(r=>pbnKat(r)==='Samapta');
  if(q) rows = rows.filter(r=>matchSearch(r, q, pbnMeta().fields));
  rows = rows.filter(pbnSamaptaInRange);
  return rows.slice().sort((a,b)=>String(a.tanggal||'').localeCompare(String(b.tanggal||'')));
}
function pbnSamaptaJumlah(r){ return parseInt(r.jumlah_peserta,10) || pbnPesertaList(r).length; }
function pbnSamaptaDokTeks(r){
  const fl = Array.isArray(r.dokumentasi_files) ? r.dokumentasi_files.map(f=>f.name).filter(Boolean) : [];
  return fl.concat(r.dokumentasi ? [r.dokumentasi] : []).join(', ');
}
/* ============ Laporan WA Grup — Pembinaan Tenaga SAR (Samapta / Kesamaptaan Rutin) ============
   Menyusun pesan laporan dari data Samapta pada satu tanggal (lokasi, rangkaian kegiatan, jumlah peserta per lokasi),
   ditampilkan dulu agar bisa diperiksa/diedit, lalu dikirim lewat WhatsApp (pilih grup tujuan di WhatsApp). */
const WA_HARI = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const WA_LOKASI = [ // [kata kunci, nama tampil] — urutan = urutan di laporan
  ['JAKARTA','Kantor SAR Jakarta'], ['SUKABUMI','Pos SAR Sukabumi'], ['SERIBU','Pos SAR Kepulauan 1000'],
  ['BEKASI','Unit Siaga SAR Bekasi'], ['BOGOR','Unit Siaga SAR Bogor'], ['DERMAGA','Dermaga Pantai Mutiara'],
  ['GANESHA','KN SAR Ganesha'], ['ANTASENA','KN SAR Antasena'],
];
function waTglPanjang(iso){
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso||''));
  if(!m) return '';
  const d = new Date(+m[1], +m[2]-1, +m[3]);
  return WA_HARI[d.getDay()] + ', ' + (+m[3]) + ' ' + BULAN_NAMA[+m[2]-1] + ' ' + m[1];
}
function waLokIdx(l){
  const s = normLabel(l);
  let i = WA_LOKASI.findIndex(x=>s.includes(x[0]));
  if(i<0 && (s.includes('KPP') || s.includes('KANTOR'))) i = 0;
  if(i<0 && (s.includes('KEP') || s.includes('1000'))) i = 2;
  if(i<0 && s.includes('USS')) i = -1;
  return i;
}
function waLokNama(l){ const i = waLokIdx(l); return i>=0 ? WA_LOKASI[i][1] : String(l||'').trim(); }
function waSmpCfg(){ if(!DB.wa_samapta) DB.wa_samapta = {absensi:'', evidence:'', ttd:'KKS - JKT'}; return DB.wa_samapta; }
function waSmpRows(iso){ return (DB.pembinaan||[]).filter(r=>pbnKat(r)==='Samapta' && String(r.tanggal||'').slice(0,10)===iso); }
function waSmpPesan(iso, cfg){
  const rows = waSmpRows(iso);
  if(!rows.length) return '';
  // lokasi + jumlah peserta per lokasi
  const per = {}; rows.forEach(r=>{ const n = waLokNama(r.lokasi||'') || '-'; per[n] = (per[n]||0) + pbnSamaptaJumlah(r); });
  const urut = n => { const i = WA_LOKASI.findIndex(x=>x[1]===n); return i<0 ? 99 : i; };
  const lokasi = Object.keys(per).sort((a,b)=>urut(a)-urut(b));
  const total = lokasi.reduce((t,n)=>t+per[n], 0);
  // rangkaian kegiatan: gabungan Materi / Program Latihan (dipisah baris, titik koma, atau koma), tanpa duplikat
  const seen = new Set(), materi = [];
  rows.forEach(r=>String(r.materi||'').split(/[\n;,]+/).map(s=>s.replace(/^\s*\d+[.)]\s*/, '').trim()).filter(Boolean).forEach(m=>{
    const k = normNama(m); if(!seen.has(k)){ seen.add(k); materi.push(m); }
  }));
  // evidence: isian modal; bila kosong ambil tautan dokumentasi data Samapta
  let evidence = String(cfg.evidence||'').trim();
  if(!evidence) evidence = Array.from(new Set(rows.map(r=>String(r.dokumentasi||'').trim()).filter(d=>/^https?:\/\//i.test(d)))).join('\n');
  const L = [
    '*SALAM QUICK ACTION*', '*WE ARE FAMILY*', '*PROFESIONAL-MODERN-TERUJI*', '',
    'KANTOR PENCARIAN DAN PERTOLONGAN KELAS A JAKARTA',
    waTglPanjang(iso), '',
    'Yth : *Kabasarnas*', 'Cc :  Pimti Madya & Pratama', '',
    'Mohon izin Melaporkan Kegiatan *Kesamaptaan Rutin* Pegawai Kantor Pencarian dan Pertolongan Jakarta.', '',
    '📍*Lokasi Kegiatan :*'
  ].concat(lokasi.map(n=>'> - '+n)).concat(['', '📋*Rangkaian Kegiatan:*', 'Kesamaptaan'])
   .concat(materi.length ? materi.map((m,i)=>'> '+(i+1)+'. '+m) : ['> -'])
   .concat(['', '', '*PESERTA KEGIATAN:*', total+' Tenaga SAR', 'terdiri dari :'])
   .concat(lokasi.map(n=>'* '+n+' - '+per[n]+' Orang'))
   .concat(['', '', 'KETERANGAN', 'Absensi:', String(cfg.absensi||'').trim() || '-', '', 'Evidence:', evidence || '-', '',
     'TTD', String(cfg.ttd||'').trim() || 'KKS - JKT', '',
     '=========================',
     'KANTOR PENCARIAN DAN PERTOLONGAN JAKARTA',
     '🏪Alamat Kantor : Jl. Marsekal Suryadarma No. 115 RT. 006/RW.003, Selapajang Jaya, Kec. Neglasari, Kota Tangerang, Banten 15127',
     '☎️Telp. : (021) 55727115',
     '📠Fax.  : (021) 55726667, (021) 5576672',
     '📱🪀    : 08111350115',
     '🆘Emergency Call : 115,',
     '🌐Website : [www.jakarta.basarnas.go.id](https://www.jakarta.basarnas.go.id)',
     '📬E-mail : siaga.komunikasi.jakarta@gmail.com, kansar.jkt@gmail.com',
     'Instagram : @sar.jakarta']);
  return L.join('\n');
}
function waSamaptaBtnHtml(){
  return `<button type="button" class="btn btn-ghost !py-1 !px-2 text-xs" title="Kirim laporan Kesamaptaan ke WA Grup" onclick="openWaSamapta()">Kirim WA</button>`;
}
function openWaSamapta(){
  const smp = (DB.pembinaan||[]).filter(r=>pbnKat(r)==='Samapta' && /^\d{4}-\d{2}-\d{2}/.test(String(r.tanggal||'')));
  if(!smp.length){ toast('Belum ada data Samapta untuk dilaporkan'); return; }
  const tgls = Array.from(new Set(smp.map(r=>String(r.tanggal).slice(0,10)))).sort();
  const g = (typeof dashRange!=='undefined' && dashRange.pembinaan) || {};
  let tgl = tgls[tgls.length-1];
  if(g.from){ const x = tgls.find(t=>t>=g.from && (!g.to || t<=g.to)); if(x) tgl = x; }
  const cfg = waSmpCfg();
  openModal(`
    <div class="p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-3">
        <h3 class="font-display font-semibold text-base">Kirim Laporan Kesamaptaan ke WA Grup</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid gap-2 mb-3" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
        <label class="text-xs">Tanggal kegiatan<input type="date" id="wa_smp_tgl" value="${esc(tgl)}" oninput="waSmpRefresh()"></label>
        <label class="text-xs">TTD<input type="text" id="wa_smp_ttd" value="${esc(cfg.ttd)}" oninput="waSmpRefresh()" onchange="waSmpSimpan()"></label>
        <label class="text-xs">Link Absensi<input type="text" id="wa_smp_abs" value="${esc(cfg.absensi)}" placeholder="https://drive.google.com/..." oninput="waSmpRefresh()" onchange="waSmpSimpan()"></label>
        <label class="text-xs">Link Evidence<input type="text" id="wa_smp_evd" value="${esc(cfg.evidence)}" placeholder="Kosong = ambil dari Dokumentasi Samapta" oninput="waSmpRefresh()" onchange="waSmpSimpan()"></label>
      </div>
      <p class="text-xs mb-1" style="color:var(--muted);">Pesan disusun otomatis dari data Samapta pada tanggal tersebut. Boleh diedit di bawah sebelum dikirim (mengubah tanggal/link akan menyusun ulang pesan).</p>
      <textarea id="wa_smp_txt" rows="16" style="width:100%; font-size:12px; font-family:ui-monospace,Menlo,Consolas,monospace;"></textarea>
      <div class="flex flex-wrap justify-end gap-2 mt-3">
        <button class="btn btn-ghost" onclick="closeModal()">Tutup</button>
        <button class="btn btn-ghost" onclick="waSmpSalin()">Salin</button>
        <button class="btn btn-primary" onclick="waSmpKirim()">Kirim ke WA Grup</button>
      </div>
    </div>`, true);
  waSmpRefresh();
}
function waSmpSimpan(){ persist(); }
function waSmpRefresh(){
  const v = id => (document.getElementById(id)||{}).value || '';
  const cfg = waSmpCfg();
  cfg.ttd = v('wa_smp_ttd'); cfg.absensi = v('wa_smp_abs'); cfg.evidence = v('wa_smp_evd');
  const ta = document.getElementById('wa_smp_txt'); if(!ta) return;
  const pesan = waSmpPesan(v('wa_smp_tgl'), cfg);
  ta.value = pesan || 'Tidak ada data Samapta pada tanggal tersebut.';
  ta.dataset.ok = pesan ? '1' : '';
}
function waSmpKirim(){
  const ta = document.getElementById('wa_smp_txt');
  if(!ta || !ta.dataset.ok){ toast('Tidak ada data Samapta pada tanggal tersebut'); return; }
  persist();
  const w = window.open('https://wa.me/?text=' + encodeURIComponent(ta.value), '_blank');
  if(!w) toast('Pop-up diblokir browser, izinkan pop-up lalu coba lagi');
  else toast('WhatsApp dibuka, pilih WA Grup tujuan');
}
function waSmpSalin(){
  const ta = document.getElementById('wa_smp_txt');
  if(!ta || !ta.dataset.ok){ toast('Tidak ada data Samapta pada tanggal tersebut'); return; }
  const ok = () => toast('Pesan disalin');
  if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(ta.value).then(ok, ()=>{ ta.select(); document.execCommand('copy'); ok(); });
  else { ta.select(); document.execCommand('copy'); ok(); }
}

function exportSamapta(fmt){
  document.querySelectorAll('.dd.open').forEach(d=>d.classList.remove('open'));
  const rows = pbnSamaptaRows();
  if(!rows.length){ toast('Belum ada data Samapta untuk diunduh'); return; }
  const g = pbnSmpRange;
  const periode = (g.from||g.to) ? (g.from ? tglDMY(g.from) : 'awal')+' s.d. '+(g.to ? tglDMY(g.to) : 'sekarang') : 'Semua Tanggal';
  const head = ['No','ID','Nama Kegiatan','Materi / Program Latihan','Tanggal','Lokasi','Jumlah Peserta','Nama Anggota','Dokumentasi'];
  const body = rows.map((r,i)=>[
    i+1, r.id||'', r.nama_kegiatan||'', r.materi||'', tglDMY(r.tanggal||''), r.lokasi||'',
    pbnSamaptaJumlah(r), pbnPesertaList(r).join(', '), pbnSamaptaDokTeks(r)
  ]);
  const totalPeserta = rows.reduce((n,r)=>n+pbnSamaptaJumlah(r), 0);
  const ringkas = 'Total kegiatan: '+rows.length+'   |   Total peserta: '+totalPeserta;
  const judul = 'Laporan Pembinaan Tenaga SAR - Samapta';
  const fname = ('Laporan Samapta '+periode).replace(/[\\/:*?"<>|]+/g,'').replace(/\s+/g,'_');
  try{
    if(fmt==='excel'){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet([[judul],['Periode: '+periode],['Dicetak: '+nowStr()],[],head,...body,[],[ringkas]]);
      ws['!cols'] = [5,10,32,30,12,24,10,40,30].map(w=>({wch:w}));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Samapta');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      doc.setFontSize(13); doc.text(judul, 40, 40);
      doc.setFontSize(10); doc.text('Periode: '+periode, 40, 56);
      doc.setFontSize(9); doc.text('Dicetak '+nowStr(), 40, 70);
      doc.autoTable({
        head:[head], body, startY:80,
        styles:{fontSize:8, cellPadding:3, valign:'middle'},
        headStyles:{fillColor:[19,49,92], halign:'center'},
        columnStyles:{0:{halign:'center', cellWidth:24}, 6:{halign:'center', cellWidth:48}}
      });
      const y = (doc.lastAutoTable ? doc.lastAutoTable.finalY : 80) + 18;
      doc.setFontSize(9); doc.text(ringkas, 40, y);
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
/* ---- IMT Pembinaan Tenaga SAR ----
   Daftar nama diambil dari Daftar Anggota. Yang diinput: Jenis Kelamin, Berat Badan (kg), Tinggi Badan (cm).
   Hasil Hitung IMT = BB / (TB dalam meter)^2 dan Keterangan (kategori) terisi otomatis. Disimpan di DB.pembinaan_imt. */
const PBN_IMT_JK = ['Laki-laki','Perempuan'];
let pbImtBulan = '';
function pbImtBulanAktif(){ if(!pbImtBulan) pbImtBulan = todayISO().slice(0,7); return pbImtBulan; }
function pbImtRec(id, buat){
  if(!Array.isArray(DB.pembinaan_imt)) DB.pembinaan_imt = [];
  const b = pbImtBulanAktif();
  let r = DB.pembinaan_imt.find(x=>x.anggota_id===id && x.bulan===b);
  if(!r && buat){ r = {anggota_id:id, bulan:b, jk:'', bb:'', tb:''}; DB.pembinaan_imt.push(r); }
  return r || null;
}
function pbImtNum(v){ const n = parseFloat(String(v==null?'':v).replace(',','.')); return isFinite(n) && n>0 ? n : null; }
// TB dimasukkan dalam cm; bila yang diketik tampak dalam meter (mis. 1,7) otomatis dikonversi.
function pbImtHitung(bb, tb){
  const b = pbImtNum(bb); let t = pbImtNum(tb);
  if(b===null || t===null) return null;
  if(t < 3) t = t*100;
  return b / Math.pow(t/100, 2);
}
function pbImtKet(v){
  if(v===null) return '';
  const r = Math.round(v*10)/10;
  if(r < 18.5) return 'Underweight';
  if(r <= 24.9) return 'Normal';
  if(r <= 29.9) return 'Obese Class I';
  return 'Obese Class II';
}
function pbImtKetBadge(k){
  if(!k) return '<span style="color:var(--muted);">-</span>';
  const cls = {'Normal':'b-ok','Underweight':'b-warn','Obese Class I':'b-warn','Obese Class II':'b-bad'}[k] || 'b-info';
  return `<span class="badge ${cls}">${esc(k)}</span>`;
}
function pbImtFmt(v){ return v===null ? '' : v.toFixed(1).replace('.',','); }
function pbImtTerisiCount(){ const b = pbImtBulanAktif(); return (DB.pembinaan_imt||[]).filter(r=>r.bulan===b && pbImtHitung(r.bb, r.tb)!==null && (DB.anggota||[]).some(a=>a.id===r.anggota_id)).length; }
function pbImtInput(id, field, el){
  const r = pbImtRec(id, true);
  r[field] = field==='jk' ? el.value : String(el.value||'').replace(',','.');
  const v = pbImtHitung(r.bb, r.tb);
  const c = document.getElementById('imt-val-'+id), k = document.getElementById('imt-ket-'+id);
  if(c) c.textContent = v===null ? '-' : pbImtFmt(v);
  if(k) k.innerHTML = pbImtKetBadge(pbImtKet(v));
  const t = document.getElementById('imt-total'); if(t) t.textContent = pbImtTerisiCount();
  persist();
}
function renderPbnImt(label, restricted){
  const canEdit = !restricted || isApprover();
  const q = absNorm(search['pembinaan_imt']||'');
  const all = DB.anggota || [];
  const rows = all.filter(a=>!q || absNorm([a.nama, a.nip, a.jabatan, a.unit].join(' ')).includes(q));
  const lbl = esc(label), rs = !!restricted;
  document.getElementById('app').innerHTML = `
    <div class="panel rounded-xl p-4">
      ${pembinaanTabHtml()}
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari nama anggota..." value="${esc(search['pembinaan_imt']||'')}" class="!w-56" data-sid="search-pembinaan_imt" oninput="runSearchRender(this, ()=>{ search['pembinaan_imt']=this.value; renderPbnImt('${lbl}', ${rs}); })">
        <input type="month" style="width:auto;" aria-label="Periode (bulan)" value="${esc(pbImtBulanAktif())}" onchange="pbImtBulan=this.value||''; renderPbnImt('${lbl}', ${rs})">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} anggota · <span id="imt-total">${pbImtTerisiCount()}</span> sudah terhitung IMT</span>
        <div class="dd ml-auto">
          <button type="button" class="btn btn-ghost" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
          <div class="dd-menu">
            <button type="button" class="dd-item" onclick="exportPbnImt('pdf')">PDF</button>
            <button type="button" class="dd-item" onclick="exportPbnImt('excel')">Excel</button>
          </div>
        </div>
        ${canEdit ? '' : '<span class="text-xs badge b-info">Lihat saja</span>'}
      </div>
      <div class="text-[11px] mb-3" style="color:var(--muted);">Periode: <b>${esc(absBulanLabel(pbImtBulanAktif()))}</b> — isian disimpan per bulan. Daftar nama diambil dari Daftar Anggota. Isi Jenis Kelamin, Berat Badan (kg), dan Tinggi Badan (cm); Hasil Hitung IMT (BB ÷ TB² dalam meter) dan Keterangan terisi otomatis: Underweight &lt; 18,5 · Normal 18,5–24,9 · Obese Class I 25–29,9 · Obese Class II ≥ 30.</div>
      <div class="tablewrap">
        <table>
          <thead><tr><th>No</th><th>Nama</th><th>Jenis Kelamin</th><th>Berat Badan (kg)</th><th>Tinggi Badan (cm)</th><th>Hasil Hitung IMT</th><th>Keterangan</th></tr></thead>
          <tbody>
            ${rows.length ? rows.map((a,i)=>{
              const r = pbImtRec(a.id, false) || {jk:'', bb:'', tb:''};
              const v = pbImtHitung(r.bb, r.tb), id = esc(a.id);
              const dis = canEdit ? '' : 'disabled';
              return `<tr>
                <td>${i+1}</td>
                <td class="font-medium whitespace-nowrap">${esc(a.nama)}</td>
                <td><select style="width:auto;min-width:9rem;" aria-label="Jenis Kelamin ${esc(a.nama)}" ${dis} onchange="pbImtInput('${id}','jk',this)">
                  <option value="">- Pilih -</option>
                  ${PBN_IMT_JK.map(j=>`<option value="${j}" ${r.jk===j?'selected':''}>${j}</option>`).join('')}
                </select></td>
                <td><input type="number" min="0" step="0.1" inputmode="decimal" style="width:7rem;" aria-label="Berat Badan ${esc(a.nama)}" value="${esc(r.bb)}" ${dis} oninput="pbImtInput('${id}','bb',this)"></td>
                <td><input type="number" min="0" step="0.1" inputmode="decimal" style="width:7rem;" aria-label="Tinggi Badan ${esc(a.nama)}" value="${esc(r.tb)}" ${dis} oninput="pbImtInput('${id}','tb',this)"></td>
                <td class="font-medium" id="imt-val-${id}">${v===null ? '-' : pbImtFmt(v)}</td>
                <td id="imt-ket-${id}">${pbImtKetBadge(pbImtKet(v))}</td>
              </tr>`;
            }).join('') : `<tr><td colspan="7" class="text-center py-6" style="color:var(--muted);">Belum ada data anggota.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
  enhanceTableScroll();
}
function pbImtExportRows(){
  const out = [];
  (DB.anggota||[]).forEach(a=>{
    const r = pbImtRec(a.id, false); if(!r) return;
    const v = pbImtHitung(r.bb, r.tb);
    if(!r.jk && v===null) return;
    out.push([a.nama, r.jk||'-', pbImtNum(r.bb)===null?'-':pbImtFmt(pbImtNum(r.bb)), pbImtNum(r.tb)===null?'-':pbImtFmt(pbImtNum(r.tb)), v===null?'-':pbImtFmt(v), pbImtKet(v)||'-']);
  });
  return out;
}
function exportPbnImt(fmt){
  document.querySelectorAll('.dd.open').forEach(x=>x.classList.remove('open'));
  const rows = pbImtExportRows();
  if(!rows.length){ toast('Belum ada data IMT pada bulan ini'); return; }
  const bl = absBulanLabel(pbImtBulanAktif());
  const judul = ['REKAPITULASI PENGUKURAN INDEKS MASSA TUBUH (IMT)', 'KANTOR PENCARIAN DAN PERTOLONGAN KELAS A JAKARTA', 'BULAN '+bl];
  const head = ['NO','NAMA','JENIS KELAMIN','BERAT BADAN (KG)','TINGGI BADAN (CM)','HASIL HITUNG IMT','KETERANGAN'];
  const body = rows.map((r,i)=>[i+1].concat(r));
  const fname = ('IMT '+bl).replace(/\s+/g,'_');
  try{
    if(fmt==='excel'){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const ws = XLSX.utils.aoa_to_sheet(judul.map(x=>[x]).concat([[]], [head], body));
      ws['!cols'] = [{wch:5},{wch:40},{wch:15},{wch:18},{wch:18},{wch:18},{wch:18}];
      const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'IMT');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'portrait', unit:'pt', format:'a4'});
      const W = doc.internal.pageSize.getWidth();
      doc.setFont('helvetica','bold'); doc.setFontSize(11);
      judul.forEach((t,i)=>doc.text(t, W/2, 40+i*14, {align:'center'}));
      doc.autoTable({head:[head], body, startY:40+judul.length*14+8, theme:'grid',
        styles:{fontSize:8.5, cellPadding:3, lineColor:[0,0,0], lineWidth:0.5, textColor:0, halign:'center', valign:'middle'},
        headStyles:{fillColor:[243,244,246], textColor:0, fontStyle:'bold'},
        columnStyles:{0:{cellWidth:28}, 1:{halign:'left', cellWidth:150}}});
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
/* ---- Absensi Pembinaan Tenaga SAR ----
   Nama anggota yang dipilih di tab Samapta atau Pembelajaran otomatis masuk ke tab Absensi (sumber 'Samapta' / 'Pembelajaran', default Hadir).
   Absensi juga bisa ditambah manual (sumber 'Manual'). Disimpan di DB.pembinaan_absensi. */
const ABS_STATUS = ['Hadir','Izin','Sakit','Alpa','TH','DL','SG','LS','SB','TB','C','SOC','OPS','RAPAT'];
/* Kode di tabel rekap (mengikuti PDF Rekapitulasi Kehadiran): Hadir = centang, selainnya kode singkat */
const ABS_KODE = {Hadir:'✓', Izin:'I', Sakit:'S', Alpa:'TH'};
const ABS_NAMA = {Hadir:'Hadir', Izin:'Izin', Sakit:'Sakit', Alpa:'Alpa (Tidak Hadir)', TH:'Tidak Hadir', DL:'Dinas Luar', SG:'Siaga', LS:'Lepas Siaga', SB:'Stand By', TB:'Tugas Belajar', C:'Cuti', SOC:'SOC', OPS:'OPS', RAPAT:'Rapat'};
const ABS_MIN_HADIR = 3; // TERCAPAI (per orang) bila jumlah hadir >= 3x; kurang dari itu TIDAK TERCAPAI
function absAdminOnly(){ if(typeof isAdmin==='function' && isAdmin()) return true; if(typeof toast==='function') toast('Absensi hanya dapat diubah oleh Admin / Admin Utama'); return false; }
const ABS_BULAN = ['JANUARI','FEBRUARI','MARET','APRIL','MEI','JUNI','JULI','AGUSTUS','SEPTEMBER','OKTOBER','NOVEMBER','DESEMBER'];
function absKode(k){ return ABS_KODE[k] || k || 'TH'; }
let absView = 'rekap', absBulan = '', absSumber = 'Semua', absUnit = 'Semua';
function absAuto(a){ return !!a && ABS_SUMBER_AUTO.includes(a.sumber); }
function pbnPesertaList(r){ return String((r && r.peserta) || '').split(';').map(s=>s.trim()).filter(Boolean); }
// Samakan absensi otomatis dengan data Samapta (satu data Samapta bila diberikan, jika tidak semuanya). Mengembalikan true bila ada perubahan.
function pbnAbsSync(db, samapta){
  if(!Array.isArray(db.pembinaan_absensi)) db.pembinaan_absensi = [];
  const abs = db.pembinaan_absensi;
  const list = samapta ? [samapta] : (db.pembinaan||[]).filter(r=>ABS_SUMBER_AUTO.includes(pbnKat(r)));
  let changed = false;
  list.forEach(sm=>{
    const names = pbnPesertaList(sm), sumber = pbnKat(sm);
    for(let i=abs.length-1; i>=0; i--){
      if(absAuto(abs[i]) && abs[i].sumber_id===sm.id && (abs[i].sumber!==sumber || !names.includes(abs[i].nama_anggota))){ abs.splice(i,1); changed = true; }
    }
    names.forEach(n=>{
      const a = abs.find(x=>x.sumber===sumber && x.sumber_id===sm.id && x.nama_anggota===n);
      if(!a){
        abs.push({id:uid('ABS', abs), nama_anggota:n, tanggal:sm.tanggal||'', nama_kegiatan:sm.nama_kegiatan||'', lokasi:sm.lokasi||'', kehadiran:'Hadir', keterangan:'', sumber:sumber, sumber_id:sm.id});
        changed = true;
      } else if(a.tanggal!==(sm.tanggal||'') || a.nama_kegiatan!==(sm.nama_kegiatan||'') || (a.lokasi||'')!==(sm.lokasi||'')){
        a.tanggal = sm.tanggal||''; a.nama_kegiatan = sm.nama_kegiatan||''; a.lokasi = sm.lokasi||''; changed = true;
      }
    });
  });
  return changed;
}
function pbnAbsHapusSumber(sumberId){
  const n = (DB.pembinaan_absensi||[]).length;
  DB.pembinaan_absensi = (DB.pembinaan_absensi||[]).filter(a=>!(absAuto(a) && a.sumber_id===sumberId));
  return n !== DB.pembinaan_absensi.length;
}
/* Dinas Luar (DL): anggota yang ditugaskan (Pilih Anggota Bertugas) pada kegiatan/tugas yang Disetujui dan tanggalnya
   (tanggal s.d. tanggal selesai) sama dengan tanggal pembinaan akan tampil DL (bukan centang) di Absensi. Mengembalikan fungsi (nama, tanggal) => kegiatan | null. */
function absDlFn(){
  const idByNama = {};
  (DB.anggota||[]).forEach(a=>{ const n = absNorm(a.nama); if(n && !idByNama[n]) idByNama[n] = a.id; });
  const kegs = (DB.kegiatan||[]).filter(k=>k.status_persetujuan==='Disetujui' && (k.anggota_ids||[]).length && String(k.tanggal||'').slice(0,10));
  return (nama, tanggal)=>{
    const id = idByNama[absNorm(nama)], t = String(tanggal||'').slice(0,10);
    if(!id || !t) return null;
    return kegs.find(k=>{
      const a = String(k.tanggal).slice(0,10), b = String(k.tanggal_selesai||'').slice(0,10);
      return (k.anggota_ids||[]).includes(id) && t>=a && t<=(b>a ? b : a);
    }) || null;
  };
}
function absBadge(v){
  const cls = {Hadir:'b-ok', Izin:'b-info', Sakit:'b-warn', Alpa:'b-bad'}[v] || 'b-info';
  return `<span class="badge ${cls}">${esc(v||'-')}</span>`;
}
function renderPbnAbsensiList(label, restricted){
  const canEdit = isAdmin();
  const q = normSearch(search['pembinaan_absensi']||'');
  const dlDari = absDlFn();
  const all = (DB.pembinaan_absensi || []).map(a=> (a.kehadiran==='Hadir' && dlDari(a.nama_anggota, a.tanggal)) ? Object.assign({}, a, {kehadiran:'DL', _dl:true}) : a);
  const rows = all.filter(a=>!q || normSearch([a.nama_anggota, a.nama_kegiatan, a.lokasi, a.tanggal, a.kehadiran, a.keterangan, a.sumber].join(' ')).includes(q))
    .slice().sort((a,b)=>String(b.tanggal||'').localeCompare(String(a.tanggal||'')) || String(a.nama_anggota).localeCompare(String(b.nama_anggota)));
  const rekap = ABS_STATUS.map(s=>[s, all.filter(a=>a.kehadiran===s).length]).filter(([s,n])=>n>0 || ['Hadir','Izin','Sakit','Alpa'].includes(s)).map(([s,n])=>`<span class="badge ${{Hadir:'b-ok',Izin:'b-info',Sakit:'b-warn',Alpa:'b-bad'}[s]||'b-info'}">${s}: ${n}</span>`).join(' ');
  document.getElementById('app').innerHTML = `
    <div class="panel rounded-xl p-4">
      ${pembinaanTabHtml()}
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari absensi..." value="${esc(search['pembinaan_absensi']||'')}" class="!w-56" data-sid="search-pembinaan_absensi" oninput="runSearchRender(this, ()=>{ search['pembinaan_absensi']=this.value; renderPbnAbsensi('${esc(label)}', ${!!restricted}); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} data</span>
        <button class="btn btn-ghost" onclick="absView='rekap'; renderPbnAbsensi('${esc(label)}', ${!!restricted})">Tampilan Rekap</button>
        ${canEdit ? `<button class="btn btn-primary ml-auto" onclick="openAbsensiForm()">+ Tambah Absensi</button>` : `<span class="ml-auto text-xs badge b-info">Lihat saja</span>`}
      </div>
      <div class="flex flex-wrap items-center gap-2 mb-3">${rekap}</div>
      <div class="text-[11px] mb-3" style="color:var(--muted);">Nama anggota yang dipilih pada tab Samapta atau Pembelajaran masuk otomatis ke sini (status awal Hadir). Anda tetap bisa mengubah kehadirannya atau menambah absensi manual.</div>
      <div class="tablewrap">
        <table>
          <thead><tr><th>No</th><th>Nama Anggota</th><th>Tanggal</th><th>Kegiatan</th><th>Lokasi</th><th>Kehadiran</th><th>Keterangan</th><th>Sumber</th>${canEdit ? '<th>Aksi</th>' : ''}</tr></thead>
          <tbody>
            ${rows.length ? rows.map((a,i)=>`
              <tr>
                <td>${i+1}</td>
                <td class="font-medium">${esc(a.nama_anggota)}</td>
                <td>${esc(tglDMY(a.tanggal)||'-')}</td>
                <td>${esc(a.nama_kegiatan||'-')}</td>
                <td>${esc(a.lokasi||'-')}</td>
                <td>${absBadge(a.kehadiran)}</td>
                <td>${esc(a.keterangan||'')}${a._dl ? '<div class="text-[11px]" style="color:var(--muted);">Bertugas di Kegiatan/Tugas (' + esc(tglDMY(a.tanggal)) + ')</div>' : ''}</td>
                <td><span class="badge ${absAuto(a)?'b-info':'b-warn'}">${absAuto(a)?'Otomatis ('+esc(a.sumber)+')':'Manual'}</span></td>
                ${canEdit ? `<td class="whitespace-nowrap"><div style="display:flex; flex-direction:column; align-items:flex-start; gap:.35rem;">
                  <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openAbsensiForm('${a.id}')">Ubah</button>
                  ${absAuto(a) ? '' : `<button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteAbsensi('${a.id}')">Hapus</button>`}
                </div></td>` : ''}
              </tr>`).join('') : `<tr><td colspan="9" class="text-center py-6" style="color:var(--muted);">Belum ada data absensi.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
  enhanceTableScroll();
}
/* ---- Tampilan Absensi seperti PDF "Rekapitulasi Kehadiran": matriks Nama x Tanggal per unit, per bulan ---- */
function absNorm(v){ return normSearch(String(v||'')); }
function absKatOk(r){ return absSumber==='Semua' ? ABS_SUMBER_AUTO.includes(pbnKat(r)) : pbnKat(r)===absSumber; }
function absSumberOk(a){ return absSumber==='Semua' || a.sumber===absSumber || a.sumber==='Manual'; }
function absJudulSumber(){ return absSumber==='Samapta' ? 'PEMBINAAN KEBUGARAN JASMANI (SAMAPTA)' : (absSumber==='Pembelajaran' ? 'PEMBELAJARAN' : 'PEMBINAAN TENAGA PENCARIAN DAN PERTOLONGAN (SAMAPTA & PEMBELAJARAN)'); }
function absBulanLabel(ym){ const [y,m] = String(ym).split('-'); return (ABS_BULAN[(parseInt(m,10)||1)-1]||'')+' '+y; }
// Susun data rekap bulan terpilih: kelompok per LOKASI (sesuai lokasi yang dipilih di Samapta/Pembelajaran),
// tiap lokasi punya tanggal pertemuan & daftar nama sendiri.
const ABS_TANPA_LOKASI = 'Tanpa Lokasi';
function absLokasiOpsi(){ const f = ((TABLE_MODULES.find(m=>m.key==='pembinaan')||{}).fields||[]).find(x=>x.key==='lokasi'); return (f && f.options) || []; }
function absRekapData(){
  if(!absBulan) absBulan = todayISO().slice(0,7);
  const bln = absBulan;
  const inBln = t => String(t||'').slice(0,7)===bln;
  const lokOf = x => String((x && x.lokasi) || '').trim() || ABS_TANPA_LOKASI;
  const dlDari = absDlFn();
  const absAll = (DB.pembinaan_absensi||[]).filter(a=>inBln(a.tanggal) && absSumberOk(a));
  const kegAll = (DB.pembinaan||[]).filter(r=>absKatOk(r) && inBln(r.tanggal));
  const gmap = {};
  const G = l => gmap[l] || (gmap[l] = {unit:l, dateSet:new Set(), kegPerTgl:{}, names:[], seen:new Set()});
  const addNama = (g, nama) => { const n = absNorm(nama); if(n && !g.seen.has(n)){ g.seen.add(n); g.names.push(nama); } };
  kegAll.forEach(r=>{
    const g = G(lokOf(r)), t = String(r.tanggal).slice(0,10);
    g.dateSet.add(t); (g.kegPerTgl[t] = g.kegPerTgl[t]||[]).push(r.nama_kegiatan||'');
    pbnPesertaList(r).forEach(n=>addNama(g, n));
  });
  absAll.forEach(a=>{ const g = G(lokOf(a)); g.dateSet.add(String(a.tanggal).slice(0,10)); addNama(g, a.nama_anggota); });
  const q = absNorm(search['pembinaan_absensi']||'');
  const urut = absLokasiOpsi();
  const groups = Object.keys(gmap).sort((x,y)=>{ const ix = urut.indexOf(x), iy = urut.indexOf(y); return (ix<0?99:ix)-(iy<0?99:iy) || x.localeCompare(y); }).map(l=>{
    const g = gmap[l], dates = Array.from(g.dateSet).sort();
    const members = g.names.filter(n=>!q || absNorm(n).includes(q)).sort((x,y)=>x.localeCompare(y)).map(nama=>{
      const nn = absNorm(nama);
      const cells = dates.map(t=>{
        const recs = absAll.filter(a=>absNorm(a.nama_anggota)===nn && String(a.tanggal).slice(0,10)===t && lokOf(a)===l);
        if(!recs.length) return {v:'TH', implicit:true, recs:[]};
        const hadir = recs.find(a=>a.kehadiran==='Hadir');
        const v = (hadir||recs[0]).kehadiran;
        const dl = v==='Hadir' ? dlDari(nama, t) : null; // sedang bertugas di Kegiatan/Tugas pada tanggal yang sama
        return dl ? {v:'DL', implicit:false, recs, dl} : {v, implicit:false, recs};
      });
      const jumlah = cells.filter(c=>c.v==='Hadir').length;
      return {nama, cells, jumlah, capai: jumlah>=ABS_MIN_HADIR};
    });
    return {unit:l, dates, kegPerTgl:g.kegPerTgl, members};
  });
  const shown = groups.filter(g=>g.members.length && (absUnit==='Semua' || g.unit===absUnit));
  const maxDates = shown.reduce((m,g)=>Math.max(m,g.dates.length),0);
  return {dates:{length:maxDates}, groups:shown, allUnits:groups.map(g=>g.unit), bulan:bln};
}
function absSel(v, ro){ return `<span class="abs-c ${v==='Hadir'?'abs-ok':(v==='TH'||v==='Alpa'?'abs-bad':'abs-oth')}">${esc(absKode(v))}</span>`; }
function renderPbnAbsensi(label, restricted){
  if(absView==='daftar') return renderPbnAbsensiList(label, restricted);
  const canEdit = isAdmin();
  const d = absRekapData();
  const lbl = esc(label), rs = !!restricted;
  const rer = `renderPbnAbsensi('${lbl}', ${rs})`;
  const unitHtml = d.groups.map(g=>{
    const colN = g.dates.length;
    return `<div class="tablewrap mb-4"><table class="abs-rekap abs-fixed">
      <colgroup><col style="width:44px"><col style="width:260px">${colN ? g.dates.map(()=>'<col style="width:56px">').join('') : '<col style="width:56px">'}<col style="width:100px"><col style="width:140px"></colgroup>
      <thead>
        <tr><th colspan="${colN+4}" class="abs-unit">${esc(g.unit)}</th></tr>
        <tr><th rowspan="2" class="abs-no">NO</th><th rowspan="2" class="abs-nama">NAMA PEGAWAI</th><th colspan="${Math.max(colN,1)}">TANGGAL</th><th rowspan="2">JUMLAH<br>KEHADIRAN</th><th rowspan="2">KETERANGAN</th></tr>
        <tr>${colN ? g.dates.map(t=>`<th title="${esc((g.kegPerTgl[t]||[]).join(', ')||tglDMY(t))}">${parseInt(t.slice(8,10),10)}</th>`).join('') : '<th>-</th>'}</tr>
      </thead>
      <tbody>
        ${g.members.map((m,i)=>`<tr>
          <td class="abs-no">${i+1}</td><td class="abs-nama">${esc(m.nama)}</td>
          ${colN ? m.cells.map((c,j)=>`<td class="${canEdit?'abs-edit':''}" ${canEdit?`onclick="openAbsCell(${esc(JSON.stringify(m.nama)).replace(/'/g,'&#39;')}, '${g.dates[j]}', ${esc(JSON.stringify(g.unit)).replace(/'/g,'&#39;')})"`:''} title="${esc(c.dl ? 'DL — bertugas: ' + (c.dl.nama||'') + ' (' + tglRentang(c.dl) + ')' + (canEdit ? '. Klik untuk ubah' : '') : (canEdit ? 'Klik untuk ubah' : ''))}">${absSel(c.v)}</td>`).join('') : '<td></td>'}
          <td class="abs-no"><b>${m.jumlah}</b></td>
          <td class="${m.capai?'abs-capai':'abs-tidak'}">${m.capai?'TERCAPAI':'TIDAK TERCAPAI'}</td>
        </tr>`).join('')}
      </tbody>
    </table></div>`;
  }).join('');
  const legend = ['Hadir','TH','DL','SG','LS','SB','TB','C','SOC','OPS','RAPAT','Izin','Sakit'].map(k=>`<span class="mr-3 whitespace-nowrap"><b>${esc(absKode(k))}</b> ${esc(ABS_NAMA[k])}</span>`).join('');
  document.getElementById('app').innerHTML = `
    <style>
      .abs-rekap{border-collapse:collapse; width:100%; font-size:12px;}
      .abs-rekap th,.abs-rekap td{border:1px solid #1f2937; padding:4px 6px; text-align:center; white-space:nowrap;}
      .abs-rekap thead th{background:#f3f4f6; font-weight:700;}
      .abs-fixed{table-layout:fixed; width:auto!important; min-width:100%;}
      .abs-rekap .abs-unit{text-align:left; background:#fff; font-weight:700;}
      .abs-rekap .abs-nama{text-align:left; white-space:normal;}
      .abs-rekap .abs-no{width:42px;}
      .abs-edit{cursor:pointer;} .abs-edit:hover{background:var(--info-bg);}
      .abs-c{font-weight:600;} .abs-ok{color:var(--ok);} .abs-bad{color:var(--bad);} .abs-oth{color:var(--info);}
      .abs-capai{font-weight:700; color:var(--ok);} .abs-tidak{font-weight:700; color:var(--bad);}
      .abs-rekap tfoot td{border:1px solid #1f2937;} .abs-rekap tfoot .abs-sum{border:none;} .abs-sum-l{font-weight:700; text-align:left!important;} .abs-sum-n{font-weight:700;}
    </style>
    <div class="panel rounded-xl p-4">
      ${pembinaanTabHtml()}
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari nama..." value="${esc(search['pembinaan_absensi']||'')}" class="!w-48" data-sid="search-pembinaan_absensi" oninput="runSearchRender(this, ()=>{ search['pembinaan_absensi']=this.value; ${rer}; })">
        <input type="month" style="width:auto;" aria-label="Bulan" value="${esc(d.bulan)}" onchange="absBulan=this.value||''; ${rer}">
        <select style="width:auto;" aria-label="Kegiatan" onchange="absSumber=this.value; ${rer}">
          ${['Semua','Samapta','Pembelajaran'].map(o=>`<option value="${o}" ${absSumber===o?'selected':''}>${o==='Semua'?'Semua Kegiatan':o}</option>`).join('')}
        </select>
        <select style="width:auto;" aria-label="Lokasi" onchange="absUnit=this.value; ${rer}">
          ${['Semua'].concat(d.allUnits).map(o=>`<option value="${esc(o)}" ${absUnit===o?'selected':''}>${o==='Semua'?'Semua Lokasi':esc(o)}</option>`).join('')}
        </select>
        <div class="dd ml-auto">
          <button type="button" class="btn btn-ghost" onclick="toggleUnduhMenu(event, this)">Unduh <span aria-hidden="true">&#9662;</span></button>
          <div class="dd-menu">
            <button type="button" class="dd-item" onclick="exportAbsensiRekap('pdf')">PDF</button>
            <button type="button" class="dd-item" onclick="exportAbsensiRekap('excel')">Excel</button>
          </div>
        </div>
        <button class="btn btn-ghost" onclick="absView='daftar'; ${rer}">Tampilan Daftar</button>
        ${canEdit ? `<button class="btn btn-primary" onclick="openAbsensiForm()">+ Tambah Absensi</button>` : `<span class="text-xs badge b-info">Lihat saja</span>`}
      </div>
      <div class="text-[11px] mb-3" style="color:var(--muted);">Daftar nama dikelompokkan per Lokasi yang dipilih pada tab Samapta atau Pembelajaran (✓ = Hadir). Anggota di suatu lokasi yang tidak dipilih pada tanggal kegiatan lokasi itu dihitung TH. Klik sel untuk mengubah kehadiran. Keterangan dinilai per orang: TERCAPAI bila sudah hadir minimal ${ABS_MIN_HADIR}x pembelajaran, kurang dari itu TIDAK TERCAPAI.</div>
      <div class="text-center mb-4" style="line-height:1.35;">
        <div class="font-display font-bold text-sm">REKAPITULASI KEHADIRAN</div>
        <div class="font-bold text-xs">PELAKSANAAN ${esc(absJudulSumber())} KANTOR PENCARIAN DAN PERTOLONGAN KELAS A JAKARTA</div>
        <div class="font-bold text-xs">BULAN ${esc(absBulanLabel(d.bulan))}</div>
      </div>
      ${d.groups.length ? unitHtml : `<div class="text-center py-6" style="color:var(--muted);">Belum ada kegiatan Samapta/Pembelajaran dengan nama anggota pada bulan ini.</div>`}
      <div class="text-[11px]" style="color:var(--muted); line-height:1.8;">${legend}</div>
    </div>`;
  enhanceTableScroll();
}
// Ubah kehadiran satu orang pada satu tanggal (dari klik sel rekap)
function absLokOf(a){ return String((a && a.lokasi) || '').trim() || ABS_TANPA_LOKASI; }
function openAbsCell(nama, tanggal, lokasi){
  if(!absAdminOnly()) return;
  const nn = absNorm(nama);
  const recs = (DB.pembinaan_absensi||[]).filter(a=>absNorm(a.nama_anggota)===nn && String(a.tanggal).slice(0,10)===tanggal && absSumberOk(a) && absLokOf(a)===lokasi);
  const cur = recs.length ? ((recs.find(a=>a.kehadiran==='Hadir')||recs[0]).kehadiran) : 'TH';
  openModal(`
    <div class="p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-display font-semibold text-base">Ubah Kehadiran</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="text-sm mb-3"><b>${esc(nama)}</b><br><span style="color:var(--muted);">${esc(tglDMY(tanggal))} · ${esc(lokasi)}</span></div>
      ${(()=>{ const dl = (cur==='Hadir') ? absDlFn()(nama, tanggal) : null; return dl ? `<div class="text-[11px] mb-3 rounded-lg p-2" style="background:var(--info-bg);">Anggota ini bertugas di <b>${esc(dl.nama||'')}</b> (${esc(tglRentang(dl))}) sehingga status Hadir tampil sebagai <b>DL</b>. Pilih status lain bila perlu menimpanya.</div>` : ''; })()}
      <label class="block text-xs font-medium mb-1" style="color:var(--muted);">Kehadiran</label>
      <select id="abc_hadir">${ABS_STATUS.map(o=>`<option value="${o}" ${cur===o?'selected':''}>${esc(absKode(o))} — ${esc(ABS_NAMA[o]||o)}</option>`).join('')}</select>
      <label class="block text-xs font-medium mt-3 mb-1" style="color:var(--muted);">Keterangan</label>
      <textarea id="abc_ket" rows="2">${esc(recs[0]?recs[0].keterangan||'':'')}</textarea>
      <div class="flex justify-end gap-2 mt-4">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveAbsCell(${esc(JSON.stringify(nama)).replace(/'/g,'&#39;')}, '${tanggal}', ${esc(JSON.stringify(lokasi)).replace(/'/g,'&#39;')})">Simpan</button>
      </div>
    </div>`, true);
}
function saveAbsCell(nama, tanggal, lokasi){
  if(!absAdminOnly()) return;
  const v = k => (document.getElementById(k)||{}).value || '';
  if(!Array.isArray(DB.pembinaan_absensi)) DB.pembinaan_absensi = [];
  const nn = absNorm(nama), hadir = v('abc_hadir'), ket = v('abc_ket').trim();
  const recs = DB.pembinaan_absensi.filter(a=>absNorm(a.nama_anggota)===nn && String(a.tanggal).slice(0,10)===tanggal && absSumberOk(a) && absLokOf(a)===lokasi);
  const meta = {diperbarui_oleh: pelaku(), diperbarui_waktu: nowStr()};
  if(recs.length){ recs.forEach(r=>Object.assign(r, {kehadiran:hadir, keterangan:ket}, meta)); }
  else {
    const keg = (DB.pembinaan||[]).filter(r=>absKatOk(r) && String(r.tanggal).slice(0,10)===tanggal && absLokOf(r)===lokasi).map(r=>r.nama_kegiatan).filter(Boolean)[0] || '';
    DB.pembinaan_absensi.push(Object.assign({id:uid('ABS', DB.pembinaan_absensi), nama_anggota:nama, tanggal, nama_kegiatan:keg, lokasi:(lokasi===ABS_TANPA_LOKASI?'':lokasi), kehadiran:hadir, keterangan:ket, sumber:'Manual', sumber_id:''}, meta));
  }
  persist(); closeModal(); toast('Kehadiran diperbarui'); render('pembinaan');
}
function exportAbsensiRekap(fmt){
  document.querySelectorAll('.dd.open').forEach(x=>x.classList.remove('open'));
  const d = absRekapData();
  if(!d.groups.length){ toast('Belum ada data absensi pada bulan ini'); return; }
  const judul = ['REKAPITULASI KEHADIRAN', 'PELAKSANAAN '+absJudulSumber()+' KANTOR PENCARIAN DAN PERTOLONGAN KELAS A JAKARTA', 'BULAN '+absBulanLabel(d.bulan)];
  const headOf = g => ['NO','NAMA PEGAWAI'].concat(g.dates.map(t=>String(parseInt(t.slice(8,10),10))), ['JUMLAH KEHADIRAN','KETERANGAN']);
  const fname = ('Rekap Kehadiran '+absBulanLabel(d.bulan)).replace(/\s+/g,'_');
  try{
    if(fmt==='excel'){
      if(typeof XLSX==='undefined'){ toast('Library Excel belum termuat, periksa koneksi internet'); return; }
      const aoa = judul.map(x=>[x]).concat([[]]);
      d.groups.forEach(g=>{
        aoa.push([g.unit]); aoa.push(headOf(g));
        g.members.forEach((m,i)=>aoa.push([i+1, m.nama].concat(m.cells.map(c=>absKode(c.v)), [m.jumlah, m.capai?'TERCAPAI':'TIDAK TERCAPAI'])));
        aoa.push([]);
      });
      aoa.push(['KETERANGAN KODE']); Object.keys(ABS_NAMA).filter(k=>!['Alpa'].includes(k)).forEach(k=>aoa.push([absKode(k), ABS_NAMA[k]]));
      const ws = XLSX.utils.aoa_to_sheet(aoa);
      ws['!cols'] = [{wch:5},{wch:38}].concat(new Array(d.dates.length).fill(0).map(()=>({wch:6})), [{wch:18},{wch:20}]);
      const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Rekap Kehadiran');
      XLSX.writeFile(wb, fname+'.xlsx');
    } else {
      if(!(window.jspdf && window.jspdf.jsPDF)){ toast('Library PDF belum termuat, periksa koneksi internet'); return; }
      const doc = new window.jspdf.jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
      const W = doc.internal.pageSize.getWidth();
      doc.setFont('helvetica','bold'); doc.setFontSize(11);
      judul.forEach((t,i)=>doc.text(t, W/2, 36+i*14, {align:'center'}));
      let y = 36 + judul.length*14 + 6;
      d.groups.forEach(g=>{
        const head = headOf(g);
        const body = g.members.map((m,i)=>[i+1, m.nama].concat(m.cells.map(c=>absKode(c.v)), [m.jumlah, m.capai?'TERCAPAI':'TIDAK TERCAPAI']));
        doc.autoTable({
          head:[[{content:g.unit, colSpan:head.length, styles:{halign:'left', fillColor:[255,255,255], textColor:0}}], head], body, startY:y, theme:'grid',
          styles:{fontSize:8, cellPadding:2.5, lineColor:[0,0,0], lineWidth:0.5, textColor:0, halign:'center', valign:'middle'},
          headStyles:{fillColor:[243,244,246], textColor:0, fontStyle:'bold'},
          columnStyles:{0:{cellWidth:26}, 1:{halign:'left', cellWidth:190}},
          didParseCell:h=>{ if(h.section==='body' && h.column.index>=2 && h.column.index<head.length-2){ const v=h.cell.raw; h.cell.styles.textColor = v==='✓' ? [15,157,88] : (v==='TH' ? [192,57,43] : [44,95,158]); } }
        });
        y = doc.lastAutoTable.finalY + 14;
      });
      const k = Object.keys(ABS_NAMA).filter(x=>x!=='Alpa' && x!=='TH').map(x=>absKode(x)+' '+ABS_NAMA[x]).join('   ');
      doc.setFont('helvetica','normal'); doc.setFontSize(8);
      if(y>520){ doc.addPage(); y = 40; }
      doc.text('TH Tidak Hadir   '+k, 40, y);
      doc.save(fname+'.pdf');
    }
  }catch(e){ console.error(e); toast('Gagal mengunduh file'); }
}
function openAbsensiForm(id){
  if(!absAdminOnly()) return;
  const row = id ? (DB.pembinaan_absensi||[]).find(a=>a.id===id) : null;
  const auto = absAuto(row);
  const anggota = (DB.anggota||[]).filter(a=>a.status==='Aktif' || (row && a.nama===row.nama_anggota)).map(a=>a.nama);
  const kegiatan = Array.from(new Set((DB.pembinaan||[]).filter(r=>ABS_SUMBER_AUTO.includes(pbnKat(r))).map(r=>r.nama_kegiatan).filter(Boolean)));
  const ro = auto ? 'readonly style="background:var(--line); cursor:not-allowed;"' : '';
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-display font-semibold text-base">${row?'Ubah':'Tambah'} Absensi${auto ? ' — '+esc(row.sumber) : ''}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      ${auto ? `<div class="text-[11px] mb-3" style="color:var(--muted);">Data ini otomatis dari tab ${esc(row.sumber)}: nama, tanggal, dan kegiatan mengikuti data ${esc(row.sumber)}. Anda hanya dapat mengubah kehadiran dan keterangan.</div>` : ''}
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Nama Anggota *</label>
          <input type="text" id="abs_nama" list="abs_nama_list" autocomplete="off" value="${esc(row?row.nama_anggota:'')}" placeholder="Pilih atau ketik nama" ${ro}>
          <datalist id="abs_nama_list">${anggota.map(n=>`<option value="${esc(n)}">`).join('')}</datalist></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tanggal</label>
          <input type="date" id="abs_tanggal" value="${esc(row?row.tanggal:todayISO())}" ${ro}></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Kegiatan</label>
          <input type="text" id="abs_kegiatan" list="abs_kegiatan_list" autocomplete="off" value="${esc(row?row.nama_kegiatan:'')}" placeholder="Pilih kegiatan Samapta/Pembelajaran atau ketik" ${ro}>
          <datalist id="abs_kegiatan_list">${kegiatan.map(n=>`<option value="${esc(n)}">`).join('')}</datalist></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Lokasi</label>
          <select id="abs_lokasi" ${auto?'disabled':''}><option value="">- Pilih lokasi -</option>${absLokasiOpsi().map(o=>`<option value="${esc(o)}" ${(row&&row.lokasi)===o?'selected':''}>${esc(o)}</option>`).join('')}</select></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Kehadiran</label>
          <select id="abs_hadir">${ABS_STATUS.map(o=>`<option value="${o}" ${(row?row.kehadiran:'Hadir')===o?'selected':''}>${o}</option>`).join('')}</select></div>
        <div class="sm:col-span-2"><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Keterangan</label>
          <textarea id="abs_ket" rows="2">${esc(row?row.keterangan:'')}</textarea></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveAbsensi(${row?`'${id}'`:'null'})">Simpan</button>
      </div>
    </div>`, true);
}
function saveAbsensi(id){
  if(!absAdminOnly()) return;
  const v = k => (document.getElementById(k)||{}).value || '';
  const nama = v('abs_nama').trim();
  if(!nama){ toast('Nama anggota wajib diisi'); return; }
  if(!Array.isArray(DB.pembinaan_absensi)) DB.pembinaan_absensi = [];
  const rows = DB.pembinaan_absensi;
  const meta = {diperbarui_oleh: pelaku(), diperbarui_waktu: nowStr()};
  if(id){
    const r = rows.find(a=>a.id===id); if(!r) return;
    if(!absAuto(r)){ r.nama_anggota = nama; r.tanggal = v('abs_tanggal'); r.nama_kegiatan = v('abs_kegiatan').trim(); r.lokasi = v('abs_lokasi'); }
    r.kehadiran = v('abs_hadir'); r.keterangan = v('abs_ket').trim();
    Object.assign(r, meta);
    toast('Absensi diperbarui');
  } else {
    rows.push(Object.assign({id:uid('ABS', rows), nama_anggota:nama, tanggal:v('abs_tanggal'), nama_kegiatan:v('abs_kegiatan').trim(), lokasi:v('abs_lokasi'), kehadiran:v('abs_hadir'), keterangan:v('abs_ket').trim(), sumber:'Manual', sumber_id:''}, meta));
    toast('Absensi ditambahkan');
  }
  persist(); closeModal(); render('pembinaan');
}
function deleteAbsensi(id){
  if(!absAdminOnly()) return;
  const r = (DB.pembinaan_absensi||[]).find(a=>a.id===id); if(!r) return;
  if(absAuto(r)){ toast('Absensi otomatis dihapus dengan melepas nama dari data '+r.sumber); return; }
  if(!confirm(`Hapus absensi "${r.nama_anggota}"?`)) return;
  DB.pembinaan_absensi = DB.pembinaan_absensi.filter(a=>a.id!==id);
  persist(); toast('Absensi dihapus'); render('pembinaan');
}
let pmjSembunyi = false; // Peminjaman baru / belum di-ACC / ditolak: Status & Tanggal Dikembalikan diatur sistem (lewat ACC)
function fieldTampilForm(key, f){
  if(f.pinjamInfo) return false; // hanya tampil di tabel/detail
  if(key==='uji_pemeliharaan') return !f.noForm;
  if(key==='peminjaman' && pmjSembunyi && ['status','tanggal_kembali'].includes(f.key)) return false;
  if(key==='peminjaman' && pmjBaru && ['jenis_barang','nama_barang','jumlah'].includes(f.key)) return false; // diganti Daftar Barang (multi barang)
  if(key==='pembinaan') return !f.noForm && pbnKatOk(f, pembinaanKatForm);
  if(key!=='pemeliharaan') return true;
  return !f.noForm && (!f.kat || f.kat===pengajuanKatForm);
}
function fieldTampilDetail(key, f, row){
  if(key==='pembinaan') return pbnKatOk(f, pbnKat(row));
  if(key!=='pemeliharaan') return true;
  return !f.noDetail && (!f.kat || f.kat===pgjKat(row));
}
function pengajuanTabHtml(){
  const rows = DB.pemeliharaan || [];
  return `<div class="flex flex-wrap gap-2 mb-4">${PENGAJUAN_TABS.map(t=>{
    const all = rows.filter(r=>pgjKat(r)===t);
    const mn = all.filter(r=>pgjSP(r)==='Menunggu Persetujuan').length;
    return `<button class="btn ${pengajuanTab===t?'btn-primary':'btn-ghost'}" onclick="pengajuanTab='${t}'; render('pemeliharaan')">${t} <span class="badge ${mn?'b-warn':'b-info'}">${mn ? mn+' menunggu' : all.length+' data'}</span></button>`;
  }).join('')}</div>`;
}
function renderPengajuan(){
  const key = 'pemeliharaan';
  const m = TABLE_MODULES.find(x=>x.key===key);
  const kat = pengajuanTab;
  const q = (search[key]||'').toLowerCase();
  let rows = (DB[key]||[]).filter(r=>pgjKat(r)===kat);
  if(q) rows = rows.filter(r=>matchSearch(r, q, m.fields));
  rows = rows.filter(r=>inPeriode(r, key));
  const fld = k => m.fields.find(f=>f.key===k);
  const cols = (kat==='Kebutuhan'
    ? ['jenis_barang','nama_barang','jumlah_kebutuhan','tingkat_urgensi','estimasi_biaya','tanggal']
    : ['jenis_barang','nama_barang','jenis_pemeliharaan','tanggal','jadwal_berikutnya']).map(fld);
  const showStatus = kat==='Pemeliharaan';
  const acc = canAccPengajuan();
  document.getElementById('app').innerHTML = `
    <div class="panel rounded-xl p-4">
      ${pengajuanTabHtml()}
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <input type="text" placeholder="Cari data Pengajuan ${esc(kat)}..." value="${esc(search[key]||'')}" class="!w-56" data-sid="search-${key}" oninput="runSearchRender(this, ()=>{ search['${key}']=this.value; renderPengajuan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} data</span>
        ${periodeSelectHtml(key)}
        ${impBtnHtml(key, 'ml-auto')}
        <button class="btn btn-primary ${canImportExcel() ? '' : 'ml-auto'}" onclick="openGenericForm('${key}')">+ Tambah Pengajuan ${esc(kat)}</button>
      </div>
      <div class="tablewrap">
        <table>
          <thead><tr>
            <th>ID</th>
            ${cols.map(c=>`<th>${esc(pgjLabel(c,kat))}</th>`).join('')}
            ${showStatus ? '<th>Status Pekerjaan</th>' : ''}
            <th>Persetujuan</th>
            <th>Aksi</th>
          </tr></thead>
          <tbody>
            ${rows.length ? rows.slice().reverse().map(r=>{
              const sp = pgjSP(r), manage = canManagePengajuan(r);
              return `<tr style="cursor:pointer;" onclick="openGenericDetail('${key}','${r.id}')">
                <td class="font-medium">${esc(r.id)}</td>
                ${cols.map(c=>`<td>${cellHtml(key,c,r)}</td>`).join('')}
                ${showStatus ? `<td>${statusBadge(r.status)}</td>` : ''}
                <td>${statusBadge(sp)}${sp==='Ditolak' && r.alasan_penolakan ? `<div class="text-[11px]" style="color:var(--muted);">${esc(r.alasan_penolakan)}</div>` : ''}${sp==='Disetujui' && r.acc_oleh ? `<div class="text-[11px]" style="color:var(--muted);">oleh ${esc(r.acc_oleh)}${r.acc_nama ? ' (' + esc(r.acc_nama) + ')' : ''}</div>` : ''}</td>
                <td class="whitespace-nowrap" onclick="event.stopPropagation();">
                  <div style="display:flex; flex-direction:column; align-items:flex-start; gap:.35rem;">
                    ${sp==='Menunggu Persetujuan' && acc ? `<div class="flex gap-1"><button class="btn btn-ok !py-1 !px-2 text-xs" onclick="accPengajuan('${r.id}')">ACC</button><button class="btn btn-bad !py-1 !px-2 text-xs" onclick="tolakPengajuan('${r.id}')">Tolak</button></div>` : ''}
                    ${manage ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openGenericForm('${key}','${r.id}')">Ubah</button>
                    <button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteGeneric('${key}','${r.id}','Pengajuan')">Hapus</button>` : ''}
                  </div>
                </td>
              </tr>`; }).join('') : `<tr><td colspan="12" class="text-center py-6" style="color:var(--muted);">Belum ada pengajuan ${esc(kat)}.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
  enhanceTableScroll();
}
function pengajuanApprovalHtml(row){
  const sp = pgjSP(row);
  return `<div class="sm:col-span-2 rounded-lg p-3" style="background:var(--bg);">
    <div class="text-xs font-medium mb-1" style="color:var(--muted);">Persetujuan Pengajuan</div>
    <div class="flex flex-wrap items-center gap-2">${statusBadge(sp)}<span class="text-xs" style="color:var(--muted);">Diajukan oleh ${row.diajukan_oleh ? esc(pelakuTeks(row.diajukan_oleh)) : '-'}${row.diajukan_waktu ? ' · ' + esc(row.diajukan_waktu) : ''}</span></div>
    ${row.keterangan_persetujuan ? `<p class="text-xs mt-2">${esc(row.keterangan_persetujuan)}</p>` : ''}
    ${sp==='Menunggu Persetujuan' ? (canAccPengajuan()
      ? `<div class="flex gap-2 mt-3"><button class="btn btn-ok" onclick="accPengajuan('${row.id}')">ACC</button><button class="btn btn-bad" onclick="tolakPengajuan('${row.id}')">Tolak</button></div><p class="text-[11px] mt-2" style="color:var(--muted);">Pimpinan juga dapat memutuskan lewat WhatsApp. Keputusan pertama (dashboard atau WhatsApp) bersifat final.</p>`
      : `<p class="text-[11px] mt-2" style="color:var(--muted);">Menunggu ACC ${PENGAJUAN_ACC_ROLES.join(' / ')} atau Admin Utama.</p>`) : ''}
  </div>`;
}
function accPengajuan(id){
  const r = (DB.pemeliharaan||[]).find(x=>x.id===id);
  if(!r || pgjSP(r)!=='Menunggu Persetujuan') return;
  if(!canAccPengajuan()){ toast('Hanya ' + PENGAJUAN_ACC_ROLES.join('/') + ' atau Admin Utama yang dapat ACC'); return; }
  if(pgjKat(r)==='Pemeliharaan' && r.jenis_barang==='Peralatan' && r.status==='Sedang Dikerjakan'){
    const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(r.nama_barang));
    if(alat){
      const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang) - peralatanDipelihara(alat.nama_barang);
      if((parseFloat(r.jumlah)||1) > sisa){ toast('Stok tersedia hanya ' + Math.max(0,sisa) + ' - jumlah unit melebihi stok, ubah pengajuan dulu'); return; }
    }
  }
  r.status_persetujuan = 'Disetujui';
  r.acc_oleh = currentRole; r.acc_nama = currentUserName; r.acc_waktu = nowStr();
  r.keterangan_persetujuan = `Disetujui oleh ${currentRole} (${currentUserName}) pada ${nowStr()}.`;
  r.alasan_penolakan = '';
  syncKendaraanDariPemeliharaan(r);
  persist();
  toast('Pengajuan ' + id + ' disetujui');
  closeModal(); render('pemeliharaan'); openGenericDetail('pemeliharaan', id);
}
function tolakPengajuan(id){
  const r = (DB.pemeliharaan||[]).find(x=>x.id===id);
  if(!r || pgjSP(r)!=='Menunggu Persetujuan') return;
  if(!canAccPengajuan()){ toast('Hanya ' + PENGAJUAN_ACC_ROLES.join('/') + ' atau Admin Utama yang dapat menolak'); return; }
  const alasan = prompt('Alasan penolakan:');
  if(alasan===null) return;
  r.status_persetujuan = 'Ditolak';
  r.alasan_penolakan = alasan || '(tanpa keterangan)';
  r.acc_oleh = currentRole; r.acc_nama = currentUserName; r.acc_waktu = nowStr();
  r.keterangan_persetujuan = `Ditolak oleh ${currentRole} (${currentUserName}) pada ${nowStr()}: ${r.alasan_penolakan}`;
  persist();
  toast('Pengajuan ' + id + ' ditolak');
  closeModal(); render('pemeliharaan'); openGenericDetail('pemeliharaan', id);
}

function openGenericDetail(key, id){
  const meta = key==='anggota' ? {label:'Anggota', fields:ANGGOTA_FIELDS} : (()=>{const m=TABLE_MODULES.find(m=>m.key===key); return {label:m.label, fields:m.fields};})();
  const rows = DB[key] || [];
  const row = rows.find(r=>r.id===id);
  if(!row) return;

  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-display font-semibold text-base">Detail ${esc(meta.label)}</h3>
          <div class="text-xs" style="color:var(--muted);">${esc(row.id)}</div>
        </div>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3">
        ${meta.fields.filter(f=>!(f.laut && saprasKategori(row)!=='Sapras Laut') && fieldTampilDetail(key,f,row)).map(f=>`
          <div class="${f.type==='textarea'?'sm:col-span-2':''}">
            <div class="text-xs font-medium mb-1" style="color:var(--muted);">${esc(key==='kendaraan' ? labelKendaraan(f, saprasKategori(row)==='Sapras Laut') : (key==='pemeliharaan' ? pgjLabel(f, pgjKat(row)) : (key==='pembinaan' ? pbnLabel(f, pbnKat(row)) : f.label)))}</div>
            <div class="text-sm font-medium">${f.isStatus ? (statusBadge(statusFieldValue(key, f, row))||'-') : (cellHtml(key,f,row) || '-')}</div>
          </div>`).join('')}
        ${key==='anggota' ? `
          <div>
            <div class="text-xs font-medium mb-1" style="color:var(--muted);">Kegiatan Diikuti</div>
            <div class="text-sm font-medium">${getAnggotaKegiatanCount(row.id)} penugasan</div>
          </div>` : ''}
        ${key==='pemeliharaan' ? pengajuanApprovalHtml(row) : ''}
        ${key==='peminjaman' ? peminjamanApprovalHtml(row) : ''}
        ${key==='peminjaman' ? pmjBuktiKembaliHtml(row) : ''}
        ${row.diperbarui_oleh ? `
          <div class="sm:col-span-2">
            <div class="text-xs font-medium mb-1" style="color:var(--muted);">Diupdate Oleh</div>
            <div class="text-sm font-medium">${esc(pelakuTeks(row.diperbarui_oleh))}${row.diperbarui_waktu ? ` <span class="text-xs font-normal" style="color:var(--muted);">· ${esc(row.diperbarui_waktu)}</span>` : ''}</div>
          </div>` : ''}
      </div>
      ${PESERTA_MODULES[key] ? diklatPesertaDetailHtml(row, key) : ''}
      ${lampiranWaHtml(key, row)}
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Tutup</button>
        ${canGenericEdit(key, row) ? `<button class="btn btn-primary" onclick="closeModal(); openGenericForm('${key}','${id}')">Ubah Data</button>` : ''}
      </div>
    </div>`, true);
}
function ujiTop3Html(row){
  const M = PESERTA_MODULES.uji_periodik;
  const rows = Array.isArray(row.peserta_matrix) ? row.peserta_matrix : [];
  const groups = [{jk:'L', label:'Laki-laki (L)'}, {jk:'P', label:'Perempuan (P)'}];
  const block = g => {
    const top = rows
      .filter(r=>r.jk===g.jk)
      .map(r=>({r, avg:ujiNilaiRataRata(r, (Array.isArray(row.kolom_peserta)&&row.kolom_peserta.length) ? row.kolom_peserta : ((DB.kolom_peserta && DB.kolom_peserta.uji_periodik) || defaultUjiCols()))}))
      .filter(x=>x.avg!==null)
      .sort((a,b)=>b.avg-a.avg)
      .slice(0,3);
    return `
      <div class="mb-3">
        <div class="text-xs font-semibold mb-1">3 Nilai Keseluruhan Tertinggi — ${esc(g.label)}</div>
        <div class="tablewrap">
          <table>
            <thead><tr>
              <th class="text-center" style="width:3rem;">No</th><th>Nama</th><th>Jabatan</th><th class="text-center">${esc(M.lainLabel)}</th>
            </tr></thead>
            <tbody>
              ${top.length ? top.map((x,n)=>`
                <tr>
                  <td class="text-center">${n+1}</td>
                  <td class="font-medium">${esc(x.r.nama)||'-'}</td>
                  <td>${esc(x.r.jabatan)||'-'}</td>
                  <td class="text-center font-semibold">${x.avg.toLocaleString('id-ID',{maximumFractionDigits:2})}</td>
                </tr>`).join('') : `<tr><td colspan="4" class="text-center py-3" style="color:var(--muted);">Belum ada data nilai.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>`;
  };
  return `
    <div class="mt-4 pt-4 border-t" style="border-color:var(--line);">
      <div class="text-xs font-medium mb-2" style="color:var(--muted);">Data Peserta ${esc(M.label)}</div>
      ${groups.map(block).join('')}
    </div>`;
}
function ujiTop3AkhirHtml(ujiId){
  ujiMigrasiNilaiLama();
  const rekap = ujiPesertaRekap(ujiId).filter(o=>o.akhir!==null);
  const groups = [{jk:'L', label:'Laki-laki (L)'}, {jk:'P', label:'Perempuan (P)'}];
  const medal = ['🥇','🥈','🥉'];
  const block = g => {
    const top = rekap.filter(o=>o.jk===g.jk).sort((a,b)=>b.akhir-a.akhir).slice(0,3);
    return `
      <div class="mb-3">
        <div class="text-xs font-semibold mb-1">Peringkat 3 Besar Nilai Akhir — ${esc(g.label)}</div>
        <div class="tablewrap">
          <table>
            <thead><tr><th class="text-center" style="width:3.5rem;">Rank</th><th>Nama</th><th>Jabatan</th><th class="text-center">Nilai Akhir</th><th>Predikat</th></tr></thead>
            <tbody>${top.length ? top.map((o,i)=>`<tr>
              <td class="text-center">${medal[i]} ${i+1}</td>
              <td class="font-medium">${esc(o.nama)||'-'}</td><td>${esc(o.jabatan)||'-'}</td>
              <td class="text-center font-semibold">${fmtNilai(o.akhir)}</td><td>${ujkPredikatBadge(o.predikat)}</td>
            </tr>`).join('') : `<tr><td colspan="5" class="text-center py-3" style="color:var(--muted);">Belum ada peserta ${esc(g.label)} dengan nilai akhir lengkap.</td></tr>`}</tbody>
          </table>
        </div>
      </div>`;
  };
  const tanpaJk = rekap.filter(o=>o.jk!=='L' && o.jk!=='P').length;
  return `
    <div class="mt-4 pt-4 border-t" style="border-color:var(--line);">
      <div class="text-xs font-medium mb-2" style="color:var(--muted);">Perangkingan Nilai Akhir Uji Periodik</div>
      ${groups.map(block).join('')}
      ${tanpaJk ? `<p class="text-[11px]" style="color:var(--muted);">${tanpaJk} peserta belum diisi JK sehingga tidak masuk perangkingan.</p>` : ''}
      <p class="text-[11px] mt-1" style="color:var(--muted);">Hanya peserta dengan keempat nilai lengkap (Teknis SAR, PU, Kebugaran Jasmani, Renang) yang diperingkat.</p>
    </div>`;
}
function diklatPesertaDetailHtml(row, key){
  if((key||'diklat')==='uji_periodik') return ujiTop3AkhirHtml(row.id);
  const M = PESERTA_MODULES[key||'diklat'];
  const rows = Array.isArray(row.peserta_matrix) ? row.peserta_matrix : [];
  return `
    <div class="mt-4 pt-4 border-t" style="border-color:var(--line);">
      <div class="text-xs font-medium mb-2" style="color:var(--muted);">Data Peserta ${esc(M.label)}</div>
      <div class="tablewrap">
        <table>
          <thead><tr>
            <th>Nama</th><th>Jabatan</th>${(key||'diklat')==='uji_periodik' ? '<th class="text-center">JK</th>' : ''}
            ${getPesertaCols(key||'diklat').map(c=>`<th class="text-center">${esc(c.label)}</th>`).join('')}
            <th>${esc(M.lainLabel)}</th>
          </tr></thead>
          <tbody>
            ${rows.length ? rows.map((r,ri)=>`
              <tr>
                <td class="font-medium">${esc(r.nama)||'-'}</td>
                <td>${esc(r.jabatan)||'-'}</td>
                ${(key||'diklat')==='uji_periodik' ? `<td class="text-center">${esc(r.jk)||'-'}</td>` : ''}
                ${getPesertaCols(key||'diklat').map(c=>`<td class="text-center">${(key||'diklat')==='uji_periodik' ? ((typeof r[c.key]==='boolean' || r[c.key]==null || String(r[c.key]).trim()==='') ? '-' : esc(r[c.key])) : (r[c.key]?'✓':'-')}${(key||'diklat')==='diklat' && r[c.key] && r[c.key+'_pdf'] ? `<br><a href="#" class="text-[11px]" style="color:var(--orange-500); text-decoration:underline;" onclick="viewPesertaColPdfSaved(event,'${esc(row.id)}','${key||'diklat'}',${ri},'${c.key}')">Lihat PDF</a>` : ''}</td>`).join('')}
                <td>
                  ${(key||'diklat')==='diklat' ? (() => {
                    const items = (Array.isArray(r.diklat_lain_list) ? r.diklat_lain_list : ((r.diklat_lain_teks||r.diklat_lain_pdf) ? [{teks:r.diklat_lain_teks, pdf:r.diklat_lain_pdf}] : [])).filter(x=>(x.teks&&String(x.teks).trim())||x.pdf);
                    return items.length ? items.map((it,j)=>`<div>${esc(it.teks)||'-'}${it.pdf ? ` <a href="#" class="text-xs" style="color:var(--orange-500); text-decoration:underline;" onclick="viewDiklatLainPdfSaved(event,'${esc(row.id)}','diklat',${ri},${j})">Lihat PDF</a>` : ''}</div>`).join('') : '-';
                  })() : (key==='uji_periodik' ? `<span class="font-semibold">${ujiNilaiRataRataText(r)}</span>` : `${esc(r.diklat_lain_teks)||'-'}${r.diklat_lain_pdf ? `<br><a href="${r.diklat_lain_pdf.data}" target="_blank" rel="noopener" class="text-xs" style="color:var(--accent, #dd4a24);">Lihat PDF</a>` : ''}`)}
                </td>
              </tr>`).join('') : `<tr><td colspan="${getPesertaCols(key||'diklat').length+((key||'diklat')==='uji_periodik'?4:3)}" class="text-center py-4" style="color:var(--muted);">Belum ada data peserta.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
}

function canGenericEdit(key, row){
  if(modulReadOnly(key)) return false;
  if(key==='pemeliharaan') return canManagePengajuan(row);
  if(key==='anggota') return isApprover();
  if(key==='rescuer') return false;
  return true;
}

/* Pembinaan > Pembelajaran: opsi "+ Tambah materi lainnya…" menampilkan kolom ketik; teks yang diketik menjadi nilai Materi */
function pbnMateriChg(sel){
  const inp = sel.parentNode.querySelector('input[type=text]'), lain = !!(sel.selectedOptions[0] && sel.selectedOptions[0].dataset.lain);
  if(inp){ inp.style.display = lain ? '' : 'none'; if(lain){ inp.focus(); pbnMateriLain(inp); } }
}
function pbnMateriLain(inp){
  const sel = inp.parentNode.querySelector('select'), opt = sel && sel.querySelector('option[data-lain]');
  if(opt){ opt.value = inp.value.trim(); sel.value = opt.value; if(!opt.selected) opt.selected = true; }
}
function fieldInput(f, val, key, row){
  const v = esc(val===undefined?'':val);
  if(statusDariKegiatanAktif(key, f, row)){
    const cur = statusDariKegiatan(key, row || {});
    return `<input type="text" data-field="${f.key}" value="${esc(cur)}" readonly disabled style="background:var(--line); font-weight:600; cursor:not-allowed;">
      <div class="text-[11px] mt-1" style="color:var(--muted);">Otomatis mengikuti status Kegiatan/Tugas terkait.</div>`;
  }
  // Pembinaan Tenaga SAR tab Pembelajaran: Materi berupa dropdown (tab Samapta tetap isian teks)
  if(key==='pembinaan' && f.key==='materi' && pembinaanKatForm==='Pembelajaran'){
    const base = ['HART','MFR','Jungle','VAR','CSR','CSSR','WATER Rescue','Underwater','Heli Rescue','Sarplan','INSTRUKTUR','Mine'];
    if(val==='Hard') val = 'HART'; // data lama: HARD diganti HART
    const opts = (val && !base.includes(val)) ? [val].concat(base) : base; // nilai lama tetap tampil
    return `<div><select data-field="materi" onchange="pbnMateriChg(this)">${opts.map(o=>`<option value="${esc(o)}" ${val===o?'selected':''}>${esc(o)}</option>`).join('')}<option value="" data-lain="1">+ Tambah materi lainnya…</option></select>
      <input type="text" class="mt-2" style="display:none;" placeholder="Ketik materi baru" aria-label="Materi baru" oninput="pbnMateriLain(this)"></div>`;
  }
  // Pembinaan Tenaga SAR tab Pembelajaran: Peserta dipilih dari Daftar Anggota (tab Samapta tetap isian teks)
  if(key==='pembinaan' && f.key==='peserta'){
    const dipilih = String(val||'').split(';').map(s=>s.trim()).filter(Boolean);
    const aktif = (DB.anggota||[]).filter(a=>a.status==='Aktif' || dipilih.includes(a.nama));
    const namaAng = new Set((DB.anggota||[]).map(a=>a.nama));
    const lain = dipilih.filter(n=>!namaAng.has(n)); // isian lama yang bukan nama anggota tetap dipertahankan
    const items = lain.concat(aktif.map(a=>a.nama));
    return `<input type="hidden" data-field="peserta" id="pbn_peserta_val" value="${esc(dipilih.join('; '))}">
      <button type="button" id="pbn_peserta_btn" class="btn btn-ghost" style="width:100%; justify-content:space-between;" onclick="pbnPesertaToggle()"><span id="pbn_peserta_label">Pilih Peserta</span><span id="pbn_peserta_arrow" aria-hidden="true">&#9662;</span></button>
      <div id="pbn_peserta_panel" style="display:none; margin-top:.4rem;">
      <input type="text" id="pbn_peserta_cari" placeholder="Cari nama anggota..." autocomplete="off" oninput="pbnPesertaFilter(this.value)" style="margin-bottom:.4rem;">
      <div id="pbn_peserta_list" style="max-height:190px; overflow-y:auto; border:1px solid var(--line); border-radius:.5rem; background:var(--bg); padding:.3rem;">
        ${items.map(n=>`<label class="pbn-peserta-item flex items-center gap-2 px-2 py-1 rounded" data-cari="${esc(n.toLowerCase())}" style="cursor:pointer; font-size:.82rem;">
          <input type="checkbox" class="pbn-peserta-chk" value="${esc(n)}" style="width:1rem;height:1rem;" ${dipilih.includes(n)?'checked':''} onchange="pbnPesertaSync()"><span>${esc(n)}</span></label>`).join('') || '<p class="text-xs p-2" style="color:var(--muted);">Daftar Anggota masih kosong.</p>'}
      </div>
      </div>
      <div id="pbn_peserta_info" class="text-[11px] mt-1" style="color:var(--muted);"></div>`;
  }
  // Pembinaan Tenaga SAR tab Pembelajaran: Dokumentasi berupa unggah file (format apa saja, bisa banyak file)
  if(key==='pembinaan' && f.key==='dokumentasi'){
    pbnDokExisting = Array.isArray(row && row.dokumentasi_files) ? row.dokumentasi_files.map(x=>Object.assign({},x)) : [];
    pbnDokRemoved = [];
    const lama = (row && row.dokumentasi && !pbnDokExisting.length) ? String(row.dokumentasi) : '';
    return `<input type="hidden" data-field="dokumentasi" id="pbn_dok_val" value="${esc(val===undefined?'':val)}">
      <input type="file" id="pbn_dok_input" multiple onchange="pbnDokRender()" style="width:100%;">
      <div class="text-[11px] mt-1" style="color:var(--muted);">Boleh semua jenis file (PDF, foto, video, dokumen, arsip, dll), maks. 25 MB per file.</div>
      ${lama ? `<div class="text-[11px] mt-1" style="color:var(--muted);">Tautan sebelumnya: ${esc(lama)}</div>` : ''}
      <div id="pbn_dok_list" class="mt-1"></div>`;
  }
  if(key==='uji_pemeliharaan' && f.key==='nama_peralatan'){
    const lst = sumdaBarangList();
    const cur = row ? (sumdaBarangById(row.barang_id) || null) : null;
    const awal = cur ? cur.label : (val||'');
    return `<input type="text" data-field="nama_peralatan" list="sumda_barang_list" value="${esc(awal)}" placeholder="Ketik untuk mencari peralatan / kendaraan..." autocomplete="off">
      <datalist id="sumda_barang_list">${lst.map(o=>`<option value="${esc(o.label)}"></option>`).join('')}</datalist>
      <div class="text-[11px] mt-1" style="color:var(--muted);">Pilih dari data modul Peralatan / Kendaraan. ID mengikuti data yang dipilih.</div>`;
  }
  if(f.type==='select'){
    // Nilai lama yang tidak ada di daftar pilihan tetap ditampilkan agar tidak hilang saat Ubah
    const opts = (val && !f.options.includes(val)) ? [val].concat(f.options) : f.options;
    return `<select data-field="${f.key}">${opts.map(o=>`<option value="${esc(o)}" ${val===o?'selected':''}>${esc(o)}</option>`).join('')}</select>`;
  }
  if(f.type==='textarea'){
    return `<textarea data-field="${f.key}" rows="2">${v}</textarea>`;
  }
  if(f.type==='computed'){
    return `<input type="number" data-field="${f.key}" value="${v||0}" readonly disabled style="background:var(--line); font-weight:600; cursor:not-allowed;">`
      + (f.minusBorrowed ? `<div class="text-[11px] mt-1" style="color:var(--muted);">Berkurang saat dipinjam atau sedang dipelihara, kembali otomatis saat dikembalikan / pemeliharaan selesai.</div>` : '');
  }
  if(key==='peminjaman' && f.key==='nama_barang'){
    return `<select data-field="nama_barang" id="pmj_barang"></select>
      <div id="pmj_hint" class="text-[11px] mt-1" style="color:var(--muted);"></div>`;
  }
  if(key==='pemeliharaan' && f.key==='nama_barang' && pengajuanKatForm==='Pemeliharaan'){
    return `<select data-field="nama_barang" id="phr_barang"></select>
      <div id="phr_hint" class="text-[11px] mt-1" style="color:var(--muted);"></div>`;
  }
  return `<input type="${f.type}" data-field="${f.key}" value="${v}"${(f.type==='number' && (f.laut || f.decimal)) ? ' step="any" min="0"' : ''}${f.placeholder ? ` placeholder="${esc(f.placeholder)}"` : ''}>`;
}

/* Dokumentasi Pembinaan (tab Pembelajaran): file apa saja, disimpan di server lewat fdbPut */
let pbnDokExisting = [], pbnDokRemoved = [];
function pbnDokSize(n){ n = n||0; return n>=1048576 ? (n/1048576).toFixed(1)+' MB' : Math.max(1,Math.round(n/1024))+' KB'; }
function pbnDokRender(){
  const box = document.getElementById('pbn_dok_list'); if(!box) return;
  const inp = document.getElementById('pbn_dok_input');
  const baru = inp && inp.files ? Array.from(inp.files) : [];
  box.innerHTML = pbnDokExisting.map((f,i)=>`<div class="flex items-center gap-2 text-xs py-0.5"><span style="flex:1; word-break:break-all;">📎 ${esc(f.name)} <span style="color:var(--muted);">(${pbnDokSize(f.size)})</span></span>
      <button type="button" class="btn btn-ghost !py-0.5 !px-2 text-xs" onclick="pbnDokUnduh('${esc(f.fid)}')">Unduh</button>
      <button type="button" class="btn btn-ghost !py-0.5 !px-2 text-xs" style="color:var(--bad);" onclick="pbnDokHapus(${i})">Hapus</button></div>`).join('')
    + baru.map(f=>`<div class="text-xs py-0.5" style="word-break:break-all;">🆕 ${esc(f.name)} <span style="color:var(--muted);">(${pbnDokSize(f.size)}) — diunggah saat Simpan</span></div>`).join('');
}
function pbnDokHapus(i){ const f = pbnDokExisting[i]; if(!f) return; pbnDokRemoved.push(f.fid); pbnDokExisting.splice(i,1); pbnDokRender(); }
async function pbnDokUnduh(fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    const url = URL.createObjectURL(rec.blob);
    const a = document.createElement('a'); a.href = url; a.download = rec.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 5000);
  }catch(e){ toast('Gagal mengunduh file'); }
}
function pbnDokDetailHtml(row){
  const fl = Array.isArray(row.dokumentasi_files) ? row.dokumentasi_files : [];
  if(!fl.length) return esc(row.dokumentasi||'');
  // Tampilan singkat: tombol "Lihat File" (PDF dibuka di tab baru, jenis lain diunduh)
  return fl.map((f,i)=>`<div><button type="button" class="btn btn-ghost !py-0.5 !px-2 text-xs" title="${esc(f.name)}" onclick="event.stopPropagation(); pbnDokLihat('${esc(f.fid)}')">Lihat File${fl.length>1 ? ' '+(i+1) : ''}</button></div>`).join('');
}
async function pbnDokLihat(fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    if(!(rec.blob.type==='application/pdf' || /\.pdf$/i.test(rec.name))) return pbnDokUnduh(fid); // bukan PDF: unduh
    const blob = rec.blob.type==='application/pdf' ? rec.blob : new Blob([rec.blob], {type:'application/pdf'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 60000);
  }catch(e){ toast('Gagal membuka file'); }
}
/* Peserta Pembinaan (tab Pembelajaran): cari, centang, dan Jumlah Peserta terisi otomatis */
function pbnPesertaFilter(q){
  const nq = normSearch(q);
  document.querySelectorAll('#pbn_peserta_list .pbn-peserta-item').forEach(el=>{
    el.style.display = (!nq || normSearch(el.getAttribute('data-cari')).includes(nq)) ? '' : 'none';
  });
}
function pbnPesertaSync(){
  const dipilih = Array.from(document.querySelectorAll('#pbn_peserta_list .pbn-peserta-chk:checked')).map(el=>el.value);
  const hid = document.getElementById('pbn_peserta_val'); if(hid) hid.value = dipilih.join('; ');
  const jml = document.querySelector('[data-field="jumlah_peserta"]'); if(jml) jml.value = dipilih.length;
  const info = document.getElementById('pbn_peserta_info'); if(info) info.textContent = dipilih.length ? dipilih.length + ' peserta dipilih dari Daftar Anggota' : '';
  const lbl = document.getElementById('pbn_peserta_label'); if(lbl) lbl.textContent = dipilih.length ? 'Pilih Peserta (' + dipilih.length + ' dipilih)' : 'Pilih Peserta';
}
function pbnPesertaToggle(){
  const p = document.getElementById('pbn_peserta_panel'); if(!p) return;
  const buka = p.style.display === 'none';
  p.style.display = buka ? '' : 'none';
  const a = document.getElementById('pbn_peserta_arrow'); if(a) a.innerHTML = buka ? '&#9652;' : '&#9662;';
  if(buka){ const c = document.getElementById('pbn_peserta_cari'); if(c) c.focus(); }
}
function wirePesertaPembinaan(key){
  if(key==='pembinaan') pbnDokRender();
  if(key!=='pembinaan' || !document.getElementById('pbn_peserta_list')) return;
  const jml = document.querySelector('[data-field="jumlah_peserta"]');
  if(jml){ jml.readOnly = true; jml.style.background = 'var(--line)'; jml.style.cursor = 'not-allowed'; }
  pbnPesertaSync();
}
function wireComputedFields(fields){
  const q = k => document.querySelector(`[data-field="${k}"]`);
  fields.filter(f=>f.type==='computed').forEach(cf=>{
    const target = q(cf.key);
    if(!target) return;
    const recompute = ()=>{
      let sum = 0;
      (cf.computeFrom||[]).forEach(k=>{ const src = q(k); sum += src ? (parseFloat(src.value)||0) : 0; });
      if(cf.minusBorrowed){ const n = q('nama_barang'); sum = Math.max(0, sum - peralatanDipinjam(n ? n.value : '') - peralatanDipelihara(n ? n.value : '')); }
      target.value = sum;
    };
    (cf.computeFrom||[]).forEach(k=>{ const src = q(k); if(src) src.addEventListener('input', recompute); });
    if(cf.minusBorrowed){ const n = q('nama_barang'); if(n) n.addEventListener('input', recompute); }
    recompute();
  });
}

/* Peminjaman <-> Peralatan: dropdown barang mengikuti Jenis, menampilkan stok Tersedia, dan membatasi jumlah pinjam. */
/* Kunci lokasi: "Pos Sukabumi" dan "Pos SAR Sukabumi" dianggap lokasi yang sama. */
function pmjLokKey(s){ return normNama(s).replace(/\bsar\b/g,'').replace(/\s+/g,' ').trim(); }
/* Daftar lokasi untuk dropdown Lokasi di Daftar Barang: pilihan bawaan modul + lokasi yang sudah ada di data. */
function pmjLokasiOpsi(jenis){
  const kend = jenis==='Kendaraan';
  const mod = EXTRA_MODULES.find(m=>m.key===(kend?'kendaraan':'peralatan'));
  const f = mod && mod.fields.find(x=>x.key==='lokasi');
  const out = [], seen = new Set();
  const tambah = v => { const k = pmjLokKey(v); if(!k || seen.has(k)) return; seen.add(k); out.push(String(v).trim()); };
  ((f && f.options) || []).forEach(tambah);
  (DB[kend?'kendaraan':'peralatan']||[]).forEach(r=>tambah(r.lokasi));
  return out;
}
/* lokasi (opsional): hanya barang yang berada di lokasi tersebut. */
function pmjOpsi(jenis, exceptId, lokasi){
  const lk = pmjLokKey(lokasi);
  const cocok = r => !lk || pmjLokKey(r.lokasi)===lk;
  if(jenis==='Kendaraan'){
    return (DB.kendaraan||[]).filter(cocok).map(k=>({value:k.nama_kendaraan, lokasi:k.lokasi||'', label:k.nama_kendaraan + (k.nomor_polisi?' ['+k.nomor_polisi+']':'') + (kendaraanDipelihara(k.nama_kendaraan)?' — sedang dipelihara':''), stok:null}));
  }
  return (DB.peralatan||[]).filter(cocok).map(a=>{
    const stok = Math.max(0, peralatanBaik(a) - peralatanDipinjam(a.nama_barang, exceptId) - peralatanDipelihara(a.nama_barang));
    return {value:a.nama_barang, lokasi:a.lokasi||'', label:a.nama_barang + (a.kode_rak?' ['+a.kode_rak+']':'') + ' — tersedia ' + stok, stok};
  });
}
function wirePeminjamanForm(key, id, row){
  if(key!=='peminjaman') return;
  const q = k => document.querySelector(`[data-field="${k}"]`);
  const jenisEl = q('jenis_barang'), namaEl = q('nama_barang'), jmlEl = q('jumlah'), hint = document.getElementById('pmj_hint');
  const statusEl = q('status'), kembaliEl = q('tanggal_kembali');
  if(!jenisEl || !namaEl) return;
  let pilih = row ? row.nama_barang : '';
  const isiOpsi = ()=>{
    const ops = pmjOpsi(jenisEl.value, id);
    if(pilih && !ops.some(o=>o.value===pilih)) ops.unshift({value:pilih, label:pilih, stok:null});
    namaEl.innerHTML = ops.length
      ? `<option value="">— Pilih ${jenisEl.value==='Kendaraan'?'kendaraan':'peralatan'} —</option>` + ops.map(o=>`<option value="${esc(o.value)}" ${o.value===pilih?'selected':''}>${esc(o.label)}</option>`).join('')
      : `<option value="">(Belum ada data ${jenisEl.value==='Kendaraan'?'Kendaraan':'Peralatan'})</option>`;
    updateHint();
  };
  const updateHint = ()=>{
    pilih = namaEl.value;
    if(jenisEl.value!=='Peralatan' || !pilih){ hint.textContent = ''; if(jmlEl) jmlEl.removeAttribute('max'); return; }
    const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(pilih));
    if(!alat){ hint.textContent = ''; return; }
    const stok = Math.max(0, peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang, id) - peralatanDipelihara(alat.nama_barang));
    hint.textContent = 'Stok tersedia: ' + stok + ' dari ' + peralatanBaik(alat) + ' unit kondisi Baik (unit Rusak ' + (parseFloat(alat.jumlah_rusak)||0) + ' tidak dihitung). Tersedia di modul Peralatan akan berkurang sesuai jumlah pinjam.';
    if(jmlEl) jmlEl.setAttribute('max', stok);
  };
  jenisEl.addEventListener('change', ()=>{ pilih = ''; isiOpsi(); });
  namaEl.addEventListener('change', updateHint);
  if(jmlEl && !jmlEl.value) jmlEl.value = 1;
  // Status dan Tanggal Dikembalikan saling sinkron: dikembalikan => stok Peralatan pulih.
  if(statusEl && kembaliEl){
    statusEl.addEventListener('change', ()=>{ if(statusEl.value==='Dikembalikan' && !kembaliEl.value) kembaliEl.value = todayISO(); });
    kembaliEl.addEventListener('change', ()=>{ if(kembaliEl.value) statusEl.value = 'Dikembalikan'; });
  }
  isiOpsi();
}
/* Pemeliharaan: dropdown barang mengikuti Jenis (Peralatan / Kendaraan) dan menampilkan info status peminjaman. */
function phrOpsi(jenis){
  if(jenis==='Kendaraan'){
    return (DB.kendaraan||[]).map(k=>({value:k.nama_kendaraan, label:k.nama_kendaraan + (k.nomor_polisi?' ['+k.nomor_polisi+']':'')}));
  }
  return (DB.peralatan||[]).map(a=>({value:a.nama_barang, label:a.nama_barang + (a.kode_rak?' ['+a.kode_rak+']':'')}));
}
function kendaraanSedangDipinjam(nama){
  const n = normNama(nama); if(!n) return null;
  return (DB.peminjaman||[]).find(p=>p.jenis_barang==='Kendaraan' && normNama(p.nama_barang)===n && p.status!=='Dikembalikan' && p.status!=='Ditolak' && !p.tanggal_kembali) || null;
}
function wirePemeliharaanForm(key, id, row){
  if(key!=='pemeliharaan' || pengajuanKatForm!=='Pemeliharaan') return;
  const q = k => document.querySelector(`[data-field="${k}"]`);
  const jenisEl = q('jenis_barang'), namaEl = q('nama_barang'), hint = document.getElementById('phr_hint');
  const statusEl = q('status'), selesaiEl = q('tanggal_selesai'), tglEl = q('tanggal'), kmEl = q('km_saat_ini'), jmlEl = q('jumlah');
  if(!jenisEl || !namaEl) return;
  let pilih = row ? row.nama_barang : '';
  const updateHint = ()=>{
    pilih = namaEl.value;
    const kend = jenisEl.value==='Kendaraan';
    if(kmEl){ const w = kmEl.closest('div'); if(w) w.style.display = kend ? '' : 'none'; }
    if(jmlEl){ const w = jmlEl.closest('div'); if(w) w.style.display = kend ? 'none' : ''; if(!kend && !jmlEl.value) jmlEl.value = 1; }
    if(!pilih){ hint.textContent = ''; return; }
    if(kend){
      const pj = kendaraanSedangDipinjam(pilih);
      const k = (DB.kendaraan||[]).find(x=>normNama(x.nama_kendaraan)===normNama(pilih));
      hint.textContent = (k && k.kondisi ? 'Kondisi saat ini: ' + k.kondisi + '. Setelah pengajuan disetujui, kondisi kendaraan otomatis menjadi Unserviceable saat Sedang Dikerjakan, dan Serviceable bila selesai dengan kondisi Baik. ' : '') + (pj ? 'Perhatian: kendaraan ini masih tercatat dipinjam oleh ' + pj.nama_peminjam + '.' : '');
    } else {
      const a = (DB.peralatan||[]).find(x=>normNama(x.nama_barang)===normNama(pilih));
      if(!a){ hint.textContent = ''; return; }
      const dip = peralatanDipinjam(a.nama_barang);
      const dpl = peralatanDipelihara(a.nama_barang, id);
      hint.textContent = 'Jumlah Baik ' + peralatanBaik(a) + ', Rusak ' + (parseFloat(a.jumlah_rusak)||0) + (dip ? ', sedang dipinjam ' + dip : '') + (dpl ? ', sedang dipelihara ' + dpl : '') + '. Selama status Sedang Dikerjakan dan pengajuan sudah disetujui, stok Tersedia di Peralatan berkurang sesuai Jumlah Unit.';
      if(jmlEl) jmlEl.setAttribute('max', Math.max(0, peralatanBaik(a) - dip - dpl));
    }
  };
  const isiOpsi = ()=>{
    const ops = phrOpsi(jenisEl.value);
    if(pilih && !ops.some(o=>o.value===pilih)) ops.unshift({value:pilih, label:pilih});
    const nm = jenisEl.value==='Kendaraan' ? 'kendaraan' : 'peralatan';
    namaEl.innerHTML = ops.length
      ? `<option value="">— Pilih ${nm} —</option>` + ops.map(o=>`<option value="${esc(o.value)}" ${o.value===pilih?'selected':''}>${esc(o.label)}</option>`).join('')
      : `<option value="">(Belum ada data ${jenisEl.value})</option>`;
    updateHint();
  };
  jenisEl.addEventListener('change', ()=>{ pilih = ''; isiOpsi(); });
  namaEl.addEventListener('change', updateHint);
  if(tglEl && !tglEl.value && !row) tglEl.value = todayISO();
  // Status dan Tanggal Selesai saling sinkron.
  if(statusEl && selesaiEl){
    statusEl.addEventListener('change', ()=>{ if(statusEl.value==='Selesai' && !selesaiEl.value) selesaiEl.value = todayISO(); });
    selesaiEl.addEventListener('change', ()=>{ if(selesaiEl.value) statusEl.value = 'Selesai'; });
  }
  isiOpsi();
}
/* Peminjaman baru: Daftar Barang (multi barang). Tiap barang jadi satu data Peminjaman sendiri; Ubah data tetap satu barang. */
let pmjBaru = false;
function pmjBlokHtml(){
  return `<div class="sm:col-span-2">
    <label class="block text-xs font-medium mb-1" style="color:var(--muted);">Daftar Barang *</label>
    <div class="text-[11px] mb-2" style="color:var(--muted);">Pilih dari barang yang tersedia. Bisa meminjam lebih dari satu barang atau jenis; barang yang sama tidak bisa dipilih dua kali.</div>
    <div id="pmj_rows" class="space-y-2"></div>
    <button type="button" class="btn btn-ghost mt-2" onclick="pmjTambahBaris()">+ Tambah Barang</button>
  </div>`;
}
function pmjBarisEl(){ return Array.from(document.querySelectorAll('#pmj_rows .pmj-r')); }
function pmjUbah(r){
  const jenis = r.querySelector('.pmj-j').value, nama = r.querySelector('.pmj-b').value;
  const q = r.querySelector('.pmj-q'), hint = r.querySelector('.pmj-h');
  if(jenis==='Kendaraan'){
    q.value = 1; q.readOnly = true; q.removeAttribute('max');
    hint.textContent = (nama && kendaraanDipelihara(nama)) ? 'Perhatian: kendaraan ini sedang dalam pemeliharaan.' : '';
    return;
  }
  q.readOnly = false;
  const o = pmjOpsi('Peralatan', null).find(x=>x.value===nama);
  if(o){
    q.max = o.stok; hint.textContent = 'Stok tersedia: ' + o.stok + ' unit';
    if((parseFloat(q.value)||0) > o.stok) q.value = o.stok>0 ? o.stok : 1;
  }else{ q.removeAttribute('max'); hint.textContent = ''; }
}
function pmjSegarkan(){
  const rs = pmjBarisEl();
  rs.forEach((r,i)=>{
    r.querySelector('.pmj-no').textContent = 'Barang ' + (i+1);
    r.querySelector('.pmj-del').style.display = rs.length>1 ? '' : 'none';
    const jenis = r.querySelector('.pmj-j').value, sel = r.querySelector('.pmj-b'), cur = sel.value;
    const dipakai = new Set(rs.filter(x=>x!==r && x.querySelector('.pmj-j').value===jenis).map(x=>x.querySelector('.pmj-b').value).filter(Boolean));
    // Dropdown Lokasi (mengikuti Jenis); pilihan yang sedang dipakai dipertahankan bila masih ada.
    const lokEl = r.querySelector('.pmj-l'), lokCur = lokEl.value;
    const lokOps = pmjLokasiOpsi(jenis);
    lokEl.innerHTML = '<option value="">Semua lokasi</option>' + lokOps.map(l=>`<option value="${esc(l)}" ${pmjLokKey(l)===pmjLokKey(lokCur)?'selected':''}>${esc(l)}</option>`).join('');
    const lokasi = lokEl.value;
    // Pencarian nama barang (barang yang sedang dipilih tetap tampil walau tidak cocok dengan kata kunci).
    const kata = normNama(r.querySelector('.pmj-s').value);
    const semua = pmjOpsi(jenis, null, lokasi);
    const ops = semua.filter(o=>!kata || o.value===cur || normNama(o.label+' '+o.lokasi).includes(kata));
    const nm = jenis==='Kendaraan' ? 'kendaraan' : 'peralatan';
    sel.innerHTML = ops.length
      ? `<option value="">— Pilih ${nm}${lokasi ? ' di ' + esc(lokasi) : ''} (${ops.length}) —</option>` + ops.map(o=>{
          const habis = jenis==='Kendaraan' ? !!kendaraanSedangDipinjam(o.value) : (o.stok||0) <= 0;
          const label = o.label + ((jenis==='Kendaraan' && habis) ? ' — sedang dipinjam' : '') + ((!lokasi && o.lokasi) ? ' · ' + o.lokasi : '');
          return `<option value="${esc(o.value)}" ${(habis || dipakai.has(o.value)) ? 'disabled' : ''} ${o.value===cur ? 'selected' : ''}>${esc(label)}</option>`;
        }).join('')
      : `<option value="">${semua.length ? '(Tidak ada ' + nm + ' yang cocok dengan pencarian)' : (lokasi ? '(Tidak ada ' + nm + ' di ' + esc(lokasi) + ')' : '(Belum ada data ' + jenis + ')')}</option>`;
    pmjUbah(r);
  });
}
function pmjGanti(el, barang){
  const r = el.closest('.pmj-r');
  if(!barang) r.querySelector('.pmj-b').value = '';
  if(el.classList.contains('pmj-j')) r.querySelector('.pmj-s').value = '';
  r.querySelector('.pmj-q').value = 1;
  pmjSegarkan();
}
function pmjHapusBaris(el){ el.closest('.pmj-r').remove(); pmjSegarkan(); }
function pmjTambahBaris(){
  const box = document.getElementById('pmj_rows'); if(!box) return;
  const d = document.createElement('div');
  d.className = 'pmj-r rounded-lg p-3'; d.style.cssText = 'border:1px solid var(--line);';
  const lb = 'class="block text-xs font-medium mb-1" style="color:var(--muted);"';
  d.innerHTML = `<div class="flex items-center justify-between mb-2"><b class="pmj-no text-xs"></b><button type="button" class="pmj-del btn btn-ghost !px-2 !py-0" title="Hapus barang" onclick="pmjHapusBaris(this)">✕</button></div>
    <div class="grid sm:grid-cols-2 gap-2">
      <div><label ${lb}>Jenis</label><select class="pmj-j" onchange="pmjGanti(this)"><option>Peralatan</option><option>Kendaraan</option></select></div>
      <div><label ${lb}>Lokasi</label><select class="pmj-l" onchange="pmjGanti(this)"><option value="">Semua lokasi</option></select></div>
    </div>
    <div class="mt-2"><label ${lb}>Barang *</label>
      <input type="search" class="pmj-s mb-1" placeholder="Cari nama barang / kendaraan…" autocomplete="off" oninput="pmjSegarkan()">
      <select class="pmj-b" onchange="pmjGanti(this,true)"></select></div>
    <div class="mt-2"><label ${lb}>Jumlah</label><input type="number" class="pmj-q" min="1" step="1" value="1" oninput="pmjUbah(this.closest('.pmj-r'))">
      <div class="pmj-h text-[11px] mt-1" style="color:var(--muted);"></div></div>`;
  box.appendChild(d);
  pmjSegarkan();
}
function pmjKumpul(){
  return pmjBarisEl().map(r=>({jenis:r.querySelector('.pmj-j').value, nama:r.querySelector('.pmj-b').value, jumlah:parseInt(r.querySelector('.pmj-q').value,10)||0}));
}
function pmjCek(items, data){
  if(!items.length) return 'Pilih minimal 1 barang';
  const aktif = data.status!=='Dikembalikan' && !data.tanggal_kembali;
  for(let i=0;i<items.length;i++){
    const it = items[i], no = 'Barang ' + (i+1) + ': ';
    if(!it.nama) return no + 'pilih barang terlebih dahulu';
    if(it.jenis==='Kendaraan'){
      if(aktif){ const pj = kendaraanSedangDipinjam(it.nama); if(pj) return no + 'kendaraan masih dipinjam oleh ' + pj.nama_peminjam; }
      continue;
    }
    if(it.jumlah < 1) return no + 'jumlah minimal 1';
    if(aktif){
      const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(it.nama));
      if(alat){
        const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang) - peralatanDipelihara(alat.nama_barang);
        if(it.jumlah > sisa) return no + 'stok tersedia hanya ' + Math.max(0,sisa);
      }
    }
  }
  return '';
}
function openGenericForm(key, id){ if(guardRO(key)) return;
  const meta = key==='anggota' ? {label:'Anggota', fields:ANGGOTA_FIELDS, prefix:'AG'} : (()=>{const m=TABLE_MODULES.find(m=>m.key===key); return {label:m.label, fields:m.fields, prefix:m.prefix};})();
  const rows = DB[key] || [];
  const row = id ? rows.find(r=>r.id===id) : null;
  pmjBaru = (key==='peminjaman' && !row);
  pmjSembunyi = key==='peminjaman' && (!row || pmjSP(row)!=='Disetujui');
  if(key==='pemeliharaan') pengajuanKatForm = row ? pgjKat(row) : pengajuanTab;
  if(key==='pembinaan') pembinaanKatForm = row ? pbnKat(row) : pembinaanTab;

  if(PESERTA_MODULES[key] && key!=='uji_periodik'){
    pesertaKey = key;
    diklatPesertaContext = 'modal';
    diklatPesertaRows = (row && Array.isArray(row.peserta_matrix)) ? JSON.parse(JSON.stringify(row.peserta_matrix)) : [];
  }

  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-display font-semibold text-base">${row?'Ubah':'Tambah'} Data ${esc(meta.label)}${key==='pemeliharaan' ? ' ' + esc(pengajuanKatForm) : ''}${key==='pembinaan' ? ' — ' + esc(pembinaanKatForm) : ''}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3">
        ${key==='kendaraan' ? kendaraanSaprasPickerHtml(row) : ''}
        ${meta.fields.filter(f=>fieldTampilForm(key,f)).map(f=> f.type==='hidden' ? fieldInput(f, row?row[f.key]:'', key, row) : `
          <div class="${f.type==='textarea'?'sm:col-span-2':''}${f.laut?' sapras-laut-only':''}">
            <label ${f.key==='nomor_polisi' ? 'id="lbl_nomor_polisi"' : ''} class="block text-xs font-medium mb-1" style="color:var(--muted);">${esc(key==='pemeliharaan' ? pgjLabel(f, pengajuanKatForm) : (key==='pembinaan' ? pbnLabel(f, pembinaanKatForm) : f.label))}${f.required?' *':''}${f.type==='computed'?' (otomatis)':''}</label>
            ${fieldInput(f, row?row[f.key]:'', key, row)}
          </div>${(key==='peminjaman' && pmjBaru && f.key==='nama_peminjam') ? pmjBlokHtml() : ''}`).join('')}
      </div>
      ${PESERTA_MODULES[key] && key!=='uji_periodik' ? `<div class="mt-4 pt-4 border-t" style="border-color:var(--line);" id="diklat_peserta_wrap">${diklatPesertaEditorHtml()}</div>` : ''}
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveGeneric('${key}', ${row?`'${id}'`:'null'})">Simpan</button>
      </div>
    </div>`, true);
  wireComputedFields(meta.fields);
  wirePeminjamanForm(key, id, row);
  if(pmjBaru) pmjTambahBaris();
  wirePemeliharaanForm(key, id, row);
  wireKendaraanForm(row);
  wirePesertaPembinaan(key);
}

async function saveGeneric(key, id){ if(guardRO(key)) return;
  const meta = key==='anggota' ? {fields:ANGGOTA_FIELDS, prefix:'AG', label:'Anggota'} : (()=>{const m=TABLE_MODULES.find(m=>m.key===key); return {fields:m.fields, prefix:m.prefix, label:m.label};})();
  const rows = DB[key] || (DB[key]=[]);
  const data = {};
  let missing = false;
  document.querySelectorAll('[data-field]').forEach(el=>{
    const f = meta.fields.find(f=>f.key===el.getAttribute('data-field'));
    data[f.key] = el.value;
    if(f.required && !el.value.trim()) missing = true;
  });
  if(missing){ toast('Mohon lengkapi kolom bertanda *'); return; }
  if(key==='uji_pemeliharaan'){
    const it = sumdaBarangCari(data.nama_peralatan);
    if(!it){ toast('Pilih peralatan / kendaraan dari daftar yang tersedia'); return; }
    data.barang_id = it.id; data.barang_jenis = it.jenis; data.nama_peralatan = it.nama;
  }

  // Pembinaan > Pembelajaran: unggah file dokumentasi baru, lalu simpan metadatanya
  let dokBaru = null;
  if(key==='pembinaan' && document.getElementById('pbn_dok_input')){
    const files = Array.from(document.getElementById('pbn_dok_input').files || []);
    if(files.some(f=>f.size > HASIL_MAX_BYTES)){ toast('Ada file yang melebihi 25 MB'); return; }
    const up = [];
    if(files.length) toast('Mengunggah dokumentasi...');
    try{
      for(const f of files){
        const fid = 'D'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
        await fdbPut({fid, kid:id||'', name:f.name, blob:f});
        up.push({fid, name:f.name, size:f.size, type:f.type||'', uploaded:nowStr()});
      }
    }catch(e){ up.forEach(x=>fdbDel(x.fid)); toast('Gagal mengunggah file: ' + (e.message||'')); return; }
    dokBaru = pbnDokExisting.concat(up);
    data.dokumentasi_files = dokBaru;
    if(dokBaru.length) data.dokumentasi = dokBaru.map(x=>x.name).join('; ');
    pbnDokRemoved.forEach(fid=>fdbDel(fid));
  }

  if(key==='kendaraan' && data.sapras!=='Sapras Laut'){
    meta.fields.filter(f=>f.laut).forEach(f=>{ data[f.key] = ''; });
  }
  if(key==='peminjaman' && !pmjSembunyi){
    if(data.status==='Dikembalikan' && !data.tanggal_kembali) data.tanggal_kembali = todayISO();
    if(data.tanggal_kembali) data.status = 'Dikembalikan';
  }
  let pmjItems = null; // Peminjaman baru: daftar barang (multi barang)
  if(key==='peminjaman' && !id && document.getElementById('pmj_rows')){
    pmjItems = pmjKumpul();
    const errPmj = pmjCek(pmjItems, data);
    if(errPmj){ toast(errPmj); return; }
  }
  if(key==='peminjaman' && data.jenis_barang==='Peralatan' && data.status!=='Dikembalikan' && !data.tanggal_kembali){
    const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(data.nama_barang));
    if(alat){
      const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang, id) - peralatanDipelihara(alat.nama_barang);
      if((parseFloat(data.jumlah)||1) > sisa){ toast('Stok tersedia hanya '+Math.max(0,sisa)+' - jumlah pinjam melebihi stok'); return; }
    }
  }

  if(key==='pemeliharaan') data.kategori_pengajuan = pengajuanKatForm;
  if(key==='pembinaan') data.kategori_pembinaan = pembinaanKatForm;
  if(key==='pemeliharaan' && pengajuanKatForm==='Kebutuhan'){ if(!data.jumlah_kebutuhan) data.jumlah_kebutuhan = 1; }
  if(key==='pemeliharaan' && pengajuanKatForm==='Pemeliharaan'){
    if(data.status==='Selesai' && !data.tanggal_selesai) data.tanggal_selesai = todayISO();
    if(data.tanggal_selesai) data.status = 'Selesai';
    if(data.tanggal && data.tanggal_selesai && data.tanggal_selesai < data.tanggal){ toast('Tanggal Selesai tidak boleh sebelum Tanggal Pemeliharaan'); return; }
    if(data.jenis_barang!=='Kendaraan') data.km_saat_ini = '';
    if(data.jenis_barang==='Kendaraan') data.jumlah = '';
    else {
      if(!data.jumlah) data.jumlah = 1;
      const alat = (DB.peralatan||[]).find(a=>normNama(a.nama_barang)===normNama(data.nama_barang));
      if(alat && data.status==='Sedang Dikerjakan'){
        const sisa = peralatanBaik(alat) - peralatanDipinjam(alat.nama_barang) - peralatanDipelihara(alat.nama_barang, id);
        if((parseFloat(data.jumlah)||1) > sisa){ toast('Stok tersedia hanya '+Math.max(0,sisa)+' - jumlah unit melebihi stok'); return; }
      }
    }
  }

  if(PESERTA_MODULES[key] && key!=='uji_periodik'){
    data.peserta_matrix = diklatPesertaRows.map(r=>Object.assign({}, r));
  }
  data.diperbarui_oleh = pelaku(); // jejak: Nama sebagai (Peran)
  data.diperbarui_waktu = nowStr();

  if(id){
    const idx = rows.findIndex(r=>r.id===id);
    if(key==='pemeliharaan' && pgjSP(rows[idx])==='Ditolak'){ // diperbaiki setelah ditolak = diajukan ulang
      data.status_persetujuan = 'Menunggu Persetujuan'; data.alasan_penolakan = ''; data.keterangan_persetujuan = '';
      data.diajukan_oleh = pelaku(); data.diajukan_waktu = nowStr();
    }
    if(key==='peminjaman' && pmjSP(rows[idx])!=='Disetujui'){
      // Ditolak lalu diperbaiki = diajukan ulang; yang masih Menunggu diubah = permintaan ACC dikirim ulang dengan data terbaru.
      data.status = 'Menunggu'; data.status_persetujuan = 'Menunggu Persetujuan'; data.alasan_penolakan = ''; data.keterangan_persetujuan = '';
      data.diajukan_oleh = pelaku(); data.diajukan_waktu = nowStr();
      ['acc_oleh','acc_nama','acc_waktu','acc_via','acc_nomor'].forEach(k=>{ data[k] = ''; });
    }
    rows[idx] = Object.assign({}, rows[idx], data);
    const nSync = syncKegiatanFromModule(key, rows[idx]);
    toast(nSync ? 'Data diperbarui, Kegiatan/Tugas terkait ikut menyesuaikan' : 'Data diperbarui');
  }else{
    // Peminjaman multi barang: satu data per barang (data umum dibagi bersama); selain itu satu data saja.
    const daftarBaru = pmjItems
      ? pmjItems.map(it=>Object.assign({}, data, {jenis_barang:it.jenis, nama_barang:it.nama, jumlah: it.jenis==='Kendaraan' ? 1 : it.jumlah}))
      : [data];
    daftarBaru.forEach(d=>{
      d.id = uid(meta.prefix, rows);
      if(key==='pemeliharaan'){ d.status_persetujuan = 'Menunggu Persetujuan'; d.diajukan_oleh = pelaku(); d.diajukan_waktu = nowStr(); }
      if(key==='peminjaman'){ // menunggu ACC penyetuju peminjaman (WhatsApp / dashboard) -> Dipinjam / Ditolak
        d.status = 'Menunggu'; d.status_persetujuan = 'Menunggu Persetujuan'; d.alasan_penolakan = ''; d.keterangan_persetujuan = ''; d.tanggal_kembali = '';
        d.diajukan_oleh = pelaku(); d.diajukan_waktu = nowStr();
      }
      d.tahun_input = new Date().getFullYear(); d.bulan_input = new Date().getMonth()+1;
      d.tanggal_input = new Date().toLocaleDateString('sv-SE'); // yyyy-mm-dd lokal, acuan filter tanggal Dashboard
      rows.push(d);
    });
    toast(daftarBaru.length>1 ? daftarBaru.length + ' barang peminjaman ditambahkan' : 'Data ditambahkan');
  }
  if(key==='pemeliharaan') syncKendaraanDariPemeliharaan(id ? rows.find(r=>r.id===id) : data);
  if(key==='pembinaan' && ABS_SUMBER_AUTO.includes(pembinaanKatForm)) pbnAbsSync(DB, id ? rows.find(r=>r.id===id) : data);
  persist();
  closeModal();
  render(currentView);
}

// Kegiatan/Tugas yang terhubung dengan satu data modul: kegiatan yang membuat data
// itu (dari_kegiatan_id) atau kegiatan yang menunjuk data itu (linked_record_id).
function kegiatanTerkaitRecord(key, rec){
  if(!rec) return [];
  return (DB.kegiatan||[]).filter(k =>
    (rec.dari_kegiatan_id && k.id===rec.dari_kegiatan_id) ||
    (k.jenis===key && k.linked_record_id===rec.id)
  );
}
function deleteGeneric(key, id, label){ if(guardRO(key)) return;
  const rec = (DB[key]||[]).find(r=>r.id===id);
  if(key==='pemeliharaan' && !canManagePengajuan(rec)){ toast('Pengajuan yang sudah disetujui hanya dapat dihapus oleh ' + PENGAJUAN_ACC_ROLES.join('/') + ' atau Admin Utama'); return; }
  const terkait = kegiatanTerkaitRecord(key, rec);
  const pesan = terkait.length
    ? `Hapus data ${label} "${id}"?\n\nKegiatan/Tugas terkait (${terkait.map(k=>k.id).join(', ')}) juga akan ikut terhapus.`
    : `Hapus data ${label} "${id}"?`;
  if(!confirm(pesan)) return;
  if(key==='pembinaan' && rec) (rec.dokumentasi_files||[]).forEach(f=>fdbDel(f.fid));
  if(key==='pembinaan' && rec && ABS_SUMBER_AUTO.includes(pbnKat(rec))) pbnAbsHapusSumber(rec.id);
  DB[key] = (DB[key]||[]).filter(r=>r.id!==id);
  if(terkait.length){
    const hapusIds = new Set(terkait.map(k=>k.id));
    (DB.kegiatan||[]).filter(k=>hapusIds.has(k.id)).forEach(k=>{ (k.hasil_files||[]).forEach(f=>fdbDel(f.fid)); (k.bukti_files||[]).forEach(f=>fdbDel(f.fid)); lampiranWaList(k).forEach(f=>fdbDel(f.fid)); if(k.sprin) fdbDel(k.sprin.fid); });
    DB.kegiatan = (DB.kegiatan||[]).filter(k=>!hapusIds.has(k.id));
  }
  persist();
  toast(terkait.length ? 'Data dan Kegiatan/Tugas terkait dihapus' : 'Data dihapus');
  render(currentView);
}

/* ============================= AUTO-ISI DATA MODUL DARI KEGIATAN ============================= */
// Saat Kegiatan/Tugas diajukan, data inputannya otomatis dibuatkan juga
// sebagai data baru di modul sesuai Jenis-nya (mis. Jenis=Narasumber -> juga
// tercatat di Narasumber), supaya tidak perlu input dobel.
// Kebalikan dari autoFillModuleFromKegiatan: kalau data di modul diubah, Kegiatan/Tugas
// yang terhubung (dari_kegiatan_id / linked_record_id) ikut disesuaikan.
// Mengembalikan jumlah kegiatan yang diperbarui.
function syncKegiatanFromModule(key, rec){
  const mod = GENERIC_MODULES.find(m=>m.key===key);
  if(!mod || !rec) return 0;
  const list = kegiatanTerkaitRecord(key, rec).filter(k=>k.jenis===key);
  if(!list.length) return 0;
  const has = fk => mod.fields.some(f=>f.key===fk);
  const nameField = mod.fields.find(f=>f.required) || mod.fields.find(f=>f.col && f.type==='text');
  const dateField = mod.fields.find(f=>f.type==='date');
  const pjKey = ['penanggung_jawab','pembina','petugas','instruktur'].find(fk => has(fk) && fk!==(nameField&&nameField.key));
  const notesField = has('materi') ? 'materi' : (['keterangan','hasil'].find(has));
  list.forEach(k=>{
    if(nameField && String(rec[nameField.key]||'').trim()) k.nama = rec[nameField.key];
    if(dateField && rec[dateField.key]) k.tanggal = rec[dateField.key];
    if(has('tanggal_selesai') && rec.tanggal_selesai) k.tanggal_selesai = rec.tanggal_selesai;
    else if(dateField && rec[dateField.key]) k.tanggal_selesai = rec[dateField.key];
    if(has('waktu') && rec.waktu) k.waktu = rec.waktu;
    if(has('lokasi')) k.lokasi = rec.lokasi || '';
    if(pjKey) k.penanggung_jawab = rec[pjKey] || '';
    if(has('jumlah_peserta') && parseInt(rec.jumlah_peserta)>0) k.jumlah_dibutuhkan = parseInt(rec.jumlah_peserta);
    if(notesField) k.deskripsi = rec[notesField] || '';
    k.diperbarui = nowStr();
  });
  return list.length;
}

function autoFillModuleFromKegiatan(jenisKey, k){
  if(jenisKey === 'rescuer') return null; // Rescuer mengambil data langsung dari Daftar Anggota
  const mod = GENERIC_MODULES.find(m=>m.key===jenisKey);
  if(!mod) return null;
  const rows = DB[jenisKey] || (DB[jenisKey]=[]);
  const data = {};

  const nameField = mod.fields.find(f=>f.required) || mod.fields.find(f=>f.col && f.type==='text');
  if(nameField) data[nameField.key] = k.nama;

  const dateField = mod.fields.find(f=>f.type==='date');
  if(dateField) data[dateField.key] = k.tanggal;
  if(mod.fields.some(f=>f.key==='tanggal_selesai')) data.tanggal_selesai = k.tanggal_selesai || k.tanggal;

  if(mod.fields.some(f=>f.key==='waktu')) data.waktu = k.waktu;
  if(mod.fields.some(f=>f.key==='lokasi')) data.lokasi = k.lokasi;

  const pjFieldKey = ['penanggung_jawab','pembina','petugas','instruktur'].find(fk => mod.fields.some(f=>f.key===fk) && fk!==(nameField&&nameField.key));
  if(pjFieldKey) data[pjFieldKey] = k.penanggung_jawab;

  if(mod.fields.some(f=>f.key==='jumlah_peserta')) data.jumlah_peserta = k.jumlah_dibutuhkan;

  if(mod.fields.some(f=>f.key==='materi')) data.materi = k.deskripsi;
  else {
    const notesField = mod.fields.find(f=>['keterangan','hasil'].includes(f.key));
    if(notesField) data[notesField.key] = k.deskripsi;
  }

  const statusField = mod.fields.find(f=>f.isStatus);
  if(statusField && statusField.options && statusField.options.length) data[statusField.key] = statusField.options[0];

  if(mod.fields.some(f=>f.key==='dokumentasi') && k.file_pdf) data.dokumentasi = k.file_pdf.name;

  // ID data modul dibuat SAMA PERSIS dengan ID Kegiatan/Tugas-nya (mis.
  // KGT-003 tetap KGT-003 di modul Diklat), bukan diganti prefix modul.
  // Kalau ID itu kebetulan sudah terpakai di modul tsb, baru pakai
  // penomoran otomatis biasa.
  let newId = k.id;
  if(!newId || rows.some(r=>r.id===newId)) newId = uid(mod.prefix, rows);
  data.id = newId;
  data.dari_kegiatan_id = k.id; // jejak asal data, bukan kolom yang ditampilkan tabel
  rows.push(data);
  return data.id;
}

// Kalau status Kegiatan/Tugas berubah (persetujuan/pelaksanaan), sesuaikan
// juga status data di modul terkait — dicari berdasarkan Jenis (k.jenis) dan
// ID data yang otomatis terbentuk (k.linked_record_id).
function syncModuleStatusFromKegiatan(k){
  if(!k || !k.linked_record_id) return;
  const mod = GENERIC_MODULES.find(m=>m.key===k.jenis);
  if(!mod) return;
  const rows = DB[k.jenis] || [];
  const rec = rows.find(r=>r.id===k.linked_record_id);
  if(!rec) return;
  const statusField = mod.fields.find(f=>f.isStatus);
  if(!statusField || !statusField.options) return;
  let target = null;
  if(k.status_pelaksanaan === 'Selesai') target = 'Selesai';
  else if(k.status_pelaksanaan === 'Dalam Pelaksanaan') target = 'Berlangsung';
  else if(k.status_pelaksanaan === 'Draft') target = 'Direncanakan';
  if(target && statusField.options.includes(target)){
    rec[statusField.key] = target;
  }
}

/* ============================= KEGIATAN / TUGAS ============================= */
/* ============================= PERSURATAN: SURAT MASUK & SURAT KELUAR ============================= */
// Surat Masuk = tampilan data DB.surat_masuk yang dicatat di modul Kegiatan/Tugas (satu sumber data, tidak digandakan).
// Surat Keluar = data sendiri (DB.surat_keluar), dikelola langsung di modul ini.
const SURAT_SIFAT = ['Biasa','Segera','Sangat Segera','Rahasia'];
let psTab = 'masuk';
let skPdfFile = null, skEditId = null;
function getSuratKeluar(id){ return (DB.surat_keluar||[]).find(x=>x.id===id); }
/* Surat Tugas (Sprin) yang sudah diupload Admin di Kegiatan/Tugas otomatis tampil sebagai Surat Keluar (tanpa data ganda). */
/* Keterangan proses: Belum Di Proses (default) -> Sedang Di Proses (ditandai Admin) -> Selesai (otomatis setelah file diupload). */
const PROSES_LIST = ['Belum Di Proses','Sedang Di Proses'];
function prosesBadgeHtml(st){ return `<span class="badge ${st==='Selesai'?'b-ok':(st==='Sedang Di Proses'?'b-warn':'b-bad')}">${esc(st)}</span>`; }
function suratKeluarStatus(sk){
  if(sk.otomatis){ const k = getKegiatan(sk.kegiatan_id); return sprinStatusLabel(k||{}); }
  if(sk.status_kirim==='Terkirim' || sk.file_pdf || lampiranWaList(sk).length) return 'Selesai';
  return sk.status_proses==='Sedang Di Proses' ? 'Sedang Di Proses' : 'Belum Di Proses';
}
function setSuratKeluarProses(id, val){
  if(!isAdmin()){ toast('Hanya Admin yang dapat mengubah keterangan'); return; }
  if(!PROSES_LIST.includes(val)) return;
  const sk = getSuratKeluar(id); if(!sk) return;
  sk.status_proses = val; sk.diperbarui = nowStr();
  persist(); renderPersuratan(); toast('Keterangan: ' + val);
}
function setSprinProses(id, val, dariDetail){
  if(!isAdmin()){ toast('Hanya Admin yang dapat mengubah keterangan'); return; }
  if(!PROSES_LIST.includes(val)) return;
  const k = getKegiatan(id); if(!k || k.sprin) return;
  k.sprin_proses = val; k.diperbarui = nowStr();
  persist(); toast('Keterangan Sprin: ' + val);
  if(dariDetail){ render('kegiatan'); openKegiatanDetail(id); } else renderPersuratan();
}
function suratKeluarOtomatis(){
  return (DB.kegiatan||[]).filter(k=>k.sprin || sprinPerluTampil(k)).map(k=>{
    const nm = (k.anggota_ids||[]).map(id=>{ const a=(DB.anggota||[]).find(x=>x.id===id); return a ? a.nama : ''; }).filter(Boolean).join(', ');
    return {id:'ST-'+k.id, otomatis:true, kegiatan_id:k.id, nomor_surat:k.sprin?String(k.sprin.name||'').replace(/\.pdf$/i,''):'(Sprin belum diupload)',
      tanggal_surat:k.sprin?(k.sprin.tanggal||''):'', tujuan:nm||'-', perihal:'Surat Tugas: '+(k.nama||k.id), sifat:'Biasa', lampiran:'-'};
  });
}
/* Tgl Surat (Surat Keluar) terisi otomatis = tanggal file surat diupload. Belum ada file -> '-'. */
function suratKeluarTglUpload(sk){
  if(sk.otomatis) return sk.tanggal_surat||'';
  if(sk.tgl_upload) return sk.tgl_upload;
  const wa = lampiranWaList(sk).map(f=>String(f.tanggal||f.uploaded_iso||f.at||'').slice(0,10)).find(d=>/^\d{4}-\d{2}-\d{2}$/.test(d));
  if(wa) return wa;
  return sk.file_pdf ? (sk.tanggal_surat||'') : '';
}
function suratKeluarSemua(){ return (DB.surat_keluar||[]).concat(suratKeluarOtomatis()); }
function setPsTab(t){ psTab = t; renderPersuratan(); }
function renderPersuratan(){
  const nMasuk = (DB.surat_masuk||[]).length, nKeluar = suratKeluarSemua().length;
  const tabBtn = (key,label,n) => `<button class="btn ${psTab===key?'btn-primary':'btn-ghost'}" onclick="setPsTab('${key}')">${label} <span class="badge b-info" style="margin-left:.35rem;">${n}</span></button>`;
  document.getElementById('app').innerHTML = `
    <div class="flex flex-wrap items-center gap-2 mb-3">
      ${tabBtn('masuk','Surat Masuk',nMasuk)}
      ${tabBtn('keluar','Surat Keluar',nKeluar)}
    </div>
    ${psTab==='masuk' ? psMasukHtml() : psKeluarHtml()}`;
  if(psTab==='masuk') suratBulkRefresh(); else skBulkRefresh();
  enhanceTableScroll();
}
function psMasukHtml(){
  const q = (search.psmasuk||'').toLowerCase();
  const rows = (DB.surat_masuk||[]).filter(s => matchSearch(s, q));
  // Checkbox hapus massal memakai state yang sama dengan Surat Masuk di Kegiatan/Tugas (data & aturan hak sama)
  suratBulkVisible = rows.filter(canManageSurat).map(r=>r.id);
  const bulkOn = suratBulkVisible.length > 0;
  { const ex = new Set((DB.surat_masuk||[]).map(r=>r.id)); suratBulkSel = new Set([...suratBulkSel].filter(id=>ex.has(id))); }
  const nSel = suratBulkVisible.filter(id=>suratBulkSel.has(id)).length;
  return `
    <div class="panel rounded-xl p-4">
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari surat masuk..." value="${esc(search.psmasuk||'')}" class="!w-56" data-sid="search-psmasuk" oninput="runSearchRender(this, ()=>{ search.psmasuk=this.value; renderPersuratan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} surat</span>
        ${bulkOn ? `<button type="button" id="suratBulkDelBtn" class="btn btn-ghost" style="${nSel?'':'display:none;'}color:var(--bad);" onclick="deleteSuratBulk()">Hapus Terpilih (${nSel})</button>` : ''}
        ${impSuratBtnHtml('ml-auto')}
        <button class="btn btn-ghost ${canImportExcel('surat_masuk') ? '' : 'ml-auto'}" onclick="kegTab='surat'; render('kegiatan')">Kelola di Kegiatan/Tugas</button>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Daftar ini diambil otomatis dari Surat Masuk pada modul Kegiatan/Tugas. Surat baru dicatat dan di-ACC di modul tersebut. Surat yang dihapus di sini juga terhapus dari Surat Masuk di Kegiatan/Tugas (dan sebaliknya).</p>
      <div class="tablewrap"><table>
        <thead><tr>${bulkOn ? `<th class="c-sel" style="width:2.2rem;text-align:center;"><input type="checkbox" id="suratBulkAllCb" title="Pilih semua data" onchange="toggleSuratBulkAll(this.checked)"></th>` : ''}<th>ID</th><th>Nomor Surat</th><th>Asal Surat</th><th>Perihal</th><th>Tgl Surat</th><th>Tgl Diterima</th><th>Batas Waktu</th><th>Status Surat</th><th>Instruksi KKS</th><th>Persetujuan</th><th>File</th><th>Aksi</th></tr></thead>
        <tbody>${rows.length ? rows.slice().reverse().map(sm=>`
          <tr style="cursor:pointer;" onclick="openSuratDetail('${esc(sm.id)}')">
            ${bulkOn ? `<td style="text-align:center;" onclick="event.stopPropagation();">${canManageSurat(sm) ? `<input type="checkbox" class="suratBulkCb" data-id="${esc(sm.id)}" ${suratBulkSel.has(sm.id)?'checked':''} onchange="toggleSuratBulkRow(this.dataset.id,this.checked)">` : ''}</td>` : ''}
            <td class="font-medium whitespace-nowrap">${esc(sm.id)}</td><td style="min-width:150px;">${esc(sm.nomor_surat)}</td><td style="min-width:150px;">${esc(sm.asal_surat)||'-'}</td>
            <td style="min-width:200px;"><div class="clamp3" title="${esc(sm.perihal)}">${esc(sm.perihal)}</div></td>
            <td class="whitespace-nowrap">${esc(tglDMY(sm.tanggal_surat))||'-'}</td><td class="whitespace-nowrap">${esc(tglDMY(sm.tanggal_diterima))||'-'}${sm.jam_diterima ? '<div class="text-[11px]" style="color:var(--muted);">'+esc(sm.jam_diterima)+' WIB</div>' : ''}</td>
            <td class="whitespace-nowrap">${esc(tglDMY(sm.batas_waktu))||'-'}</td><td class="whitespace-nowrap">${esc(sm.status_surat)||'-'}</td><td style="min-width:120px;">${esc(sm.instruksi_kks)||'-'}</td>
            <td>${suratPersetujuanHtml(sm)}</td>
            <td onclick="event.stopPropagation();">${sm.file_pdf ? `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="openPdfDataUrl(getSurat('${esc(sm.id)}').file_pdf.data)">Lihat</button>` : '-'}</td>
            <td onclick="event.stopPropagation();"><button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openSuratDetail('${esc(sm.id)}')">Detail</button></td>
          </tr>`).join('') : `<tr><td colspan="13" class="text-center py-6" style="color:var(--muted);">Belum ada surat masuk.</td></tr>`}</tbody>
      </table></div>
    </div>`;
}
function psKeluarHtml(){
  const q = (search.pskeluar||'').toLowerCase();
  const rows = suratKeluarSemua().filter(s => matchSearch(s, q));
  const ro = !isAdmin();
  // Checkbox hapus massal hanya untuk Admin & hanya surat keluar manual/impor (Sprin otomatis ST-xxx dikelola dari Kegiatan/Tugas)
  skBulkVisible = ro ? [] : rows.filter(s=>!s.otomatis).map(r=>r.id);
  const bulkOn = skBulkVisible.length > 0;
  { const ex = new Set((DB.surat_keluar||[]).map(r=>r.id)); skBulkSel = new Set([...skBulkSel].filter(id=>ex.has(id))); }
  const nSel = skBulkVisible.filter(id=>skBulkSel.has(id)).length;
  return `
    <div class="panel rounded-xl p-4">
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <input type="text" placeholder="Cari surat keluar..." value="${esc(search.pskeluar||'')}" class="!w-56" data-sid="search-pskeluar" oninput="runSearchRender(this, ()=>{ search.pskeluar=this.value; renderPersuratan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} surat</span>
        ${bulkOn ? `<button type="button" id="skBulkDelBtn" class="btn btn-ghost" style="${nSel?'':'display:none;'}color:var(--bad);" onclick="deleteSkBulk()">Hapus Terpilih (${nSel})</button>` : ''}
        ${impSuratBtnHtml('ml-auto')}
        ${ro ? '' : `<button class="btn btn-primary ${canImportExcel('surat_masuk') ? '' : 'ml-auto'}" onclick="openSuratKeluarForm()">+ Surat Keluar</button>`}
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Sprin (ID berawalan ST-) dari Kegiatan/Tugas otomatis masuk ke daftar ini. Keterangan: <strong>Belum Di Proses</strong> → <strong>Sedang Di Proses</strong> → <strong>Selesai</strong> (otomatis setelah file diupload). Hanya Admin yang dapat menginput dan mengubah keterangan.</p>
      <div class="tablewrap"><table>
        <thead><tr>${bulkOn ? `<th class="c-sel" style="width:2.2rem;text-align:center;"><input type="checkbox" id="skBulkAllCb" title="Pilih semua data" onchange="toggleSkBulkAll(this.checked)"></th>` : ''}<th>ID</th><th>Nomor Surat</th><th>Tujuan</th><th>Perihal</th><th>Sifat</th><th>Tgl Surat</th><th>Status Kirim</th><th>Keterangan</th><th>File</th><th>Aksi</th></tr></thead>
        <tbody>${rows.length ? rows.slice().reverse().map(sk=>`
          <tr>
            ${bulkOn ? `<td style="text-align:center;">${sk.otomatis ? '' : `<input type="checkbox" class="skBulkCb" data-id="${esc(sk.id)}" ${skBulkSel.has(sk.id)?'checked':''} onchange="toggleSkBulkRow(this.dataset.id,this.checked)">`}</td>` : ''}
            <td class="font-medium whitespace-nowrap">${esc(sk.id)}</td><td style="min-width:150px;">${esc(sk.nomor_surat)}</td><td style="min-width:150px;">${esc(sk.tujuan)||'-'}</td>
            <td style="min-width:200px;"><div class="clamp3" title="${esc(sk.perihal)}">${esc(sk.perihal)}</div></td>
            <td class="whitespace-nowrap">${esc(sk.sifat)||'-'}</td><td class="whitespace-nowrap">${esc(tglDMY(suratKeluarTglUpload(sk)))||'-'}</td>
            <td class="whitespace-nowrap">${sk.status_kirim ? `<span class="badge ${sk.status_kirim==='Terkirim' ? 'b-ok' : 'b-warn'}">${esc(sk.status_kirim)}</span>` : '-'}</td>
            <td class="whitespace-nowrap">${suratKeluarKetHtml(sk, ro)}</td>
            <td>${sk.otomatis ? (getKegiatan(sk.kegiatan_id)&&getKegiatan(sk.kegiatan_id).sprin ? `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="lihatSprin('${esc(sk.kegiatan_id)}')">Lihat</button>` : '-') : (sk.file_pdf ? `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="openPdfDataUrl(getSuratKeluar('${esc(sk.id)}').file_pdf.data)">Lihat</button>` : (lampiranWaList(sk).length ? lampiranWaList(sk).map(f=>`<button class="btn btn-navy !py-1 !px-2 text-xs" title="${esc(f.name)} (WhatsApp)" onclick="lihatLampiran('${esc(f.fid)}')">Lihat</button>`).join(' ') : '-'))}</td>
            <td class="whitespace-nowrap">${sk.otomatis ? `<span class="badge b-info">Otomatis</span> <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="kegTab='kegiatan'; render('kegiatan'); openKegiatanDetail('${esc(sk.kegiatan_id)}')">Kegiatan</button>` : ro ? '-' : `<button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openSuratKeluarForm('${esc(sk.id)}')">Ubah</button>
              <button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteSuratKeluar('${esc(sk.id)}')">Hapus</button>`}</td>
          </tr>`).join('') : `<tr><td colspan="11" class="text-center py-6" style="color:var(--muted);">Belum ada surat keluar.</td></tr>`}</tbody>
      </table></div>
    </div>`;
}
function suratKeluarKetHtml(sk, ro){
  const st = suratKeluarStatus(sk);
  if(st==='Selesai' || ro) return prosesBadgeHtml(st);
  const fn = sk.otomatis ? `setSprinProses('${esc(sk.kegiatan_id)}',this.value)` : `setSuratKeluarProses('${esc(sk.id)}',this.value)`;
  return `<select class="!w-40 !py-1 text-xs" aria-label="Keterangan proses" onchange="${fn}">${PROSES_LIST.map(o=>`<option ${st===o?'selected':''}>${o}</option>`).join('')}</select>`;
}
function guardAdminSurat(){ if(!isAdmin()){ toast('Hanya Admin yang dapat menginput Surat Keluar'); return true; } return false; }
function openSuratKeluarForm(editId){ if(guardAdminSurat()) return;
  const e = editId ? getSuratKeluar(editId) : null;
  if(editId && !e) return;
  skEditId = e ? e.id : null;
  skPdfFile = e && e.file_pdf ? e.file_pdf : null;
  const lbl = t => `<label class="block text-xs font-medium mb-1" style="color:var(--muted);">${t}</label>`;
  const val = (k,def='') => esc(e ? (e[k]==null ? '' : e[k]) : def);
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-display font-semibold text-base">${e ? 'Ubah Surat Keluar ' + esc(e.id) : 'Surat Keluar Baru'}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div>${lbl('Nomor Surat *')}<input type="text" id="sk_nomor" value="${val('nomor_surat')}"></div>
        <div>${lbl('Tujuan *')}<input type="text" id="sk_tujuan" value="${val('tujuan')}"></div>
        <div>${lbl('Sifat')}<select id="sk_sifat">${SURAT_SIFAT.map(o=>`<option ${(e?e.sifat:'Biasa')===o?'selected':''}>${o}</option>`).join('')}</select></div>
        <div class="sm:col-span-2">${lbl('Perihal *')}<input type="text" id="sk_perihal" value="${val('perihal')}"></div>
        <div>${lbl('Ditandatangani Oleh')}<input type="text" id="sk_ttd" value="${val('penandatangan')}"></div>
        <div>${lbl('Status Kirim')}<select id="sk_kirim">${['Belum Terkirim','Terkirim'].map(o=>`<option ${(e&&e.status_kirim?e.status_kirim:'Belum Terkirim')===o?'selected':''}>${o}</option>`).join('')}</select></div>
        <div class="sm:col-span-2">${lbl('File Surat (PDF)')}
          <input type="file" id="sk_pdf" accept="application/pdf,.pdf" onchange="handleSkPdfChange(this)">
          <p id="sk_pdf_info" class="text-[11px] mt-1" style="color:var(--muted);">${e && e.file_pdf ? 'File saat ini: ' + esc(e.file_pdf.name) + '. Pilih file baru hanya jika ingin menggantinya.' : 'Maks. 10MB, hanya format PDF.'} Tanggal surat terisi otomatis saat file diupload.</p>
        </div>
        <div class="sm:col-span-2">${lbl('Keterangan Proses')}<select id="sk_proses">${PROSES_LIST.map(o=>`<option ${(e&&e.status_proses?e.status_proses:'Belum Di Proses')===o?'selected':''}>${o}</option>`).join('')}</select>
          <p class="text-[11px] mt-1" style="color:var(--muted);">Otomatis menjadi <strong>Selesai</strong> setelah file surat diupload.</p></div>
        <div class="sm:col-span-2">${lbl('Catatan')}<textarea id="sk_ket" rows="2">${val('keterangan')}</textarea></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveSuratKeluar()">${e ? 'Simpan Perubahan' : 'Simpan Surat Keluar'}</button>
      </div>
    </div>`, true);
}
function handleSkPdfChange(input){
  const info = document.getElementById('sk_pdf_info');
  const cur = skEditId ? getSuratKeluar(skEditId) : null;
  skPdfFile = cur && cur.file_pdf ? cur.file_pdf : null;
  const file = input.files && input.files[0];
  if(!file){ info.textContent = cur && cur.file_pdf ? 'File saat ini: ' + cur.file_pdf.name : 'Maks. 10MB, hanya format PDF.'; return; }
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){ toast('File harus berformat PDF'); input.value=''; return; }
  if(file.size > 10*1024*1024){ toast('Ukuran file maksimal 10MB'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = () => { skPdfFile = {name:file.name, size:file.size, data:reader.result}; info.textContent = `Terpilih: ${file.name} (${(file.size/1024).toFixed(0)} KB)`; };
  reader.onerror = () => toast('Gagal membaca file PDF');
  reader.readAsDataURL(file);
}
function saveSuratKeluar(){ if(guardAdminSurat()) return;
  const v = id => document.getElementById(id).value.trim();
  const nomor = v('sk_nomor'), tujuan = v('sk_tujuan'), perihal = v('sk_perihal');
  if(!nomor){ toast('Nomor surat wajib diisi'); return; }
  if(!tujuan && !(skEditId && (getSuratKeluar(skEditId)||{}).sumber==='Import Excel')){ toast('Tujuan wajib diisi'); return; }
  if(!perihal){ toast('Perihal wajib diisi'); return; }
  if(!DB.surat_keluar) DB.surat_keluar = [];
  const data = {nomor_surat:nomor, tujuan, perihal, sifat:v('sk_sifat'), status_kirim:v('sk_kirim'),
    penandatangan:v('sk_ttd'), keterangan:document.getElementById('sk_ket').value, file_pdf:skPdfFile, status_proses:v('sk_proses')};
  if(skEditId){
    const ex = getSuratKeluar(skEditId);
    if(!ex){ toast('Surat tidak ditemukan'); return; }
    const oldPdf = ex.file_pdf;
    Object.assign(ex, data, {diperbarui:nowStr()});
    if(skPdfFile && skPdfFile !== oldPdf) ex.tgl_upload = todayISO(); // file baru/diganti -> tanggal upload hari ini
    ex.tanggal_surat = suratKeluarTglUpload(ex);
    toast('Surat keluar diperbarui');
  } else {
    const tgl = skPdfFile ? todayISO() : '';
    DB.surat_keluar.push(Object.assign({id:uid('SK', DB.surat_keluar), dibuat_oleh:pelaku(), dibuat:nowStr(), diperbarui:nowStr(), tgl_upload:tgl, tanggal_surat:tgl}, data));
    toast('Surat keluar disimpan');
  }
  persist();
  skPdfFile = null; skEditId = null;
  closeModal(); psTab = 'keluar'; renderPersuratan();
}
function deleteSuratKeluar(id){ if(guardAdminSurat()) return;
  const sk = getSuratKeluar(id); if(!sk) return;
  if(!confirm('Hapus surat keluar ' + sk.nomor_surat + '?')) return;
  DB.surat_keluar = DB.surat_keluar.filter(x=>x.id!==id);
  skBulkSel.delete(id);
  persist(); renderPersuratan(); toast('Surat keluar dihapus');
}

/* ============================= SURAT MASUK -> ACC -> KEGIATAN ============================= */
// Alur awal modul Kegiatan/Tugas: Surat Masuk dicatat -> menunggu ACC pimpinan
// (KKS/KSB/KSD/KSO) -> setelah di-ACC, Kegiatan/Tugas terbentuk otomatis
// (beserta data di modul sesuai Jenis-nya lewat autoFillModuleFromKegiatan).
const SURAT_ACC_ROLES = ['KKS','KSB','KSD','KSO'];
// 'salah_satu' = cukup 1 dari 4 pejabat yang ACC | 'semua' = keempatnya harus ACC
const SURAT_ACC_MODE = 'salah_satu';
let kegTab = 'surat';
let smPdfFile = null; // {name, size, data}
let smEditId = null;  // id surat yang sedang diedit (null = mode tambah)

function canAccSurat(){ return isSuper() || SURAT_ACC_ROLES.includes(currentRole); }
// Edit/Hapus: siapa pun boleh selama surat masih menunggu; setelah diputuskan hanya pejabat ACC.
function canManageSurat(sm){ return sm.status==='Menunggu Persetujuan' || canAccSurat() || (!!sm.tanpa_acc && isAdmin()); }
function suratPersetujuanHtml(sm){ return sm.tanpa_acc ? '<span class="badge b-ok">Selesai</span>' : statusBadge(sm.status); }
function getSurat(id){ return (DB.surat_masuk||[]).find(x=>x.id===id); }
function suratAccRoles(sm){ return (sm.acc||[]).map(a=>a.role); }
function suratAccInfo(sm){
  if(sm.tanpa_acc) return 'Import Excel — tanpa ACC, tidak masuk Kegiatan';
  if(sm.status==='Ditolak') return '';
  if(SURAT_ACC_MODE!=='semua') return sm.status==='Disetujui' ? 'ACC: '+suratAccRoles(sm).join(', ') : 'Menunggu salah satu ACC '+SURAT_ACC_ROLES.join('/');
  const sudah = suratAccRoles(sm);
  return 'ACC ' + sudah.length + '/' + SURAT_ACC_ROLES.length + (sudah.length ? ' ('+sudah.join(', ')+')' : '');
}
function setKegTab(t){ kegTab = t; renderKegiatan(); }

function renderKegiatan(){
  const menunggu = (DB.surat_masuk||[]).filter(s=>s.status==='Menunggu Persetujuan').length;
  const tabBtn = (key,label,badge) => `<button class="btn ${kegTab===key?'btn-primary':'btn-ghost'}" onclick="setKegTab('${key}')">${label}${badge?` <span class="badge b-warn" style="margin-left:.35rem;">${badge}</span>`:''}</button>`;
  document.getElementById('app').innerHTML = `
    <div class="keg-steps">
      ${tabBtn('surat','1. Surat Masuk',menunggu)}
      <span class="keg-arrow" style="color:var(--muted);">→</span>
      <span class="keg-acc text-xs" style="color:var(--muted);">ACC ${SURAT_ACC_ROLES.join('/')}</span>
      <span class="keg-arrow" style="color:var(--muted);">→</span>
      ${tabBtn('kegiatan','2. Kegiatan/Tugas',0)}
      <span class="keg-arrow" style="color:var(--muted);">→</span>
      ${tabBtn('selesai','3. Tugas Selesai',0)}
    </div>
    ${kegTab==='surat' ? suratListHtml() : (kegTab==='selesai' ? tugasSelesaiListHtml() : kegiatanListHtml())}`;
  if(kegTab==='surat') suratBulkRefresh();
  enhanceTableScroll();
}

function tugasSelesaiListHtml(){
  const q = (search.selesai||'').toLowerCase();
  const rows = (DB.kegiatan||[]).filter(k => k.status_pelaksanaan==='Selesai' && matchSearch(k, q) && inPeriode(k, 'kegiatan'));
  const namaAnggota = k => (k.anggota_ids||[]).map(id=>{ const a=(DB.anggota||[]).find(x=>x.id===id); return a ? namaDepan(a.nama) : ''; }).filter(Boolean).join(', ');
  return `
    <div class="panel rounded-xl p-4">
      <div class="keg-toolbar flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari tugas selesai..." value="${esc(search.selesai||'')}" class="!w-56 keg-search" data-sid="search-selesai" oninput="runSearchRender(this, ()=>{ search.selesai=this.value; renderKegiatan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} tugas selesai</span>
        ${periodeSelectHtml('kegiatan')}
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Daftar otomatis dari Kegiatan/Tugas yang status pelaksanaannya Selesai.</p>
      <div class="tablewrap">
        <table class="tbl-fit tbl-fit-t">
          <thead><tr>
            <th>ID</th><th>Nama Kegiatan</th><th class="xs-hide">Jenis</th><th>Tanggal</th><th class="xs-hide">Lokasi</th><th class="xs-hide">Anggota Bertugas</th>
            <th class="xs-hide">Hasil</th><th>Pelaksanaan</th><th>Aksi</th>
          </tr></thead>
          <tbody>
            ${rows.length ? rows.slice().reverse().map(k=>`
              <tr style="cursor:pointer;" onclick="openKegiatanDetail('${k.id}')">
                <td class="font-medium c-id">${esc(k.id)}</td>
                <td data-label="Nama Kegiatan"><div class="clamp3" title="${esc(k.nama)}">${esc(k.nama)}</div></td>
                <td class="xs-hide" data-label="Jenis">${esc(k.jenis_label)}${k.jenis_ket ? '<div class="text-[11px]" style="color:var(--muted);">'+esc(k.jenis_ket)+'</div>' : ''}</td>
                <td data-label="Tanggal">${esc(tglRentang(k))}</td>
                <td class="xs-hide" data-label="Lokasi">${esc(k.lokasi)||'-'}</td>
                <td class="xs-hide" data-label="Anggota">${esc(namaAnggota(k))||'-'}</td>
                <td class="xs-hide" data-label="Hasil">${(k.hasil_files||[]).length ? (k.hasil_files.length+' file') : '<span style="color:var(--muted);">Belum ada</span>'}</td>
                <td data-label="Pelaksanaan">${statusBadge(k.status_pelaksanaan)}</td>
                <td class="c-aksi" onclick="event.stopPropagation();"><button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openKegiatanDetail('${k.id}')">Detail</button></td>
              </tr>`).join('') : `<tr><td colspan="9" class="text-center py-6" style="color:var(--muted);">Belum ada tugas yang selesai.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
}

function suratListHtml(){
  const q = (search.surat||'').toLowerCase();
  const rows = (DB.surat_masuk||[]).filter(s => matchSearch(s, q));
  // Checkbox hapus massal: hanya untuk surat yang boleh dikelola pengguna (sama seperti tombol Hapus per baris)
  suratBulkVisible = rows.filter(canManageSurat).map(r=>r.id);
  const bulkOn = suratBulkVisible.length > 0;
  { const ex = new Set((DB.surat_masuk||[]).map(r=>r.id)); suratBulkSel = new Set([...suratBulkSel].filter(id=>ex.has(id))); }
  const nSel = suratBulkVisible.filter(id=>suratBulkSel.has(id)).length;
  return `
    <div class="panel rounded-xl p-4">
      <div class="keg-toolbar flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari surat masuk..." value="${esc(search.surat||'')}" class="!w-56 keg-search" data-sid="search-surat" oninput="runSearchRender(this, ()=>{ search.surat=this.value; renderKegiatan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} surat</span>
        ${bulkOn ? `<button type="button" id="suratBulkDelBtn" class="btn btn-ghost" style="${nSel?'':'display:none;'}color:var(--bad);" onclick="deleteSuratBulk()">Hapus Terpilih (${nSel})</button>` : ''}
        ${impSuratBtnHtml('ml-auto')}
        <button class="btn btn-primary ${canImportExcel('surat_masuk') ? '' : 'ml-auto'}" onclick="openSuratForm()">+ Surat Masuk</button>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Catat surat masuk → menunggu ACC ${SURAT_ACC_ROLES.join('/')} → setelah di-ACC, Kegiatan/Tugas terbentuk otomatis.</p>
      <div class="tablewrap">
        <table class="tbl-fit tbl-fit-s">
          <thead><tr>
            ${bulkOn ? `<th class="c-sel" style="width:2.2rem;text-align:center;"><input type="checkbox" id="suratBulkAllCb" title="Pilih semua data" onchange="toggleSuratBulkAll(this.checked)"></th>` : ''}
            <th>ID</th><th>Nomor Surat</th><th>Asal Surat</th><th>Perihal</th><th class="xs-hide">Jenis</th><th class="xs-hide">Tgl Surat</th>
            <th>Persetujuan</th><th class="xs-hide">Kegiatan</th><th>Aksi</th>
          </tr></thead>
          <tbody>
            ${rows.length ? rows.slice().reverse().map(sm=>{
              const kg = sm.kegiatan_id ? getKegiatan(sm.kegiatan_id) : null;
              return `
              <tr style="cursor:pointer;" onclick="openSuratDetail('${sm.id}')">
                ${bulkOn ? `<td class="c-sel" style="text-align:center;" onclick="event.stopPropagation();">${canManageSurat(sm) ? `<input type="checkbox" class="suratBulkCb" data-id="${esc(sm.id)}" ${suratBulkSel.has(sm.id)?'checked':''} onchange="toggleSuratBulkRow(this.dataset.id,this.checked)">` : ''}</td>` : ''}
                <td class="font-medium c-id">${esc(sm.id)}</td>
                <td data-label="Nomor Surat">${esc(sm.nomor_surat)}</td>
                <td data-label="Asal Surat">${esc(sm.asal_surat)||'-'}</td>
                <td data-label="Perihal">${esc(sm.perihal)}</td>
                <td class="xs-hide" data-label="Jenis">${esc(sm.jenis_label)}${sm.jenis_ket ? '<div class="text-[11px]" style="color:var(--muted);">'+esc(sm.jenis_ket)+'</div>' : ''}</td>
                <td class="xs-hide" data-label="Tgl Surat">${esc(tglDMY(sm.tanggal_surat))||'-'}</td>
                <td data-label="Persetujuan">${suratPersetujuanHtml(sm)}<div class="text-[11px]" style="color:var(--muted);">${esc(suratAccInfo(sm))}</div></td>
                <td class="xs-hide" data-label="Kegiatan" onclick="event.stopPropagation();">${kg ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="kegTab='kegiatan'; render('kegiatan'); openKegiatanDetail('${kg.id}')">${esc(kg.id)}</button>` : '-'}</td>
                <td class="c-aksi" onclick="event.stopPropagation();"><div class="flex flex-wrap gap-1">
                  <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openSuratDetail('${sm.id}')">Detail</button>
                  ${canManageSurat(sm) ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openSuratForm('${sm.id}')">Edit</button>
                  <button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="deleteSurat('${sm.id}')">Hapus</button>` : ''}
                </div></td>
              </tr>`;}).join('') : `<tr><td colspan="10" class="text-center py-6" style="color:var(--muted);">Belum ada surat masuk.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
}

function kegiatanListHtml(){
  const q = (search.kegiatan||'').toLowerCase();
  const rows = DB.kegiatan.filter(k => k.status_pelaksanaan!=='Selesai' && matchSearch(k, q) && inPeriode(k, 'kegiatan'));
  return `
    <div class="panel rounded-xl p-4">
      <div class="keg-toolbar flex flex-wrap items-center gap-2 mb-2">
        <input type="text" placeholder="Cari kegiatan..." value="${esc(search.kegiatan||'')}" class="!w-56 keg-search" data-sid="search-kegiatan" oninput="runSearchRender(this, ()=>{ search.kegiatan=this.value; renderKegiatan(); })">
        <span class="text-xs ml-1" style="color:var(--muted);">${rows.length} kegiatan</span>
        ${periodeSelectHtml('kegiatan')}
        ${impBtnHtml('kegiatan', 'ml-auto')}
        <button class="btn btn-ghost ${canImportExcel() ? '' : 'ml-auto'}" onclick="setKegTab('surat')">+ Dari Surat Masuk</button>
        <button class="btn btn-primary" onclick="openKegiatanForm()">+ Ajukan Kegiatan/Tugas</button>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">Kegiatan/Tugas terbentuk otomatis setelah Surat Masuk di-ACC, atau bisa diajukan langsung tanpa surat masuk (menunggu persetujuan di Detail).</p>
      <div class="tablewrap">
        <table class="tbl-fit tbl-fit-k">
          <thead><tr>
            <th>ID</th><th>Nama Kegiatan</th><th class="xs-hide">Jenis</th><th>Tanggal</th><th class="xs-hide">Lokasi</th><th class="xs-hide">Asal Surat</th>
            <th>Persetujuan</th><th>Pelaksanaan</th><th class="xs-hide">WhatsApp</th><th>Aksi</th>
          </tr></thead>
          <tbody>
            ${rows.length ? rows.slice().reverse().map(k=>{
              const sm = k.surat_id ? getSurat(k.surat_id) : null;
              return `
              <tr style="cursor:pointer;" onclick="openKegiatanDetail('${k.id}')">
                <td class="font-medium c-id">${esc(k.id)}</td>
                <td data-label="Nama Kegiatan"><div class="clamp3" title="${esc(k.nama)}">${esc(k.nama)}</div></td>
                <td class="xs-hide" data-label="Jenis">${esc(k.jenis_label)}${k.jenis_ket ? '<div class="text-[11px]" style="color:var(--muted);">'+esc(k.jenis_ket)+'</div>' : ''}</td>
                <td data-label="Tanggal">${esc(tglRentang(k))}</td>
                <td class="xs-hide" data-label="Lokasi">${esc(k.lokasi)}</td>
                <td class="xs-hide" data-label="Asal Surat">${sm ? esc(sm.id) : '-'}</td>
                <td data-label="Persetujuan">${statusBadge(k.status_persetujuan)}</td>
                <td data-label="Pelaksanaan">${statusBadge(k.status_pelaksanaan)}</td>
                <td class="xs-hide" data-label="WhatsApp">${statusBadge(k.status_wa)}</td>
                <td class="c-aksi" onclick="event.stopPropagation();"><button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="openKegiatanDetail('${k.id}')">Detail</button></td>
              </tr>`;}).join('') : `<tr><td colspan="10" class="text-center py-6" style="color:var(--muted);">Belum ada kegiatan.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>`;
}

function openSuratForm(editId){
  const e = editId ? getSurat(editId) : null;
  if(editId && !e) return;
  smEditId = e ? e.id : null;
  smPdfFile = e && e.file_pdf ? e.file_pdf : null;
  const jenisOptions = GENERIC_MODULES.map(m=>`<option value="${m.key}" ${e && e.jenis===m.key ? 'selected' : ''}>${esc(m.label)}</option>`).join('');
  const lbl = t => `<label class="block text-xs font-medium mb-1" style="color:var(--muted);">${t}</label>`;
  const val = (k,def='') => esc(e ? (e[k]==null ? '' : e[k]) : def);
  const pdfInfo = e
    ? (e.file_pdf ? `File saat ini: ${esc(e.file_pdf.name)} (${(e.file_pdf.size/1024).toFixed(0)} KB). Pilih file baru hanya jika ingin menggantinya.` : 'Belum ada file. Maks. 10MB, hanya format PDF.')
    : 'Maks. 10MB, hanya format PDF. Upload dulu agar form terisi otomatis, lalu periksa kembali.';
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-1">
        <h3 class="font-display font-semibold text-base">${e ? 'Edit Surat Masuk ' + esc(e.id) : 'Surat Masuk Baru'}</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <p class="text-xs mb-3" style="color:var(--muted);">${e
        ? (e.kegiatan_id ? 'Surat ini sudah menjadi Kegiatan ' + esc(e.kegiatan_id) + '. Perubahan di sini hanya mengubah data surat, tidak mengubah kegiatan yang sudah terbentuk.' : 'Perubahan akan disimpan pada surat ini.')
        : `Setelah disimpan, surat menunggu ACC ${SURAT_ACC_ROLES.join('/')}. Kegiatan/Tugas dibuat otomatis begitu di-ACC.`}</p>
      <div class="grid sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          ${lbl(e ? 'File Surat (PDF)' : 'File Surat (PDF) — isian di bawah terisi otomatis dari surat')}
          <input type="file" id="sm_pdf" accept="application/pdf,.pdf" onchange="handleSmPdfChange(this)">
          <p id="sm_pdf_info" class="text-[11px] mt-1" style="color:var(--muted);">${pdfInfo}</p>
        </div>
        <div>${lbl('Nomor Surat *')}<input type="text" id="sm_nomor" value="${val('nomor_surat')}"></div>
        <div>${lbl('Asal Surat (Pengirim)')}<input type="text" id="sm_asal" value="${val('asal_surat')}"></div>
        <div>${lbl('Tanggal Surat')}<input type="date" id="sm_tgl_surat" value="${val('tanggal_surat', todayISO())}"></div>
        <div>${lbl('Tanggal Diterima')}<input type="date" id="sm_tgl_terima" value="${val('tanggal_diterima', todayISO())}"></div>
        <div class="sm:col-span-2">${lbl('Perihal * (menjadi nama kegiatan)')}<input type="text" id="sm_perihal" value="${val('perihal')}"></div>
        <div>${lbl('Sumber Class (Jenis Kegiatan)')}<select id="sm_jenis" onchange="smToggleJenisKet()">${jenisOptions}</select>
          <div id="sm_jenis_ket_wrap" class="mt-2" style="display:${val('jenis')==='lainnya' ? 'block' : 'none'};">${lbl('Keterangan (Jenis Lainnya) *')}<input type="text" id="sm_jenis_ket" placeholder="Jelaskan jenis kegiatan..." value="${val('jenis_ket')}"></div></div>
        <div>${lbl('Tanggal Kegiatan')}<input type="date" id="sm_tgl_keg" value="${val('tanggal_kegiatan', todayISO())}"></div>
        <div>${lbl('Waktu')}<input type="time" id="sm_waktu" value="${val('waktu', '08:00')}"></div>
        <div>${lbl('Lokasi')}<input type="text" id="sm_lokasi" value="${val('lokasi')}"></div>
        <div>${lbl('Penanggung Jawab')}<input type="text" id="sm_pj" value="${val('penanggung_jawab')}"></div>
        <div>${lbl('No. HP')}<input type="tel" id="sm_hp" inputmode="tel" placeholder="mis. 0812xxxxxxxx" value="${val('no_hp')}"></div>
        <div>${lbl('Jumlah Anggota Dibutuhkan')}<input type="number" id="sm_jml" min="1" value="${val('jumlah_dibutuhkan', 2)}"></div>
        <div class="sm:col-span-2">${lbl('Isi Ringkas / Deskripsi')}<textarea id="sm_desk" rows="2">${val('deskripsi')}</textarea></div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveSuratForm()">${e ? 'Simpan Perubahan' : 'Simpan Surat Masuk'}</button>
      </div>
    </div>`, true);
}

function handleSmPdfChange(input){
  const info = document.getElementById('sm_pdf_info');
  const def = 'Maks. 10MB, hanya format PDF.';
  const file = input.files && input.files[0];
  const cur = smEditId ? getSurat(smEditId) : null;
  smPdfFile = cur && cur.file_pdf ? cur.file_pdf : null; // mode edit: tetap pakai file lama bila tidak ganti
  if(!file){ info.textContent = cur && cur.file_pdf ? 'File saat ini: ' + cur.file_pdf.name : def; return; }
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){
    toast('File harus berformat PDF'); input.value = ''; info.textContent = def; return;
  }
  if(file.size > 10*1024*1024){
    toast('Ukuran file maksimal 10MB'); input.value = ''; info.textContent = def; return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    smPdfFile = { name: file.name, size: file.size, data: reader.result };
    if(smEditId){ info.textContent = `File baru: ${file.name} (${(file.size/1024).toFixed(0)} KB) — akan menggantikan file lama saat disimpan.`; return; }
    info.textContent = `Terpilih: ${file.name} (${(file.size/1024).toFixed(0)} KB) — membaca isi surat…`; smAutofillFromPdf(file, info);
  };
  reader.onerror = () => { toast('Gagal membaca file PDF'); info.textContent = def; };
  reader.readAsDataURL(file);
}

/* ---- Isi otomatis form Surat Masuk dari PDF ---- */
let _pdfjsPromise = null;
function loadPdfJs(){
  if(_pdfjsPromise) return _pdfjsPromise;
  const base = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  _pdfjsPromise = new Promise((resolve, reject) => {
    if(window.pdfjsLib) return resolve(window.pdfjsLib);
    const sc = document.createElement('script');
    sc.src = base + 'pdf.min.js';
    sc.onload = async () => {
      try{
        // worker diambil sebagai blob agar lolos batasan cross-origin
        const code = await (await fetch(base + 'pdf.worker.min.js')).text();
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(new Blob([code], {type:'text/javascript'}));
      }catch(e){ window.pdfjsLib.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.min.js'; }
      resolve(window.pdfjsLib);
    };
    sc.onerror = () => { _pdfjsPromise = null; reject(new Error('Library pembaca PDF gagal dimuat')); };
    document.head.appendChild(sc);
  });
  return _pdfjsPromise;
}

async function pdfToLines(file){
  const lib = await loadPdfJs();
  const pdf = await lib.getDocument({data: await file.arrayBuffer()}).promise;
  const lines = [];
  const maxPage = Math.min(pdf.numPages, 3);
  for(let p=1; p<=maxPage; p++){
    const tc = await (await pdf.getPage(p)).getTextContent();
    const rows = [];
    tc.items.forEach(it => {
      if(!it.str || !it.str.trim()) return;
      const y = it.transform[5], x = it.transform[4];
      let r = rows.find(r => Math.abs(r.y - y) < 3);
      if(!r){ r = {y, parts:[]}; rows.push(r); }
      r.parts.push({x, t: it.str});
    });
    rows.sort((a,b) => b.y - a.y).forEach(r => {
      lines.push(r.parts.sort((a,b) => a.x - b.x).map(o => o.t).join(' ').replace(/\s+/g,' ').trim());
    });
  }
  return lines;
}

const BLN_ID = {januari:1,februari:2,maret:3,april:4,mei:5,juni:6,juli:7,agustus:8,september:9,oktober:10,november:11,desember:12,
  jan:1,feb:2,mar:3,apr:4,jun:6,jul:7,agu:8,agt:8,ags:8,sep:9,okt:10,nov:11,des:12};
function parseTglId(str){
  if(!str) return '';
  let m = str.match(/(\d{1,2})\s+([A-Za-z]+)\.?\s+(\d{4})/);
  if(m && BLN_ID[m[2].toLowerCase()]){
    return `${m[3]}-${String(BLN_ID[m[2].toLowerCase()]).padStart(2,'0')}-${m[1].padStart(2,'0')}`;
  }
  m = str.match(/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/);
  if(m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
  return '';
}

function parseSuratLines(lines){
  const out = {};
  const text = lines.join('\n');
  const val = (re, grp=1) => { const m = text.match(re); return m ? m[grp].trim() : ''; };

  // Nomor
  let nomor = val(/^\s*(?:Nomor|No\.?)\s*[:：]\s*(.+)$/im);
  if(nomor) out.nomor = nomor.replace(/\s*\/\s*/g,'/').replace(/\s{2,}/g,' ');

  // Perihal (gabung baris lanjutan bila ada)
  const iP = lines.findIndex(l => /^(Perihal|Hal)\s*[:：]/i.test(l));
  if(iP >= 0){
    let per = lines[iP].replace(/^(Perihal|Hal)\s*[:：]\s*/i,'');
    const nx = lines[iP+1];
    if(nx && !/[:：]/.test(nx) && !/^(Yth|Kepada|Lampiran|Sifat|Dengan hormat)/i.test(nx) && !/\d{4}/.test(nx) && !/,\s*\d{1,2}\s/.test(nx) && nx.length < 80) per += ' ' + nx;
    out.perihal = per.trim();
  }

  // Asal surat: kop surat (baris sebelum Nomor), ambil baris huruf besar bermakna
  const iN = lines.findIndex(l => /^(Nomor|No\.?)\s*[:：]/i.test(l));
  const kop = lines.slice(0, iN > 0 ? iN : 6).filter(l => l.length > 3 && !/(jl\.|jalan|telp|telepon|fax|email|website|www\.|@|\d{5})/i.test(l));
  const dari = val(/^\s*(?:Dari|Pengirim)\s*[:：]\s*(.+)$/im);
  if(dari) out.asal = dari;
  else if(kop.length) out.asal = kop.slice(0, 2).join(' ').replace(/\s{2,}/g,' ');

  // Tanggal surat: "Jakarta, 2 Oktober 2026"
  const mTs = text.match(/[A-Za-z .]+,\s*(\d{1,2}\s+[A-Za-z]+\.?\s+\d{4})/);
  if(mTs) out.tgl_surat = parseTglId(mTs[1]);

  // Tanggal kegiatan
  let tk = val(/(?:Hari\s*\/\s*Tanggal|Hari,?\s*Tanggal|Tanggal|Hari)\s*[:：]\s*(.+)/i);
  if(tk) out.tgl_keg = parseTglId(tk);
  if(!out.tgl_keg){
    const all = [...text.matchAll(/(\d{1,2}\s+(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+\d{4})/gi)].map(m => parseTglId(m[1]));
    const cand = all.find(d => d && d !== out.tgl_surat);
    if(cand) out.tgl_keg = cand;
  }

  // Waktu
  const mW = text.match(/(?:Waktu|Pukul|Jam)\s*[:：]?\s*(\d{1,2})[.:](\d{2})/i);
  if(mW) out.waktu = String(mW[1]).padStart(2,'0') + ':' + mW[2];

  // Lokasi
  const lok = val(/(?:Tempat|Lokasi)\s*[:：]\s*(.+)/i);
  if(lok) out.lokasi = lok;

  // Penanggung jawab & HP
  const pj = val(/(?:Penanggung\s*Jawab|Narahubung|Contact\s*Person|CP|PIC)\s*[:：]\s*([^\d(+\n]+)/i);
  if(pj) out.pj = pj.replace(/[-–,]+\s*$/,'').trim();
  const hp = val(/((?:\+62|62|0)8[\d\s\-]{8,14}\d)/);
  if(hp) out.hp = hp.replace(/[\s\-]/g,'');

  // Jumlah anggota
  const mJ = text.match(/(\d{1,3})\s*\(?[a-z]*\)?\s*(?:orang|personel|personil)\b/i);
  if(mJ) out.jml = parseInt(mJ[1]);

  // Jenis kegiatan dari kata kunci (perihal diutamakan)
  const hay = ((out.perihal||'') + ' ' + text).toLowerCase();
  const map = [
    ['sgts', /sar go to school|sgts/], ['bimtek', /bimtek|bimbingan teknis/], ['diklat', /diklat|pendidikan dan pelatihan/],
    ['narasumber', /narasumber/], ['uji_periodik', /uji periodik/], ['uji_pemeliharaan', /pemeliharaan/],
    ['pemberdayaan', /pemberdayaan/], ['pembinaan', /pembinaan/], ['potensi', /potensi sar|potensi/],
    ['rescuer', /rescuer/], ['peminjaman', /peminjaman|pinjam/], ['kendaraan', /kendaraan/], ['peralatan', /peralatan/],
    ['lainnya', /kegiatan lainnya|lainnya/],
  ];
  const hit = map.find(([k,re]) => re.test(hay));
  if(hit) out.jenis = hit[0];

  // Deskripsi singkat: kalimat pembuka setelah salam
  const iS = lines.findIndex(l => /dengan hormat|sehubungan|menindaklanjuti|bersama ini/i.test(l));
  if(iS >= 0) out.desk = lines.slice(iS, iS+4).join(' ').slice(0, 400);
  return out;
}

async function smAutofillFromPdf(file, info){
  const def = `Terpilih: ${file.name} (${(file.size/1024).toFixed(0)} KB)`;
  try{
    const lines = await pdfToLines(file);
    if(lines.join('').length < 30){
      info.textContent = def + ' — PDF berupa gambar/scan, isi tidak bisa dibaca otomatis. Silakan isi manual.';
      return;
    }
    const d = parseSuratLines(lines);
    const set = (id, v) => {
      if(v === undefined || v === null || v === '') return false;
      const el = document.getElementById(id);
      if(!el) return false;
      el.value = v;
      el.style.outline = '2px solid #f59e0b'; el.style.outlineOffset = '-2px';
      el.addEventListener('input', () => { el.style.outline = ''; }, {once:true});
      return true;
    };
    let n = 0;
    [['sm_nomor',d.nomor],['sm_asal',d.asal],['sm_tgl_surat',d.tgl_surat],['sm_tgl_terima',todayISO()],
     ['sm_perihal',d.perihal],['sm_jenis',d.jenis],['sm_tgl_keg',d.tgl_keg],['sm_waktu',d.waktu],
     ['sm_lokasi',d.lokasi],['sm_pj',d.pj],['sm_hp',d.hp],['sm_jml',d.jml],['sm_desk',d.desk]
    ].forEach(([id,v]) => { if(set(id,v) && id!=='sm_tgl_terima') n++; });
    smToggleJenisKet();
    info.textContent = def + (n ? ` — ${n} kolom terisi otomatis (bertanda oranye). Mohon periksa kembali.` : ' — tidak ada data yang dikenali, isi manual.');
    if(n) toast(n + ' kolom terisi otomatis dari surat');
  }catch(e){
    console.error(e);
    info.textContent = def + ' — gagal membaca isi PDF otomatis, silakan isi manual.';
  }
}

function smToggleJenisKet(){
  const sel = document.getElementById('sm_jenis'), w = document.getElementById('sm_jenis_ket_wrap');
  if(!sel || !w) return;
  const on = sel.value==='lainnya';
  w.style.display = on ? 'block' : 'none';
  if(on){ const i = document.getElementById('sm_jenis_ket'); if(i) i.focus(); }
}
function saveSuratForm(){
  const v = id => document.getElementById(id).value.trim();
  const nomor = v('sm_nomor'), perihal = v('sm_perihal');
  if(!nomor){ toast('Nomor surat wajib diisi'); return; }
  if(!perihal){ toast('Perihal wajib diisi'); return; }
  if(!DB.surat_masuk) DB.surat_masuk = [];
  const jenisKey = document.getElementById('sm_jenis').value;
  const jenisKet = jenisKey==='lainnya' ? v('sm_jenis_ket') : '';
  if(jenisKey==='lainnya' && !jenisKet){ toast('Keterangan jenis kegiatan (Lainnya) wajib diisi'); return; }
  const data = {
    jenis_ket: jenisKet,
    nomor_surat: nomor, asal_surat: v('sm_asal'),
    tanggal_surat: v('sm_tgl_surat'), tanggal_diterima: v('sm_tgl_terima'),
    perihal, jenis: jenisKey, jenis_label: GENERIC_MODULES.find(m=>m.key===jenisKey).label,
    tanggal_kegiatan: v('sm_tgl_keg'), waktu: v('sm_waktu'), lokasi: v('sm_lokasi'),
    penanggung_jawab: v('sm_pj'), no_hp: v('sm_hp'),
    jumlah_dibutuhkan: parseInt(v('sm_jml'))||1, deskripsi: document.getElementById('sm_desk').value,
    file_pdf: smPdfFile,
  };
  // ---- UPDATE ----
  if(smEditId){
    const sm = getSurat(smEditId);
    if(!sm){ toast('Surat tidak ditemukan'); return; }
    if(!canManageSurat(sm)){ toast('Anda tidak berwenang mengubah surat ini'); return; }
    Object.assign(sm, data, { diperbarui: nowStr() });
    persist();
    smPdfFile = null; smEditId = null;
    closeModal();
    toast('Surat masuk diperbarui');
    kegTab = 'surat';
    render('kegiatan');
    return;
  }
  // ---- CREATE ----
  const row = Object.assign({
    id: uid('SM', DB.surat_masuk),
    status: 'Menunggu Persetujuan', acc: [], keterangan_persetujuan: '', kegiatan_id: '', diajukan_oleh: pelaku(),
    dibuat: nowStr(), diperbarui: nowStr(),
  }, data);
  DB.surat_masuk.push(row);
  persist();
  smPdfFile = null;
  closeModal();
  toast('Surat masuk dicatat, menunggu ACC ' + SURAT_ACC_ROLES.join('/'));
  kegTab = 'surat';
  render('kegiatan');
}

function openSuratDetail(id){
  const sm = getSurat(id);
  if(!sm) return;
  const kg = sm.kegiatan_id ? getKegiatan(sm.kegiatan_id) : null;
  const menunggu = sm.status==='Menunggu Persetujuan';
  const sudahAcc = suratAccRoles(sm).includes(currentRole);
  const bisaAcc = menunggu && canAccSurat() && !sudahAcc;
  openModal(`
    <div class="p-5 max-h-[88vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-1">
        <div>
          <h3 class="font-display font-semibold text-base">${esc(sm.perihal)}</h3>
          <p class="text-xs" style="color:var(--muted);">${esc(sm.id)} · ${esc(sm.nomor_surat)} · ${esc(sm.jenis_label)}${sm.jenis_ket ? ' ('+esc(sm.jenis_ket)+')' : ''}</p>
        </div>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="flex flex-wrap gap-1.5 my-3">${suratPersetujuanHtml(sm)} <span class="text-xs" style="color:var(--muted);align-self:center;">${esc(suratAccInfo(sm))}</span></div>

      <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1.5 text-sm mb-4">
        <div><span style="color:var(--muted);">Asal Surat:</span> ${esc(sm.asal_surat)||'-'}</div>
        <div><span style="color:var(--muted);">Tanggal Surat:</span> ${esc(sm.tanggal_surat)||'-'}</div>
        <div><span style="color:var(--muted);">Tanggal Diterima:</span> ${esc(sm.tanggal_diterima)||'-'}</div>
        <div><span style="color:var(--muted);">Tanggal Kegiatan:</span> ${esc(sm.tanggal_kegiatan)||'-'} ${esc(sm.waktu)}</div>
        <div><span style="color:var(--muted);">Lokasi:</span> ${esc(sm.lokasi)||'-'}</div>
        <div><span style="color:var(--muted);">Penanggung Jawab:</span> ${esc(sm.penanggung_jawab)||'-'}</div>
        <div><span style="color:var(--muted);">No. HP:</span> ${esc(sm.no_hp)||'-'}</div>
        <div><span style="color:var(--muted);">Anggota Dibutuhkan:</span> ${sm.jumlah_dibutuhkan}</div>
        <div class="sm:col-span-2"><span style="color:var(--muted);">Isi Ringkas:</span> ${esc(sm.deskripsi)||'-'}</div>
        ${suratImportDetailHtml(sm)}
      </div>

      ${sm.file_pdf ? `
        <div class="flex items-center gap-2.5 rounded-lg p-3 mb-4" style="background:var(--bg);">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium truncate">${esc(sm.file_pdf.name)}</p>
            <p class="text-[11px]" style="color:var(--muted);">${(sm.file_pdf.size/1024).toFixed(0)} KB</p>
          </div>
          <a href="${sm.file_pdf.data}" download="${esc(sm.file_pdf.name)}" class="btn btn-ghost !py-1 !px-2 text-xs">Unduh</a>
          <button class="btn btn-navy !py-1 !px-2 text-xs" onclick="openPdfDataUrl(getSurat('${sm.id}').file_pdf.data)">Lihat</button>
        </div>` : ''}

      ${(sm.acc||[]).length ? `
        <div class="mb-4">
          <p class="text-sm font-medium mb-2">Riwayat ACC</p>
          ${sm.acc.map(a=>`<div class="text-xs py-1" style="color:var(--muted);"><span class="badge b-ok">${esc(a.role)}</span> ${esc(a.waktu)}</div>`).join('')}
        </div>` : ''}

      ${sm.keterangan_persetujuan ? `<div class="text-sm rounded-lg p-3 mb-4" style="background:var(--bg);"><strong>Keterangan:</strong> ${esc(sm.keterangan_persetujuan)}</div>` : ''}

      ${bisaAcc ? `
        <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
          <p class="text-sm font-medium mb-1">Persetujuan (${esc(currentRole)})</p>
          <p class="text-[11px] mb-2" style="color:var(--muted);">Setelah di-ACC, Kegiatan/Tugas langsung terbentuk otomatis.</p>
          <div class="flex gap-2">
            <button class="btn btn-ok" onclick="accSurat('${sm.id}')">ACC</button>
            <button class="btn btn-bad" onclick="rejectSurat('${sm.id}')">Tolak</button>
          </div>
          <p class="text-[11px] mt-2" style="color:var(--muted);">Pimpinan juga dapat memutuskan lewat WhatsApp. Keputusan pertama (dashboard atau WhatsApp) bersifat final.</p>
        </div>` : (menunggu ? `<p class="text-xs mb-4" style="color:var(--muted);">${sudahAcc ? 'Anda sudah ACC surat ini, menunggu pejabat lain.' : 'Hanya ' + SURAT_ACC_ROLES.join('/') + ' yang dapat ACC surat ini.'}</p>` : '')}

      ${lampiranWaHtml('surat_masuk', sm)}

      ${kg ? `<button class="btn btn-primary mb-2" onclick="closeModal(); kegTab='kegiatan'; render('kegiatan'); openKegiatanDetail('${kg.id}')">Lihat Kegiatan ${esc(kg.id)}</button>` : ''}

      <div class="flex justify-between items-center mt-4 pt-3 border-t" style="border-color:var(--line);">
        <span class="text-[11px]" style="color:var(--muted);">Dibuat ${esc(sm.dibuat)} · Diperbarui ${esc(sm.diperbarui)}</span>
        ${canManageSurat(sm) ? `<span class="flex gap-2">
          <button class="btn btn-ghost text-xs" onclick="openSuratForm('${sm.id}')">Edit</button>
          <button class="btn btn-ghost text-xs" style="color:var(--bad);" onclick="deleteSurat('${sm.id}')">Hapus</button>
        </span>` : ''}
      </div>
    </div>`, true);
}

function buatKegiatanDariSurat(sm){
  const row = {
    id: uid('KGT', DB.kegiatan),
    nomor: (DB.kegiatan.length+1).toString().padStart(3,'0') + '/KGT/2026',
    jenis: sm.jenis, jenis_label: sm.jenis_label, jenis_ket: sm.jenis_ket || '',
    nama: sm.perihal, tanggal: sm.tanggal_kegiatan, tanggal_selesai: sm.tanggal_kegiatan, waktu: sm.waktu,
    lokasi: sm.lokasi, penanggung_jawab: sm.penanggung_jawab, no_hp: sm.no_hp,
    deskripsi: sm.deskripsi, jumlah_dibutuhkan: sm.jumlah_dibutuhkan,
    status_persetujuan: 'Disetujui',
    keterangan_persetujuan: `Disetujui (ACC ${suratAccRoles(sm).join(', ')}) berdasarkan Surat Masuk ${sm.id} nomor ${sm.nomor_surat}.`,
    anggota_ids: [], status_pelaksanaan: 'Draft', status_wa: 'Belum Terkirim', wa_log: [], dokumentasi: '',
    file_pdf: sm.file_pdf || null, surat_id: sm.id,
    diajukan_oleh: sm.diajukan_oleh || null,
    disetujui_oleh: (sm.acc||[]).map(a=>({nama:a.nama||'-', peran:a.role})),
    dibuat: nowStr(), diperbarui: nowStr(),
  };
  DB.kegiatan.push(row);
  const linkedId = autoFillModuleFromKegiatan(sm.jenis, row);
  if(linkedId) row.linked_record_id = linkedId;
  sm.kegiatan_id = row.id;
  return row;
}

function accSurat(id){
  const sm = getSurat(id);
  if(!sm || sm.status!=='Menunggu Persetujuan') return;
  if(!canAccSurat()){ toast('Hanya ' + SURAT_ACC_ROLES.join('/') + ' atau Admin Utama yang dapat ACC'); return; }
  if(suratAccRoles(sm).includes(currentRole)){ toast('Anda sudah ACC surat ini'); return; }
  sm.acc = sm.acc || [];
  sm.acc.push({role: currentRole, nama: currentUserName, waktu: nowStr()});
  sm.diperbarui = nowStr();
  const cukup = SURAT_ACC_MODE==='semua' ? (isSuper() || SURAT_ACC_ROLES.every(r=>suratAccRoles(sm).includes(r))) : true; // Admin Utama menggantikan semua pejabat
  if(!cukup){
    persist();
    toast('ACC ' + currentRole + ' tercatat, menunggu pejabat lain');
    render('kegiatan'); openSuratDetail(id);
    return;
  }
  sm.status = 'Disetujui';
  sm.keterangan_persetujuan = `Di-ACC oleh ${suratAccRoles(sm).join(', ')} (${currentUserName}) pada ${nowStr()}.`;
  const k = buatKegiatanDariSurat(sm);
  persist();
  toast(`Surat di-ACC, Kegiatan ${k.id} terbentuk otomatis`);
  kegTab = 'kegiatan';
  render('kegiatan'); openKegiatanDetail(k.id);
}

function rejectSurat(id){
  const alasan = prompt('Alasan penolakan:');
  if(alasan===null) return;
  const sm = getSurat(id);
  if(!sm) return;
  sm.status = 'Ditolak';
  sm.keterangan_persetujuan = `Ditolak oleh ${currentRole} (${currentUserName}): ${alasan || '(tanpa keterangan)'}`;
  sm.diperbarui = nowStr();
  persist();
  toast('Surat ditolak');
  render('kegiatan'); openSuratDetail(id);
}

function deleteSurat(id){
  const sm = getSurat(id);
  if(!sm) return;
  if(!canManageSurat(sm)){ toast('Anda tidak berwenang menghapus surat ini'); return; }
  const kg = sm.kegiatan_id ? getKegiatan(sm.kegiatan_id) : null;
  const pesan = kg
    ? `Hapus surat masuk ${id}?\n\nSurat ini sudah menjadi Kegiatan ${kg.id}. Kegiatan TIDAK ikut terhapus, hanya hubungannya ke surat ini yang dilepas.`
    : `Hapus surat masuk ${id}?`;
  if(!confirm(pesan)) return;
  if(kg){ kg.surat_id = ''; kg.diperbarui = nowStr(); }
  DB.surat_masuk = DB.surat_masuk.filter(x=>x.id!==id);
  suratBulkSel.delete(id);
  persist();
  closeModal();
  toast('Surat masuk dihapus');
  suratRerender();
}
/* Surat Masuk dipakai bersama oleh Kegiatan/Tugas dan Persuratan (satu sumber data DB.surat_masuk):
   setelah hapus, tampilkan ulang halaman yang sedang dibuka. */
function suratRerender(){
  if(currentView==='persuratan') renderPersuratan(); else render('kegiatan');
}

/* ---- Hapus massal Surat Masuk (checkbox pilih data + pilih semua) ---- */
let suratBulkSel = new Set(), suratBulkVisible = [];
function suratBulkRefresh(){
  const vis = suratBulkVisible, n = vis.filter(id=>suratBulkSel.has(id)).length;
  const btn = document.getElementById('suratBulkDelBtn');
  if(btn){ btn.style.display = n ? '' : 'none'; btn.textContent = 'Hapus Terpilih (' + n + ')'; }
  const all = document.getElementById('suratBulkAllCb');
  if(all){ all.checked = vis.length>0 && n===vis.length; all.indeterminate = n>0 && n<vis.length; }
  document.querySelectorAll('.suratBulkCb').forEach(cb=>{ cb.checked = suratBulkSel.has(cb.dataset.id); });
}
function toggleSuratBulkRow(id, on){
  if(on) suratBulkSel.add(id); else suratBulkSel.delete(id);
  suratBulkRefresh();
}
function toggleSuratBulkAll(on){
  suratBulkVisible.forEach(id=>{ if(on) suratBulkSel.add(id); else suratBulkSel.delete(id); });
  suratBulkRefresh();
}
function deleteSuratBulk(){
  const ids = suratBulkVisible.filter(id=>suratBulkSel.has(id));
  if(!ids.length){ toast('Belum ada data yang dipilih'); return; }
  const idSet = new Set(ids);
  const recs = (DB.surat_masuk||[]).filter(r=>idSet.has(r.id) && canManageSurat(r));
  if(!recs.length) return;
  const terkait = recs.filter(r=>r.kegiatan_id && getKegiatan(r.kegiatan_id));
  const semua = recs.length === (DB.surat_masuk||[]).length;
  let pesan = `Hapus ${recs.length} surat masuk${semua ? ' (SELURUH data)' : ''}?\n\nTindakan ini tidak dapat dibatalkan.`;
  if(terkait.length) pesan += `\n\n${terkait.length} surat sudah menjadi Kegiatan/Tugas. Kegiatan TIDAK ikut terhapus, hanya hubungannya ke surat dilepas.`;
  if(!confirm(pesan)) return;
  const hapus = new Set(recs.map(r=>r.id));
  terkait.forEach(r=>{ const kg = getKegiatan(r.kegiatan_id); if(kg){ kg.surat_id = ''; kg.diperbarui = nowStr(); } });
  DB.surat_masuk = (DB.surat_masuk||[]).filter(x=>!hapus.has(x.id));
  suratBulkSel = new Set();
  persist();
  toast(recs.length + ' surat masuk dihapus');
  suratRerender();
}

/* ---- Hapus massal Surat Keluar (Persuratan) — Sprin otomatis (ST-xxx) tidak ikut dipilih ---- */
let skBulkSel = new Set(), skBulkVisible = [];
function skBulkRefresh(){
  const vis = skBulkVisible, n = vis.filter(id=>skBulkSel.has(id)).length;
  const btn = document.getElementById('skBulkDelBtn');
  if(btn){ btn.style.display = n ? '' : 'none'; btn.textContent = 'Hapus Terpilih (' + n + ')'; }
  const all = document.getElementById('skBulkAllCb');
  if(all){ all.checked = vis.length>0 && n===vis.length; all.indeterminate = n>0 && n<vis.length; }
  document.querySelectorAll('.skBulkCb').forEach(cb=>{ cb.checked = skBulkSel.has(cb.dataset.id); });
}
function toggleSkBulkRow(id, on){
  if(on) skBulkSel.add(id); else skBulkSel.delete(id);
  skBulkRefresh();
}
function toggleSkBulkAll(on){
  skBulkVisible.forEach(id=>{ if(on) skBulkSel.add(id); else skBulkSel.delete(id); });
  skBulkRefresh();
}
function deleteSkBulk(){ if(guardAdminSurat()) return;
  const ids = skBulkVisible.filter(id=>skBulkSel.has(id));
  if(!ids.length){ toast('Belum ada data yang dipilih'); return; }
  const idSet = new Set(ids);
  const recs = (DB.surat_keluar||[]).filter(r=>idSet.has(r.id));
  if(!recs.length) return;
  const semua = recs.length === (DB.surat_keluar||[]).length;
  if(!confirm(`Hapus ${recs.length} surat keluar${semua ? ' (SELURUH data)' : ''}?\n\nTindakan ini tidak dapat dibatalkan.`)) return;
  const hapus = new Set(recs.map(r=>r.id));
  DB.surat_keluar = (DB.surat_keluar||[]).filter(x=>!hapus.has(x.id));
  skBulkSel = new Set();
  persist();
  toast(recs.length + ' surat keluar dihapus');
  renderPersuratan();
}

function openKegiatanForm(){
  const jenisOptions = GENERIC_MODULES.map(m=>`<option value="${m.key}">${esc(m.label)}</option>`).join('');
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-display font-semibold text-base">Ajukan Kegiatan/Tugas</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Nama Kegiatan *</label><input type="text" id="kf_nama"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Sumber Class</label><select id="kf_jenis" onchange="kfToggleJenisKet()">${jenisOptions}</select>
          <div id="kf_jenis_ket_wrap" class="mt-2" style="display:none;"><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Keterangan (Jenis Lainnya) *</label><input type="text" id="kf_jenis_ket" placeholder="Jelaskan jenis kegiatan..."></div></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tanggal Kegiatan</label><input type="date" id="kf_tanggal" value="${todayISO()}" onchange="kfSyncTanggal()"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Tanggal Selesai</label><input type="date" id="kf_tanggal_selesai" value="${todayISO()}" min="${todayISO()}"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Waktu</label><input type="time" id="kf_waktu" value="08:00"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Lokasi</label><input type="text" id="kf_lokasi"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Penanggung Jawab</label><input type="text" id="kf_pj"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">No. HP</label><input type="tel" id="kf_hp" inputmode="tel" placeholder="mis. 0812xxxxxxxx"></div>
        <div><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Jumlah Anggota Dibutuhkan</label><input type="number" id="kf_jml" min="1" value="2"></div>
        <div class="sm:col-span-2"><label class="block text-xs font-medium mb-1" style="color:var(--muted);">Deskripsi Kegiatan</label><textarea id="kf_desk" rows="2"></textarea></div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium mb-1" style="color:var(--muted);">Lampiran File PDF</label>
          <input type="file" id="kf_pdf" accept="application/pdf,.pdf" onchange="handleKfPdfChange(this)">
          <p id="kf_pdf_info" class="text-[11px] mt-1" style="color:var(--muted);">Maks. 10MB, hanya format PDF.</p>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveKegiatanForm()">Ajukan</button>
      </div>
    </div>`, true);
}

// Tanggal selesai tidak boleh sebelum tanggal mulai
function kfSyncTanggal(){
  const a = document.getElementById('kf_tanggal'), b = document.getElementById('kf_tanggal_selesai');
  if(!a || !b) return;
  b.min = a.value || '';
  if(a.value && (!b.value || b.value < a.value)) b.value = a.value;
}

let kfPdfFile = null; // {name, size, data} data = base64 data URL

function handleKfPdfChange(input){
  const info = document.getElementById('kf_pdf_info');
  const file = input.files && input.files[0];
  kfPdfFile = null;
  if(!file) { info.textContent = 'Maks. 10MB, hanya format PDF.'; return; }
  if(file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')){
    toast('File harus berformat PDF');
    input.value = '';
    info.textContent = 'Maks. 10MB, hanya format PDF.';
    return;
  }
  if(file.size > 10*1024*1024){
    toast('Ukuran file maksimal 10MB');
    input.value = '';
    info.textContent = 'Maks. 10MB, hanya format PDF.';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    kfPdfFile = { name: file.name, size: file.size, data: reader.result };
    info.textContent = `Terpilih: ${file.name} (${(file.size/1024).toFixed(0)} KB)`;
  };
  reader.onerror = () => {
    toast('Gagal membaca file PDF');
    info.textContent = 'Maks. 10MB, hanya format PDF.';
  };
  reader.readAsDataURL(file);
}

function kfToggleJenisKet(){
  const sel = document.getElementById('kf_jenis'), w = document.getElementById('kf_jenis_ket_wrap');
  if(!sel || !w) return;
  const on = sel.value==='lainnya';
  w.style.display = on ? 'block' : 'none';
  if(on){ const i = document.getElementById('kf_jenis_ket'); if(i) i.focus(); }
}
function saveKegiatanForm(){
  const nama = document.getElementById('kf_nama').value.trim();
  if(!nama){ toast('Nama kegiatan wajib diisi'); return; }
  const jenisKey = document.getElementById('kf_jenis').value;
  const jenisLabel = GENERIC_MODULES.find(m=>m.key===jenisKey).label;
  const jenisKet = jenisKey==='lainnya' ? document.getElementById('kf_jenis_ket').value.trim() : '';
  if(jenisKey==='lainnya' && !jenisKet){ toast('Keterangan jenis kegiatan (Lainnya) wajib diisi'); return; }
  const tglMulai = document.getElementById('kf_tanggal').value;
  const tglSelesai = document.getElementById('kf_tanggal_selesai').value || tglMulai;
  if(tglMulai && tglSelesai < tglMulai){ toast('Tanggal selesai tidak boleh sebelum tanggal kegiatan'); return; }
  const row = {
    id: uid('KGT', DB.kegiatan),
    nomor: (DB.kegiatan.length+1).toString().padStart(3,'0') + '/KGT/2026',
    jenis: jenisKey, jenis_label: jenisLabel, jenis_ket: jenisKet,
    nama, tanggal: tglMulai, tanggal_selesai: tglSelesai, waktu: document.getElementById('kf_waktu').value,
    lokasi: document.getElementById('kf_lokasi').value, penanggung_jawab: document.getElementById('kf_pj').value, no_hp: document.getElementById('kf_hp').value.trim(),
    deskripsi: document.getElementById('kf_desk').value, jumlah_dibutuhkan: parseInt(document.getElementById('kf_jml').value)||1,
    status_persetujuan:'Menunggu Persetujuan', keterangan_persetujuan:'', anggota_ids:[],
    diajukan_oleh: pelaku(),
    status_pelaksanaan:'Draft', status_wa:'Belum Terkirim', wa_log:[], dokumentasi:'',
    file_pdf: kfPdfFile,
    dibuat: nowStr(), diperbarui: nowStr(),
  };
  DB.kegiatan.push(row);
  const linkedId = autoFillModuleFromKegiatan(jenisKey, row);
  if(linkedId){ row.linked_record_id = linkedId; }
  persist();
  kfPdfFile = null;
  closeModal();
  toast(linkedId ? `Kegiatan diajukan, data juga tercatat otomatis di ${jenisLabel}` : 'Kegiatan diajukan, menunggu persetujuan');
  render('kegiatan');
}

function getKegiatan(id){ return DB.kegiatan.find(k=>k.id===id); }

function openKegiatanDetail(id){
  const k = getKegiatan(id);
  if(!k) return;
  const assigned = DB.anggota.filter(a => k.anggota_ids.includes(a.id));
  const canApprove = isApprover() && (!isAdmin() || isSuper()) && k.status_persetujuan==='Menunggu Persetujuan';
  const canAssign = k.status_persetujuan==='Disetujui' && !k.sprin && isApprover();
  const canSendWa = k.status_persetujuan==='Disetujui' && k.anggota_ids.length>0 && !!k.sprin && k.status_wa!=='Terkirim' && isApprover();
  const canProgress = k.status_persetujuan==='Disetujui' && isApprover();

  openModal(`
    <div class="p-5 max-h-[88vh] overflow-y-auto">
      <div class="flex items-start justify-between mb-1">
        <div>
          <h3 class="font-display font-semibold text-base">${esc(k.nama)}</h3>
          <p class="text-xs" style="color:var(--muted);">${esc(k.id)} · ${esc(k.nomor)} · ${esc(k.jenis_label)}${k.jenis_ket ? ' ('+esc(k.jenis_ket)+')' : ''}</p>
        </div>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <div class="flex flex-wrap gap-1.5 my-3">
        ${statusBadge(k.status_persetujuan)} ${statusBadge(k.status_pelaksanaan)} ${statusBadge(k.status_wa)} ${sprinBadgeHtml(k)}
      </div>

      <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1.5 text-sm mb-4">
        <div><span style="color:var(--muted);">Tanggal:</span> ${esc(tglRentang(k))} ${esc(k.waktu)}</div>
        <div><span style="color:var(--muted);">Lokasi:</span> ${esc(k.lokasi)||'-'}</div>
        <div><span style="color:var(--muted);">Penanggung Jawab:</span> ${esc(k.penanggung_jawab)||'-'}</div>
        <div><span style="color:var(--muted);">No. HP:</span> ${esc(k.no_hp)||'-'}</div>
        <div><span style="color:var(--muted);">Anggota Dibutuhkan:</span> ${k.jumlah_dibutuhkan}</div>
        <div class="sm:col-span-2"><span style="color:var(--muted);">Deskripsi:</span> ${esc(k.deskripsi)||'-'}</div>
        <div class="sm:col-span-2"><span style="color:var(--muted);">Diajukan Oleh:</span> ${esc(pelakuTeks(k.diajukan_oleh))}</div>
        <div class="sm:col-span-2"><span style="color:var(--muted);">${k.status_persetujuan==='Ditolak' ? 'Ditolak Oleh' : 'Disetujui Oleh'}:</span> ${k.status_persetujuan==='Menunggu Persetujuan' ? '-' : esc(pelakuTeks(disetujuOlehKegiatan(k))) + (k.acc_via ? ' · via ' + esc(k.acc_via) : '')}</div>
      </div>

      ${k.file_pdf ? `
        <div class="flex items-center gap-2.5 rounded-lg p-3 mb-4" style="background:var(--bg);">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--bad);"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium truncate">${esc(k.file_pdf.name)}</p>
            <p class="text-[11px]" style="color:var(--muted);">${(k.file_pdf.size/1024).toFixed(0)} KB</p>
          </div>
          <a href="${k.file_pdf.data}" download="${esc(k.file_pdf.name)}" class="btn btn-ghost !py-1 !px-2 text-xs">Unduh</a>
          <a href="${k.file_pdf.data}" target="_blank" rel="noopener" class="btn btn-navy !py-1 !px-2 text-xs">Lihat</a>
        </div>` : ''}

      ${(k.surat_id && getSurat(k.surat_id)) ? `<div class="text-sm rounded-lg p-3 mb-4 flex items-center justify-between gap-2" style="background:var(--bg);"><span><span style="color:var(--muted);">Asal:</span> Surat Masuk ${esc(k.surat_id)} · ${esc(getSurat(k.surat_id).nomor_surat)}</span><button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="closeModal(); kegTab='surat'; render('kegiatan'); openSuratDetail('${k.surat_id}')">Lihat Surat</button></div>` : ''}

      ${k.linked_record_id ? `<button class="btn btn-ghost mb-4" onclick="closeModal(); openGenericDetail('${k.jenis}','${k.linked_record_id}')">Lihat Data di ${esc(k.jenis_label)}</button>` : ''}

      ${k.keterangan_persetujuan ? `<div class="text-sm rounded-lg p-3 mb-4" style="background:var(--bg);"><strong>Keterangan Persetujuan:</strong> ${esc(k.keterangan_persetujuan)}</div>` : ''}
      ${k.status_persetujuan==='Ditolak' ? `<p class="text-xs mb-4" style="color:var(--bad);">Kegiatan ditolak: status pelaksanaan terkunci <strong>Draft</strong> dan tidak dapat diubah; data kegiatan ini tidak ada di modul ${esc(k.jenis_label)}.</p>` : ''}

      ${canApprove ? `
        <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
          <p class="text-sm font-medium mb-2">Persetujuan (${esc(currentRole)})</p>
          <div class="flex gap-2">
            <button class="btn btn-ok" onclick="approveKegiatan('${k.id}')">Setujui</button>
            <button class="btn btn-bad" onclick="rejectKegiatan('${k.id}')">Tolak</button>
          </div>
          <p class="text-[11px] mt-2" style="color:var(--muted);">Pimpinan juga dapat memutuskan lewat WhatsApp. Keputusan pertama (dashboard atau WhatsApp) bersifat final.</p>
        </div>` : ''}

      ${assigned.length ? `
        <div class="mb-4">
          <p class="text-sm font-medium mb-2">Anggota Ditugaskan</p>
          <div class="flex flex-wrap gap-1.5">
            ${assigned.map(a=>`<span class="badge b-info">${esc(a.nama)}</span>`).join('')}
          </div>
        </div>` : ''}

      ${canAssign ? `<button class="btn btn-navy mb-4" onclick="openAssignAnggota('${k.id}')">${k.anggota_ids.length ? 'Ubah Anggota Bertugas' : 'Pilih Anggota Bertugas'}</button>` : ''}
      ${(k.status_persetujuan==='Disetujui' && k.sprin && isApprover()) ? `<p class="text-[11px] mb-4" style="color:var(--muted);">Sprin sudah terinput, anggota bertugas terkunci.</p>` : ''}
      ${sprinCardHtml(k)}
      ${canSendWa ? `<button class="btn btn-primary mb-4" onclick="sendWhatsapp('${k.id}')">Kirim Info Tugas via WhatsApp</button>` : ''}

      ${k.wa_log.length ? `<p class="text-xs mb-4" style="color:var(--muted);">Terakhir dikirim ke ${k.wa_log[k.wa_log.length-1].jumlah} anggota pada ${esc(k.wa_log[k.wa_log.length-1].waktu)}.</p>` : ''}

      ${canProgress ? `
        <div class="flex flex-wrap items-center gap-2 mb-2">
          ${k.status_pelaksanaan==='Draft' ? `<button class="btn btn-ghost" onclick="setPelaksanaan('${k.id}','Dalam Pelaksanaan')">Mulai Pelaksanaan</button>` : ''}
          ${k.status_pelaksanaan==='Dalam Pelaksanaan' ? `<button class="btn btn-ghost" onclick="setPelaksanaan('${k.id}','Selesai')">Tandai Selesai</button>` : ''}
          ${k.status_pelaksanaan!=='Selesai' ? `<span class="text-[11px]" style="color:var(--muted);">Tombol cadangan jika status tidak berubah otomatis dari upload file.</span>` : ''}
        </div>` : ''}

      ${buktiKegiatanHtml(k)}
      ${totalPesertaHtml(k)}
      ${laporanSelesaiHtml(k)}
      ${hasilKegiatanHtml(k)}
      ${lampiranWaHtml('kegiatan', k)}

      <div class="flex justify-between items-center mt-4 pt-3 border-t" style="border-color:var(--line);">
        <span class="text-[11px]" style="color:var(--muted);">Dibuat ${esc(k.dibuat)} · Diperbarui ${esc(k.diperbarui)}</span>
        ${isApprover() ? `<button class="btn btn-ghost text-xs" style="color:var(--bad);" onclick="deleteKegiatan('${k.id}')">Hapus</button>` : ''}
      </div>
    </div>`, true);
}

function touchKegiatan(k){ k.diperbarui = nowStr(); syncModuleStatusFromKegiatan(k); persist(); }

function approveKegiatan(id){
  const k = getKegiatan(id);
  if(!k || k.status_persetujuan!=='Menunggu Persetujuan'){ toast('Status persetujuan sudah final dan tidak dapat diubah'); return; }
  k.status_persetujuan = 'Disetujui';
  k.keterangan_persetujuan = `Disetujui oleh ${currentRole} pada ${nowStr()}.`;
  k.disetujui_oleh = pelaku();
  touchKegiatan(k);
  toast('Kegiatan disetujui');
  render('kegiatan'); openKegiatanDetail(id);
}
function rejectKegiatan(id){
  const k0 = getKegiatan(id);
  if(!k0 || k0.status_persetujuan!=='Menunggu Persetujuan'){ toast('Status persetujuan sudah final dan tidak dapat diubah'); return; }
  const alasan = prompt('Alasan penolakan:');
  if(alasan===null) return;
  const k = k0;
  k.status_persetujuan = 'Ditolak';
  k.status_pelaksanaan = 'Draft'; // ditolak: pelaksanaan kembali/tetap Draft dan terkunci
  k.keterangan_persetujuan = `Ditolak oleh ${currentRole}: ${alasan || '(tanpa keterangan)'}`;
  k.disetujui_oleh = pelaku();
  const dihapus = dataModulTerkaitKegiatan(k);
  dihapus.forEach(t=>{ DB[t.key] = (DB[t.key]||[]).filter(r=>r.id!==t.id); });
  k.linked_record_id = '';
  touchKegiatan(k);
  toast(dihapus.length ? 'Kegiatan ditolak, data di modul ' + k.jenis_label + ' dihapus' : 'Kegiatan ditolak');
  render('kegiatan'); openKegiatanDetail(id);
}

// Anggota yang sudah ditugaskan di kegiatan lain pada tanggal yang sama (kegiatan ditolak tidak dihitung)
function anggotaBentrok(k){
  const tgl = String(k.tanggal||'').slice(0,10);
  const tglAkhir = String(k.tanggal_selesai||'').slice(0,10) || tgl;
  const map = {};
  if(!tgl) return map;
  (DB.kegiatan||[]).forEach(o=>{
    const oa = String(o.tanggal||'').slice(0,10), ob = String(o.tanggal_selesai||'').slice(0,10) || oa;
    // bentrok bila rentang tanggal kedua kegiatan saling beririsan
    if(o.id===k.id || o.status_persetujuan==='Ditolak' || !oa || oa > tglAkhir || ob < tgl) return;
    (o.anggota_ids||[]).forEach(aid=>{ (map[aid] = map[aid]||[]).push(o.id); });
  });
  return map;
}
function openAssignAnggota(id){
  const k = getKegiatan(id);
  if(k.sprin){ toast('Sprin sudah terinput, anggota bertugas tidak dapat diubah'); return; }
  const aktif = DB.anggota.filter(a=>a.status==='Aktif');
  const bentrok = anggotaBentrok(k);
  const dipilih = new Set(k.anggota_ids||[]);
  openModal(`
    <div class="p-5 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-1">
        <h3 class="font-display font-semibold text-base">${dipilih.size ? 'Ubah' : 'Pilih'} Anggota Bertugas</h3>
        <button class="btn btn-ghost !px-2 !py-1" onclick="closeModal()">✕</button>
      </div>
      <p class="text-xs mb-1" style="color:var(--muted);">Dibutuhkan ${k.jumlah_dibutuhkan} anggota untuk "${esc(k.nama)}" pada ${esc(tglRentang(k))}.</p>
      <p class="text-[11px] mb-3" style="color:var(--muted);">Anggota yang sudah bertugas di kegiatan lain pada rentang tanggal yang sama tidak dapat dipilih.</p>
      <div class="flex items-center gap-2 mb-2">
        <input type="text" id="assign_cari" placeholder="Cari nama atau jabatan anggota..." style="flex:1; min-width:0;" autocomplete="off" oninput="filterAssignAnggota(this.value)">
        <button type="button" class="btn btn-navy" id="assign_btn_sar" style="white-space:nowrap;" onclick="toggleAssignTenagaSar(${k.jumlah_dibutuhkan})">Tenaga SAR</button>
      </div>
      <div class="space-y-1.5 max-h-[45vh] overflow-y-auto" id="assign_list">
        ${aktif.map(a=>{
          const bt = bentrok[a.id];
          return `
          <label class="assign-item flex items-center gap-2.5 p-2 rounded-lg" data-sar="${isTenagaSar(a)?'1':'0'}" data-cari="${esc((a.nama+' '+(a.jabatan||'')).toLowerCase())}" style="background:var(--bg); ${bt?'opacity:.55; cursor:not-allowed;':''}">
            <input type="checkbox" class="assign-chk" value="${a.id}" style="width:1rem;height:1rem;" ${dipilih.has(a.id)?'checked':''} ${bt?'disabled':''}>
            <span class="text-sm">${esc(a.nama)} <span style="color:var(--muted)">— ${esc(a.jabatan)}</span>${bt ? ` <span class="badge b-warn" style="margin-left:.25rem;">Bertugas di ${esc(bt.join(', '))}</span>` : ''}</span>
          </label>`;}).join('')}
        <p id="assign_kosong" class="text-sm text-center py-4" style="display:none; color:var(--muted);">Anggota tidak ditemukan.</p>
      </div>
      <div id="assign_wa_box" class="mt-3 p-3 rounded-lg flex flex-wrap items-center justify-between gap-2" style="display:none; background:var(--bg); position:sticky; bottom:0; z-index:5;">
        <span class="text-xs" style="color:var(--muted);">Tenaga SAR dipilih. Kirim notifikasi penugasan ke WA Grup.</span>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn btn-navy" onclick="saveAssignAnggota('${id}')">Simpan Data</button>
          <button type="button" class="btn btn-primary" onclick="kirimNotifWaGrup('${id}')">Kirim Notifikasi via WA Grup</button>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-5">
        <button class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button class="btn btn-primary" onclick="saveAssignAnggota('${id}')">Simpan Penugasan</button>
      </div>
    </div>`, true);
}
// Tenaga SAR = anggota yang jabatannya termasuk kelompok Tenaga SAR (BA_KATEGORI)
function isTenagaSar(a){
  const t = String((a && a.jabatan) || '').trim().toLowerCase();
  return BA_KATEGORI.some(k=>k.re.test(t));
}
// Tombol "Tenaga SAR": centang semua anggota Tenaga SAR yang masih bisa dipilih (tidak bentrok).
// Klik lagi bila semuanya sudah tercentang untuk membatalkan centang Tenaga SAR.
function toggleAssignTenagaSar(maks){
  const btn = document.getElementById('assign_btn_sar');
  const boxes = Array.from(document.querySelectorAll('#assign_list .assign-item'))
    .filter(el=>el.getAttribute('data-sar')==='1')
    .map(el=>el.querySelector('.assign-chk'))
    .filter(cb=>cb && !cb.disabled);
  if(!boxes.length){ toast('Tidak ada anggota Tenaga SAR yang dapat dipilih'); return; }
  // Klik pertama selalu mencentang semua Tenaga SAR; klik berikutnya (setelah aktif) membatalkan centang
  const aktif = btn && btn.getAttribute('data-aktif')==='1';
  const centang = !aktif;
  boxes.forEach(cb=>{ cb.checked = centang; });
  if(btn){
    btn.setAttribute('data-aktif', centang ? '1' : '0');
    btn.textContent = centang ? 'Tenaga SAR ✓' : 'Tenaga SAR';
  }
  const waBox = document.getElementById('assign_wa_box');
  if(waBox) waBox.style.display = centang ? '' : 'none';
  if(!centang){ toast('Centang Tenaga SAR dibatalkan'); return; }
  const total = document.querySelectorAll('.assign-chk:checked').length;
  if(maks && total > maks) toast(`${boxes.length} Tenaga SAR dicentang, melebihi kebutuhan (${maks} anggota). Kurangi centang sebelum menyimpan`);
  else toast(`${boxes.length} Tenaga SAR dicentang`);
}
// Kirim notifikasi penugasan ke WA Grup: buka WhatsApp dengan pesan siap kirim (pilih grup tujuan di WhatsApp)
function kirimNotifWaGrup(id){
  const k = getKegiatan(id);
  if(!k) return;
  const ids = Array.from(document.querySelectorAll('.assign-chk:checked')).map(el=>el.value);
  const nama = DB.anggota.filter(a=>ids.includes(a.id)).map(a=>a.nama);
  if(!nama.length){ toast('Belum ada anggota yang dicentang'); return; }
  // Uji Periodik: template ringkas (Kegiatan / Tanggal / Jam / Lokasi) tanpa daftar anggota
  if(k.jenis==='uji_periodik'){
    const pesanUjian = [
      'Kegiatan: ' + (k.nama||''),
      'Tanggal: ' + tglRentang(k),
      'Jam: ' + (k.waktu ? k.waktu + ' WIB' : '-'),
      'Lokasi: ' + (k.lokasi||'-')
    ].join('\n');
    window.open('https://wa.me/?text=' + encodeURIComponent(pesanUjian), '_blank');
    toast('WhatsApp dibuka, pilih WA Grup tujuan');
    return;
  }
  const baris = [
    '*NOTIFIKASI PENUGASAN*',
    '',
    'Kegiatan : ' + (k.nama||''),
    'Tanggal  : ' + tglRentang(k) + (k.waktu ? ' pukul ' + k.waktu : ''),
    'Lokasi   : ' + (k.lokasi||'-'),
    '',
    'Anggota bertugas (' + nama.length + '):'
  ].concat(nama.map((n,i)=>(i+1)+'. '+n)).concat(['', 'Mohon dipersiapkan. Terima kasih.']);
  window.open('https://wa.me/?text=' + encodeURIComponent(baris.join('\n')), '_blank');
  toast('WhatsApp dibuka, pilih WA Grup tujuan');
}
// Cari anggota: sembunyikan yang tidak cocok; centang tetap tersimpan walau sedang disembunyikan
function filterAssignAnggota(q){
  const nq = normSearch(q);
  let n = 0;
  document.querySelectorAll('#assign_list .assign-item').forEach(el=>{
    const ok = !nq || normSearch(el.getAttribute('data-cari')).includes(nq);
    el.style.display = ok ? '' : 'none';
    if(ok) n++;
  });
  const k = document.getElementById('assign_kosong');
  if(k) k.style.display = n ? 'none' : '';
}
function saveAssignAnggota(id){
  const k = getKegiatan(id);
  if(k.sprin){ toast('Sprin sudah terinput, anggota bertugas tidak dapat diubah'); return; }
  const chosen = Array.from(document.querySelectorAll('.assign-chk:checked')).map(el=>el.value);
  if(chosen.length===0){ toast('Pilih minimal satu anggota'); return; }
  if(chosen.length > k.jumlah_dibutuhkan){ toast(`Maksimal ${k.jumlah_dibutuhkan} anggota untuk kegiatan ini`); return; }
  const bentrok = anggotaBentrok(k);
  const dobel = chosen.filter(aid=>bentrok[aid]);
  if(dobel.length){ toast('Ada anggota yang sudah bertugas di kegiatan lain pada rentang tanggal yang sama'); return; }
  const lama = (k.anggota_ids||[]).slice();
  const keluar = lama.filter(aid=>!chosen.includes(aid));
  const masuk = chosen.filter(aid=>!lama.includes(aid));
  const berubah = JSON.stringify((k.anggota_ids||[]).slice().sort()) !== JSON.stringify(chosen.slice().sort());
  k.anggota_ids = chosen;
  if(berubah && k.status_wa==='Terkirim') k.status_wa = 'Belum Terkirim'; // anggota berubah: info tugas perlu dikirim ulang
  touchKegiatan(k);
  toast('Penugasan disimpan');
  closeModal();
  render('kegiatan'); openKegiatanDetail(id);
  if(keluar.length) notifGantiAnggotaWa(id, keluar, masuk); // beri tahu anggota yang digantikan lewat WhatsApp
}
/* Anggota yang digantikan pada penugasan menerima notifikasi WhatsApp (dikirim server; data dibaca dari server). */
async function notifGantiAnggotaWa(id, keluar, masuk){
  try{
    await flushPersist(); // pastikan daftar anggota bertugas terbaru sudah di server
    const r = await fetch(API_URL + '?action=wa_ganti_anggota', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body:JSON.stringify({id, keluar, masuk})});
    const j = await r.json().catch(()=>({ok:false}));
    if(!r.ok || !j.ok){ toast('Notifikasi WA ke anggota yang digantikan gagal: ' + (j.error || ('HTTP '+r.status))); return; }
    if(!j.total) return;
    const gagal = (j.hasil||[]).filter(h=>!h.ok);
    if(gagal.length) alert('Notifikasi penggantian terkirim ke ' + j.terkirim + ' dari ' + j.total + ' anggota.\n\nGagal:\n' + gagal.map(h=>'- '+h.nama+': '+h.ket).join('\n'));
    else toast('Notifikasi WA terkirim ke ' + j.terkirim + ' anggota yang digantikan');
  }catch(e){ console.error(e); }
}

// Kirim info tugas (template Nama Kegiatan/Tanggal/Lokasi/Jam/Anggota) + PDF Sprin ke WhatsApp tiap anggota bertugas.
// Pengiriman dilakukan server (api.php?action=wa_tugas) lewat gateway WhatsApp; token tidak pernah ke browser.
let waTugasBusy = false;
async function sendWhatsapp(id){
  if(waTugasBusy) return;
  const k = getKegiatan(id); if(!k) return;
  const assigned = DB.anggota.filter(a=>k.anggota_ids.includes(a.id));
  if(!k.sprin){ toast('Upload Sprin terlebih dahulu'); return; }
  const tanpaNo = assigned.filter(a=>!String(a.whatsapp||'').trim()).map(a=>a.nama);
  let msg = `Kirim ke ${assigned.length} anggota via WhatsApp?\n\nSetiap anggota menerima 2 pesan terpisah:\n1. Info tugas + PDF Sprin\n2. Link update kegiatan\n\n(Link upload laporan dikirim otomatis setelah anggota mengisi update kegiatan.)`;
  if(tanpaNo.length) msg += `\n\nTanpa nomor WhatsApp (tidak akan menerima):\n- ${tanpaNo.join('\n- ')}`;
  if(!confirm(msg)) return;
  waTugasBusy = true;
  toast('Mengirim ke WhatsApp, mohon tunggu...');
  try{
    persist(); await flushPersist(); // pastikan data (anggota bertugas, Sprin) di server sudah terbaru
    const r = await fetch(API_URL + '?action=wa_tugas', {method:'POST', headers:{'X-Requested-With':'basarnas','Content-Type':'application/json'}, body: JSON.stringify({id})});
    const j = await r.json().catch(()=>({}));
    if(!r.ok || !j.ok){ toast(j.error || 'Gagal mengirim WhatsApp'); return; }
    const gagal = (j.hasil||[]).filter(h=>!h.ok);
    if(j.terkirim > 0){
      // Ambil ulang record terbaru (DB bisa saja sudah diganti hasil sinkronisasi saat pengiriman berjalan);
      // status & riwayat kirim dicatat server, browser hanya mengikuti.
      const kk = getKegiatan(id) || k;
      kk.status_wa = j.status_wa || (gagal.length ? 'Belum Terkirim' : 'Terkirim');
      if(Array.isArray(j.wa_log)) kk.wa_log = j.wa_log;
      else { kk.wa_log = kk.wa_log || []; kk.wa_log.push({waktu: nowStr(), jumlah: j.terkirim}); }
      if(j.status_wa){ persist(); SYNC_REV = -1; } else touchKegiatan(kk);
    }
    if(gagal.length) alert(`Terkirim ke ${j.terkirim} dari ${j.total} anggota.\n\nGagal:\n` + gagal.map(h=>`- ${h.nama}: ${h.ket}`).join('\n') + '\n\nPerbaiki nomor/koneksi lalu kirim ulang.');
    else toast(`Info tugas + Sprin dan link update terkirim ke ${j.terkirim} anggota`);
  }catch(e){
    toast('Gagal mengirim WhatsApp: ' + (e && e.message ? e.message : 'koneksi bermasalah'));
  }finally{
    waTugasBusy = false;
    render('kegiatan'); openKegiatanDetail(id);
  }
}

function setPelaksanaan(id, status){
  const k = getKegiatan(id);
  if(!k || k.status_persetujuan!=='Disetujui'){ toast('Kegiatan yang ditolak/belum disetujui tetap berstatus Draft dan tidak dapat diubah'); return; }
  k.status_pelaksanaan = status;
  touchKegiatan(k);
  toast(status==='Selesai' ? 'Kegiatan selesai, dipindahkan ke Tugas Selesai' : 'Status pelaksanaan diperbarui: ' + status);
  if(status==='Selesai'){ kegTab = 'selesai'; notifSelesaiWa(id); }
  render('kegiatan'); openKegiatanDetail(id);
}

// Data modul yang terhubung dengan satu kegiatan: data yang ditunjuk kegiatan
// (linked_record_id) atau data yang mencatat kegiatan ini sebagai asalnya (dari_kegiatan_id).
function dataModulTerkaitKegiatan(k){
  const hasil = [];
  if(!k) return hasil;
  GENERIC_MODULES.forEach(m=>{
    (DB[m.key]||[]).forEach(r=>{
      const tertaut = (m.key===k.jenis && k.linked_record_id && r.id===k.linked_record_id) || r.dari_kegiatan_id===k.id;
      if(tertaut) hasil.push({key:m.key, label:m.label, id:r.id});
    });
  });
  return hasil;
}
function deleteKegiatan(id){
  const k = (DB.kegiatan||[]).find(x=>x.id===id);
  const terkait = dataModulTerkaitKegiatan(k);
  const pesan = terkait.length
    ? 'Hapus kegiatan ini?\n\nData terkait di modul (' + terkait.map(t=>t.label+' '+t.id).join(', ') + ') juga akan ikut terhapus.'
    : 'Hapus kegiatan ini?';
  if(!confirm(pesan)) return;
  ((k&&k.hasil_files)||[]).forEach(f=>fdbDel(f.fid));
  ((k&&k.bukti_files)||[]).forEach(f=>fdbDel(f.fid));
  lampiranWaList(k).forEach(f=>fdbDel(f.fid));
  if(k && k.sprin) fdbDel(k.sprin.fid);
  DB.kegiatan = DB.kegiatan.filter(x=>x.id!==id);
  terkait.forEach(t=>{ DB[t.key] = (DB[t.key]||[]).filter(r=>r.id!==t.id); });
  persist();
  closeModal();
  toast(terkait.length ? 'Kegiatan dan data terkait di modul dihapus' : 'Kegiatan dihapus');
  render('kegiatan');
}


/* ============================= SPRIN (SURAT PERINTAH) ============================= */
// Pengganti Surat Tugas: Sprin dibuat di luar aplikasi, lalu diupload (PDF) oleh Admin.
// File disimpan di IndexedDB (sama seperti file hasil kegiatan); metadata di k.sprin.
// Keterangan: Belum Di Proses (default) / Sedang Di Proses (diatur Admin) / Selesai (otomatis setelah diupload).
const SPRIN_MAX_BYTES = 25*1024*1024;
function sprinPerluTampil(k){ return k.status_persetujuan==='Disetujui' && (k.anggota_ids||[]).length>0; }
function sprinStatusLabel(k){ return k.sprin ? 'Selesai' : (k.sprin_proses==='Sedang Di Proses' ? 'Sedang Di Proses' : 'Belum Di Proses'); }
function sprinBadgeHtml(k){
  if(!sprinPerluTampil(k)) return '';
  return 'Sprin: ' + prosesBadgeHtml(sprinStatusLabel(k));
}
function isAdmin(){ return currentRole==='Admin' || isSuper(); }
function sprinCardHtml(k){
  if(!sprinPerluTampil(k)) return '';
  const sp = k.sprin;
  const ico = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/></svg>`;
  return `
    <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
      <div class="flex items-center gap-2.5">
        ${ico}
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium">Sprin</p>
          <p class="text-[11px]" style="color:var(--muted);">${sp ? esc(sp.name)+' · '+fmtUkuran(sp.size)+' · diupload '+esc(sp.uploaded) : 'Belum diupload oleh Admin'}</p>
        </div>
        ${sprinBadgeHtml(k)}
      </div>
      ${(!sp && isAdmin()) ? `<div class="mt-3"><p class="text-[11px] mb-1" style="color:var(--muted);">Keterangan Sprin:</p><select class="!w-52" onchange="setSprinProses('${k.id}',this.value,true)">${PROSES_LIST.map(o=>`<option ${sprinStatusLabel(k)===o?'selected':''}>${o}</option>`).join('')}</select></div>` : ''}
      ${sp ? `<div class="flex flex-wrap gap-2 mt-3">
        <button class="btn btn-navy !py-1 !px-2 text-xs" onclick="lihatSprin('${k.id}')">Lihat</button>
        <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="unduhSprin('${k.id}')">Unduh</button>
        ${isAdmin() ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="hapusSprin('${k.id}')">Hapus</button>` : ''}
      </div>` : ''}
      ${isAdmin() ? `<div class="mt-3">
        <p class="text-[11px] mb-1" style="color:var(--muted);">${sp ? 'Ganti file Sprin (PDF, maks. 25MB):' : 'Upload file Sprin (PDF, maks. 25MB):'}</p>
        <input type="file" accept="application/pdf,.pdf" onchange="uploadSprin('${k.id}', this)">
      </div>` : (sp ? '' : `<p class="text-[11px] mt-2" style="color:var(--muted);">Sprin akan diupload oleh Admin.</p>`)}
    </div>`;
}
async function uploadSprin(id, input){
  const k = getKegiatan(id); if(!k) return;
  if(!isAdmin()){ toast('Hanya Admin yang dapat mengupload Sprin'); return; }
  const f = (input.files||[])[0]; if(!f) return;
  if(!(f.type==='application/pdf' || /\.pdf$/i.test(f.name))){ toast('Sprin harus berformat PDF'); input.value=''; return; }
  if(f.size > SPRIN_MAX_BYTES){ toast('File Sprin melebihi 25MB'); input.value=''; return; }
  const fid = 'S'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
  try{
    await fdbPut({fid, kid:id, name:f.name, blob:f});
    if(k.sprin) await fdbDel(k.sprin.fid);
    k.sprin = {fid, name:f.name, size:f.size, type:'application/pdf', uploaded:nowStr(), tanggal:todayISO(), oleh:currentRole};
    k.diperbarui = nowStr(); persist();
    toast('Sprin berhasil diupload');
  }catch(e){ toast('Gagal menyimpan file Sprin'); }
  render('kegiatan'); openKegiatanDetail(id);
}
async function sprinBlobUrl(id){
  const k = getKegiatan(id); if(!k || !k.sprin) return null;
  const rec = await fdbGet(k.sprin.fid);
  if(!rec){ toast('File Sprin tidak ditemukan di server'); return null; }
  const blob = rec.blob.type==='application/pdf' ? rec.blob : new Blob([rec.blob], {type:'application/pdf'});
  return {url:URL.createObjectURL(blob), name:rec.name};
}
async function lihatSprin(id){
  try{
    const r = await sprinBlobUrl(id); if(!r) return;
    const a = document.createElement('a');
    a.href = r.url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(r.url), 60000);
  }catch(e){ toast('Gagal membuka Sprin'); }
}
async function unduhSprin(id){
  try{
    const r = await sprinBlobUrl(id); if(!r) return;
    const a = document.createElement('a');
    a.href = r.url; a.download = r.name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(r.url), 5000);
  }catch(e){ toast('Gagal mengunduh Sprin'); }
}
async function hapusSprin(id){
  const k = getKegiatan(id); if(!k || !k.sprin) return;
  if(!isAdmin()){ toast('Hanya Admin yang dapat menghapus Sprin'); return; }
  if(!confirm(`Hapus file Sprin "${k.sprin.name}"?\n\nKeterangan akan kembali menjadi "Belum Di Proses" / "Sedang Di Proses".`)) return;
  await fdbDel(k.sprin.fid);
  delete k.sprin;
  k.diperbarui = nowStr(); persist();
  toast('Sprin dihapus');
  render('kegiatan'); openKegiatanDetail(id);
}

/* ============================= UPLOAD HASIL KEGIATAN ============================= */
// File hasil (format apa pun) disimpan di IndexedDB agar tidak membebani localStorage;
// metadatanya (nama, ukuran, waktu) disimpan di data kegiatan (k.hasil_files).
const FILE_DB_NAME = 'basarnas_kegiatan_files';
const HASIL_MAX_BYTES = 25*1024*1024;
/* Penyimpanan file di server (folder data/uploads) lewat api.php */
async function fdbPut(rec){
  const fd = new FormData();
  fd.append('fid', rec.fid); fd.append('kid', rec.kid || ''); fd.append('file', rec.blob, rec.name);
  const r = await fetch(API_URL + '?action=file_put', {method:'POST', headers:{'X-Requested-With':'basarnas'}, body:fd});
  const j = await r.json().catch(()=>({}));
  if(!r.ok || !j.ok) throw new Error(j.error || 'Upload gagal');
  return true;
}
async function fdbGet(fid){
  const r = await fetch(API_URL + '?action=file_get&fid=' + encodeURIComponent(fid));
  if(!r.ok) return null;
  const blob = await r.blob();
  let name = fid; const hn = r.headers.get('X-File-Name');
  if(hn){ try{ name = decodeURIComponent(hn); }catch(e){ name = hn; } }
  return {fid, name, blob};
}
function fdbDel(fid){
  return fetch(API_URL + '?action=file_del&fid=' + encodeURIComponent(fid), {method:'POST', headers:{'X-Requested-With':'basarnas'}}).catch(()=>{});
}

function fmtUkuran(b){ return b>=1048576 ? (b/1048576).toFixed(1)+' MB' : Math.max(1,Math.round(b/1024))+' KB'; }

/* ---- Bukti penugasan (JPG/PNG/PDF) saat status "Dalam Pelaksanaan": bisa diupload anggota ---- */
const BUKTI_MAX_BYTES = 10*1024*1024;
function buktiTipeOk(f){ return /^image\/(jpeg|png)$/.test(f.type) || f.type==='application/pdf' || /\.(jpe?g|png|pdf)$/i.test(f.name); }
function buktiKegiatanHtml(k){
  if(k.status_persetujuan!=='Disetujui') return '';
  const files = k.bukti_files||[];
  const aktif = k.status_pelaksanaan==='Dalam Pelaksanaan' || (k.status_pelaksanaan==='Draft' && !!k.sprin);
  if(!aktif && !files.length) return '';
  return `
    <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
      <div class="flex items-center justify-between gap-2 mb-1">
        <p class="text-sm font-medium">Bukti Penugasan</p>
        ${files.some(buktiIsFoto) ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" id="btnKolase" onclick="unduhKolaseBukti('${k.id}')">Unduh</button>` : ''}
      </div>
      <p class="text-[11px] mb-2" style="color:var(--muted);">${aktif ? (k.status_pelaksanaan==='Draft' ? 'Sprin sudah tersedia. Upload foto atau dokumen sebagai bukti bahwa anggota sedang bertugas; setelah terupload, status pelaksanaan otomatis menjadi <strong>Dalam Pelaksanaan</strong>. ' : 'Tambahkan bukti lain bila perlu. ') + 'Format JPG, PNG atau PDF, maks. 10MB per file.' : 'Bukti yang diupload selama kegiatan berlangsung.'}</p>
      ${files.length ? `<div class="space-y-1.5 mb-3">${files.map(f=>`
        <div class="flex items-center gap-2 rounded-lg p-2" style="background:var(--panel, #fff);">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium truncate">${esc(f.name)}</p>
            <p class="text-[11px]" style="color:var(--muted);">${fmtUkuran(f.size)} · ${esc(f.uploaded)}${f.oleh ? ' · oleh '+esc(f.oleh) : ''}</p>
          </div>
          <button class="btn btn-navy !py-1 !px-2 text-xs" onclick="lihatBukti('${k.id}','${f.fid}')">Lihat</button>
          <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="unduhHasil('${k.id}','${f.fid}')">Unduh</button>
          ${isApprover() ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="hapusBukti('${k.id}','${f.fid}')">Hapus</button>` : ''}
        </div>`).join('')}</div>` : `<p class="text-xs mb-3" style="color:var(--muted);">Belum ada bukti yang diupload.</p>`}
      ${aktif ? `<input type="file" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" onchange="uploadBukti('${k.id}', this)">` : ''}
    </div>`;
}
/* ---- Unduh kolase semua foto bukti penugasan (JPG/PNG) menjadi 1 gambar ---- */
function buktiIsFoto(f){ return /^image\/(jpeg|png)$/.test(f.type||'') || /\.(jpe?g|png)$/i.test(f.name||''); }
async function bukaGambarBlob(blob){
  if(window.createImageBitmap){
    try{ return await createImageBitmap(blob, {imageOrientation:'from-image'}); }catch(e){}
  }
  return await new Promise((res, rej)=>{
    const u = URL.createObjectURL(blob), im = new Image();
    im.onload = ()=>{ URL.revokeObjectURL(u); res(im); };
    im.onerror = ()=>{ URL.revokeObjectURL(u); rej(new Error('gambar rusak')); };
    im.src = u;
  });
}
/* Template kolase gaya poster BASARNAS. Judul & teks tanggal diatur Admin Utama (menu Pengaturan);
   gambar header & footer diambil dari folder assets/ (kolase_header.png, kolase_footer.png). */
const KOLASE_CFG_FID = 'KOLASEPOSTERCFG';
const KOLASE_DEFAULT = {judul:'{nama}', tanggal:'{tanggal}', huruf_besar:true};
async function kolaseTplLoad(){
  let cfg = Object.assign({}, KOLASE_DEFAULT);
  try{
    const rec = await fdbGet(KOLASE_CFG_FID);
    if(rec){ const j = JSON.parse(await rec.blob.text()); if(j && typeof j==='object') cfg = Object.assign(cfg, j); }
  }catch(e){}
  return cfg;
}
/* "Selasa, 1 September 2026" (1 hari) atau "1 – 3 September 2026" (beberapa hari) */
function kolaseTglPanjang(k){
  const a = String(k.tanggal||'').slice(0,10), b = String(k.tanggal_selesai||'').slice(0,10);
  const ma = /^(\d{4})-(\d{2})-(\d{2})$/.exec(a); if(!ma) return '';
  const mb = /^(\d{4})-(\d{2})-(\d{2})$/.exec(b);
  if(mb && b > a){
    if(ma[1]===mb[1] && ma[2]===mb[2]) return (+ma[3])+' – '+(+mb[3])+' '+BULAN_NAMA[+ma[2]-1]+' '+ma[1];
    return (+ma[3])+' '+BULAN_NAMA[+ma[2]-1]+' '+ma[1]+' – '+(+mb[3])+' '+BULAN_NAMA[+mb[2]-1]+' '+mb[1];
  }
  return waTglPanjang(a);
}
function kolaseMuatAset(nama){
  return new Promise(res=>{
    const im = new Image();
    im.onload = ()=>res(im); im.onerror = ()=>res(null);
    im.src = new URL('assets/'+nama, location.href).href;
  });
}
/* Rancangan mosaik yang mengikuti rasio foto yang diupload anggota (portrait/landscape):
   foto dikelompokkan per baris (maks. 4 foto/baris) dengan tinggi baris yang sama dan lebar tiap sel
   sebanding rasio fotonya, sehingga hampir tidak ada bagian foto yang terpotong. Jumlah baris dipilih
   otomatis agar tinggi kolase mendekati tinggi poster (makin banyak foto, kolase makin tinggi). */
function kolaseRancang(imgs, W, G, AREA){
  const n = imgs.length;
  const asp = imgs.map(o => Math.min(3, Math.max(0.4, (o.w||1)/(o.h||1))));
  const HMIN = 200, HMAX = 1100, MAXROW = 4;
  const HDES = n<=8 ? AREA : Math.round(AREA*n/8);
  const hBaris = (i,j)=>{ let s=0; for(let k=i;k<j;k++) s+=asp[k]; return (W-(j-i-1)*G)/s; };
  const jepit = h => Math.min(HMAX, Math.max(HMIN, h));
  const biaya = (h,T)=> (h-T)*(h-T) + 4*(h-jepit(h))*(h-jepit(h));
  let terbaik = null;
  for(let r=1; r<=n; r++){
    if(r*MAXROW < n) continue;
    const T = (HDES-(r-1)*G)/r;
    const g = Array.from({length:r+1}, ()=>new Array(n+1).fill(Infinity));
    const p = Array.from({length:r+1}, ()=>new Array(n+1).fill(-1));
    g[0][0] = 0;
    for(let k=1;k<=r;k++) for(let j=k;j<=n;j++) for(let i=Math.max(k-1,j-MAXROW); i<j; i++){
      if(g[k-1][i]===Infinity) continue;
      const c = g[k-1][i] + biaya(hBaris(i,j), T);
      if(c<g[k][j]){ g[k][j] = c; p[k][j] = i; }
    }
    if(g[r][n]===Infinity) continue;
    const batas = []; let j = n;
    for(let k=r;k>=1;k--){ batas.unshift([p[k][j], j]); j = p[k][j]; }
    const tot = batas.reduce((s,[a,b])=>s+jepit(hBaris(a,b)),0) + (r-1)*G;
    const skor = g[r][n] + (tot-HDES)*(tot-HDES);
    if(!terbaik || skor<terbaik.skor) terbaik = {skor, batas};
  }
  const rows = [];
  for(const [a,b] of terbaik.batas){
    const m = b-a, avail = W-(m-1)*G;
    let s = 0; for(let k=a;k<b;k++) s += asp[k];
    const h = Math.round(jepit(hBaris(a,b)));
    const cells = []; let x = 0;
    for(let k=a;k<b;k++){
      const w = k===b-1 ? W-x : Math.floor(avail*asp[k]/s);
      cells.push({i:k, x, w}); x += w+G;
    }
    rows.push({h, cells});
  }
  return {rows, areaH: rows.reduce((s,r)=>s+r.h,0) + (rows.length-1)*G};
}
function kolaseGambarCover(c, img, x, y, w, h){
  const iw = img.width||img.naturalWidth, ih = img.height||img.naturalHeight;
  const sc = Math.max(w/iw, h/ih), sw = w/sc, sh = h/sc;
  c.fillStyle = '#fff'; c.fillRect(x, y, w, h); // latar putih untuk PNG transparan
  c.drawImage(img, (iw-sw)/2, (ih-sh)*0.35, sw, sh, x, y, w, h); // sedikit condong ke atas agar wajah tidak terpotong
}
function kolaseBungkus(c, teks, maxW){
  const kata = String(teks||'').split(/\s+/).filter(Boolean), baris = []; let cur = '';
  for(const w of kata){
    const t = cur ? cur+' '+w : w;
    if(c.measureText(t).width > maxW && cur){ baris.push(cur); cur = w; } else cur = t;
  }
  if(cur) baris.push(cur);
  return baris;
}
async function unduhKolaseBukti(id){
  const k = getKegiatan(id); if(!k) return;
  const fotos = (k.bukti_files||[]).filter(buktiIsFoto);
  if(!fotos.length){ toast('Belum ada foto bukti untuk dijadikan kolase'); return; }
  const btn = document.getElementById('btnKolase');
  if(btn){ btn.disabled = true; btn.textContent = 'Memproses…'; }
  try{
    const [cfg, imgHead, imgFoot] = await Promise.all([kolaseTplLoad(), kolaseMuatAset('kolase_header.png'), kolaseMuatAset('kolase_footer.png')]);
    const imgs = [];
    for(const f of fotos){
      try{
        const rec = await fdbGet(f.fid);
        if(!rec) continue;
        const im = await bukaGambarBlob(rec.blob);
        imgs.push({img:im, w:im.width||im.naturalWidth, h:im.height||im.naturalHeight, f});
      }catch(e){ console.error(e); }
    }
    if(!imgs.length){ toast('Foto bukti tidak dapat dibaca dari server'); return; }

    const n = imgs.length;
    const W = 1080, G = 10, HEAD_H = 70, FOOT_H = 133, AREA = 954;
    const FONT = 'Arial, "Helvetica Neue", Helvetica, sans-serif';
    const lay = kolaseRancang(imgs, W, G, AREA);

    // teks judul & tanggal (dihitung dulu agar tinggi panel judul pas)
    const isi = t => String(t||'').replace(/\{nama\}/g, k.nama||'').replace(/\{id\}/g, k.id||'')
      .replace(/\{tanggal\}/g, kolaseTglPanjang(k)||'').replace(/\{lokasi\}/g, k.lokasi||'-').replace(/\{jumlah\}/g, String(n));
    let judul = isi(cfg.judul).trim() || (k.nama||'');
    if(cfg.huruf_besar!==false) judul = judul.toUpperCase();
    const tgl = isi(cfg.tanggal).trim();
    const meas = document.createElement('canvas').getContext('2d');
    meas.font = 'bold 28px ' + FONT;
    const barisJudul = kolaseBungkus(meas, judul, W-84);
    const LH = 34, panelH = 28 + barisJudul.length*LH + (tgl ? 14+34 : 0) + 26;

    const H = HEAD_H + lay.areaH + panelH + FOOT_H;
    const S = (W*2)*(H*2) <= 16e6 ? 2 : 1; // 2x bila ukuran kanvas masih aman
    const cv = document.createElement('canvas'); cv.width = W*S; cv.height = H*S;
    const c = cv.getContext('2d'); c.scale(S,S);
    c.imageSmoothingQuality = 'high';
    const grad = (y0, y1, a, b) => { const g = c.createLinearGradient(0,0,W,0); g.addColorStop(0,a); g.addColorStop(1,b); return g; };

    // header
    if(imgHead) c.drawImage(imgHead, 0, 0, W, HEAD_H);
    else{
      c.fillStyle = grad(0,0,'#ff3131','#ff904d'); c.fillRect(0,0,W,HEAD_H);
      c.fillStyle = '#fff'; c.font = 'bold 22px '+FONT; c.textBaseline = 'middle'; c.textAlign = 'left';
      c.fillText('KANTOR PENCARIAN DAN PERTOLONGAN JAKARTA', 24, HEAD_H/2);
    }

    // mosaik foto (celah antar foto memakai warna gradasi merah–oranye)
    c.fillStyle = grad(0,0,'#ff3131','#ff904d'); c.fillRect(0, HEAD_H, W, lay.areaH);
    let y = HEAD_H;
    for(const row of lay.rows){
      for(const cell of row.cells){
        c.save(); c.beginPath(); c.rect(cell.x, y, cell.w, row.h); c.clip();
        kolaseGambarCover(c, imgs[cell.i].img, cell.x, y, cell.w, row.h);
        c.restore();
      }
      y += row.h + G;
    }
    imgs.forEach(o=>{ if(o.img.close) try{ o.img.close(); }catch(e){} });

    // panel judul
    const py = HEAD_H + lay.areaH;
    c.fillStyle = grad(0,0,'#ffdb58','#ff964e'); c.fillRect(0, py, W, panelH);
    c.fillStyle = '#000'; c.fillRect(0, py, W, 3); c.fillRect(0, py+panelH-3, W, 3);
    c.fillStyle = '#000'; c.textAlign = 'center'; c.textBaseline = 'middle';
    c.font = 'bold 28px '+FONT;
    let ty = py + 28 + LH/2;
    for(const b of barisJudul){ c.fillText(b, W/2, ty); ty += LH; }
    if(tgl){
      c.font = 'italic 28px '+FONT;
      c.fillText(tgl, W/2, ty + 14);
    }

    // footer
    if(imgFoot) c.drawImage(imgFoot, 0, H-FOOT_H, W, FOOT_H);
    else{
      c.fillStyle = grad(0,0,'#ffdb58','#ff964e'); c.fillRect(0, H-FOOT_H, W, FOOT_H);
      c.fillStyle = '#000'; c.font = 'bold 22px '+FONT;
      c.fillText('021-55727115 · 08111350115 · kantorsar_jakarta', W/2, H-FOOT_H/2);
    }

    const blob = await new Promise(r=>cv.toBlob(r, 'image/jpeg', 0.92));
    if(!blob){ toast('Gagal membuat kolase'); return; }
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const slug = String(k.nama||k.id).replace(/[^\w\-]+/g,'_').slice(0,40);
    a.href = url; a.download = `Kolase_Bukti_Penugasan_${slug}.jpg`;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 10000);
    toast(`Kolase ${n} foto diunduh`);
  }catch(e){
    console.error(e); toast('Gagal membuat kolase');
  }finally{
    if(btn){ btn.disabled = false; btn.textContent = 'Unduh'; }
  }
}
async function uploadBukti(id, input){
  const k = getKegiatan(id); if(!k) return;
  if(!(k.status_persetujuan==='Disetujui' && (k.status_pelaksanaan==='Dalam Pelaksanaan' || (k.status_pelaksanaan==='Draft' && k.sprin)))){ toast('Bukti dapat diupload setelah Sprin tersedia'); return; }
  const files = Array.from(input.files||[]); if(!files.length) return;
  let ok = 0;
  for(const f of files){
    if(!buktiTipeOk(f)){ toast(`"${f.name}" harus berformat JPG, PNG atau PDF`); continue; }
    if(f.size > BUKTI_MAX_BYTES){ toast(`"${f.name}" melebihi 10MB`); continue; }
    const fid = 'B'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
    try{
      await fdbPut({fid, kid:id, name:f.name, blob:f});
      (k.bukti_files = k.bukti_files||[]).push({fid, name:f.name, size:f.size, type:f.type||'', uploaded:nowStr(), oleh:currentRole});
      ok++;
    }catch(e){ toast(`Gagal menyimpan "${f.name}"`); }
  }
  if(ok){
    const mulai = k.status_pelaksanaan==='Draft';
    if(mulai){ k.status_pelaksanaan = 'Dalam Pelaksanaan'; touchKegiatan(k); } else { k.diperbarui = nowStr(); persist(); }
    toast(mulai ? 'Bukti terupload, status pelaksanaan menjadi Dalam Pelaksanaan' : ok+' bukti penugasan diupload');
    if(mulai) render('kegiatan');
    notifBuktiWa(id);
  }
  openKegiatanDetail(id);
}
// Beri tahu semua anggota bertugas lewat WhatsApp bahwa bukti sudah terinput (dikirim server setelah data tersimpan)
async function notifSelesaiWa(id){
  try{
    await flushPersist();
    const r = await fetch(API_URL + '?action=wa_notif_selesai', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body:JSON.stringify({id})});
    const j = await r.json().catch(()=>({ok:false}));
    if(!r.ok || !j.ok){ toast('Notifikasi WA selesai gagal: ' + (j.error || ('HTTP '+r.status))); return; }
    if(j.dilewati) return;
    toast('Notifikasi selesai terkirim ke ' + j.terkirim + ' dari ' + j.total + ' anggota bertugas');
  }catch(e){ console.error(e); }
}
async function notifBuktiWa(id){
  try{
    await flushPersist();
    const r = await fetch(API_URL + '?action=wa_notif_bukti', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'basarnas'}, body:JSON.stringify({id})});
    const j = await r.json().catch(()=>({ok:false}));
    if(!r.ok || !j.ok){ toast('Notifikasi WA ke anggota gagal: ' + (j.error || ('HTTP '+r.status))); return; }
    if(j.dilewati) return;
    toast('Notifikasi WA terkirim ke ' + j.terkirim + ' dari ' + j.total + ' anggota bertugas');
  }catch(e){ console.error(e); }
}
async function lihatBukti(id, fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    if(!/^(application\/pdf|image\/(png|jpeg))$/.test(rec.blob.type)){ return unduhHasil(id, fid); } // selain PDF/JPG/PNG: unduh saja
    const url = URL.createObjectURL(rec.blob);
    const a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 60000);
  }catch(e){ toast('Gagal membuka file'); }
}
async function hapusBukti(id, fid){
  const k = getKegiatan(id); if(!k) return;
  const f = (k.bukti_files||[]).find(x=>x.fid===fid);
  if(!f || !confirm(`Hapus bukti "${f.name}"?`)) return;
  await fdbDel(fid);
  k.bukti_files = k.bukti_files.filter(x=>x.fid!==fid);
  const balik = k.status_pelaksanaan==='Dalam Pelaksanaan' && !k.bukti_files.length;
  if(balik){ k.status_pelaksanaan = 'Draft'; touchKegiatan(k); } else { k.diperbarui = nowStr(); persist(); }
  toast(balik ? 'Bukti dihapus, status kembali Draft' : 'Bukti dihapus');
  if(balik) render('kegiatan');
  openKegiatanDetail(id);
}

/* ---- Bukti pengembalian barang (foto JPG/PNG) di Detail Peminjaman ----
   Disimpan sebagai rec.lampiran_files dengan penanda bukti_kembali:true (sama seperti bukti pengembalian dari WhatsApp),
   sehingga tampil di daftar lampiran (Lihat / Unduh / Hapus) dan ikut dilaporkan di notifikasi pengembalian. */
const PMJ_FOTO_MAX_BYTES = 10*1024*1024;
function pmjBuktiKembaliHtml(row){
  if(pmjSP(row)!=='Disetujui' || modulReadOnly('peminjaman')) return '';
  const n = lampiranWaList(row).filter(f=>f.bukti_kembali).length;
  return `<div class="sm:col-span-2 rounded-lg p-3" style="background:var(--bg);">
    <div class="flex items-center justify-between gap-2 mb-1">
      <p class="text-sm font-medium">Bukti Pengembalian (Foto)</p>
      <span class="text-[11px]" style="color:var(--muted);">${n ? n + ' foto terupload' : 'Belum ada foto'}</span>
    </div>
    <p class="text-[11px] mb-2" style="color:var(--muted);">Foto kondisi barang saat dikembalikan. Format JPG atau PNG, maks. 10MB per foto (foto otomatis diperkecil). Setelah foto terupload, status Dipinjam otomatis menjadi <b>Dikembalikan</b> dan stok Peralatan kembali. Foto tampil di daftar lampiran di bawah.</p>
    <div class="flex flex-wrap items-center gap-2">
      <input type="file" id="pmjFotoKembali" multiple accept="image/jpeg,image/png,.jpg,.jpeg,.png">
      <button type="button" class="btn btn-navy !py-1 !px-3 text-xs" id="pmjFotoBtn" onclick="uploadBuktiKembali('${esc(row.id)}')">Upload Foto</button>
    </div>
  </div>`;
}
async function pmjKompresFoto(file){
  const im = await bukaGambarBlob(file);
  const iw = im.width||im.naturalWidth, ih = im.height||im.naturalHeight, sc = Math.min(1, 1600/Math.max(iw, ih));
  const cv = document.createElement('canvas'); cv.width = Math.round(iw*sc); cv.height = Math.round(ih*sc);
  const c = cv.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0,0,cv.width,cv.height); c.drawImage(im, 0, 0, cv.width, cv.height);
  if(im.close) try{ im.close(); }catch(e){}
  const blob = await new Promise(r=>cv.toBlob(r, 'image/jpeg', 0.82));
  return (blob && blob.size < file.size) ? blob : file;
}
async function uploadBuktiKembali(id){
  if(guardRO('peminjaman')) return;
  const rec = (DB.peminjaman||[]).find(r=>r.id===id); if(!rec) return;
  const input = document.getElementById('pmjFotoKembali'), btn = document.getElementById('pmjFotoBtn');
  const files = Array.from((input && input.files) || []);
  if(!files.length){ toast('Pilih foto bukti pengembalian terlebih dahulu'); return; }
  if(btn){ btn.disabled = true; btn.textContent = 'Mengupload…'; }
  let ok = 0;
  try{
    for(const f of files){
      if(!(/^image\/(jpeg|png)$/.test(f.type) || /\.(jpe?g|png)$/i.test(f.name))){ toast(`"${f.name}" harus berupa foto JPG atau PNG`); continue; }
      if(f.size > PMJ_FOTO_MAX_BYTES){ toast(`"${f.name}" melebihi 10MB`); continue; }
      let blob = f;
      try{ blob = await pmjKompresFoto(f); }catch(e){ blob = f; }
      const jpg = blob!==f && blob.type==='image/jpeg';
      const nama = 'Bukti Pengembalian ' + id + ' - ' + (f.name || 'foto').replace(/\.[^.]+$/, '') + (jpg ? '.jpg' : (/\.png$/i.test(f.name) ? '.png' : '.jpg'));
      const fid = 'PK' + Date.now().toString(36) + Math.random().toString(36).slice(2,6);
      try{
        await fdbPut({fid, kid:id, name:nama, blob});
        (rec.lampiran_files = rec.lampiran_files || []).push({fid, name:nama, size:blob.size, type:blob.type||f.type||'', uploaded:nowStr(), oleh:currentRole, bukti_kembali:true});
        ok++;
      }catch(e){ toast(`Gagal menyimpan "${f.name}": ${e.message||''}`); }
    }
  } finally {
    if(btn){ btn.disabled = false; btn.textContent = 'Upload Foto'; }
  }
  if(ok){
    rec.diperbarui_oleh = pelaku(); rec.diperbarui_waktu = nowStr();
    // Bukti sudah ada => barang dianggap dikembalikan: status jadi Dikembalikan + tanggal kembali terisi.
    // Stok Peralatan (Terpakai/Tersedia) dan status Kendaraan dihitung langsung dari kedua field ini, jadi otomatis pulih.
    const baruKembali = rec.status!=='Dikembalikan' || !rec.tanggal_kembali;
    if(baruKembali){ rec.status = 'Dikembalikan'; if(!rec.tanggal_kembali) rec.tanggal_kembali = todayISO(); }
    persist();
    toast(baruKembali ? ok + ' foto diupload — status menjadi Dikembalikan dan stok dikembalikan' : ok + ' foto bukti pengembalian diupload');
    if(baruKembali) render(currentView);
    openGenericDetail('peminjaman', id);
  }
}

/* ---- Lampiran dari WhatsApp (rec.lampiran_files): dikirim lewat tautan upload / chat WA, disimpan di data/uploads ---- */
function lampiranWaList(rec){ return (rec && Array.isArray(rec.lampiran_files)) ? rec.lampiran_files : []; }
function lampiranWaHtml(key, rec){
  const fl = lampiranWaList(rec);
  if(!fl.length) return '';
  return `
    <div class="mt-4 rounded-lg p-3" style="background:var(--bg);">
      <p class="text-sm font-medium mb-2">${fl.some(f=>f.bukti_kembali) && key==='peminjaman' ? 'Lampiran & Bukti Pengembalian' : 'Lampiran (WhatsApp)'}</p>
      ${fl.map(f=>`
        <div class="flex items-center gap-2 py-1.5">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium truncate" title="${esc(f.name)}">${f.bukti_kembali ? '<span class="text-[10px] font-semibold px-1.5 py-0.5 rounded mr-1" style="background:var(--navy-500,#0b2a4a);color:#fff;">Bukti Pengembalian</span>' : ''}${f.link ? '🔗 ' : ''}${esc(f.name)}</p>
            <p class="text-[11px]" style="color:var(--muted);">${f.link ? 'Link · ' : ''}${f.size ? Math.max(1,Math.round(f.size/1024))+' KB · ' : ''}${esc(f.uploaded||'')}${f.oleh ? ' · '+esc(f.oleh) : ''}</p>
          </div>
          ${f.link ? `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="bukaLinkLampiran('${esc(key)}','${esc(rec.id)}','${esc(f.fid)}')">Buka Link</button>` : `
          <button class="btn btn-navy !py-1 !px-2 text-xs" onclick="lihatLampiran('${esc(f.fid)}')">Lihat</button>
          <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="unduhLampiran('${esc(f.fid)}')">Unduh</button>`}
          ${isApprover() ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="hapusLampiran('${esc(key)}','${esc(rec.id)}','${esc(f.fid)}')">Hapus</button>` : ''}
        </div>`).join('')}
    </div>`;
}
function bukaLinkLampiran(key, id, fid){
  const rec = (DB[key]||[]).find(r=>r.id===id);
  const f = rec ? lampiranWaList(rec).find(y=>y.fid===fid) : null;
  const url = f && String(f.link||'');
  if(!url || !/^https?:\/\//i.test(url)){ toast('Link tidak valid'); return; }
  window.open(url, '_blank', 'noopener,noreferrer');
}
async function lihatLampiran(fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    if(!/^(application\/pdf|image\/(png|jpeg))$/.test(rec.blob.type)) return unduhLampiran(fid);
    const url = URL.createObjectURL(rec.blob);
    const a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 60000);
  }catch(e){ toast('Gagal membuka file'); }
}
async function unduhLampiran(fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    const url = URL.createObjectURL(rec.blob);
    const a = document.createElement('a');
    a.href = url; a.download = rec.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 5000);
  }catch(e){ toast('Gagal mengunduh file'); }
}
async function hapusLampiran(key, id, fid){
  if(!isApprover()) return;
  const rec = (DB[key]||[]).find(r=>r.id===id); if(!rec) return;
  const f = lampiranWaList(rec).find(x=>x.fid===fid);
  if(!f || !confirm(`Hapus lampiran "${f.name}"?`)) return;
  if(!f.link) await fdbDel(fid);
  rec.lampiran_files = rec.lampiran_files.filter(x=>x.fid!==fid);
  if(rec.diperbarui) rec.diperbarui = nowStr();
  persist();
  toast('Lampiran dihapus');
  if(key==='kegiatan') openKegiatanDetail(id);
  else if(key==='surat_masuk') openSuratDetail(id);
  else if(key==='surat_keluar') { closeModal(); renderPersuratan(); }
  else openGenericDetail(key, id);
}

/* ---- Laporan penyelesaian (PDF): saat diupload, status pelaksanaan otomatis menjadi Selesai ---- */
function laporanSelesaiHtml(k){
  if(k.status_persetujuan!=='Disetujui' || k.status_pelaksanaan!=='Dalam Pelaksanaan') return '';
  return `
    <div class="rounded-lg p-3 mb-4" style="background:var(--bg); border:1px dashed var(--orange-500);">
      <p class="text-sm font-medium mb-1">Hasil Penugasan</p>
      <p class="text-[11px] mb-2" style="color:var(--muted);">Upload file PDF hasil penugasan (maks. 25MB) <strong>atau</strong> masukkan link hasil penugasan (mis. Google Drive). Setelah file/link tersimpan, status pelaksanaan otomatis berubah menjadi <strong>Selesai</strong> dan kegiatan pindah ke Tugas Selesai.</p>
      <p class="text-xs font-medium mb-1">Upload file PDF</p>
      <input type="file" accept="application/pdf,.pdf" onchange="uploadLaporanSelesai('${k.id}', this)">
      <p class="text-xs font-medium mt-3 mb-1">Atau input link</p>
      <div class="flex flex-wrap gap-2">
        <input type="url" id="hasilLink_${k.id}" placeholder="https://..." class="flex-1 min-w-[200px]" onkeydown="if(event.key==='Enter'){event.preventDefault();simpanLinkLaporan('${k.id}');}">
        <button class="btn btn-navy !py-1 !px-3 text-xs" onclick="simpanLinkLaporan('${k.id}')">Simpan Link</button>
      </div>
    </div>`;
}
/* Link hasil: hanya http/https. Mengembalikan URL bersih atau null. */
function normalisasiLink(v){
  v = String(v||'').trim(); if(!v) return null;
  if(!/^[a-z][a-z0-9+.-]*:/i.test(v)) v = 'https://' + v;
  try{ const u = new URL(v); return /^https?:$/.test(u.protocol) ? u.href : null; }catch(e){ return null; }
}
function bukaLinkHasil(id, fid){
  const k = getKegiatan(id); const f = k && [].concat(k.hasil_files||[]).find(x=>x.fid===fid);
  const u = f && normalisasiLink(f.link);
  if(!u){ toast('Link tidak valid'); return; }
  window.open(u, '_blank', 'noopener,noreferrer');
}
function simpanLinkLaporan(id){
  const k = getKegiatan(id); if(!k) return;
  if(k.status_persetujuan!=='Disetujui' || k.status_pelaksanaan!=='Dalam Pelaksanaan'){ toast('Kegiatan tidak sedang Dalam Pelaksanaan'); return; }
  const inp = document.getElementById('hasilLink_'+id);
  const u = normalisasiLink(inp && inp.value);
  if(!u){ toast('Masukkan link yang valid (diawali http:// atau https://)'); return; }
  const fid = 'U'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
  (k.hasil_files = k.hasil_files||[]).push({fid, name:u, link:u, size:0, type:'link', uploaded:nowStr(), laporan:true, oleh:currentRole});
  k.status_pelaksanaan = 'Selesai';
  touchKegiatan(k);
  toast('Link hasil tersimpan, status pelaksanaan menjadi Selesai');
  notifSelesaiWa(id);
  kegTab = 'selesai';
  render('kegiatan'); openKegiatanDetail(id);
}
async function uploadLaporanSelesai(id, input){
  const k = getKegiatan(id); if(!k) return;
  if(k.status_persetujuan!=='Disetujui' || k.status_pelaksanaan!=='Dalam Pelaksanaan'){ toast('Kegiatan tidak sedang Dalam Pelaksanaan'); return; }
  const f = (input.files||[])[0]; if(!f) return;
  if(!(f.type==='application/pdf' || /\.pdf$/i.test(f.name))){ toast('File harus berformat PDF'); input.value=''; return; }
  if(f.size > HASIL_MAX_BYTES){ toast('File melebihi 25MB'); input.value=''; return; }
  const fid = 'L'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
  try{
    await fdbPut({fid, kid:id, name:f.name, blob:f});
  }catch(e){ toast('Gagal menyimpan file'); return; }
  (k.hasil_files = k.hasil_files||[]).push({fid, name:f.name, size:f.size, type:'application/pdf', uploaded:nowStr(), laporan:true, oleh:currentRole});
  k.status_pelaksanaan = 'Selesai';
  touchKegiatan(k);
  toast('Laporan terupload, status pelaksanaan menjadi Selesai');
  notifSelesaiWa(id);
  kegTab = 'selesai';
  render('kegiatan'); openKegiatanDetail(id);
}

/* Total Peserta (khusus SGTS): kotak tersendiri, terpisah dari Hasil Kegiatan; bisa diisi sejak Dalam Pelaksanaan. */
function totalPesertaHtml(k){
  if(k.jenis!=='sgts' || k.status_persetujuan!=='Disetujui' || !(k.anggota_ids||[]).length) return '';
  if(k.status_pelaksanaan!=='Dalam Pelaksanaan' && k.status_pelaksanaan!=='Selesai') return '';
  const ada = k.total_peserta!=null && k.total_peserta!=='';
  return `
    <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
      <label class="text-sm font-medium block mb-1" for="totalPeserta_${k.id}">Total Peserta</label>
      <div class="flex flex-wrap items-center gap-2">
        <input type="number" min="0" step="1" id="totalPeserta_${k.id}" value="${ada ? esc(String(k.total_peserta)) : ''}" placeholder="Jumlah peserta yang hadir" style="max-width:220px;" onkeydown="if(event.key==='Enter'){event.preventDefault();simpanTotalPeserta('${k.id}');}">
        <button class="btn btn-navy !py-1 !px-3 text-xs" onclick="simpanTotalPeserta('${k.id}')">Simpan</button>
        ${ada ? `<span class="text-[11px]" style="color:var(--muted);">Tersimpan: <strong>${esc(String(k.total_peserta))}</strong> peserta</span>` : ''}
      </div>
    </div>`;
}
function hasilKegiatanHtml(k){
  if(k.status_persetujuan!=='Disetujui' || !(k.anggota_ids||[]).length) return '';
  if(k.status_pelaksanaan!=='Selesai'){
    return `<p class="text-xs mb-4" style="color:var(--muted);">Upload hasil kegiatan tersedia setelah kegiatan ditandai selesai.</p>`;
  }
  const files = k.hasil_files||[];
  return `
    <div class="rounded-lg p-3 mb-4" style="background:var(--bg);">
      <p class="text-sm font-medium mb-1">Hasil Kegiatan</p>
      <p class="text-[11px] mb-2" style="color:var(--muted);">Upload dokumen hasil kegiatan (laporan, foto, video, dll; semua format, maks. 25MB per file) atau tambahkan link.</p>
      ${files.length ? `<div class="space-y-1.5 mb-3">${files.map(f=>`
        <div class="flex items-center gap-2 rounded-lg p-2" style="background:var(--panel, #fff);">
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium truncate">${f.link ? '🔗 ' : ''}${esc(f.name)}</p>
            <p class="text-[11px]" style="color:var(--muted);">${f.link ? 'Link' : fmtUkuran(f.size)} · ${esc(f.uploaded)}${f.oleh ? ' · oleh '+esc(f.oleh) : ''}</p>
          </div>
          ${f.link ? `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="bukaLinkHasil('${k.id}','${f.fid}')">Buka Link</button>` : `<button class="btn btn-navy !py-1 !px-2 text-xs" onclick="lihatBukti('${k.id}','${f.fid}')">Lihat</button>
          <button class="btn btn-ghost !py-1 !px-2 text-xs" onclick="unduhHasil('${k.id}','${f.fid}')">Unduh</button>`}
          ${isApprover() ? `<button class="btn btn-ghost !py-1 !px-2 text-xs" style="color:var(--bad);" onclick="hapusHasil('${k.id}','${f.fid}')">Hapus</button>` : ''}
        </div>`).join('')}</div>` : `<p class="text-xs mb-3" style="color:var(--muted);">Belum ada file atau link yang diinput.</p>`}
      <input type="file" multiple onchange="uploadHasilKegiatan('${k.id}', this)">
      <div class="flex flex-wrap gap-2 mt-3">
        <input type="url" id="hasilLinkTambah_${k.id}" placeholder="Atau tempel link hasil (https://...)" class="flex-1 min-w-[200px]" onkeydown="if(event.key==='Enter'){event.preventDefault();tambahLinkHasil('${k.id}');}">
        <button class="btn btn-navy !py-1 !px-3 text-xs" onclick="tambahLinkHasil('${k.id}')">Simpan Link</button>
      </div>
    </div>`;
}
async function uploadHasilKegiatan(id, input){
  const k = getKegiatan(id); if(!k) return;
  const files = Array.from(input.files||[]); if(!files.length) return;
  let ok = 0;
  for(const f of files){
    if(f.size > HASIL_MAX_BYTES){ toast(`"${f.name}" melebihi 25MB`); continue; }
    const fid = 'F'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
    try{
      await fdbPut({fid, kid:id, name:f.name, blob:f});
      (k.hasil_files = k.hasil_files||[]).push({fid, name:f.name, size:f.size, type:f.type||'', uploaded:nowStr()});
      ok++;
    }catch(e){ toast(`Gagal menyimpan "${f.name}"`); }
  }
  if(ok){ k.diperbarui = nowStr(); persist(); toast(ok+' file hasil kegiatan diupload'); }
  openKegiatanDetail(id);
}
/* Sar Go To School: total peserta aktual dicatat di kegiatan dan disalin ke data modul SGTS yang terhubung (Jumlah Peserta). */
function simpanTotalPeserta(id){
  const k = getKegiatan(id); if(!k || k.jenis!=='sgts') return;
  const inp = document.getElementById('totalPeserta_'+id);
  const raw = String(inp ? inp.value : '').trim();
  if(!/^\d+$/.test(raw)){ toast('Total Peserta harus berupa angka bulat (0 atau lebih)'); return; }
  k.total_peserta = parseInt(raw, 10);
  const rec = k.linked_record_id ? (DB.sgts||[]).find(r=>r.id===k.linked_record_id) : null;
  if(rec) rec.jumlah_peserta = k.total_peserta;
  k.diperbarui = nowStr(); persist();
  toast('Total Peserta tersimpan: ' + k.total_peserta);
  openKegiatanDetail(id);
}
function tambahLinkHasil(id){
  const k = getKegiatan(id); if(!k) return;
  const inp = document.getElementById('hasilLinkTambah_'+id);
  const u = normalisasiLink(inp && inp.value);
  if(!u){ toast('Masukkan link yang valid (diawali http:// atau https://)'); return; }
  const fid = 'U'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
  (k.hasil_files = k.hasil_files||[]).push({fid, name:u, link:u, size:0, type:'link', uploaded:nowStr(), oleh:currentRole});
  k.diperbarui = nowStr(); persist(); toast('Link hasil kegiatan tersimpan');
  openKegiatanDetail(id);
}
async function unduhHasil(id, fid){
  try{
    const rec = await fdbGet(fid);
    if(!rec){ toast('File tidak ditemukan di server'); return; }
    const url = URL.createObjectURL(rec.blob);
    const a = document.createElement('a');
    a.href = url; a.download = rec.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 5000);
  }catch(e){ toast('Gagal mengunduh file'); }
}
async function hapusHasil(id, fid){
  const k = getKegiatan(id); if(!k) return;
  const f = (k.hasil_files||[]).find(x=>x.fid===fid);
  if(!f || !confirm(`Hapus file "${f.name}"?`)) return;
  await fdbDel(fid);
  k.hasil_files = k.hasil_files.filter(x=>x.fid!==fid);
  // Laporan penyelesaian dihapus (dan tidak ada laporan lain): status kembali Dalam Pelaksanaan
  const kembali = f.laporan && k.status_pelaksanaan==='Selesai' && !k.hasil_files.some(x=>x.laporan);
  if(kembali){ k.status_pelaksanaan = 'Dalam Pelaksanaan'; touchKegiatan(k); kegTab = 'kegiatan'; }
  else { k.diperbarui = nowStr(); persist(); }
  toast(kembali ? 'File dihapus, status kembali Dalam Pelaksanaan dan kegiatan tampil lagi di Kegiatan/Tugas' : 'File dihapus');
  if(kembali) render('kegiatan');
  openKegiatanDetail(id);
}

/* ============================= BATAS 3 BARIS UNTUK SEMUA SEL TABEL =============================
   Teks sel tabel yang lebih dari 3 baris dipotong dengan (...); teks lengkap muncul sebagai tooltip.
   Sel berisi tombol/input/badge/link/tabel bersarang tidak diubah. */
let _clampBusy = false, _clampTimer = null;
function clampTableCells(root){
  if(_clampBusy) return;
  _clampBusy = true;
  try{
    (root || document).querySelectorAll('td').forEach(td=>{
      if(td.dataset.cl || td.colSpan > 1 || td.closest('.no-clamp') || td.classList.contains('whitespace-nowrap')) return;
      td.dataset.cl = '1';
      if(td.querySelector('button,input,select,textarea,a,svg,table,div,.badge,img')) return;
      const txt = td.textContent.trim();
      if(!txt) return;
      if(/^[A-Z]{2,4}-\d+$/.test(txt)) return; // kolom ID (mis. KGT-042) tidak diubah
      const wrap = document.createElement('div');
      wrap.className = 'clamp3';
      wrap.title = txt;
      while(td.firstChild) wrap.appendChild(td.firstChild);
      td.appendChild(wrap);
    });
  }finally{ _clampBusy = false; }
}
const _mqFit = window.matchMedia('(max-width:640px)');
function fitColspan(t){
  // baris kosong ("Belum ada data") harus span sebanyak kolom yang terlihat, kalau tidak tabel punya kolom hantu & menyempit
  if(!t._fit) return;
  const n=t._fit.n, k=t._fit.keep.size;
  t.querySelectorAll('tbody td[colspan], tbody td[data-cs]').forEach(c=>{
    // colspan baris kosong sering dipatok (mis. 10) padahal kolom sebenarnya lebih sedikit -> kolom hantu
    // yang menyempitkan kolom asli. Selalu pakai jumlah kolom header yang nyata (n) / yang terlihat di HP (k).
    if(!c.dataset.cs){ const v=parseInt(c.getAttribute('colspan')||'1',10); if(v<n) return; c.dataset.cs=String(v); }
    c.colSpan = _mqFit.matches ? Math.max(1, k) : n;
  });
}
function fitRows(t){
  const {keep,ak,n}=t._fit;
  const body=t.tBodies[0]; if(!body) return;
  [...body.rows].forEach(r=>{
    if(r.dataset.fr) return; r.dataset.fr='1';
    if([...r.cells].some(c=>c.colSpan>1)) return;
    [...r.cells].forEach((c,i)=>{
      if(!keep.has(i)) c.classList.add('xs-hide');
      if(i===ak) c.classList.add('c-aksi');
    });
  });
  fitColspan(t);
}
function cardifyTables(root){
  (root||document).querySelectorAll('.tablewrap > table').forEach(t=>{
    if(t._fit){ fitRows(t); return; }
    if(t.dataset.cd) return; t.dataset.cd='1';
    if(t.classList.contains('tbl-fit')||t.classList.contains('no-clamp')) return;
    if(t.closest('#diklat_peserta_wrap,#diklat_peserta_wrap_main,#diklat_peserta_wrap_daftar,.siaga-alat,#rekap_panel_diklat,.no-clamp')) return;
    if(!t.tHead || t.tHead.rows.length!==1) return;
    const body=t.tBodies[0];
    if(body && body.querySelector('input:not([type=checkbox]),select,textarea')) return;
    const ths=[...t.tHead.rows[0].cells];
    const n=ths.length;
    if(n<2) return;
    const heads=ths.map(th=>th.textContent.trim());
    // kolom yang dipertahankan di HP: 4 pertama, kolom status/persetujuan (1), dan Aksi (terakhir)
    const keep=new Set();
    for(let i=0;i<Math.min(4,n);i++) keep.add(i);
    const st=heads.findIndex((h,i)=>i>=4 && /persetujuan|status/i.test(h));
    if(st>=0) keep.add(st);
    const ak=heads.findIndex(h=>/^aksi$/i.test(h));
    if(ak>=0) keep.add(ak);
    ths.forEach((th,i)=>{
      if(!keep.has(i)) th.classList.add('xs-hide');
      if(i===ak) th.classList.add('th-aksi');
      if(!heads[i]) th.classList.add('th-empty');
    });
    t._fit={keep,ak,n};
    t.classList.add('tbl-fit','tbl-fit-auto');
    fitRows(t);
  });
}
_mqFit.addEventListener && _mqFit.addEventListener('change', ()=>{
  document.querySelectorAll('table.tbl-fit-auto').forEach(fitColspan);
});
function scheduleClamp(){
  clearTimeout(_clampTimer);
  _clampTimer = setTimeout(()=>{ cardifyTables(); clampTableCells(); }, 30);
}
new MutationObserver(()=>{ if(!_clampBusy) scheduleClamp(); })
  .observe(document.body, {childList:true, subtree:true});

/* ============================= INIT ============================= */
TABLE_MODULES.forEach(m=>search[m.key]='');
search.kegiatan=''; search.anggota=''; search.selesai='';
renderSidebar();
render(awalView());

// ===== Tombol kembali ke atas (hanya di modul Dashboard) =====
(function(){
  const btn = document.getElementById('btnToTop');
  if(!btn) return;
  function cek(){
    const tampil = (typeof currentView!=='undefined' && currentView==='dashboard') && (window.scrollY || document.documentElement.scrollTop) > 300;
    btn.classList.toggle('show', !!tampil);
  }
  btn.addEventListener('click', ()=>{
    const kurangGerak = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top:0, behavior: kurangGerak ? 'auto' : 'smooth'});
  });
  window.addEventListener('scroll', cek, {passive:true});
  window.addEventListener('resize', cek);
  // currentView berubah saat pindah modul: cek ulang secara berkala ringan
  setInterval(cek, 400);
  cek();
})();
</script>
</body>
</html>