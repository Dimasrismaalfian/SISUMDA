-- Membuat akun Admin Utama langsung di database (alternatif bila tidak ingin menunggu pembuatan otomatis).
-- ID: adminutama | Kata sandi: Basarnas#2026  (segera ganti lewat Kelola Akun)
INSERT INTO users (username, display_name, password_hash, created_at, role, jabatan)
VALUES ('adminutama', 'Admin Utama', '$2y$10$2leld4X2fGM.S2htoa.SD.7c6q3COifVNleisB/guD8FKuyuHq3f.', NOW(), 'admin_utama', 'Admin')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin_utama';
