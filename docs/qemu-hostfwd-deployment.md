# QEMU Host Forward Deployment

This document explains how to use MikReMan's `QEMU hostfwd_add/remove` integration safely for CHR deployments based on QEMU `user,hostfwd`.

## Purpose

This feature is useful when:
- CHR runs inside a container or QEMU process
- the static QEMU port forwards are not enough
- MikReMan needs to add random public forwards when creating per-user NAT rules

Example flow:
- MikReMan creates a RouterOS NAT rule `18045 -> 10.51.0.2:8291`
- MikReMan then runs `hostfwd_add tcp::18045-:18045`
- public traffic to `HOST:18045` enters the CHR guest
- RouterOS inside CHR forwards that traffic to the PPP client router

## Recommended Model

Use `same-host socket mode`.

That means:
- MikReMan runs on the same Linux host as the QEMU CHR container
- the app accesses a local monitor socket such as `/opt/ros7-monitor/hmp.sock`
- the app does not store a VPS root password
- the app does not need SSH access to the host

This is the deployment model currently supported by the repository.

## Required Configuration

In `Admin > MikroTik > QEMU Dynamic Host Forward`, configure:
- `Enable runtime hostfwd_add/remove`
- `QEMU HMP Socket`
- `socat Binary`

Typical values:
- `QEMU HMP Socket`: `/opt/ros7-monitor/hmp.sock`
- `socat Binary`: `/usr/bin/socat`

## Monitor Socket Permissions

For `Remote SSH Key` mode, the restricted SSH user must be able to open:
- `/opt/ros7-monitor/hmp.sock`

This repository includes `scripts/run-ros7-qemu.sh`, which:
- starts QEMU
- waits for `hmp.sock` and `qmp.sock` to appear
- applies `chgrp` and `chmod` to the monitor sockets

`scripts/recreate-ros7.sh` automatically detects the GID of the default host user `mikreman-fwd` and exports:
- `ROS7_MONITOR_GID`
- `ROS7_MONITOR_MODE`

To create that restricted SSH user, run:

```bash
sudo ./scripts/setup-mikreman-fwd-user.sh --pubkey-file /path/to/mikreman_fwd_ed25519.pub
```

If your SSH user is not `mikreman-fwd`, run:

```bash
ROS7_MONITOR_USER=your-user ./scripts/recreate-ros7.sh
```

## Do Not Store Full VPS Credentials

Do not store these in MikReMan:
- a VPS root password
- a root SSH private key
- any token with unrestricted shell access

The app does not need them when a local HMP socket is available.

## Remote Agent Mode

If MikReMan later runs on a different host than QEMU:
- do not grant root access directly to the app
- expose a small service on the QEMU host that only allows:
  - add host forward
  - remove host forward
  - list host forward

This mode is not implemented in the repository yet.

## Technical Limits

- `hostfwd_add/remove` is still one rule per port
- this deployment works well for TCP and UDP forwarding
- `PPTP` still has additional constraints because it requires `GRE`
- if the monitor socket is unavailable, the app cannot create dynamic forwards

## Host Operations

Example host commands:

```bash
printf 'hostfwd_add tcp::18045-:18045\n' | socat - UNIX-CONNECT:/opt/ros7-monitor/hmp.sock
printf 'hostfwd_remove tcp::18045\n' | socat - UNIX-CONNECT:/opt/ros7-monitor/hmp.sock
printf 'info usernet\n' | socat - UNIX-CONNECT:/opt/ros7-monitor/hmp.sock
```

## Production Notes

- use a persistent CHR disk on the host
- back up the `qcow2` file regularly
- restrict monitor socket access to only the users or services that need it
- watch PHP error logs for hostfwd and NAT failures
