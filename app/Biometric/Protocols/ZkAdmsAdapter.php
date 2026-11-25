<?php

namespace App\Biometric\Protocols;

use App\Biometric\Data\DeviceUser;
use App\Biometric\Enums\CommandType;
use App\Biometric\Support\EncodedCommand;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use Carbon\CarbonInterface;

/**
 * ZKTeco ADMS / iClock PUSH protocol codec.
 *
 * Command strings follow the ZKTeco PUSH communication protocol. The device
 * receives them as "C:<id>:<command>" lines from /iclock/getrequest and
 * reports results to /iclock/devicecmd as "ID=<id>&Return=<code>&CMD=<cmd>".
 *
 * NOTE: the clock command ("SET OPTIONS DateTime=<packed>") uses the packed
 * time format of the ZK SDK; its acceptance through PUSH firmware varies by
 * model, which is why ZKTecoProvider marks time sync as Unverified.
 */
class ZkAdmsAdapter extends AbstractAdapter
{
    public function encode(BiometricCommand $command, BiometricDevice $device): EncodedCommand
    {
        $p = $command->payload ?? [];

        $body = match ($command->type) {
            CommandType::Ping, CommandType::GetInfo => 'INFO',
            CommandType::SyncTime => 'SET OPTIONS DateTime=' . self::encodeTime($this->deviceNow($device, $p['time'] ?? null)),
            CommandType::UserUpsert => sprintf(
                "DATA UPDATE USERINFO PIN=%s\tName=%s\tPri=%d\tPasswd=\tCard=%s\tGrp=1\tTZ=0000000100000000",
                $this->clean($p['pin'] ?? '', 24),
                $this->clean($p['name'] ?? '', 24),
                (int) ($p['privilege'] ?? 0),
                $this->clean($p['card'] ?? '', 20)
            ),
            CommandType::UserDelete => 'DATA DELETE USERINFO PIN=' . $this->clean($p['pin'] ?? '', 24),
            CommandType::QueryUsers => 'DATA QUERY USERINFO',
            CommandType::FetchLogs => sprintf(
                "DATA QUERY ATTLOG StartTime=%s\tEndTime=%s",
                $this->toDeviceTime($p['from'] ?? null, $device)->format('Y-m-d H:i:s'),
                $this->toDeviceTime($p['to'] ?? null, $device)->format('Y-m-d H:i:s')
            ),
            CommandType::ClearLogs => 'CLEAR LOG',
            CommandType::Reboot => 'REBOOT',
            default => throw $this->unsupported($command),
        };

        return new EncodedCommand($body);
    }

    /** "Return" codes: 0 = success; >0 = success with count (queries); <0 = error. */
    public static function isSuccessCode(?string $code): bool
    {
        return $code !== null && $code !== '' && is_numeric($code) && (int) $code >= 0;
    }

    /**
     * Parse an INFO reply body (key=value lines) into neutral device information.
     */
    public static function parseInfo(string $body): array
    {
        $map = [
            'fwversion' => 'firmware_version',
            'firmver' => 'firmware_version',
            'devicename' => 'model',
            'usercount' => 'user_count',
            'fpcount' => 'fingerprint_count',
            'facecount' => 'face_count',
            'transactioncount' => 'log_count',
        ];

        $info = [];
        foreach (preg_split('/\r\n|\r|\n|&/', $body) as $line) {
            if (preg_match('/^\s*~?([A-Za-z]+)\s*=\s*(.*)$/', trim($line), $m)) {
                $key = strtolower($m[1]);
                if (isset($map[$key]) && trim($m[2]) !== '' && !isset($info[$map[$key]])) {
                    $info[$map[$key]] = trim($m[2]);
                }
            }
        }

        return $info;
    }

    /**
     * Parse "USER PIN=..\tName=..\tPri=..\tCard=.." lines uploaded by the device
     * (OPERLOG / USERINFO tables).
     *
     * @return DeviceUser[]
     */
    public static function parseUsers(string $body): array
    {
        $users = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if (!preg_match('/^USER\s+(.*)$/i', $line, $m)) {
                continue;
            }

            $fields = [];
            foreach (preg_split('/\t+/', $m[1]) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $fields[strtolower(trim($k))] = trim($v);
            }

            if (($fields['pin'] ?? '') === '') {
                continue;
            }

            $users[] = new DeviceUser(
                pin: $fields['pin'],
                name: $fields['name'] ?? '',
                card: ($fields['card'] ?? '') !== '' && ($fields['card'] ?? '') !== '0' ? $fields['card'] : null,
                privilege: (int) ($fields['pri'] ?? 0),
            );
        }

        return $users;
    }

    /** ZKTeco packed time format. */
    public static function encodeTime(CarbonInterface $t): int
    {
        return ((($t->year % 100) * 12 * 31 + (($t->month - 1) * 31) + $t->day - 1) * (24 * 60 * 60))
            + ($t->hour * 60 + $t->minute) * 60
            + $t->second;
    }
}
