# HRMS Local Biometric Device Communication Agent

A lightweight, zero-dependency Python 3.10+ daemon that runs inside a company's
office network (Windows first, Linux supported) and bridges local LAN, Wi-Fi and
USB biometric devices securely to the cloud HRMS over HTTPS.

Biometric devices stay strictly inside the local office network — no inbound
firewall ports or public IPs are required.

## Architecture

```
Office LAN / USB                                     Cloud HRMS (HTTPS)
┌──────────────────────┐                             ┌────────────────────────────┐
│ Biometric Devices    │◄── LAN / Wi-Fi / USB ──┐    │ /api/biometric/agent/v1/*  │
│ (Own Brand, ZKTeco,  │                        │    │  • /provision (one-time)   │
│  USB, Vendor SDKs)   │                        ▼    │  • /config                 │
└──────────────────────┘   ┌───────────────────────┐ │  • /heartbeat              │
                           │ Local Agent           │─┼─► /logs (idempotent batch) │
                           │ • Pluggable Drivers   │ │  • /commands + /ack        │
                           │ • SQLite WAL Queue    │ │  • /employees              │
                           │ • DPAPI / 0600 Creds  │ │  • /discovery              │
                           │ • HMAC-SHA256 Signer  │ │  • /errors                 │
                           └───────────────────────┘ └────────────────────────────┘
```

## Quick Start (Windows)

1. In HRMS, go to **Attendance → Biometric Agents → Register Agent** and copy the
   one-time provisioning command.
2. Open PowerShell as Administrator in `biometric-agent\`:
   ```powershell
   .\installers\windows\install-service.ps1 `
     -ServerUrl "https://hrms.yourcompany.com" `
     -AgentId "AGT-XXXXXXXX" `
     -Token "prov_..."
   ```
   Credentials are encrypted using **Windows DPAPI** (`CryptProtectData`).

## Quick Start (Linux)

```bash
sudo ./installers/linux/install-service.sh
sudo -u hrms-agent python3 -m hrms_agent --data-dir /var/lib/hrms-biometric-agent \
  provision --server "https://hrms.yourcompany.com" --agent-id "AGT-XXXXXXXX" --token "prov_..."
sudo systemctl start hrms-biometric-agent
```

## CLI Commands

```bash
python -m hrms_agent provision --server https://... --agent-id AGT-... --token prov_...
python -m hrms_agent run          # start daemon
python -m hrms_agent run --once   # run one sync cycle and exit
python -m hrms_agent status       # inspect queue depth & drivers
python -m hrms_agent discover --cidr 192.168.1.0/24
```

## Integrating Hardware SDKs Independently

Two independent extension mechanisms are supported without changing core agent or HRMS code:

1. **Python SDK Plugin (`plugins/*.py`)**: drop a file implementing `HardwareDriver`
   (see `plugins/_example_sdk_plugin.py`). It is auto-loaded at startup and selected via
   the device's `Agent Driver / SDK Plugin` field in HRMS.
2. **Out-of-process SDK Bridge (`sdk_subprocess`)**: wrap any 32-bit Windows COM DLL
   (e.g. `zkemkeeper.dll`), C++ `.dll`/`.so`, or `.NET` vendor SDK in a small executable
   that reads one JSON request on `stdin` and writes one JSON response on `stdout`. Set
   `sdk_command` on the device in HRMS.

## Offline Resilience

All retrieved attendance logs are written to an ACID SQLite WAL database
(`agent_queue.db`) with a unique `local_uuid` and `(device_serial, employee_code, punched_at)`
deduplication index before upload. If internet connectivity drops:
- The agent continues polling local devices using its cached configuration.
- Uploads use capped exponential backoff (30s → 1h).
- The moment connectivity returns, backoff is cleared and queued punches flush automatically.
- Communication errors are buffered locally and uploaded once online.

## Running Agent Unit Tests

```bash
python3 -m unittest discover -s tests -v
```
