<?php

namespace App\Providers;

use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Plan;
use App\Models\User;
use App\Observers\PlanObserver;
use App\Observers\UserObserver;
use App\Policies\BiometricAgentPolicy;
use App\Policies\BiometricDevicePolicy;
use App\Policies\BiometricEnrollmentPolicy;
use App\Providers\AssetServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\WebhookService::class);

        // Register our AssetServiceProvider
        $this->app->register(AssetServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the UserObserver
        User::observe(UserObserver::class);

        // Register the PlanObserver
        Plan::observe(PlanObserver::class);

        // Register granular Biometric authorization policies
        Gate::policy(BiometricDevice::class, BiometricDevicePolicy::class);
        Gate::policy(BiometricAgent::class, BiometricAgentPolicy::class);
        Gate::policy(BiometricEnrollment::class, BiometricEnrollmentPolicy::class);

        // Dedicated per-agent & per-IP rate limiters for Biometric APIs
        $this->configureBiometricRateLimiters();

        // Configure dynamic storage disks
        try {
            // \App\Services\DynamicStorageService::configureDynamicDisks();
        } catch (\Exception $e) {
            // Silently fail during migrations or when database is not ready
        }
    }

    private function configureBiometricRateLimiters(): void
    {
        RateLimiter::for('biometric-agent-register', function (Request $request) {
            $rpm = max(3, (int) config('biometric.agent.rate_limits.register_per_minute', 10));
            $claimed = strtolower(trim((string) $request->input('agent_identifier', '')));

            return [
                Limit::perMinute($rpm)->by('bio-reg:ip:' . $request->ip()),
                Limit::perMinute($rpm)->by('bio-reg:id:' . ($claimed !== '' ? $claimed : $request->ip())),
            ];
        });

        RateLimiter::for('biometric-agent-api', function (Request $request) {
            $rpm = max(30, (int) config('biometric.agent.rate_limits.api_per_minute', 180));
            /** @var ?BiometricAgent $agent */
            $agent = $request->attributes->get('biometric_agent');
            $key = $agent ? 'bio-agent:' . $agent->id : 'bio-ip:' . $request->ip();

            return Limit::perMinute($rpm)->by($key);
        });

        RateLimiter::for('biometric-agent-sync', function (Request $request) {
            $rpm = max(10, (int) config('biometric.agent.rate_limits.sync_per_minute', 60));
            /** @var ?BiometricAgent $agent */
            $agent = $request->attributes->get('biometric_agent');
            $key = $agent ? 'bio-sync:' . $agent->id : 'bio-sync-ip:' . $request->ip();

            return Limit::perMinute($rpm)->by($key);
        });
    }
}
