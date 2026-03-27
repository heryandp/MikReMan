# RouterOS REST API (ROS7)

This document summarizes the official MikroTik RouterOS REST API documentation in the context of MikReMan.

Official sources:
- MikroTik RouterOS REST API: https://help.mikrotik.com/docs/spaces/ROS/pages/47579162/REST+API
- MikroTik RouterOS API: https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API

Source note:
- the REST API documentation page was last updated on August 12, 2025 according to MikroTik page metadata
- the classic API documentation page was last updated on February 27, 2025 according to MikroTik page metadata

## Summary

RouterOS REST API is available starting from RouterOS `v7.1beta4` and acts as a JSON wrapper around RouterOS console/API behavior.

To access the REST API:
- enable `www-ssl`, then use `https://<router-ip>/rest`
- or enable `www`, then use `http://<router-ip>/rest`

For production, HTTPS is the safer option.

## REST API vs Classic API

RouterOS also includes the classic API, which is different from the REST API.

According to the official classic API docs:
- the classic API follows RouterOS CLI concepts
- the default API service uses TCP `8728`
- the default API-SSL service uses TCP `8729`
- communication is sentence/word based, not HTTP JSON

Implications for this repository:
- MikReMan uses the HTTP/HTTPS REST API under `/rest`
- ports `8728` and `8729` are classic API endpoints, not MikReMan's main connection target
- port `8291` is Winbox, not a REST API endpoint

If the app cannot connect:
- check the RouterOS web service that exposes `/rest`
- do not assume Winbox or classic API ports are automatically compatible

## Authentication

The REST API uses HTTP Basic Auth:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/system/resource
```

Notes:
- `-k` is commonly used with self-signed certificates
- the same RouterOS username/password is used

## JSON Behavior

Important points from the official docs:
- almost all response values are returned as strings, including numbers and booleans
- octal and hex are accepted when sent as numbers, not strings
- exponent notation is not supported

Implications for MikReMan:
- do not assume `true`, `false`, numbers, or durations are returned as native JSON types
- explicitly handle string values such as `"false"` versus boolean `false`

## Base URL

Typical base URL:

```text
https://<router-ip>/rest
```

Common endpoints:
- `/rest/system/resource`
- `/rest/ppp/secret`
- `/rest/ip/firewall/nat`
- `/rest/interface/l2tp-server/server`

## HTTP Methods

Official RouterOS method mapping:
- `GET`: read or list data
- `PATCH`: update one record
- `PUT`: create one record
- `DELETE`: delete one record
- `POST`: general command-style operations and console-like endpoints

## GET Examples

Get all records:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/ip/address
```

Get one record by `.id`:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/ip/address/*1
```

Get one record by name when supported:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/interface/ether1
```

Filter with query parameters:

```bash
curl -k -u 'admin:password' "https://<router-ip>/rest/ip/address?network=10.155.101.0&dynamic=true"
```

Limit the returned properties:

```bash
curl -k -u 'admin:password' "https://<router-ip>/rest/ip/address?.proplist=address,disabled"
```

## PATCH Example

```bash
curl -k -u 'admin:password' \
  -X PATCH \
  https://<router-ip>/rest/ip/address/*3 \
  -H "Content-Type: application/json" \
  --data '{"comment":"test"}'
```

## PUT Example

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ip/address \
  -H "Content-Type: application/json" \
  --data '{"address":"192.168.111.111","interface":"dummy"}'
```

Notes:
- one `PUT` request creates one resource

## DELETE Example

```bash
curl -k -u 'admin:password' \
  -X DELETE \
  https://<router-ip>/rest/ip/address/*9
```

If the record no longer exists, RouterOS may return `404`.

## POST Examples

`POST` is used for command-style operations and console-like behavior.

Change a password:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/password \
  -H "Content-Type: application/json" \
  --data '{"old-password":"old","new-password":"new","confirm-new-password":"new"}'
```

Run a CLI script:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/execute \
  -H "Content-Type: application/json" \
  --data '{"script":"/log/info test"}'
```

## `.proplist` and `.query`

For `print` commands, the official docs support:
- `.proplist`
- `.query`

Example `.proplist`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/interface/print \
  -H "Content-Type: application/json" \
  --data '{".proplist":["name","type"]}'
```

Example `.query`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/interface/print \
  -H "Content-Type: application/json" \
  --data '{".query":["type=ether","type=vlan","#|!"]}'
```

Combined example:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/ip/address/print \
  -H "Content-Type: application/json" \
  --data '{".proplist":[".id","address","interface"],".query":["network=192.168.111.111","dynamic=true","#|"]}'
```

## Timeout

The official docs state the current REST API timeout is `60 seconds`.

Long-running operations will time out, so commands such as `ping` or monitoring tasks should be constrained with parameters like `count` or `duration`.

Example bounded `ping`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/ping \
  -H "Content-Type: application/json" \
  --data '{"address":"8.8.8.8","count":"4"}'
```

## Error Format

When a request fails, the HTTP status is usually `>= 400` and the body is commonly JSON, for example:

```json
{"detail":"not found"}
```

or:

```json
{"error":400,"message":"Bad Request"}
```

## Examples Relevant To MikReMan

Get system resources:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/system/resource
```

Create a PPP secret:

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ppp/secret \
  -H "Content-Type: application/json" \
  --data '{"name":"vpn1001","password":"secret","service":"l2tp","profile":"L2TP","remote-address":"10.51.0.2"}'
```

List firewall NAT rules:

```bash
curl -k -u 'admin:password' https://<router-ip>/rest/ip/firewall/nat
```

Create a NAT rule:

```bash
curl -k -u 'admin:password' \
  -X PUT \
  https://<router-ip>/rest/ip/firewall/nat \
  -H "Content-Type: application/json" \
  --data '{"chain":"dstnat","action":"dst-nat","protocol":"tcp","dst-port":"18045","to-addresses":"10.51.0.2","to-ports":"8291"}'
```

Run a RouterOS command via `execute`:

```bash
curl -k -u 'admin:password' \
  -X POST \
  https://<router-ip>/rest/execute \
  -H "Content-Type: application/json" \
  --data '{"script":"/interface/l2tp-server/server/print"}'
```

## Notes For This Repository

MikReMan currently:
- connects to RouterOS through `/rest`
- uses `GET`, `PUT`, `PATCH`, `DELETE`, and `POST`
- uses `POST /execute` for some RouterOS command flows

Recommended guidance:
- use RouterOS `7.5+` for compatibility with MikReMan
- enable `www-ssl` and prefer HTTPS
- avoid HTTP except for short-lived test scenarios
- make sure the RouterOS web service really points to RouterOS, not another reverse proxy or unrelated app
- remember that many response values are still strings, including booleans such as `"false"` and `"true"`
