<?php

use App\Biometric\Support\NetworkGuard;

uses(Tests\TestCase::class);

it('always blocks loopback, link-local and metadata addresses', function (string $ip) {
    config(['biometric.connectivity.allow_private_probe' => true]);

    expect((new NetworkGuard())->validate($ip))->not->toBeNull();
})->with(['127.0.0.1', '169.254.169.254', '0.0.0.0', '::1', '224.0.0.1']);

it('blocks private ranges unless explicitly allowed', function () {
    $guard = new NetworkGuard();

    config(['biometric.connectivity.allow_private_probe' => false]);
    expect($guard->validate('192.168.1.201'))->not->toBeNull();

    config(['biometric.connectivity.allow_private_probe' => true]);
    expect($guard->validate('192.168.1.201'))->toBeNull()
        ->and($guard->validate('10.0.0.5'))->toBeNull();
});

it('allows public addresses', function () {
    config(['biometric.connectivity.allow_private_probe' => false]);

    expect((new NetworkGuard())->validate('8.8.8.8'))->toBeNull();
});
