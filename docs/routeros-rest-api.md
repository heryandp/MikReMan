# RouterOS REST API (ROS7)

Referensi ini merangkum dokumentasi resmi MikroTik REST API untuk kebutuhan MikReMan.

Sumber resmi:
- MikroTik RouterOS REST API: https://help.mikrotik.com/docs/spaces/ROS/pages/47579162/REST+API
- MikroTik RouterOS API: https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API

Catatan sumber:
- Halaman resmi tersebut terakhir diperbarui pada 12 Agustus 2025 menurut metadata halaman dokumentasi MikroTik.
- Halaman resmi API klasik RouterOS terakhir diperbarui pada 27 Februari 2025 menurut metadata halaman dokumentasi MikroTik.

## Ringkasan

REST API RouterOS tersedia mulai RouterOS `v7.1beta4` dan bekerja sebagai JSON wrapper untuk console API RouterOS.

Untuk mengakses REST API:
- aktifkan `www-ssl`, lalu akses `https://<router-ip>/rest`
- atau aktifkan `www` mulai RouterOS `v7.9`, lalu akses `http://<router-ip>/rest`

Untuk production, HTTPS lebih aman. Dokumentasi resmi MikroTik tidak menyarankan HTTP karena kredensial bisa disadap.

## REST API vs API Klasik

RouterOS juga memiliki API klasik yang berbeda dari REST API.

Menurut dokumentasi resmi API klasik:
- API klasik mengikuti sintaks CLI RouterOS
- service API default memakai TCP `8728`
- service API-SSL default memakai TCP `8729`
- komunikasi dilakukan dengan sentence dan word protocol, bukan HTTP JSON

Implikasi untuk repo ini:
- MikReMan saat ini memakai REST API HTTP/HTTPS di `/rest`
- port `8728` dan `8729` adalah API klasik, bukan endpoint yang dipakai MikReMan untuk koneksi utama
- port `8291` adalah Winbox, juga bukan endpoint REST

Jadi bila aplikasi ini gagal connect:
- cek port web RouterOS untuk `/rest`
- jangan asumsi port Winbox atau API klasik otomatis kompatibel
## Authentication

REST API memakai HTTP Basic Auth:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/system/resource
```

Catatan:
- `-k` dipakai jika sertifikat self-signed
- username dan password sama dengan user console RouterOS

## Format JSON

Hal penting dari dokumentasi resmi:
- hampir semua nilai di response dikirim sebagai string, termasuk angka dan boolean
- octal dan hex diterima jika dikirim sebagai angka, bukan string
- notasi exponent tidak didukung

Implikasi untuk MikReMan:
- jangan anggap `true`, `false`, angka, atau duration langsung bertipe native
- parsing `"false"` vs `false` harus ditangani eksplisit

## Base URL

Contoh umum:

```text
https://<router-ip>/rest
```

Contoh endpoint:
- `/rest/system/resource`
- `/rest/ppp/secret`
- `/rest/ip/firewall/nat`
- `/rest/interface/l2tp-server/server`

## HTTP Methods

Mapping method yang didokumentasikan MikroTik:
- `GET`: baca/list data
- `PATCH`: update satu record
- `PUT`: create satu record
- `DELETE`: hapus satu record
- `POST`: method universal untuk console command dan command-style endpoint

## GET

Ambil semua record:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/ip/address
```

Ambil satu record berdasarkan `.id`:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/ip/address/*1
```

Ambil record berdasarkan nama bila menu mendukung:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/interface/ether1
```

Filter dengan query string:

```bash
curl -k -u 'admin:password' "https://<router-ip>/rest/ip/address?network=10.155.101.0&dynamic=true"
```

Ambil property tertentu saja:

```bash
curl -k -u 'admin:password' "https://<router-ip>/rest/ip/address?.proplist=address,disabled"
```

## PATCH

Update satu record:

```bash
curl -k -u 'admin:password' \
  -X PATCH \
  https://<router-ip>/rest/ip/address/*3 \
  -H "Content-Type: application/json" \
  --data '{"comment":"test"}'
```

## PUT

Buat satu record baru:

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ip/address \
  -H "Content-Type: application/json" \
  --data '{"address":"192.168.111.111","interface":"dummy"}'
```

Catatan:
- satu request `PUT` hanya membuat satu resource

## DELETE

Hapus satu record:

```bash
curl -k -u 'admin:password' \
  -X DELETE \
  https://<router-ip>/rest/ip/address/*9
```

Jika record sudah tidak ada, RouterOS bisa mengembalikan `404`.

## POST

`POST` dipakai untuk command-style operation dan fitur console API.

Contoh ganti password:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/password \
  -H "Content-Type: application/json" \
  --data '{"old-password":"old","new-password":"new","confirm-new-password":"new"}'
```

Contoh jalankan script CLI:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/execute \
  -H "Content-Type: application/json" \
  --data '{"script":"/log/info test"}'
```

## `.proplist` dan `.query`

Untuk command `print`, dokumentasi resmi mendukung dua key khusus:
- `.proplist`
- `.query`

Contoh `.proplist`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/interface/print \
  -H "Content-Type: application/json" \
  --data '{".proplist":["name","type"]}'
```

Contoh `.query`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/interface/print \
  -H "Content-Type: application/json" \
  --data '{".query":["type=ether","type=vlan","#|!"]}'
```

Contoh gabungan:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/ip/address/print \
  -H "Content-Type: application/json" \
  --data '{".proplist":[".id","address","interface"],".query":["network=192.168.111.111","dynamic=true","#|"]}'
```

## Timeout

Dokumentasi resmi menyebut timeout REST API saat ini `60 detik`.

Perintah yang berjalan terus-menerus akan timeout. Karena itu command seperti `ping` atau monitoring harus dibatasi dengan parameter seperti `count` atau `duration`.

Contoh `ping` yang dibatasi:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/ping \
  -H "Content-Type: application/json" \
  --data '{"address":"10.155.101.1","count":"4"}'
```

Contoh bandwidth test dengan duration:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/tool/bandwidth-test \
  -H "Content-Type: application/json" \
  --data '{"address":"10.155.101.1","duration":"2s"}'
```

## Error Format

Bila gagal, HTTP status code akan `>= 400` dan body biasanya berupa JSON seperti:

```json
{"error":404,"message":"Not Found"}
```

atau:

```json
{"error":406,"message":"Not Acceptable","detail":"no such command or directory (remove)"}
```

## Contoh yang Relevan ke MikReMan

### Test koneksi

```bash
curl -k -u 'admin:password' \
  https://<router-ip>/rest/system/resource
```

### Ambil daftar PPP secret

```bash
curl -k -u 'admin:password' \
  https://<router-ip>/rest/ppp/secret
```

### Tambah PPP secret

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ppp/secret \
  -H "Content-Type: application/json" \
  --data '{"name":"vpn8136","password":"secret123","service":"l2tp","profile":"L2TP","remote-address":"10.51.0.2"}'
```

### Update PPP secret

```bash
curl -k -u 'admin:password' \
  -X PATCH \
  https://<router-ip>/rest/ppp/secret/*1 \
  -H "Content-Type: application/json" \
  --data '{"disabled":"true"}'
```

### Hapus PPP secret

```bash
curl -k -u 'admin:password' \
  -X DELETE \
  https://<router-ip>/rest/ppp/secret/*1
```

### Ambil firewall NAT

```bash
curl -k -u 'admin:password' \
  https://<router-ip>/rest/ip/firewall/nat
```

### Tambah firewall NAT

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ip/firewall/nat \
  -H "Content-Type: application/json" \
  --data '{"chain":"dstnat","action":"dst-nat","protocol":"tcp","dst-port":"18512","to-addresses":"10.51.0.2","to-ports":"8291","comment":"vpn8136"}'
```

### Toggle L2TP server via `execute`

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/execute \
  -H "Content-Type: application/json" \
  --data '{"script":"/interface l2tp-server server set enabled=yes"}'
```

## Catatan untuk Repo Ini

MikReMan saat ini:
- memakai Basic Auth
- membangun base URL sebagai `http(s)://host:port/rest`
- memakai `GET`, `PUT`, `PATCH`, `DELETE`, dan `POST`
- memakai `POST /execute` untuk beberapa command yang lebih cocok dijalankan sebagai script RouterOS

File implementasi terkait:
- `includes/mikrotik.php`
- `api/mikrotik.php`

## Rekomendasi Operasional

- gunakan RouterOS `7.5+` untuk kompatibilitas dengan MikReMan
- aktifkan `www-ssl` dan pakai HTTPS
- hindari HTTP kecuali untuk test sementara
- pastikan port web RouterOS benar-benar mengarah ke service RouterOS, bukan reverse proxy atau aplikasi lain
- perhatikan bahwa nilai response sering berupa string, termasuk boolean seperti `"false"` dan `"true"`
