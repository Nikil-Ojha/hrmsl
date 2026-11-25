<?php

use App\Biometric\Services\AgentCredentialManager;

uses(Tests\TestCase::class);

it('produces canonical HMAC signatures identical to the Python local agent', function () {
    $manager = new AgentCredentialManager();
    $body = '{"logs":[]}';
    $canonical = AgentCredentialManager::canonicalString('post', 'api/biometric/agent/v1/logs', '1700000000', 'abc123456789', $body);

    expect($canonical)->toBe("POST\n/api/biometric/agent/v1/logs\n1700000000\nabc123456789\n" . hash('sha256', $body));

    $phpSig = $manager->sign('deadbeef', 'POST', '/api/biometric/agent/v1/logs', '1700000000', 'abc123456789', $body);
    $expected = hash_hmac('sha256', $canonical, 'deadbeef');

    expect($phpSig)->toBe($expected);
});

it('rejects non-HTTPS requests when require_https is enabled', function () {
    config(['biometric.agent.require_https' => true]);
    $req = Illuminate\Http\Request::create('http://hrms.example.com/api/biometric/agent/v1/config', 'GET');
    $res = (new AgentCredentialManager())->verifySignedRequest($req);

    expect($res['ok'])->toBeFalse()
        ->and($res['status'])->toBe(426);
});

it('rejects missing headers and excessive clock skew', function () {
    config(['biometric.agent.require_https' => false, 'biometric.agent.max_clock_skew_seconds' => 300]);
    $manager = new AgentCredentialManager();

    $missing = $manager->verifySignedRequest(Illuminate\Http\Request::create('/api/biometric/agent/v1/config', 'GET'));
    expect($missing['ok'])->toBeFalse()->and($missing['status'])->toBe(401);

    $skewed = Illuminate\Http\Request::create('/api/biometric/agent/v1/config', 'GET');
    $skewed->headers->set('X-Agent-Id', 'AGT-TEST');
    $skewed->headers->set('Authorization', 'Bearer agt_fake');
    $skewed->headers->set('X-Agent-Timestamp', (string) (time() - 600));
    $skewed->headers->set('X-Agent-Nonce', 'nonce12345678');
    $skewed->headers->set('X-Agent-Signature', 'sig');

    $res = $manager->verifySignedRequest($skewed);
    expect($res['ok'])->toBeFalse()->and($res['error'])->toContain('clock skew');
});
