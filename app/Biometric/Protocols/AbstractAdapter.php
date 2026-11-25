<?php

namespace App\Biometric\Protocols;

use App\Biometric\Contracts\ProtocolAdapter;
use App\Biometric\Support\UnsupportedCommandException;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

abstract class AbstractAdapter implements ProtocolAdapter
{
    protected function unsupported(BiometricCommand $command): UnsupportedCommandException
    {
        return new UnsupportedCommandException(sprintf(
            'The %s adapter cannot encode the "%s" command.',
            class_basename(static::class),
            $command->type->label()
        ));
    }

    protected function deviceNow(BiometricDevice $device, ?string $override = null): CarbonInterface
    {
        $time = $override ? Carbon::parse($override) : Carbon::now();

        return $time->setTimezone($device->timezoneName());
    }

    protected function toDeviceTime(?string $value, BiometricDevice $device): CarbonInterface
    {
        return $this->deviceNow($device, $value);
    }

    /** Remove characters that would break tab/line based protocols. */
    protected function clean(?string $value, int $max = 24): string
    {
        return mb_substr(trim(preg_replace('/[\t\r\n]+/', ' ', (string) $value)), 0, $max);
    }
}
