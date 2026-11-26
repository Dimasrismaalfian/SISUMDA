<?php
/**
 * KONFIGURASI KONEKSI DATABASE MYSQL
 * ------------------------------------------------------------------
 * Ubah nilai di bawah sesuai server MySQL/MariaDB Anda, atau atur lewat
 * environment variable (BASARNAS_DB_HOST, BASARNAS_DB_PORT, BASARNAS_DB_NAME,
 * BASARNAS_DB_USER, BASARNAS_DB_PASS) -- environment variable didahulukan.
 *
 * Database (kosong) harus sudah dibuat lebih dulu, mis. lewat phpMyAdmin:
 *   CREATE DATABASE basarnas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * Tabel dibuat otomatis oleh aplikasi saat halaman pertama dibuka.
 *
 * File ini berisi kata sandi: jangan di-commit ke repositori publik.
 */
return [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'basarnas',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
    // Zona waktu sesi MySQL (hanya memengaruhi fungsi waktu MySQL; aplikasi menyimpan waktu sebagai teks dari PHP)
    'timezone' => '+07:00',
];
