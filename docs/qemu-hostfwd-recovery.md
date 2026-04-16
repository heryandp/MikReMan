# QEMU Hostfwd Recovery

Dokumen ini menjelaskan kenapa dynamic `QEMU hostfwd` bisa hilang, bagaimana cara memulihkannya, dan bagaimana MikReMan sudah diatur supaya restore berjalan otomatis.

## Ringkasnya

Dynamic `hostfwd_add/remove` di QEMU adalah state runtime. Saat:

- `ros7` di-recreate
- QEMU reboot
- host VPS restart

state itu hilang. Yang permanen hanya static `hostfwd` di startup QEMU dan aturan NAT/PPP di RouterOS.

## Socket Runtime Yang Benar

Monitor QEMU yang aktif sekarang memakai socket runtime host:

```text
/opt/mikreman/runtime/ros7-monitor/hmp.sock
```

Jangan lagi memakai socket lama:

```text
/opt/ros7-monitor/hmp.sock
```

## Gejala Umum

Kalau dynamic forward hilang, biasanya gejalanya:

- port random PPP tidak bisa diakses dari publik
- `info usernet` masih menampilkan static forward, tetapi port 16000-20000 kosong
- restore helper mengembalikan `Permission denied` atau `Connection refused`

## Restore Manual

Jalankan restore dari socket runtime yang aktif:

```bash
QEMU_HMP_SOCKET=/opt/mikreman/runtime/ros7-monitor/hmp.sock \
  /opt/mikreman/scripts/restore-qemu-hostfwd-from-app.sh
```

Kalau ingin cek hasilnya:

```bash
printf 'info usernet\n' | socat - UNIX-CONNECT:/opt/mikreman/runtime/ros7-monitor/hmp.sock | grep HOST_FORWARD
```

Kalau Anda mau replay per port secara manual:

```bash
/opt/mikreman/scripts/replay-qemu-hostfwd.sh tcp 18045
/opt/mikreman/scripts/replay-qemu-hostfwd.sh udp 18046
```

## Snapshot Otomatis

MikReMan sekarang menyimpan snapshot dynamic hostfwd ke:

```text
/opt/mikreman/runtime/ros7-monitor/hostfwd-snapshot.txt
```

Snapshot ini dipakai sebagai fallback kalau RouterOS NAT belum kebaca saat restore pertama.

## Restore Otomatis

Sudah ada systemd unit untuk auto-restore:

- `mikreman-qemu-hostfwd-watchdog.service`
- `mikreman-qemu-hostfwd-watchdog.timer`

Timer jalan:

- saat boot
- setiap 15 detik

Pastikan timer aktif:

```bash
systemctl status mikreman-qemu-hostfwd-watchdog.timer
```

Kalau perlu enable ulang:

```bash
sudo /opt/mikreman/scripts/install-qemu-hostfwd-watchdog.sh
```

Host iptables juga punya persistence terpisah:

- `mikreman-host-iptables.service`
- `mikreman-host-iptables.timer`

Log apply host-iptables disimpan di:

```text
/var/log/mikreman-host-iptables.log
```

Kalau range random publik masih mati walau QEMU hostfwd sudah ada, jalankan:

```bash
sudo /opt/mikreman/scripts/install-host-iptables-service.sh
```

## Saat Recreate CHR

Script recreate sudah menangani urutan ini:

1. capture snapshot sebelum recreate
2. boot QEMU baru
3. tunggu socket monitor muncul
4. replay snapshot
5. replay dynamic hostfwd dari MikReMan
6. simpan snapshot terbaru lagi

Script yang dipakai:

```bash
/opt/mikreman/scripts/recreate-ros7.sh
```

## Checklist Troubleshooting

Kalau forwarding masih hilang:

1. Cek socket aktif:

```bash
ls -l /opt/mikreman/runtime/ros7-monitor
```

2. Cek state QEMU:

```bash
printf 'info usernet\n' | socat - UNIX-CONNECT:/opt/mikreman/runtime/ros7-monitor/hmp.sock
```

3. Jalankan restore manual:

```bash
QEMU_HMP_SOCKET=/opt/mikreman/runtime/ros7-monitor/hmp.sock /opt/mikreman/scripts/restore-qemu-hostfwd-from-app.sh
```

4. Cek timer watchdog:

```bash
systemctl status mikreman-qemu-hostfwd-watchdog.timer
```

5. Pastikan host iptables restore juga tetap hidup, karena random port publik tetap butuh iptables host untuk survive reboot.

## Catatan Operasional

- Jangan simpan password root VPS di app.
- Jangan andalkan socket stale lama.
- Kalau QEMU di-recreate, dynamic forward harus direplay, bukan diharapkan tetap ada sendiri.
