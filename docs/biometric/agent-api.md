# Biometric Local Agent Secure REST API Reference

This document specifies the secure HTTPS API used by Local Device Communication Agents to communicate with the cloud HRMS without exposing biometric hardware to the public internet.

---

## 1. Security Architecture

| Requirement | Implementation |
|---|---|
| **HTTPS-Only Communication** | Enforced by `EnforceBiometricHttps` (`biometric.https`). Plain HTTP requests are rejected with `426 Upgrade Required` when `BIOMETRIC_AGENT_REQUIRE_HTTPS=true` (enabled by default in production). |
| **Per-Agent Authentication** | Every authenticated request requires 5 headers: `Authorization: Bearer <access_token>`, `X-Agent-Id`, `X-Agent-Timestamp` (Unix seconds), `X-Agent-Nonce` (8–128 chars), and `X-Agent-Signature` (HMAC-SHA256 hex digest). |
| **Token Provisioning & Revocation** | Admins create an agent in HRMS to generate a single-use `prov_...` token (hashed with HMAC-SHA256, 24h TTL). Registration atomically exchanges it inside a locked DB transaction for permanent `agt_...` + HMAC secret. Admins can revoke immediately (`403` on next request), or active agents can self-rotate via `POST /api/biometric/agent/token/rotate`. |
| **Company / Tenant Isolation** | Tenant (`company_id`) is derived exclusively from the authenticated `BiometricAgent` record. Client-supplied `company_id`, `tenant_id`, `employee_id`, and `user_id` fields are stripped prior to validation. |
| **Device-Level Authorization** | `AgentAuthorizationService` requires BOTH `device.company_id === agent.company_id` AND `device.agent_id === agent.id`. Cross-tenant or unassigned device IDs return `403 Forbidden` without leaking device metadata. |
| **Request Validation** | Strict Laravel FormRequests validate all fields, batch limits, IP formats, and enum values (`422 Unprocessable Entity`). |
| **Rate Limiting** | Named Laravel limiters: `biometric-agent-register` (10 req/min per IP & claimed ID), `biometric-agent-api` (180 req/min per authenticated agent), `biometric-agent-sync` (60 req/min per authenticated agent). |
| **Replay Protection** | Timestamps outside `±300s` are rejected (`401 replay_blocked`). Nonces are checked via BOTH `Cache::add()` AND a persistent unique DB index (`biometric_agent_nonces`) so replays remain blocked across web nodes and cache restarts. |
| **Audit Logs** | `AuditBiometricApiRequest` records every call (2xx, 401, 403, 422, 426, 429, 5xx) in `biometric_api_audit_logs` with recursive secret/template redaction. Visible per-agent under **Biometric Agents → Show → API Audit Logs**. |

### Canonical HMAC-SHA256 Signature String
```text
<UPPERCASE_HTTP_METHOD>\n
/<normalized_path_without_query>\n
<X-Agent-Timestamp>\n
<X-Agent-Nonce>\n
<sha256_hex_of_raw_request_body>
```
Compute `HMAC-SHA256(canonical_string, hmac_secret)` as lowercase hex in `X-Agent-Signature`.

---

## 2. Canonical Endpoints

> All legacy `/api/biometric/agent/v1/*` routes remain supported with the exact same security middleware stack.

### 1. `POST /api/biometric/agent/register`
Exchanges a one-time provisioning token for per-agent Bearer + HMAC-SHA256 credentials.
- **Auth:** One-time `provisioning_token` (HTTPS-enforced, rate-limited `10/min`)
- **Request Body:** `{ agent_identifier, provisioning_token, agent_version?, os_info?, hostname?, capabilities? }`
- **Response (`200`):** `{ status: true, data: { agent_identifier, agent_name, access_token, hmac_secret, settings } }`

### 2. `POST /api/biometric/agent/heartbeat`
Reports agent liveness, local queue depth, error counts, and optional per-device connectivity snapshots.
- **Auth:** Signed per-agent headers (`180/min`)
- **Request Body:** `{ agent_version?, os_info?, hostname?, local_queue_depth?, local_error_count?, capabilities?, devices?: [...] }`
- **Response (`200`):** `{ status: true, server_time, devices_updated, devices_rejected, pending_commands }`

### 3. `GET /api/biometric/agent/commands`
Polls authorized queued commands for devices assigned to this agent (`?limit=20`, max `100`).
- **Auth:** Signed per-agent headers (`180/min`)
- **Response (`200`):** `{ status: true, data: { commands: [...] } }`

### 4. `POST /api/biometric/agent/commands/{id}/acknowledge`
Reports execution outcome for a polled command strictly authorized to this agent.
- **Auth:** Signed per-agent headers (`180/min`)
- **Request Body:** `{ success: boolean, return_code?, result?, error? }`
- **Response (`200`):** `{ status: true, data: { command_id, status } }` (returns `404` if unauthorized)

### 5. `POST /api/biometric/attendance/sync`
Uploads batched attendance events (up to 500 per call). Supports `X-Idempotency-Key` header for batch replay safety plus 3-layer DB punch deduplication. Client-supplied `employee_id` / `company_id` values are stripped; employee lookup is strictly server-side.
- **Auth:** Signed per-agent headers (`60/min`)
- **Request Body:** `{ logs: [...] }` (or `{ events: [...] }`), each item containing `{ local_uuid, device_id|device_serial, device_user_id|employee_code, punched_at, punch_type?, verification_method?, original_device_log_id?, raw_metadata? }`
- **Response (`200`):** `{ status: true, data: { accepted, duplicate, unmatched, rejected, attendance_updated, results } }` + `X-Idempotent-Replay: true|false`

### 6. `POST /api/biometric/devices/status`
Reports single or bulk device health & connectivity snapshots (`online`, `offline`, `error`) with strict per-device authorization.
- **Auth:** Signed per-agent headers (`180/min`)
- **Request Body:** `{ devices: [{ device_id|device_serial, online|status, latency_ms?, firmware_version?, user_count?, log_count?, error? }] }` (or single-device object)
- **Response (`200` / `403`):** `{ status, server_time, data: { updated, rejected, results } }`

### 7. `POST /api/biometric/enrollment/sync`
Synchronizes employee biometric enrollment status & device user directories for an authorized device. Any supplied `template_data` passes through `BiometricTemplateVault` (raw images are always rejected; server storage is blocked unless `BIOMETRIC_ALLOW_TEMPLATE_TRANSFER=true`).
- **Auth:** Signed per-agent headers (`60/min`)
- **Request Body:** `{ device_id|device_serial, enrollments: [{ device_user_id, name?, modality?, slot_index?, status?, enrolled_at?, template_data?, template_format?, quality_score? }] }`
- **Response (`200` / `403`):** `{ status, data: { device_id, synced, unmatched, rejected, results } }`

### 8. `GET /api/biometric/devices/{id}/configuration`
Fetches full hardware configuration, timezone, credentials, runtime intervals, and eligible employee PIN list (never biometric templates) for a single authorized device.
- **Auth:** Signed per-agent headers (`180/min`)
- **Response (`200` / `403`):** `{ status: true, config_version, data: { device, is_active, agent_settings, template_storage_mode, eligible_employees_count, eligible_employees, server_time } }`

### Additional Utility Endpoint: `POST /api/biometric/agent/token/rotate`
Rotates the authenticated agent's Bearer access token and HMAC secret immediately invalidating old credentials.
