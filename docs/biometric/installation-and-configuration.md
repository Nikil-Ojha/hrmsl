# Biometric Attendance System — Installation & Configuration Guide

This guide covers deploying and configuring both the **Cloud HRMS Biometric Module** (Laravel 12 / MySQL 8.4 / Inertia + React) and the **Local Device Communication Agent** (Windows / Linux).

---

## 1. Prerequisites

### Cloud HRMS Server
- PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `json`, `ctype`, `tokenizer`
- MySQL 8.0+ / 8.4+
- Node.js 20+ (for frontend asset builds)
- HTTPS / TLS 1.2+ certificate (required for production agent & device APIs)
- Cron / Supervisor running `php artisan schedule:run` every minute

### Local Office Host (Windows / Linux)
- Python 3.10+ (zero external `pip` dependencies required — stdlib only)
- Outbound HTTPS (TCP 443) access to the cloud HRMS domain
- LAN/Wi-Fi or USB connectivity to office biometric terminals (biometric terminals are **never** exposed to the public internet)

---

## 2. Cloud HRMS Installation

### Step 1: Run Database Migrations
All 13 biometric migrations are additive, idempotent, and reversible:
```bash
php artisan migrate --force
```
This creates all normalized biometric tables (`biometric_devices`, `biometric_agents`, `biometric_employee_mappings`, `biometric_enrollments`, `biometric_punches`, `biometric_sync_runs`, `biometric_sync_failures`, `biometric_commands`, `biometric_device_command_logs`, `biometric_api_audit_logs`, `biometric_audit_logs`, etc.) and seeds all granular biometric permissions to the `superadmin` and `company` roles.

### Step 2: Configure Environment Variables (`.env`)
Add or verify the following optional settings in `.env` (defaults live in `config/biometric.php`):

```ini
# Enforce HTTPS on all agent & device push APIs (defaults true in APP_ENV=production)
BIOMETRIC_AGENT_REQUIRE_HTTPS=true

# Persist replay-protection nonces in MySQL in addition to Cache (recommended for multi-node)
BIOMETRIC_AGENT_PERSIST_NONCES=true

# Maximum allowed clock skew (seconds) for HMAC-signed agent requests
BIOMETRIC_AGENT_MAX_SKEW=300

# One-time provisioning token lifetime (hours)
BIOMETRIC_AGENT_PROVISION_TTL_HOURS=24

# Agent API rate limits (requests per minute)
BIOMETRIC_AGENT_REGISTER_RPM=10
BIOMETRIC_AGENT_API_RPM=180
BIOMETRIC_AGENT_SYNC_RPM=60

# Device & agent online heartbeat thresholds (seconds)
BIOMETRIC_ONLINE_THRESHOLD=300
BIOMETRIC_AGENT_ONLINE_THRESHOLD=180

# Biometric privacy policy:
# Keep false (device_only) so templates remain exclusively on hardware devices.
# Set true ONLY when encrypted cross-device template replication is required.
# Raw fingerprint/facial images are ALWAYS rejected regardless of this flag.
BIOMETRIC_ALLOW_TEMPLATE_TRANSFER=false

# Attendance processing defaults (overridable per Attendance Policy in HRMS UI)
BIOMETRIC_DUPLICATE_WINDOW_SECONDS=60
BIOMETRIC_NIGHT_SHIFT_CUTOFF_HOURS=6

# Development mock driver (enabled automatically only in local/testing)
BIOMETRIC_MOCK_ENABLED=false

# Unverified vendor wire commands remain disabled unless explicitly enabled after hardware validation
BIOMETRIC_ZK_ALLOW_UNVERIFIED=false
BIOMETRIC_FKWEB_ALLOW_UNVERIFIED=false

# Retention for device events & API audit logs (days)
BIOMETRIC_EVENTS_RETENTION_DAYS=90
BIOMETRIC_AUDIT_LOGS_RETENTION_DAYS=90
```

### Step 3: Build Frontend Assets & Clear Caches
```bash
npm run build
php artisan config:cache
php artisan route:cache
php artisan permission:cache-reset
```

### Step 4: Enable the Laravel Scheduler
Ensure cron invokes the Laravel scheduler every minute:
```cron
* * * * * cd /var/www/html/hrlaravel && php artisan schedule:run >> /dev/null 2>&1
```
Scheduled biometric background tasks (`routes/console.php`):
- `biometric:monitor` (every minute) — marks stale devices/agents offline, expires/fails timed-out commands, finalizes sync runs, and prunes expired replay nonces & aged logs.
- `biometric:retry-sync` (every 5 minutes) — retries pending failed attendance sync entries with exponential backoff.
- `biometric:detect-missing-clockouts` (hourly) — flags overdue open attendance records whose shift end + grace period has elapsed.

---

## 3. Role & Permission Configuration

Assign granular permissions to custom HR/IT roles via **Settings → Roles**:
- `view-biometric-devices` — view Biometric Dashboard and device list/details
- `create-biometric-devices` — register new biometric terminals
- `edit-biometric-devices` — edit device configuration and activate/deactivate terminals
- `delete-biometric-devices` — delete devices (with automated dependency checks)
- `enroll-biometric-employees` — start enrollment, re-enroll, and confirm captures on employee profiles
- `sync-biometric-devices` — check connectivity, sync time/employees, fetch logs, retry failures, and reprocess punches
- `view-biometric-logs` — inspect raw biometric logs, sync history, and biometric audit trails
- `manage-biometric-settings` — rotate device API keys, manage local agents & tokens, configure biometric policy rules
- `execute-biometric-commands` — restart devices, clear device logs, remove users, and cancel queued commands

---

## 4. Attendance Policy & Shift Configuration

1. Open **Attendance → Shifts** and mark cross-midnight shifts (e.g. `22:00–06:00`) with **Night Shift** enabled and accurate `break_start_time`/`break_end_time`.
2. Open **Attendance → Attendance Policies** and configure:
   - **Punch Pairing Mode**: `First-In / Last-Out`, `Sequential In/Out Pairs`, or `Device Punch Type (IN/OUT)`
   - **Duplicate Punch Window (sec)**: rapid bounce suppression window (default `60s`)
   - **Missing Clock-Out Grace (hours)**: hours after shift end before flagging missing clock-out (default `4h`)
   - **Auto-Detect Matching Shift**: enable for rotating/multi-shift workforces

---

## 5. Local Device Communication Agent Installation

### Step 1: Register Agent in HRMS
1. Open **Attendance → Biometric Agents → Register Agent**.
2. Assign the site/branch and copy the **one-time provisioning token** (`prov_...`, valid 24 hours).
3. Register your biometric terminals under **Attendance → Biometric Devices** and assign them to this Local Agent.

### Step 2: Install on Windows (PowerShell as Administrator)
```powershell
cd biometric-agent\installers\windows
.\install.ps1 `
  -ServerUrl "https://your-hrms.example.com" `
  -AgentId "agt-hq-01" `
  -ProvisioningToken "prov_xxxxxxxxxxxxxxxxxxxxxxxx"
```
This installs the agent, stores HMAC credentials protected via **Windows DPAPI**, and creates a resilient Windows Scheduled Task / service with local SQLite WAL offline queuing.

### Step 3: Install on Linux (`systemd`)
```bash
sudo bash biometric-agent/installers/linux/install.sh \
  --server-url "https://your-hrms.example.com" \
  --agent-id "agt-hq-01" \
  --provisioning-token "prov_xxxxxxxxxxxxxxxxxxxxxxxx"
```
Credentials are stored with `0600` file permissions under `/etc/hrms-biometric-agent/` and the daemon is managed by `systemctl status hrms-biometric-agent`.

---

## 6. Running Automated Tests

### Laravel Unit & End-to-End Scenario Suite (Pest)
```bash
./vendor/bin/pest tests/Unit/Biometric
```
Covers duplicate synchronization, offline delayed/out-of-order synchronization, invalid device/agent credentials, replay protection, employee mapping failures & retry resolution, multiple punches, overnight shifts, timezone conversions, template vault security, dashboard KPIs, and granular authorization policies. All DB tests run inside rolled-back transactions leaving zero test data behind.

### Python Local Agent Test Suite
```bash
PYTHONPATH=biometric-agent python3 -m unittest discover -s biometric-agent/tests -v
```
Covers SQLite WAL offline queue persistence/backoff/recovery, HMAC signing parity with Laravel, HTTPS enforcement, private-CIDR LAN discovery, USB file parsing, and subprocess SDK plugins.

---

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| `426 Upgrade Required` on agent calls | Verify reverse proxy forwards `X-Forwarded-Proto: https` and Laravel `TrustProxies` is configured. |
| `401 replay_blocked` | Synchronize NTP clock on the local agent host (skew must be within `±300s`). |
| `403 unauthorized_device` | Verify the device is assigned to that exact Local Agent under **Biometric Agents → Show → Assigned Devices**. |
| Punches stuck as `unmatched_employee` | Check **Biometric Enrollments** or `employees.biometric_emp_id` matches the device PIN, then click **Retry** under **Attendance Sync → Failed Sync Logs**. |
| Device shows `Offline` on Dashboard | Confirm `php artisan schedule:run` is running and check the agent heartbeat under **Biometric Agents**. |
