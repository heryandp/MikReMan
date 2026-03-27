<h1 align="center">MikReMan v1.69</h1>
<h2 align="center">For MikroTik RouterOS 7.5+ Only</h2>

<p align="center">
  <a href="https://github.com/safrinnetwork/MikReMan">
    <img src="https://img.shields.io/badge/MikReMan-1.69-2ea44f?style=for-the-badge" alt="MikReMan 1.69">
  </a>
  <img src="https://img.shields.io/badge/Status-Active-brightgreen?style=for-the-badge" alt="Status Active">
  <img src="https://img.shields.io/badge/Made%20with-PHP-blue?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
</p>

<!-- Badges: MikroTik, Ubuntu, VPN, Hosting -->
<p align="center">
  <img src="https://img.shields.io/badge/MikroTik-Remote%20Manager-black?style=for-the-badge&logo=mikrotik&logoColor=white" alt="MikroTik">
  <img src="https://img.shields.io/badge/Ubuntu-22.04%2B-E95420?style=for-the-badge&logo=ubuntu&logoColor=white" alt="Ubuntu">
  <img src="https://img.shields.io/badge/VPN-OpenVPN%2FWireGuard-FF7B00?style=for-the-badge&logo=openvpn&logoColor=white" alt="VPN">
  <img src="https://img.shields.io/badge/Hosting-Shared%2FVPS-7952B3?style=for-the-badge&logo=apache&logoColor=white" alt="Hosting">
</p>

<p align="center">
  A lightweight panel for managing remote MikroTik VPN users (L2TP/PPTP/SSTP).
  Ready to deploy on shared hosting or VPS environments with a simple PHP stack.
</p>

<p align="center">
  <a href="https://github.com/safrinnetwork/MikReMan/archive/refs/heads/main.zip">
    <img src="https://img.shields.io/badge/⬇️%20Download-main.zip-informational?style=for-the-badge" alt="Download ZIP">
  </a>
  &nbsp;
  <a href="https://youtu.be/X0zZetC3eVc?si=4jyX0aoj_D2xPPHL">
    <img src="https://img.shields.io/badge/▶%20YouTube-Tutorial-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="YouTube">
  </a>
</p>

---

## ✨ Highlights
- 📡 Manage L2TP, PPTP, and SSTP users (add / edit / disable / monitor)
- 🔐 Simple login flow with encrypted app configuration
- ⚙️ Ready for shared hosting or VPS deployment
- 🧩 Native PHP structure that is easy to customize and port

---

## 🚀 Quick Start

**1) Download**
- ZIP: **[Download here](https://github.com/safrinnetwork/MikReMan/archive/refs/heads/main.zip)**
- Or via terminal:
  ```bash
  wget -O MikReMan.zip https://github.com/safrinnetwork/MikReMan/archive/refs/heads/main.zip
  unzip MikReMan.zip && mv MikReMan-main MikReMan
  ```

**2) Upload to Your Host**
- Upload the project contents to your document root (for example `/public_html/` or `/var/www/html/`).
- Use standard permissions: **644** for files and **755** for directories.

**3) Login**
- Open your app URL in the browser.
- **Default Login**
  - **User:** `user1234`
  - **Password:** `mostech`

> ⚠️ **Important:** Change the default password immediately after the first login.

---

## 📼 Tutorial
- YouTube: **https://youtu.be/X0zZetC3eVc?si=4jyX0aoj_D2xPPHL**

[![Watch Tutorial](https://img.youtube.com/vi/X0zZetC3eVc/hqdefault.jpg)](https://youtu.be/X0zZetC3eVc?si=4jyX0aoj_D2xPPHL)

---

## 🧰 Requirements
- Web server (Apache / Nginx / LiteSpeed)
- PHP **7.4+** recommended
- Standard PHP extensions enabled (for example cURL and OpenSSL)

---

## 🐳 Docker QEMU Deployment

This repository now also ships deploy artifacts for the following scenario:
- MikReMan in a PHP/Apache container
- CHR in a `ros7` container
- dynamic `QEMU hostfwd_add/remove`
- host iptables rules for random public port ranges

Relevant files:
- `Dockerfile`
- `docker-compose.yml`
- `scripts/bootstrap-same-host.sh`
- `scripts/init-ros7-qcow.sh`
- `scripts/setup-mikreman-fwd-user.sh`
- `scripts/recreate-ros7.sh`
- `scripts/setup-host-iptables.sh`
- `scripts/qemu-hostfwd.sh`

Documentation:
- `docs/docker-qemu-same-host.md`
- `docs/qemu-hostfwd-deployment.md`

Quick path:

```bash
./scripts/bootstrap-same-host.sh
sudo ./scripts/setup-host-iptables.sh
```

---

## 👤 Credits
Created by **Safrin (Mostech Network)** — GitHub: [@safrinnetwork](https://github.com/safrinnetwork)

---
