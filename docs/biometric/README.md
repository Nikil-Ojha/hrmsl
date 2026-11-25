# HRMS Biometric Attendance System — Architecture & Documentation Index

This index maps every component, module, test suite, and guide for the vendor-independent Biometric Attendance Management System integrated into the HRMS.

---

## 1. Documentation Guides

1. **[Installation & Configuration Guide](installation-and-configuration.md)** — Cloud HRMS setup, `.env` variables, scheduler, RBAC roles, Windows/Linux agent deployment, tests, and troubleshooting.
2. **[Secure Agent REST API Reference](agent-api.md)** — All 8 canonical HTTPS endpoints, HMAC-SHA256 signing, replay protection, rate limiting, and audit logs.
3. **[Local Device Communication Agent Architecture](local-agent.md)** (+ [biometric-agent/README.md](../../biometric-agent/README.md)) — Windows/Linux daemon, SQLite WAL offline queue, DPAPI/`0600` credential storage, LAN/Wi-Fi/USB discovery, and pluggable hardware drivers.
4. **[Device Provider & Driver Integration Guide](providers.md)** — Implementing `BiometricDeviceInterface`, registering new hardware manufacturers without modifying core attendance code, and using the Mock provider.
5. **[Normalized Database Design & ERD](database-design.md)** — Relational schema, Mermaid ER diagram, foreign keys, unique constraints, indexes, and tenant scoping.

---

## 2. Complete Implementation Catalog

### Database Migrations (`database/migrations/`)
- `2026_11_20_000001_create_biometric_devices_table.php`
- `2026_11_20_000002_create_biometric_sync_runs_table.php`
- `2026_11_20_000003_create_biometric_commands_table.php`
- `2026_11_20_000004_create_biometric_device_events_table.php`
- `2026_11_20_000005_add_biometric_device_permissions.php`
- `2026_11_20_000006_add_device_communication_tables.php`
- `2026_11_20_000007_create_biometric_agents_tables.php`
- `2026_11_20_000008_create_biometric_enrollments_tables.php`
- `2026_11_20_000009_create_biometric_attendance_sync_tables.php`
- `2026_11_20_000010_add_attendance_processing_rules_columns.php`
- `2026_11_20_000011_create_biometric_api_security_tables.php`
- `2026_11_20_000012_normalize_biometric_database_schema.php`
- `2026_11_20_000013_create_biometric_audit_logs_and_permissions.php`

### Models (`app/Models/`)
- Biometric entities: `BiometricDevice`, `BiometricAgent`, `BiometricAgentDiscovery`, `BiometricEmployeeMapping`, `BiometricEnrollment`, `BiometricEnrollmentCredential`, `BiometricEncryptedTemplate`, `BiometricDeviceUser`, `BiometricPunch`, `BiometricSyncRun`, `BiometricSyncFailure`, `BiometricIdempotencyKey`, `BiometricCommand`, `BiometricDeviceCommandLog`, `BiometricDeviceEvent`, `BiometricApiAuditLog`, `BiometricAuditLog`.
- Enhanced existing HRMS models (adapted in-place, never duplicated): `AttendanceRecord`, `AttendancePolicy`, `AttendanceRegularization`, `Employee`.

### Controllers
- **Web (`app/Http/Controllers/Biometric/`):** `BiometricDashboardController`, `BiometricDeviceController`, `BiometricAgentController`, `BiometricEnrollmentController`, `BiometricSyncController`, `BiometricAuditLogController`.
- **API (`app/Http/Controllers/Api/`):** `BiometricAgentApiController`, `BiometricDevicePushApiController`, `BiometricAttendanceApiController`.

### Provider Interfaces, Drivers & Services (`app/Biometric/`)
- **Contracts:** `BiometricDeviceInterface` (13 methods), `ProtocolAdapter`, `QueuesDeviceCommands`.
- **Providers:** `OwnBiometricDeviceProvider` (`native`), `ZKTecoProvider` (`zk_adms`), `FkWebProvider` (`fkweb`), `GenericBiometricProvider` (`generic_http`), `LocalAgentProvider` (`local_agent`), `MockBiometricDeviceProvider` (`mock`), plus `BiometricDeviceFactory` & `ProviderRegistry`.
- **Protocol Adapters:** `NativeAdapter`, `ZkAdmsAdapter`, `FkWebAdapter`.
- **Core Services:** `DeviceManager`, `CommandQueue`, `SyncRunService`, `ConnectivityChecker`, `NetworkGuard`, `DeviceActivityRecorder`, `DeviceApiKeyManager`, `DeviceDependencyChecker`, `DeviceGateway`, `DeviceUserDirectory`, `EmployeeSelector`, `AgentCredentialManager`, `AgentAuthorizationService`, `AgentApiAuditLogger`, `AgentLogIngestionService`, `EnrollmentService`, `BiometricTemplateVault`, `PunchSynchronizationService`, `ShiftResolver`, `AttendanceProcessor`, `SyncFailureRetryService`, `DashboardService`, `BiometricAuditLogger`.

### Authorization Policies (`app/Policies/`)
- `BiometricDevicePolicy`, `BiometricAgentPolicy`, `BiometricEnrollmentPolicy` (registered in `AppServiceProvider`, enforcing all 9 granular permissions and tenant isolation).

### Request Validation (`app/Http/Requests/`)
- Web: `Biometric/BiometricDeviceRequest`, `Biometric/BiometricAgentRequest`.
- API: `Api/Biometric/AgentRegisterRequest`, `AgentHeartbeatRequest`, `AgentCommandAckRequest`, `AgentAttendanceSyncRequest`, `AgentDeviceStatusRequest`, `AgentEnrollmentSyncRequest`.

### React / Inertia Pages & Components (`resources/js/pages/hr/`)
- `biometric-dashboard/index.tsx` — Fleet KPI dashboard, 7-day chart, health breakdown, and Device Activity table.
- `biometric-devices/{index,show}.tsx` + `components/{shared,device-form-dialog,action-dialogs}.tsx`.
- `biometric-agents/{index,show}.tsx` + `components/agent-dialogs.tsx` (includes API Audit Logs tab).
- `biometric-enrollments/index.tsx` + `employees/components/biometric-enrollment-tab.tsx`.
- `biometric-sync/index.tsx` + `components/raw-logs-audit-dialog.tsx` (separate Processed Attendance vs Raw Biometric Logs).
- `biometric-audit-logs/index.tsx` — Searchable audit log viewer with Before/After diff modal.
- `attendance-policies/index.tsx` — Biometric pairing, bounce window, missing clock-out grace, and auto-shift rules.

### Automated Test Suites
- **Laravel Pest (`tests/Unit/Biometric/`):** `AgentApiSecurityTest`, `AgentCredentialTest`, `AttendanceProcessingRulesTest`, `AttendanceSynchronizationTest`, `BiometricScenariosTest`, `BiometricTemplateVaultTest`, `DashboardServiceTest`, `NetworkGuardTest`, `PermissionsAndAuditTest`, `ProtocolAdapterTest`, `ProviderContractTest`.
- **Python Local Agent (`biometric-agent/tests/`):** `test_discovery.py`, `test_drivers.py`, `test_runner.py`, `test_security.py`, `test_storage.py`.
