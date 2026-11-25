<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hardware providers
    |--------------------------------------------------------------------------
    |
    | Every biometric device is bound to exactly one provider (stored in
    | biometric_devices.protocol). A provider implements
    | App\Biometric\Contracts\BiometricDeviceInterface for one manufacturer /
    | protocol. To integrate a new manufacturer, create a provider class and
    | register it here; the device and attendance modules need no changes.
    |
    | allow_unverified: enable operations whose wire format has not yet been
    | validated on real hardware (see SupportLevel::Unverified). Only switch
    | this on after testing against the actual device model.
    |
    */
    'providers' => [
        'local_agent' => [
            'class' => \App\Biometric\Providers\LocalAgentProvider::class,
        ],
        'native' => [
            'class' => \App\Biometric\Providers\OwnBiometricDeviceProvider::class,
        ],
        'zk_adms' => [
            'class' => \App\Biometric\Providers\ZKTecoProvider::class,
            'allow_unverified' => (bool) env('BIOMETRIC_ZK_ALLOW_UNVERIFIED', false),
        ],
        'fkweb' => [
            'class' => \App\Biometric\Providers\FkWebProvider::class,
            'allow_unverified' => (bool) env('BIOMETRIC_FKWEB_ALLOW_UNVERIFIED', false),
        ],
        'generic_http' => [
            'class' => \App\Biometric\Providers\GenericBiometricProvider::class,
        ],
        'mock' => [
            'class' => \App\Biometric\Providers\MockBiometricDeviceProvider::class,
            'enabled' => (bool) env('BIOMETRIC_MOCK_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        ],
    ],

    'default_provider' => 'native',

    /*
    |--------------------------------------------------------------------------
    | Connectivity
    |--------------------------------------------------------------------------
    */
    'connectivity' => [
        // A push-mode device is considered online if it contacted the server
        // within this many seconds.
        'online_threshold_seconds' => (int) env('BIOMETRIC_ONLINE_THRESHOLD', 300),

        // TCP probe timeout (seconds) used by "Check connectivity".
        'probe_timeout_seconds' => (float) env('BIOMETRIC_PROBE_TIMEOUT', 3),

        // Allow probing private (RFC1918) addresses. In SaaS mode this is
        // disabled by default to avoid tenants scanning the server network.
        'allow_private_probe' => env('BIOMETRIC_ALLOW_PRIVATE_PROBE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */
    'commands' => [
        // Commands not picked up by the device within this time are expired.
        'ttl_minutes' => (int) env('BIOMETRIC_COMMAND_TTL', 1440),

        // Commands sent but not acknowledged within this time are failed.
        'ack_timeout_minutes' => (int) env('BIOMETRIC_COMMAND_ACK_TIMEOUT', 30),

        // Max commands handed to a device per poll.
        'batch_size' => (int) env('BIOMETRIC_COMMAND_BATCH', 20),

        // Wire IDs for the new command queue are offset so they never collide
        // with the IDs of the legacy biometric_device_commands table that is
        // still served through the same device endpoints.
        'wire_id_offset' => 900000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Biometric Template Security Policy
    |--------------------------------------------------------------------------
    |
    | By default, biometric templates stay exclusively on hardware devices
    | (storage_mode = 'device_only') and matching runs on the device itself.
    | Raw fingerprint or facial images are ALWAYS rejected and never stored.
    |
    | Set BIOMETRIC_ALLOW_TEMPLATE_TRANSFER=true only when cross-device
    | encrypted template replication is required by supported hardware.
    |
    */
    'templates' => [
        'storage_mode' => env('BIOMETRIC_ALLOW_TEMPLATE_TRANSFER', false) ? 'encrypted_transfer' : 'device_only',
        'allow_transfer' => (bool) env('BIOMETRIC_ALLOW_TEMPLATE_TRANSFER', false),
        'max_template_bytes' => (int) env('BIOMETRIC_MAX_TEMPLATE_BYTES', 32768), // 32 KB ceiling (templates are small; images are rejected)
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance Synchronization & Processing
    |--------------------------------------------------------------------------
    */
    'sync' => [
        // Ignore rapid repeated punches by the same employee within this window (seconds).
        'duplicate_window_seconds' => (int) env('BIOMETRIC_DUPLICATE_WINDOW_SECONDS', 60),

        // Hours after a night shift's end_time that an early-morning punch still
        // attributes to the previous day's night shift.
        'night_shift_cutoff_hours' => (int) env('BIOMETRIC_NIGHT_SHIFT_CUTOFF_HOURS', 6),

        // Retry limits for failed sync entries.
        'max_retry_attempts' => (int) env('BIOMETRIC_SYNC_MAX_RETRIES', 8),
        'retry_base_delay_seconds' => (int) env('BIOMETRIC_SYNC_RETRY_BASE_SECONDS', 60),

        // How long API idempotency keys are kept (hours).
        'idempotency_ttl_hours' => (int) env('BIOMETRIC_IDEMPOTENCY_TTL_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'events_days' => (int) env('BIOMETRIC_EVENTS_RETENTION_DAYS', 90),
        'audit_logs_days' => (int) env('BIOMETRIC_AUDIT_LOGS_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Device Communication Agent
    |--------------------------------------------------------------------------
    */
    'agent' => [
        // Enforce HTTPS on agent API endpoints (enabled in production by default).
        'require_https' => (bool) env('BIOMETRIC_AGENT_REQUIRE_HTTPS', env('APP_ENV', 'production') === 'production'),

        // Persist replay-protection nonces in MySQL in addition to Cache so replays
        // are blocked across multiple web nodes and cache restarts.
        'persist_nonces' => (bool) env('BIOMETRIC_AGENT_PERSIST_NONCES', true),

        // Maximum allowed clock skew (seconds) on signed agent requests.
        'max_clock_skew_seconds' => (int) env('BIOMETRIC_AGENT_MAX_SKEW', 300),

        // Rate limits (requests per minute).
        'rate_limits' => [
            'register_per_minute' => (int) env('BIOMETRIC_AGENT_REGISTER_RPM', 10),
            'api_per_minute' => (int) env('BIOMETRIC_AGENT_API_RPM', 180),
            'sync_per_minute' => (int) env('BIOMETRIC_AGENT_SYNC_RPM', 60),
        ],

        // Lifetime of one-time provisioning tokens (hours).
        'provisioning_token_ttl_hours' => (int) env('BIOMETRIC_AGENT_PROVISION_TTL_HOURS', 24),

        // An agent is considered online if its last heartbeat is within this threshold.
        'online_threshold_seconds' => (int) env('BIOMETRIC_AGENT_ONLINE_THRESHOLD', 180),

        // Default runtime settings sent to agents.
        'defaults' => [
            'heartbeat_interval_sec' => 60,
            'command_poll_interval_sec' => 15,
            'log_poll_interval_sec' => 60,
            'config_refresh_interval_sec' => 300,
            'log_batch_size' => 200,
        ],
    ],
];
