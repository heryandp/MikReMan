# Docker QEMU Same-Host Deployment

This document describes a from-scratch deployment for the following stack:
- MikReMan runs in a PHP/Apache container
- CHR runs in the `ros7` container
- CHR continues to use QEMU `user,hostfwd`
- random management ports are exposed through a combination of:
  - host iptables
  - `hostfwd_add/remove`
  - RouterOS NAT inside CHR

## Files Used By This Setup

The repository provides:
- `Dockerfile`
- `docker-compose.yml`
- `scripts/bootstrap-same-host.sh`
- `scripts/init-ros7-qcow.sh`
- `scripts/setup-mikreman-fwd-user.sh`
- `scripts/recreate-ros7.sh`
- `scripts/setup-host-iptables.sh`
- `scripts/install-host-iptables-service.sh`
- `scripts/qemu-hostfwd.sh`

Runtime files that will be created:
- `runtime/ros7/chr-7.15.3.qcow2`
- `runtime/ros7-monitor/hmp.sock`
- `runtime/ros7-monitor/qmp.sock`
- `runtime/trial-stats.sqlite`
- `runtime/trials/_index/`
- `runtime/trials/_logs/`
- `runtime/trials/YYYY-MM-DD/*.json`
- `config/`

## 0. Quick Bootstrap

If you want the shortest path:

```bash
./scripts/bootstrap-same-host.sh
```

If the script is not run as root, it will still:
- initialize the disk
- recreate `ros7`
- start `mikreman`

It will then remind you to run the iptables setup separately with `sudo`.

## 1. Initialize The CHR Disk

```bash
./scripts/init-ros7-qcow.sh
```

This script extracts the `qcow2` image from `safrinnetwork/ros7:latest` if the file does not already exist.

## 2. Recreate CHR With Monitor Sockets

If you plan to use `Remote SSH Key` mode, create the restricted SSH user first:

```bash
sudo ./scripts/setup-mikreman-fwd-user.sh --pubkey-file /path/to/mikreman_fwd_ed25519.pub
```

This script will:
- create the `mikreman-fwd` user if it does not exist
- create `/home/mikreman-fwd/.ssh`
- install the public key into `authorized_keys`
- fix permissions
- verify `socat`
- print the user GID for monitor socket access

Then recreate CHR:

```bash
ROS7_MONITOR_USER=mikreman-fwd ./scripts/recreate-ros7.sh
```

After success, the QEMU sockets will be available at:
- `runtime/ros7-monitor/hmp.sock`
- `runtime/ros7-monitor/qmp.sock`

## 3. Enable Host Forwarding For The Port Range

Run as root:

```bash
sudo ./scripts/setup-host-iptables.sh
```

Default range:
- `16000-20000`

Default target:
- `172.20.0.10`

If needed, override:

```bash
sudo PORT_START=16000 PORT_END=16100 ROS7_IP=172.20.0.10 ./scripts/setup-host-iptables.sh
```

Then install the boot-time restore service and timer:

```bash
sudo ./scripts/install-host-iptables-service.sh
```

If needed, override the generated unit environment:

```bash
sudo PORT_START=16000 PORT_END=16100 ROS7_IP=172.20.0.10 ./scripts/install-host-iptables-service.sh
```

This installer creates:
- `mikreman-host-iptables.service`
- `mikreman-host-iptables.timer`

The service applies the host DNAT rules immediately, and the timer reapplies them:
- at boot
- every 60 seconds

## 4. Start MikReMan

```bash
docker compose up -d mikreman
```

Default web port:
- `http://127.0.0.1:8080`

Override:

```bash
MIKREMAN_HTTP_PORT=8088 docker compose up -d mikreman
```

If you plan to use the public trial page at `order.php`, also make sure:
- `config/` is writable by PHP
- `runtime/` is writable by PHP
- the same paths remain mounted into the `mikreman` container

## 5. Configure MikReMan Admin

In `Admin > MikroTik`:

- enable `QEMU Dynamic Host Forward`
- set `QEMU HMP Socket` to `/opt/mikreman/runtime/ros7-monitor/hmp.sock`
- set `socat Binary` to `/usr/bin/socat`

The `mikreman` container already mounts:
- host `./runtime/ros7-monitor`
- container `/opt/ros7-monitor`

So the application path should be:
- `/opt/mikreman/runtime/ros7-monitor/hmp.sock`

## 6. Test Host Forwarding Manually

```bash
./scripts/qemu-hostfwd.sh info
./scripts/qemu-hostfwd.sh add tcp 18046 8291
./scripts/qemu-hostfwd.sh remove tcp 18046
```

Example:
- `add tcp 18046 8291`
- meaning public host port `18046` is forwarded to guest CHR port `8291`

## 7. Working Random NAT Flow

When you create a PPP user with NAT enabled:
1. MikReMan creates a RouterOS rule:
   - `18045 -> 10.51.0.2:8291`
2. MikReMan runs:
   - `hostfwd_add tcp::18045-:18045`
3. The Linux host forwards the `16000-20000` range to the `ros7` container
4. QEMU forwards `18045` into the CHR guest
5. RouterOS inside CHR forwards traffic again to the PPP client router

## 8. Public Trial Cleanup Cron

The repository now uses a single host-side cleanup job for public trial accounts.

Why:
- better scaling than one RouterOS scheduler per trial
- cleanup can remove QEMU hostfwd entries as well as RouterOS resources
- trial state stays in the filesystem under `runtime/trials`

Recommended cron entry:

```cron
*/5 * * * * docker exec mikreman-app php /var/www/html/scripts/cleanup-expired-trials.php >> /var/log/mikreman-trial-cleanup.log 2>&1
```

This cleanup script processes expired trial records and removes:
- PPP secret
- PPP active session
- Netwatch
- NAT rules
- QEMU hostfwd mappings

Runtime state used by this flow:
- `runtime/trial-stats.sqlite`
- `runtime/trials/_index/`
- `runtime/trials/_logs/`
- `runtime/trials/YYYY-MM-DD/*.json`

The SQLite file is used only for trial statistics and reporting:
- total trials
- today
- this week
- this month
- recent read-only trial rows shown in `Admin > Trial Stats`

## Important Notes

- this model is still one rule per port
- it works well for TCP and UDP forwarding
- `PPTP` still has additional limitations because of `GRE`
- do not store a VPS root password in MikReMan
- the recommended deployment model remains `same-host socket mode`
- if Cloudflare Turnstile is enabled, local development on `localhost` still bypasses it automatically, but production hosts do not
- if all random public mappings suddenly return `Connection refused` while PPP sessions, RouterOS NAT rules, and QEMU hostfwd entries still exist, check the host iptables rules first
- if the host iptables service or timer is disabled, reinstall it with `scripts/install-host-iptables-service.sh`
