# Bulma Migration Plan for MikReMan

Dokumen ini adalah rencana refactor penuh repo MikReMan dari Bootstrap/native CSS campuran ke Bulma.

## Goal

Tujuan migrasi:
- mengganti dependency Bootstrap CSS dan Bootstrap JS
- menyederhanakan styling dengan Bulma sebagai base framework
- mempertahankan feature parity
- memperbaiki konsistensi layout, form, alert, dan modal

## Current State

Dari audit codebase saat ini:
- semua halaman utama masih memuat Bootstrap CSS CDN
- semua halaman utama masih memuat Bootstrap Icons
- beberapa halaman memuat Bootstrap JS bundle
- custom CSS saat ini banyak meng-override selector Bootstrap

File utama:
- `index.php`
- `pages/admin.php`
- `pages/dashboard.php`
- `pages/monitoring.php`
- `pages/ppp.php`
- `assets/css/style.css`
- `assets/js/admin.js`

## Risks

Refactor penuh ke Bulma berisiko pada:
- modal behavior
- alert dismiss
- sidebar collapse
- table responsiveness
- button loading states
- JavaScript yang mengubah class Bootstrap secara dinamis

## Required Refactor Areas

### 1. Framework includes

Semua halaman harus dipindah dari:
- Bootstrap CSS CDN
- Bootstrap JS bundle

ke:
- Bulma CSS
- icon library yang dipilih
- helper JS lokal untuk interaktivitas

### 2. CSS layer

`assets/css/style.css` perlu dirombak dari:
- Bootstrap override oriented

menjadi:
- theme tokens
- Bulma overrides ringan
- app-specific component styles

### 3. JavaScript layer

Semua interaksi yang sekarang bergantung pada Bootstrap harus dipindah ke helper JS lokal:
- modal open/close
- dismiss notifications
- sidebar/menu toggle
- accordion/collapse state

### 4. HTML structure

Semua markup perlu diganti ke struktur Bulma:
- grid
- forms
- cards
- buttons
- notifications
- tables

## Proposed Execution Order

### Batch 1: Foundation

- tambahkan dokumen Bulma dan migration plan
- pilih pendekatan theme
- siapkan helper JS Bulma-like untuk modal/notification/toggle
- siapkan peta class Bootstrap -> Bulma

### Batch 2: Login Page

File:
- `index.php`
- `assets/css/style.css`
- `assets/js/login.js`

Alasan:
- isolated
- paling sedikit dependency Bootstrap JS
- cocok untuk menetapkan visual language

### Batch 3: Admin Page

File:
- `pages/admin.php`
- `assets/js/admin.js`
- `assets/css/style.css`

Target:
- forms
- cards
- service controls
- configuration alerts

### Batch 4: Dashboard + Monitoring

File:
- `pages/dashboard.php`
- `pages/monitoring.php`
- `assets/css/style.css`

Target:
- cards
- stat blocks
- tables
- panel layout

### Batch 5: PPP Users

File:
- `pages/ppp.php`
- `assets/css/style.css`

Target:
- biggest conversion
- modal system
- table + details + bulk actions
- user details UI

## Definition of Done Per Page

Suatu halaman dianggap selesai dimigrasikan jika:
- tidak lagi memuat Bootstrap CSS
- tidak lagi memuat Bootstrap JS
- semua class Bootstrap utama di halaman itu hilang
- semua interaksi tetap jalan
- layout tetap usable di mobile dan desktop

## Suggested JS Utilities

Sebaiknya buat helper lokal kecil:
- `window.ui.openModal(id)`
- `window.ui.closeModal(id)`
- `window.ui.showNotification(type, message)`
- `window.ui.toggleMenu(id)`

Ini akan menggantikan ketergantungan pada `bootstrap.Modal` dan `data-bs-*`.

## Suggested Design Direction

Untuk menjaga identitas repo:
- pertahankan dark-first admin theme
- gunakan Bulma cards, menu, table, tags, notification
- gunakan spacing lebih bersih dan struktur form Bulma yang konsisten
- hindari mencoba meniru Bootstrap 1:1

## Immediate Next Step

Langkah implementasi paling aman berikutnya:
1. refactor `index.php` ke Bulma penuh
2. buat helper JS modal/notification generic
3. lanjut ke `pages/admin.php`

