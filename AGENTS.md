# AGENTS.md

## Project Overview

MikReMan is a native PHP application for managing MikroTik RouterOS 7 VPN users through the RouterOS REST API. The main UI now uses Bulma CDN, Bootstrap Icons, and SweetAlert2. There is no PHP framework, database, Composer dependency, or frontend build step.

Core project capabilities:
- Session and CSRF-based admin login
- Encrypted application configuration storage
- PPP user, NAT, VPN service, and RouterOS monitoring workflows
- Telegram integration for backup and notifications
- QEMU `user,hostfwd` deployment support with dynamic `hostfwd_add/remove`

Expected runtime:
- PHP 7.4+
- `curl` and `openssl` extensions
- `socat` available when `QEMU Dynamic Host Forward` is enabled
- RouterOS 7.5+ with REST API enabled

## Architecture

Primary request flow:
1. `index.php` handles login and session bootstrap.
2. Pages under `pages/` render the UI and enforce auth.
3. JavaScript under `assets/js/` calls JSON endpoints in `api/`.
4. Endpoints under `api/` use helpers from `includes/` for auth, config, MikroTik, and QEMU host forwarding.

Core components:
- `index.php`: login, session rate limiting, CSRF token
- `pages/admin.php`: app configuration, RouterOS settings, published ports, service hostnames, QEMU hostfwd
- `pages/dashboard.php`: router dashboard
- `pages/ppp.php`: PPP CRUD, user details, NAT mappings, client config generation
- `pages/monitoring.php`: monitoring workflows
- `api/config.php`: encrypted config read/write
- `api/mikrotik.php`: main RouterOS actions, PPP, NAT, service tests, netwatch
- `includes/config.php`: `ConfigManager`, encrypted config, default schema merge
- `includes/mikrotik.php`: RouterOS REST wrapper
- `includes/qemu_hostfwd.php`: `hostfwd_add/remove` helper through QEMU HMP
- `includes/ui.php`: shared navbar, page header, and asset helpers

## Frontend Notes

Current UI stack:
- Bulma via CDN
- Bootstrap Icons
- SweetAlert2
- Light/dark theme toggle via `assets/js/theme.js`

Important guidance:
- Do not reimplement the navbar or page header per page. Reuse helpers from `includes/ui.php`.
- Follow Bulma conventions for `navbar`, `tabs`, `modal-card`, `field/control`, and `table-container`.
- `assets/css/style.css` is now the app-specific layer, not a legacy Bootstrap override layer.

## Runtime Files

Files and folders created at runtime:
- `config/config.json.enc`
- `config/encryption.key`
- same-host QEMU deployments typically also use:
  - `runtime/ros7/chr-7.15.3.qcow2`
  - `runtime/ros7-monitor/hmp.sock`
  - `runtime/ros7-monitor/qmp.sock`

Implications:
- do not commit runtime secrets
- preserve backward compatibility when changing config schema

## Security-Sensitive Areas

Pay close attention to:
- `includes/config.php` still stores both plaintext passwords and `password_hash` values for admin retrieval flows
- `api/config.php` and `api/mikrotik.php` must continue to enforce `requireAuth()` and CSRF protection
- `includes/mikrotik.php` currently disables SSL verification for deployment compatibility
- `QEMU Dynamic Host Forward` should prefer a local same-host socket model. Do not store VPS root passwords in the app.

## MikroTik And QEMU Integration

RouterOS integration:
- uses the REST API under `/rest`
- published/public ports are stored under the `mikrotik` config section
- the PPP page reads these ports for client config generation and service testing

QEMU integration:
- `includes/qemu_hostfwd.php` sends HMP commands such as:
  - `hostfwd_add tcp::18045-:18045`
  - `hostfwd_remove tcp::18045`
- this is used when CHR runs under QEMU `user,hostfwd` and needs random public ports
- the recommended deployment model is `same-host socket mode`

## Suggested Validation After Changes

There is no automated test suite. Manual baseline validation:
- `php -l` on changed PHP files
- `node --check` on changed JS files
- login still works
- admin config can still be saved
- RouterOS connection tests still work
- PPP create/delete flows still work
- when QEMU integration is enabled:
  - `hostfwd_add/remove` works
  - random management ports are actually reachable

## Safe Change Strategy

Recommended working order:
1. Identify whether the change touches UI, auth, config, RouterOS, or QEMU hostfwd.
2. Review both the caller page/JS and the paired `api/` endpoint.
3. For config changes, consider the impact on already-encrypted existing config files.
4. For NAT and PPP changes, audit delete paths so cleanup stays symmetric.
5. For deploy/QEMU changes, update docs and host scripts together with application code.

## Known Constraints

- There is no formal environment management like `.env`.
- There is no migration system.
- There is no PHP package manager in use.
- Runtime state is stored on the local filesystem.
- The bundled Docker CHR deployment uses QEMU `user,hostfwd`, so random port support depends on coordinated behavior between:
  - `hostfwd_add/remove`
  - host iptables
  - RouterOS NAT inside CHR
