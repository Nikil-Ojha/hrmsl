# Biometric device providers

The HRMS talks to biometric hardware only through
`App\Biometric\Contracts\BiometricDeviceInterface`. Controllers, the device
module and (later) the attendance module never contain vendor code.

```
Controller ──► DeviceManager (rules, sync history)
                   │
                   ▼
           BiometricDeviceFactory ──► ProviderRegistry (config/biometric.php)
                   │
                   ▼
        BiometricDeviceInterface  (one instance per device)
        ├── OwnBiometricDeviceProvider   native      push   NativeAdapter
        ├── ZKTecoProvider               zk_adms     push   ZkAdmsAdapter
        ├── FkWebProvider                fkweb       push   FkWebAdapter (unverified)
        ├── GenericBiometricProvider     generic_http one-way push, no commands
        └── MockBiometricDeviceProvider  mock        simulated (development)
```

| Layer | Responsibility | Location |
|---|---|---|
| Provider | Implements the interface for one manufacturer/protocol; declares the support matrix | `app/Biometric/Providers` |
| Protocol adapter | Pure encoder/decoder: neutral command → wire format, wire replies → neutral data. No I/O | `app/Biometric/Protocols` |
| Transport | How bytes move. Push providers use `CommandQueue` (device polls); pull providers would open connections themselves | `app/Biometric/Services/CommandQueue.php` |
| Data objects | `DeviceUser`, `PunchRecord`, `DeviceInformation`, `DeviceStatus`, `OperationResult` | `app/Biometric/Data` |

## Operation results

Every operation returns an `OperationResult`:

| Status | Meaning |
|---|---|
| `completed` | Finished synchronously (pull / simulated). `data` holds the result |
| `queued` | Commands queued; the device executes them on its next poll. The sync run finishes when the device acknowledges them |
| `failed` | Attempted and failed |
| `unsupported` | The protocol has no such capability |
| `disabled` | The operation is `Unverified` and not enabled for this provider |

Operations never throw for missing capabilities, so callers need no
vendor-specific branching.

## Support levels and unverified commands

Each provider declares a `SupportLevel` for each `Operation`:

* **Supported**: implemented against documented protocol behaviour.
* **Unverified**: the wire format comes from vendor SDK material but hasn't
  been validated on hardware. It is **disabled** unless
  `biometric.providers.<key>.allow_unverified` is true. Currently:
  ZKTeco time sync (`BIOMETRIC_ZK_ALLOW_UNVERIFIED`) and all FKWeb commands
  (`BIOMETRIC_FKWEB_ALLOW_UNVERIFIED`).
* **Unsupported**: not available in the protocol.

Don't add commands to an adapter unless they are documented by the
manufacturer. If documentation is incomplete, mark the operation
`Unverified`.

## Adding a manufacturer

1. **Adapter** (push protocols): create `app/Biometric/Protocols/AcmeAdapter.php`
   implementing `ProtocolAdapter::encode()`. Throw
   `UnsupportedCommandException` for commands the protocol lacks.
2. **Provider**: create `app/Biometric/Providers/AcmeProvider.php`.
   * Push device: extend `AbstractPushProvider`, return the adapter from
     `adapter()`, implement `definition()` and `setupHints()`. All operations
     are already mapped to neutral commands.
   * Pull device / vendor SDK / cloud API: extend `AbstractProvider` and
     override the operations, returning `OperationResult::completed(...)` with
     neutral data objects.
   * Override `interpretInformation()` if the device reports information in a
     custom format.
3. **Register** it in `config/biometric.php`:
   ```php
   'acme' => ['class' => \App\Biometric\Providers\AcmeProvider::class, 'allow_unverified' => false],
   ```
4. **Device endpoint** (push only): the protocol gateway routes device requests
   to `DeviceGateway::touch()`, `CommandQueue::nextFor()` and
   `CommandQueue::acknowledge()`.
5. **Tests**: add the key to the dataset in
   `tests/Unit/Biometric/ProviderContractTest.php` and add encoding tests.

The device form, capability badges, action buttons and validation rules are
all generated from `definition()`, so no UI or controller changes are needed.

## Mock provider (development)

Enabled when `APP_ENV` is `local`/`testing` or `BIOMETRIC_MOCK_ENABLED=true`.
Choose **Mock Device (development only)** as the protocol. All operations
complete instantly against simulated device memory stored in the cache.

```bash
php artisan biometric:mock-device {id}                    # show state
php artisan biometric:mock-device {id} punch --pin=1001   # inject a punch
php artisan biometric:mock-device {id} offline|online     # simulate outages
php artisan biometric:mock-device {id} reset
```
