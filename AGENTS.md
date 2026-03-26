# AGENTS.md

## Project Overview

MikReMan adalah aplikasi PHP native untuk mengelola user VPN MikroTik secara remote melalui REST API RouterOS. UI utama memakai Bootstrap dari CDN, backend memakai file PHP biasa tanpa framework, database, Composer, atau build step frontend.

Fungsi utama proyek:
- Login admin berbasis session.
- Penyimpanan konfigurasi aplikasi dan kredensial router dalam file terenkripsi.
- Manajemen service dan user PPP MikroTik.
- Dashboard monitoring resource dan status koneksi router.
- Integrasi Telegram untuk backup/notifikasi.

Target runtime yang diasumsikan kode saat ini:
- PHP 7.4+
- Ekstensi `curl` dan `openssl`
- Web server biasa seperti Apache, Nginx, atau LiteSpeed
- MikroTik RouterOS 7.5+ dengan REST API aktif

## Architecture

Request flow utamanya:
1. `index.php` menangani login dan membuat session.
2. Halaman di `pages/` merender UI admin dan melakukan pengecekan session.
3. JavaScript di `assets/js/` memanggil endpoint JSON di `api/`.
4. Endpoint `api/` memakai helper dari `includes/` untuk auth, config, dan komunikasi ke MikroTik.

Komponen inti:
- `index.php`: halaman login, rate limiting berbasis session, CSRF token login.
- `pages/admin.php`: halaman konfigurasi router, auth app, dan sistem.
- `pages/dashboard.php`: dashboard resource router dan ringkasan status.
- `pages/ppp.php`: manajemen user PPP.
- `pages/monitoring.php`: monitoring tambahan.
- `api/config.php`: baca/tulis konfigurasi terenkripsi.
- `api/mikrotik.php`: aksi utama ke MikroTik REST API.
- `api/telegram.php`: integrasi Telegram.
- `includes/config.php`: `ConfigManager`, file config terenkripsi, helper config global.
- `includes/auth.php`: login verification, session guard, helper logout/auth API.
- `includes/mikrotik.php`: wrapper REST API MikroTik.

## Important Runtime Files

Beberapa file tidak ada di repo pada awal clone karena dibuat saat runtime:
- `config/config.json.enc`: konfigurasi aplikasi terenkripsi.
- `config/encryption.key`: key untuk enkripsi config.

Implikasi:
- Jangan commit file dalam folder `config/` bila berisi secret nyata.
- Saat mengubah skema config, pertahankan backward compatibility bila memungkinkan karena file config lama akan tetap dipakai.

## Coding Notes

Karakter proyek:
- PHP procedural campur class ringan.
- Tidak ada autoloader.
- Dependensi frontend di-load dari CDN.
- Banyak halaman mengandung HTML, logika request, dan security check dalam file yang sama.

Saat mengedit:
- Pertahankan struktur sederhana yang sudah ada, jangan paksakan refactor framework-style kecuali diminta.
- Reuse helper di `includes/` daripada menduplikasi akses config atau session logic.
- Untuk endpoint baru, pastikan response tetap JSON konsisten: minimal `success` dan `message` bila relevan.
- Untuk perubahan UI, cek bahwa path relatif dari `pages/` ke `assets/` tetap benar.

## Security-Sensitive Areas

Perhatian khusus:
- `includes/config.php` menyimpan password plaintext bersama `password_hash` untuk kebutuhan retrieval admin. Ini adalah perilaku aplikasi saat ini, bukan ideal security model.
- `index.php`, `pages/admin.php`, dan `includes/auth.php` mengatur session timeout dan regenerasi session ID. Jangan memecah logika ini tanpa memastikan alur login/logout tetap konsisten.
- Endpoint di `api/` harus diproteksi dengan `requireAuth()` bila memodifikasi data atau membuka kredensial sensitif.

Catatan penting untuk perubahan berikutnya:
- Di `api/mikrotik.php`, tidak semua action saat ini memanggil `requireAuth()`. Jika menyentuh file ini, anggap auth coverage sebagai area rawan regresi dan review ulang per action.
- `includes/mikrotik.php` menonaktifkan verifikasi SSL (`CURLOPT_SSL_VERIFYPEER` dan `CURLOPT_SSL_VERIFYHOST`). Jangan ubah perilaku ini tanpa mempertimbangkan kompatibilitas deployment existing.

## MikroTik Integration

Wrapper `MikroTikAPI` memakai REST API RouterOS di path `/rest` dengan basic auth.

Asumsi implementasi sekarang:
- Default port `443`
- `use_ssl = true`
- Timeout koneksi agresif supaya UI tidak menggantung lama
- Beberapa aksi memakai endpoint `/execute` untuk menjalankan command RouterOS

Saat menambah aksi baru:
- Ikuti pola helper di `includes/mikrotik.php` bila logic reusable.
- Tangani dua bentuk response RouterOS yang kadang object tunggal dan kadang array.
- Beri error message yang cukup jelas untuk UI, tetapi hindari membocorkan secret.

## Suggested Validation After Changes

Tidak ada test suite otomatis di repo ini. Verifikasi manual adalah default:
- Buka `index.php` dan pastikan login masih berjalan.
- Simpan konfigurasi di halaman admin.
- Jalankan test connection ke MikroTik.
- Cek dashboard bila perubahan menyentuh koneksi atau parsing resource.
- Cek CRUD PPP user bila perubahan menyentuh `api/mikrotik.php` atau `includes/mikrotik.php`.
- Pastikan logout dan session timeout tidak rusak.

## Safe Change Strategy

Urutan kerja yang aman untuk agent:
1. Identifikasi apakah perubahan menyentuh UI, auth, config, atau integrasi MikroTik.
2. Cek file pasangan yang relevan, bukan hanya satu file.
3. Untuk perubahan endpoint, review juga caller JavaScript dan halaman PHP yang memakainya.
4. Untuk perubahan config, pikirkan dampaknya ke file terenkripsi lama.
5. Untuk perubahan auth/API, audit lagi apakah action sensitif sudah butuh `requireAuth()`.

## Known Constraints

- Tidak ada environment management formal seperti `.env`.
- Tidak ada migration system.
- Tidak ada package manager PHP.
- Runtime state tersimpan di filesystem lokal.
- Proyek ini cocok untuk perubahan kecil-menengah yang incremental; refactor besar harus dibagi per area.
