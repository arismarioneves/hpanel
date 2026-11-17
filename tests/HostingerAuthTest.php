<?php

declare(strict_types=1);

namespace HPanel\Tests;

use HPanel\ApiError;
use HPanel\HostingerAuth;
use HPanel\Session;
use HPanel\SessionStore;
use HPanel\Tests\Support\FakeTransport;
use HPanel\Tests\Support\TestJwt;
use PHPUnit\Framework\TestCase;
use HPanel\Tests\Support\TempDir;

final class HostingerAuthTest extends TestCase
{
    private string $dir;
    private SessionStore $store;
    private int $now = 2_000_000_000;
    private ?string $sid = null;

    protected function setUp(): void
    {
        $this->dir = TempDir::make('hpanel-auth');
        $this->store = new SessionStore($this->dir, random_bytes(32));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function sessionWith(string $token, array $extra = []): Session
    {
        $s = new Session($this->store, null, '/', false, function (string $n, string $v): void { $this->sid = $v; }, fn() => $this->now);
        $s->start(['token' => $token, 'gaid' => 'g'] + $extra);
        return new Session($this->store, $this->sid, '/', false, static function (): void {}, fn() => $this->now);
    }

    public function testFreshTokenIsUsedWithoutCallingHostinger(): void
    {
        $token = TestJwt::make($this->now + 3600);
        $http = new FakeTransport([]);
        self::assertSame($token, (new HostingerAuth($http))->ensureFresh($this->sessionWith($token), $this->now));
        self::assertSame([], $http->calls);
    }

    public function testRenewsNearExpiryAndPersistsNewToken(): void
    {
        $old = TestJwt::make($this->now + 600);
        $new = TestJwt::make($this->now + 3600);
        $http = new FakeTransport([['status' => 200, 'cookies' => ['jwt' => $new]]]);
        $session = $this->sessionWith($old);

        self::assertSame($new, (new HostingerAuth($http))->ensureFresh($session, $this->now));
        self::assertSame('jwt=' . $old . '; language=pt_BR', $http->calls[0]['cookie']);
        self::assertSame($new, $session->reload()['token']);
        self::assertSame('g', $session->reload()['gaid'], 'demais campos preservados');
    }

    public function testFailedRenewKeepsStillValidToken(): void
    {
        $old = TestJwt::make($this->now + 300);
        $http = new FakeTransport([['status' => 500]]);
        self::assertSame($old, (new HostingerAuth($http))->ensureFresh($this->sessionWith($old), $this->now));
    }

    public function testFailedRenewOfExpiredTokenIsSessionExpired(): void
    {
        $http = new FakeTransport([['status' => 401]]);
        $this->expectExceptionObject(ApiError::sessionExpired());
        (new HostingerAuth($http))->ensureFresh($this->sessionWith(TestJwt::make($this->now - 10)), $this->now);
    }

    public function testRenewRejectsMalformedCookie(): void
    {
        $http = new FakeTransport([['status' => 200, 'cookies' => ['jwt' => 'deleted']]]);
        self::assertNull((new HostingerAuth($http))->renew(TestJwt::make($this->now)));
    }

    public function testForceRenewsEvenWhenFresh(): void
    {
        $new = TestJwt::make($this->now + 7200);
        $http = new FakeTransport([['status' => 200, 'cookies' => ['jwt' => $new]]]);
        self::assertSame($new, (new HostingerAuth($http))->ensureFresh($this->sessionWith(TestJwt::make($this->now + 3600)), $this->now, true));
    }

    public function testForceRenewFailureIsUpstreamWhenTokenStillValid(): void
    {
        $http = new FakeTransport([['status' => 500]]);
        $this->expectExceptionObject(ApiError::upstream());
        (new HostingerAuth($http))->ensureFresh($this->sessionWith(TestJwt::make($this->now + 3600)), $this->now, true);
    }

    public function testExtensionSessionNearExpiryKeepsTokenWithoutCallingHostinger(): void
    {
        $token = TestJwt::make($this->now + 300);
        $http = new FakeTransport([]);
        $session = $this->sessionWith($token, ['via' => HostingerAuth::VIA_EXTENSION]);
        self::assertSame($token, (new HostingerAuth($http))->ensureFresh($session, $this->now));
        self::assertSame([], $http->calls);
    }

    public function testExpiredExtensionSessionIsSessionExpiredWithoutCallingHostinger(): void
    {
        $http = new FakeTransport([]);
        $session = $this->sessionWith(TestJwt::make($this->now - 10), ['via' => HostingerAuth::VIA_EXTENSION]);
        try {
            (new HostingerAuth($http))->ensureFresh($session, $this->now);
            self::fail('esperava session_expired');
        } catch (ApiError $e) {
            self::assertSame('session_expired', $e->errorCode);
        }
        self::assertSame([], $http->calls);
    }

    public function testForcedRenewOfExtensionSessionIsRefused(): void
    {
        $http = new FakeTransport([]);
        $session = $this->sessionWith(TestJwt::make($this->now + 3600), ['via' => HostingerAuth::VIA_EXTENSION]);
        $this->expectExceptionObject(ApiError::renewedByExtension());
        (new HostingerAuth($http))->ensureFresh($session, $this->now, true);
    }

    public function testAdoptReplacesTokenSwitchesToExtensionAndDropsCache(): void
    {
        $session = $this->sessionWith(TestJwt::make($this->now + 600), ['cache' => ['servers' => ['at' => 1, 'value' => []]]]);
        $new = TestJwt::make($this->now + 3600);

        (new HostingerAuth(new FakeTransport([])))->adopt($session, $new);

        $data = $session->reload();
        self::assertSame($new, $data['token']);
        self::assertTrue(HostingerAuth::viaExtension($data));
        self::assertArrayNotHasKey('cache', $data);
        self::assertSame('g', $data['gaid']);
    }

    public function testAdoptingTheSameTokenKeepsCache(): void
    {
        $token = TestJwt::make($this->now + 3600);
        $session = $this->sessionWith($token, ['via' => HostingerAuth::VIA_EXTENSION, 'cache' => ['servers' => ['at' => 1, 'value' => []]]]);

        (new HostingerAuth(new FakeTransport([])))->adopt($session, $token);

        self::assertArrayHasKey('cache', $session->reload());
    }

    public function testAdoptWithoutSessionIsNotConnected(): void
    {
        $session = new Session($this->store, null, '/', false, static function (): void {}, fn() => $this->now);
        $this->expectExceptionObject(ApiError::notConnected());
        (new HostingerAuth(new FakeTransport([])))->adopt($session, TestJwt::make($this->now + 3600));
    }
}
