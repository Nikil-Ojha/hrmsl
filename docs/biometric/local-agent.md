# Local Device Communication Agent

The Local Device Communication Agent (`biometric-agent/`) connects biometric
devices on a company's private office network (LAN, Wi-Fi, or USB) to the cloud
HRMS over outbound HTTPS only. Biometric devices are never exposed to the public
internet.

## Security Model

1. **Unique Installation Identity**: Every installation has a globally unique
   `agent_identifier` (e.g. `AGT-XXXXXXXX`) scoped to one `company_id` (and
   optionally one `branch_id`).
2. **One-Time Provisioning**: Creating an agent in HRMS generates a single-use
   provisioning token (hashed with `HMAC-SHA256`, expires after 24 hours). The
   agent exchanges it at `POST /api/biometric/agent/v1/provision` for:
   - `access_token` (`agt_<prefix>_<secret>`, stored hashed in `biometric_agents`)
   - `hmac_secret` (64-hex-character key, stored encrypted in `biometric_agents`)
3. **Signed HTTPS Requests**: Every subsequent call must use HTTPS (enforced in
   production via `BIOMETRIC_AGENT_REQUIRE_HTTPS`) and include:
   - `Authorization: Bearer <access_token>`
   - `X-Agent-Id: <agent_identifier>`
   - `X-Agent-Timestamp: <unix_seconds>` (±300s clock-skew limit)
   - `X-Agent-Nonce: <random_hex>` (cached to block replay attacks)
   - `X-Agent-Signature: HMAC-SHA256(hmac_secret, "{METHOD}\n{PATH}\n{TIMESTAMP}\n{NONCE}\n{SHA256(BODY)}")`
4. **Revocation & Rotation**: Admins can revoke an agent or issue a fresh
   provisioning token at any time from **Attendance → Biometric Agents**.

## API Endpoints (`/api/biometric/agent/v1`)

| Method & Path | Purpose |
|---|---|
| `POST /provision` | Exchange one-time provisioning token for runtime credentials |
| `GET /config` | Pull agent polling settings and assigned active devices |
| `POST /heartbeat` | Report agent telemetry, local queue depth, and per-device health |
| `POST /discovery` | Report devices discovered on local LAN subnets or USB ports |
| `POST /logs` | Idempotently upload batched attendance logs (`local_uuid` per punch) |
| `GET /employees` | Pull eligible employees scoped to the agent's assigned devices |
| `GET /commands` | Poll authorized vendor-neutral commands for assigned devices |
| `POST /commands/{id}/ack` | Acknowledge command completion, info, users or errors |
| `POST /errors` | Upload buffered local communication/hardware errors |

See [`biometric-agent/README.md`](../../biometric-agent/README.md) for Windows &
Linux installation instructions and SDK plugin development.
