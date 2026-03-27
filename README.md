# MikReMan

MikReMan is a native PHP management panel for MikroTik RouterOS 7. It focuses on PPP user management, NAT workflows, VPN service operations, monitoring, and QEMU-based CHR deployments that rely on dynamic `hostfwd_add/remove`.

The application uses:
- PHP 7.4+
- Bulma via CDN
- Bootstrap Icons
- SweetAlert2
- RouterOS REST API

There is no PHP framework, no Composer dependency, no database, and no frontend build step.

## Current Scope

MikReMan currently covers:
- admin login with session and CSRF protection
- encrypted local configuration storage
- MikroTik connection management
- PPP user create, edit, disable, delete, and bulk actions
- public NAT mapping generation for PPP users
- VPN service configuration for L2TP, PPTP, and SSTP
- RouterOS monitoring and Netwatch workflows
- Telegram backup and notification integration
- QEMU `user,hostfwd` support for dynamic random public ports

## UI Stack

The main UI has already been migrated to:
- Bulma
- Bulma navbar, tabs, modal-card, form, and table patterns
- light/dark theme toggle
- SweetAlert2 for user-facing action feedback

The app-specific styling lives in:
- [assets/css/style.css](assets/css/style.css)

Shared UI helpers live in:
- [includes/ui.php](includes/ui.php)

## Requirements

Minimum runtime requirements:
- PHP 7.4 or newer
- `curl` extension
- `openssl` extension
- web server such as Apache, Nginx, or LiteSpeed

Additional requirement when QEMU dynamic host forwarding is enabled:
- `socat`

Router requirement:
- MikroTik RouterOS 7.5+ with REST API enabled

## Quick Start

1. Put the project on your web root or local PHP environment.
2. Make sure the `config/` directory is writable by PHP.
3. Open the application in a browser.
4. Sign in with the current default app credentials:
   - username: `user1234`
   - password: `mostech`
5. Change the login credentials immediately in the admin page.

## Main Pages

- [index.php](index.php): login and session bootstrap
- [pages/dashboard.php](pages/dashboard.php): system dashboard
- [pages/admin.php](pages/admin.php): MikroTik, auth, Telegram, and QEMU hostfwd settings
- [pages/ppp.php](pages/ppp.php): PPP users, NAT mappings, client config generation
- [pages/monitoring.php](pages/monitoring.php): monitoring and host status

## Configuration Storage

Runtime configuration is stored locally and encrypted:
- `config/config.json.enc`
- `config/encryption.key`

Important notes:
- do not commit runtime secrets
- keep backward compatibility when changing the config schema

## Same-Host Docker + QEMU CHR Deployment

This repository includes a same-host deployment path where:
- MikReMan runs in a PHP/Apache container
- CHR runs in a `ros7` container
- QEMU monitor sockets are shared through `runtime/ros7-monitor`
- random public ports use:
  - host iptables
  - QEMU `hostfwd_add/remove`
  - RouterOS NAT inside CHR

Relevant files:
- [Dockerfile](Dockerfile)
- [docker-compose.yml](docker-compose.yml)
- [scripts/bootstrap-same-host.sh](scripts/bootstrap-same-host.sh)
- [scripts/init-ros7-qcow.sh](scripts/init-ros7-qcow.sh)
- [scripts/recreate-ros7.sh](scripts/recreate-ros7.sh)
- [scripts/setup-host-iptables.sh](scripts/setup-host-iptables.sh)
- [scripts/setup-mikreman-fwd-user.sh](scripts/setup-mikreman-fwd-user.sh)
- [scripts/qemu-hostfwd.sh](scripts/qemu-hostfwd.sh)

Bootstrap path:

```bash
./scripts/bootstrap-same-host.sh
sudo ./scripts/setup-host-iptables.sh
```

After bootstrap:
- MikReMan is available at `http://127.0.0.1:8080` by default
- the app-side QEMU HMP socket path is `/opt/ros7-monitor/hmp.sock`

## QEMU Dynamic Host Forward Modes

The app supports two QEMU host forward modes:

1. Local Socket
- recommended when MikReMan runs on the same host as the QEMU CHR
- uses the local HMP socket directly

2. Remote SSH Key
- used when the app runs on another machine
- connects to the remote host over SSH
- should use a restricted user, not the VPS root account

The helper for this integration lives in:
- [includes/qemu_hostfwd.php](includes/qemu_hostfwd.php)

## Project Structure

High-level structure:
- `pages/`: authenticated page entrypoints
- `assets/js/`: browser-side controllers
- `api/`: JSON endpoints
- `includes/`: shared auth, config, UI, RouterOS, PPP, and QEMU helpers
- `scripts/`: deployment and host setup helpers
- `docs/`: project-specific references

Notable recent modularization:
- PPP UI and script logic are now split across:
  - [includes/ppp_ui.php](includes/ppp_ui.php)
  - [includes/ppp_script.php](includes/ppp_script.php)
  - [assets/js/ppp.js](assets/js/ppp.js)
  - [includes/ppp_nat.php](includes/ppp_nat.php)
  - [includes/ppp_actions.php](includes/ppp_actions.php)
- MikroTik integration is now split by domain through:
  - [includes/mikrotik.php](includes/mikrotik.php)
  - [includes/mikrotik_service_trait.php](includes/mikrotik_service_trait.php)
  - [includes/mikrotik_ppp_trait.php](includes/mikrotik_ppp_trait.php)
  - [includes/mikrotik_firewall_trait.php](includes/mikrotik_firewall_trait.php)
  - [includes/mikrotik_netwatch_trait.php](includes/mikrotik_netwatch_trait.php)

## Documentation

Additional docs:
- [docs/docker-qemu-same-host.md](docs/docker-qemu-same-host.md)
- [docs/qemu-hostfwd-deployment.md](docs/qemu-hostfwd-deployment.md)
- [docs/routeros-rest-api.md](docs/routeros-rest-api.md)
- [docs/bulma-reference.md](docs/bulma-reference.md)
- [docs/bulma-migration-plan.md](docs/bulma-migration-plan.md)

## Manual Validation

There is no automated test suite yet. The minimum validation baseline after changes is:
- `php -l` on changed PHP files
- `node --check` on changed JS files
- login still works
- admin config can still be saved
- MikroTik connection tests still work
- PPP create, edit, delete, and NAT flows still work
- if QEMU integration is enabled:
  - `hostfwd_add/remove` works
  - random public ports are reachable

## Credits

Original project by Safrin / Mostech Network:
- https://github.com/safrinnetwork
