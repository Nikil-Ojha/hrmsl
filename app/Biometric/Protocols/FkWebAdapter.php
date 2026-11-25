<?php

namespace App\Biometric\Protocols;

use App\Biometric\Enums\CommandType;
use App\Biometric\Support\EncodedCommand;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;

/**
 * FKWeb realtime protocol codec (header based: request_code / dev_id /
 * trans_id / cmd_code).
 *
 * WARNING: command codes below are taken from FK web-SDK sample material and
 * have NOT been validated against hardware or an official specification.
 * FkWebProvider therefore marks every command operation as Unverified; they
 * stay disabled until the SDK documentation is confirmed and
 * BIOMETRIC_FKWEB_ALLOW_UNVERIFIED=true is set.
 */
class FkWebAdapter extends AbstractAdapter
{
    public function encode(BiometricCommand $command, BiometricDevice $device): EncodedCommand
    {
        $p = $command->payload ?? [];

        [$code, $body] = match ($command->type) {
            CommandType::Ping, CommandType::GetInfo => ['GET_DEVICE_STATUS', []],
            CommandType::SyncTime => ['SET_TIME', ['time' => $this->deviceNow($device, $p['time'] ?? null)->format('YmdHis')]],
            CommandType::UserUpsert => ['SET_USER_INFO', [
                'user_id' => (string) ($p['pin'] ?? ''),
                'user_name' => $this->clean($p['name'] ?? '', 24),
                'user_privilege' => ((int) ($p['privilege'] ?? 0)) > 0 ? 'MANAGER' : 'USER',
            ]],
            CommandType::UserDelete => ['DELETE_USER', ['user_id' => (string) ($p['pin'] ?? '')]],
            CommandType::FetchLogs => ['GET_LOG_DATA', [
                'begin_time' => $this->toDeviceTime($p['from'] ?? null, $device)->format('YmdHis'),
                'end_time' => $this->toDeviceTime($p['to'] ?? null, $device)->format('YmdHis'),
            ]],
            default => throw $this->unsupported($command),
        };

        return new EncodedCommand(json_encode((object) $body, JSON_UNESCAPED_UNICODE), $code);
    }
}
