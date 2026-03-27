# Bulma Reference for MikReMan

Dokumen ini adalah referensi kerja untuk migrasi MikReMan dari Bootstrap/native CSS campuran ke Bulma.

Sumber resmi:
- Bulma documentation index: https://bulma.io/documentation/
- Bulma overview: https://bulma.io/documentation/start/overview/
- Bulma features: https://bulma.io/documentation/features/
- Bulma themes: https://bulma.io/documentation/features/themes/
- Bulma modularity: https://bulma.io/documentation/start/modular/
- Bulma components: https://bulma.io/documentation/components/
- Bulma elements: https://bulma.io/documentation/elements/
- Bulma modal: https://bulma.io/documentation/components/modal/

Dokumen ini merangkum konsep, struktur, dan pola yang relevan untuk repo ini. Ini bukan salinan verbatim dokumentasi resmi.

## What Bulma Is

Bulma adalah CSS framework berbasis Flexbox yang mobile-first.

Poin dasar dari dokumentasi resmi:
- cukup butuh satu file CSS untuk mulai memakai Bulma
- tema modern Bulma memakai CSS variables
- Bulma punya dark mode dan theming berbasis variables
- Bulma tidak datang dengan JavaScript behavior built-in seperti Bootstrap

Implikasi untuk MikReMan:
- styling dan layout bisa dipindahkan ke Bulma
- interaksi seperti modal, navbar burger, dropdown, tabs, dan dismiss alert harus dihandle sendiri dengan JavaScript
- Bulma cocok untuk repo ini karena aplikasi server-rendered PHP dengan JS manual

## Starter Integration

Template paling sederhana untuk memakai Bulma:

```html
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
  </head>
  <body>
    <section class="section">
      <div class="container">
        <h1 class="title">Hello Bulma</h1>
      </div>
    </section>
  </body>
</html>
```

## Core Bulma Concepts

### Layout primitives

Bulma memisahkan layout ke beberapa keluarga besar:
- `container`
- `section`
- `columns` dan `column`
- `grid`
- `level`
- `media`
- `hero`
- `footer`

Untuk MikReMan, migrasi awal paling relevan:
- `container-fluid` -> `container is-fluid`
- `row` -> `columns`
- `col-md-6`, `col-lg-4`, dst -> `column is-half-tablet`, `is-one-third-desktop`, dst

### Form primitives

Bulma form dibangun dari:
- `field`
- `control`
- `label`
- `input`
- `textarea`
- `select`
- `checkbox`
- `radio`
- `file`

Tidak ada `form-floating` bawaan seperti Bootstrap. Jika MikReMan masih ingin floating label, harus dibuat dengan CSS custom.

### Elements

Elemen inti dari dokumentasi Bulma:
- `button`
- `box`
- `content`
- `icon`
- `image`
- `notification`
- `progress`
- `table`
- `tag`
- `title`

Untuk MikReMan, yang paling sering dipakai:
- button
- table
- notification
- tag
- title
- icon

### Components

Komponen utama Bulma:
- `card`
- `dropdown`
- `menu`
- `message`
- `modal`
- `navbar`
- `pagination`
- `panel`
- `tabs`
- `breadcrumb`

Yang paling relevan untuk repo ini:
- `navbar` atau `menu` untuk sidebar/nav
- `card` untuk admin/dashboard tiles
- `modal` untuk add/edit/detail user
- `tabs` bila admin page nanti dipecah jadi sections
- `message` atau `notification` untuk system alert

### Helpers

Bulma menyediakan helper untuk:
- color
- spacing
- typography
- visibility
- flexbox
- alignment

Contoh yang akan sering dipakai di repo ini:
- `is-flex`
- `is-align-items-center`
- `is-justify-content-space-between`
- `is-hidden-mobile`
- `is-hidden-desktop`
- `has-text-centered`
- `has-text-weight-semibold`
- `mt-`, `mb-`, `px-`, `py-` via Bulma spacing helpers where available in v1 ecosystem patterns or custom utility layer if needed

Catatan:
- Bulma helper tidak identik 1:1 dengan Bootstrap utility classes. Repo ini kemungkinan tetap butuh utility CSS lokal kecil.

## Themes and CSS Variables

Bulma v1 memakai CSS variables untuk theme.

Poin penting dari dokumentasi resmi:
- ada default light theme
- ada optional dark theme
- theme bisa dipasang via `:root`, `[data-theme=...]`, atau class seperti `.theme-dark`
- theme lebih mudah dikustom tanpa harus override banyak selector

Untuk MikReMan:
- ini cocok karena aplikasi sekarang sudah punya palet gelap custom
- lebih baik memetakan warna existing ke token Bulma/custom variables daripada mempertahankan override Bootstrap

Rekomendasi dasar:
- simpan token warna di satu file CSS lokal
- gunakan `data-theme="dark"` atau root variables untuk mode default
- pertahankan `assets/css/style.css` sebagai layer branding, bukan sebagai pengganti framework

## Bulma and JavaScript

Ini bagian penting untuk repo ini.

Berbeda dengan Bootstrap:
- Bulma tidak otomatis menyediakan JS untuk modal, collapse, navbar burger, dropdown, atau dismiss

Dari dokumentasi modal Bulma:
- Bulma menyediakan struktur modal
- dokumentasi memberi contoh implementasi JS yang bisa dipakai, tetapi behavior harus diwire sendiri

Implikasi langsung untuk MikReMan:
- semua penggunaan `bootstrap.Modal`
- semua `data-bs-toggle`
- semua `collapse`
- semua dismiss alert dari Bootstrap

harus diganti ke JavaScript custom.

## Mapping Bootstrap to Bulma for This Repo

Berikut mapping kerja yang paling relevan.

### Layout

- `container-fluid` -> `container is-fluid`
- `container` -> `container`
- `row` -> `columns is-multiline`
- `col-md-6` -> `column is-half-tablet`
- `col-lg-6` -> `column is-half-desktop`
- `col-lg-4` -> `column is-one-third-desktop`
- `col-lg-2` -> `column is-2-desktop`
- `col-md-9 col-lg-10` -> `column is-9-tablet is-10-desktop`

### Buttons

- `btn` -> `button`
- `btn-primary` -> `button is-link` atau `button is-primary`
- `btn-success` -> `button is-success`
- `btn-danger` -> `button is-danger`
- `btn-warning` -> `button is-warning`
- `btn-info` -> `button is-info`
- `btn-outline-*` -> tidak ada 1:1; perlu custom variant atau gunakan `is-light`, `is-outlined`
- `btn-sm` -> `button is-small`
- `btn-loading` -> `button is-loading`

### Forms

- `form-label` -> `label`
- `form-control` -> `input`, `textarea`, `select`
- `input-group` -> gabung `field has-addons`
- `form-floating` -> custom pattern, tidak ada bawaan
- `form-check` -> `checkbox`/`radio` wrapper custom
- `form-control-plaintext` -> `input is-static` atau plain text block custom

### Alerts

- `alert alert-success` -> `notification is-success`
- `alert alert-danger` -> `notification is-danger`
- `alert alert-warning` -> `notification is-warning`
- `alert alert-info` -> `notification is-info`
- `alert-dismissible` -> `notification` + button `.delete`

### Tables

- `table table-dark` -> `table is-fullwidth` + theme dark custom
- `table-striped` -> `table is-striped`
- `table-hover` -> custom CSS
- `table-responsive` -> wrapper `table-container`

### Cards and badges

- `card` -> `card`
- `card-header` -> `card-header`
- `card-body` -> `card-content`
- `badge` -> `tag`

### Modal and collapse

- `modal fade` -> `modal`
- `modal-dialog` -> `modal-card` or custom modal content wrapper
- `data-bs-dismiss` -> JS custom
- `collapse` -> JS custom + `is-hidden` / active class

## Bulma Structure Recommendation for MikReMan

Jika repo ini benar-benar dimigrasikan penuh, struktur CSS/HTML yang lebih sehat:

1. Bulma sebagai base framework via CDN atau build local.
2. `assets/css/style.css` difokuskan untuk:
   - theme tokens
   - app-specific components
   - Bulma overrides ringan
3. Hindari mencampur class Bootstrap dan Bulma dalam halaman yang sama.
4. Migrasi halaman dilakukan per-page, bukan half-page.

## Audit of Current Repo

Repo saat ini sangat bergantung pada Bootstrap di:
- `index.php`
- `pages/admin.php`
- `pages/dashboard.php`
- `pages/monitoring.php`
- `pages/ppp.php`

Bootstrap JS juga dipakai langsung di:
- modal
- collapse
- dismiss alerts

Hotspot migrasi:
- `pages/ppp.php`
- `assets/js/admin.js`
- `assets/css/style.css`

## Recommended Migration Order

### Phase 1

Refactor login page:
- paling kecil
- tidak tergantung modal Bootstrap
- cocok untuk menetapkan theme Bulma

### Phase 2

Refactor admin page:
- form-heavy
- cocok untuk standardisasi `field` / `control`
- perlu migrasi alert, cards, button states

### Phase 3

Refactor dashboard and monitoring:
- banyak cards, table, layout
- relatif lebih mudah dari PPP

### Phase 4

Refactor PPP page:
- paling kompleks
- banyak modal, table, dynamic JS, stateful UI
- butuh modal manager dan alert manager custom

## Bulma Migration Rules for This Repo

- Jangan memuat Bootstrap dan Bulma bersamaan dalam halaman final.
- Jangan mengandalkan `form-floating`; buat ulang dengan CSS lokal jika tetap diperlukan.
- Semua behavior modal/navbar/collapse harus punya JS custom yang eksplisit.
- Untuk dark UI, gunakan variables dan theme classes, bukan override acak per selector.
- Gunakan `table-container` untuk tabel lebar di mobile.
- Pakai `columns`/`column` untuk layout besar, bukan custom flex di mana Bulma sudah cukup.

## Page-Level Notes

### Login page

Target Bulma:
- `hero` atau `columns` full-height split layout
- `box` untuk login form
- `field` / `control`
- `notification` untuk error dan timeout

### Admin page

Target Bulma:
- `columns` for layout
- `menu` atau `panel` untuk sidebar
- `card` untuk blok konfigurasi
- `button is-loading` untuk state async

### PPP page

Target Bulma:
- `table-container`
- `modal-card`
- `notification`
- `tags`
- custom toolbar wrapper untuk bulk actions

## Practical Limits

Bulma tidak menyelesaikan semua hal otomatis:
- icon system masih butuh library terpisah
- modal behavior tetap harus di-JS-kan
- dark theme final tetap butuh layer styling lokal
- beberapa utility Bootstrap yang sekarang dipakai tidak punya padanan 1:1

## Suggested Deliverables for the Actual Refactor

Jika mulai implementasi nyata:
1. buat `docs/bulma-migration-plan.md`
2. refactor `index.php` lebih dulu
3. buat helper JS untuk Bulma modal dan notification
4. ganti theme CSS dari Bootstrap-oriented selector menjadi Bulma-oriented selector

