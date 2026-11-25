<?php

namespace App\Console\Commands;

use App\Biometric\BiometricDeviceFactory;
use App\Biometric\Providers\MockBiometricDeviceProvider;
use App\Models\BiometricDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Development helper for devices using the mock provider.
 */
class BiometricMockDeviceCommand extends Command
{
    protected $signature = 'biometric:mock-device
        {device : Device id}
        {action=status : status | punch | offline | online | reset}
        {--pin= : Biometric code for "punch"}
        {--at= : Punch time (default now), any date string}';

    protected $description = 'Control a simulated (mock provider) biometric device during development';

    public function handle(BiometricDeviceFactory $factory): int
    {
        $device = BiometricDevice::find($this->argument('device'));
        if (!$device) {
            $this->error('Device not found.');

            return self::FAILURE;
        }

        $provider = $factory->make($device);
        if (!$provider instanceof MockBiometricDeviceProvider) {
            $this->error("Device {$device->id} does not use the mock provider (provider: {$device->protocol}).");

            return self::FAILURE;
        }

        if (!MockBiometricDeviceProvider::enabled()) {
            $this->error('The mock provider is disabled (BIOMETRIC_MOCK_ENABLED=false).');

            return self::FAILURE;
        }

        switch ($this->argument('action')) {
            case 'punch':
                $pin = (string) $this->option('pin');
                if ($pin === '') {
                    $this->error('--pin is required.');

                    return self::FAILURE;
                }
                $entry = $provider->simulatePunch($pin, $this->option('at') ? Carbon::parse($this->option('at')) : null);
                $this->info("Punch recorded on simulated device: PIN {$entry['pin']} at {$entry['punched_at']}");
                break;

            case 'offline':
                $provider->setOffline(true);
                $this->info('Simulated device is now offline.');
                break;

            case 'online':
                $provider->setOffline(false);
                $this->info('Simulated device is now online.');
                break;

            case 'reset':
                $provider->reset();
                $this->info('Simulated device memory cleared.');
                break;

            default:
                $state = $provider->state();
                $this->table(['Property', 'Value'], [
                    ['Offline', $state['offline'] ? 'yes' : 'no'],
                    ['Users', count($state['users'])],
                    ['Punches', count($state['logs'])],
                    ['Clock offset (s)', $state['clock_offset']],
                    ['Booted at', $state['booted_at']],
                ]);
        }

        return self::SUCCESS;
    }
}
