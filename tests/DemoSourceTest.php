<?php

declare(strict_types=1);

namespace HPanel\Tests;

use HPanel\ApiError;
use HPanel\Config;
use HPanel\Context;
use HPanel\DemoSource;
use HPanel\RateLimit;
use HPanel\Session;
use HPanel\SessionStore;
use HPanel\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;
use HPanel\Tests\Support\TempDir;

final class DemoSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::make('hpanel-demo', 'sessions', 'rl');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*/*') ?: []);
        array_map('rmdir', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testFourFictitiousServersWithExampleDomainsOnly(): void
    {
        $servers = (new DemoSource())->servers();
        self::assertCount(4, $servers);
        self::assertSame([900001, 900002, 900003, 900004], array_column($servers, 'orderId'));
        foreach ($servers as $server) {
            self::assertNotEmpty($server['websites']);
            self::assertStringEndsWith('.example', $server['server']['hostname']);
            foreach ($server['websites'] as $site) {
                self::assertStringEndsWith('.example', $site['domain']);
            }
        }
    }

    public function testReadsReturnFixturesPerServer(): void
    {
        $demo = new DemoSource();
        $account = $demo->account('u900001', 'aurora.example', 900001);
        $disk = $account['usage']['storage'];
        self::assertGreaterThanOrEqual(0.9, $disk['value'] / $disk['limit'], 'servidor 1 com disco em nível crítico');
        self::assertStringStartsWith('203.0.113.', $account['ip']);
        self::assertNotEmpty($demo->databases('u900001', 'aurora.example', 900001));
        self::assertSame('8.2', $demo->phpVersion('u900001', 'aurora.example', 900001)['current']);
        self::assertStringStartsWith('ssh-rsa ', (string) $demo->gitKey('u900001', 'aurora.example', 900001));
        self::assertCount(2, $demo->gitRepos('u900001', 'aurora.example', 900001));
        self::assertSame([], $demo->gitRepos('u900002', 'pet-feliz.example', 900002));
        self::assertStringContainsString('Deployment', $demo->gitRepoOutput('u900001', 'aurora.example', 900001, 611001));
    }

    public function testDemoMetricsAreDeterministicWithinLimits(): void
    {
        $demo = new DemoSource();
        $a = $demo->metrics('u900001', 'aurora.example', 900001, 1440, 20);
        self::assertSame($a, $demo->metrics('u900001', 'aurora.example', 900001, 1440, 20));
        self::assertSame(['cpu', 'memory', 'ep', 'nproc', 'io', 'iops'], array_keys($a));
        foreach ($a as $s) {
            self::assertCount(73, $s['points']);
            foreach ($s['points'] as [, $usage]) {
                self::assertLessThanOrEqual($s['limit'], $usage);
            }
        }
        self::assertFalse($demo->malware('u900001', 'aurora.example', 900001)['compromised'] > 0);
        self::assertGreaterThan(0, $demo->malware('u900002', 'pet-feliz.example', 900002)['compromised']);
    }

    public function testDemoCronJobsHaveIdsAndOutputs(): void
    {
        $demo = new DemoSource();
        $jobs = $demo->cronJobs('u900001', 'aurora.example', 900001);
        self::assertCount(4, $jobs);
        self::assertSame(['id', 'time', 'command'], array_keys($jobs[0]));
        self::assertStringContainsString('ERROR 500', $demo->cronJobOutput('u900001', 'aurora.example', 900001, 'DemoCron04'));
        self::assertSame('', $demo->cronJobOutput('u900001', 'aurora.example', 900001, 'DemoCron01'));
        self::assertSame([], $demo->cronJobs('u900004', 'cafe.example', 900004));
    }

    public function testUnknownServerIsNotFound(): void
    {
        $this->expectExceptionObject(ApiError::notFound('Servidor não encontrado nesta conta.'));
        (new DemoSource())->account('x', 'x.example', 123);
    }

    /** @return iterable<string, array{\Closure(DemoSource): mixed}> */
    public static function blocked(): iterable
    {
        yield 'phpMyAdmin' => [static fn(DemoSource $d) => $d->phpMyAdminLink('u900001', 'u900001_wp', 'aurora.example', 900001)];
        yield 'file browser' => [static fn(DemoSource $d) => $d->fileBrowserLink('u900001', 'aurora.example', 900001)];
        yield 'root file browser' => [static fn(DemoSource $d) => $d->rootFileBrowserLink('u900001', 'aurora.example', 900001)];
        yield 'set PHP' => [static fn(DemoSource $d) => $d->setPhpVersion('u900001', 'aurora.example', 900001, '8.3')];
        yield 'create key' => [static fn(DemoSource $d) => $d->createGitKey('u900001', 'aurora.example', 900001)];
        yield 'delete key' => [static fn(DemoSource $d) => $d->deleteGitKey('u900001', 'aurora.example', 900001)];
        yield 'create repo' => [static fn(DemoSource $d) => $d->createGitRepo('u900001', 'aurora.example', 900001, 'git@github.com:a/b.git', 'main', '')];
        yield 'delete repo' => [static fn(DemoSource $d) => $d->deleteGitRepo('u900001', 'aurora.example', 900001, 611001)];
        yield 'deploy repo' => [static fn(DemoSource $d) => $d->deployGitRepo('u900001', 'aurora.example', 900001, 611001)];
        yield 'create cron' => [static fn(DemoSource $d) => $d->createCronJob('u900001', 'aurora.example', 900001, '0 0 * * *', 'echo oi')];
        yield 'delete cron' => [static fn(DemoSource $d) => $d->deleteCronJob('u900001', 'aurora.example', 900001, 'DemoCron01')];
        yield 'save dns' => [static fn(DemoSource $d) => $d->saveDnsRecords('aurora.example', 'www', 'A', 3600, ['203.0.113.10'], false)];
        yield 'delete dns' => [static fn(DemoSource $d) => $d->deleteDnsRecords('aurora.example', 'www', 'A')];
    }

    /** @dataProvider blocked */
    public function testLinksAndMutationsAreForbidden(\Closure $call): void
    {
        $this->expectExceptionObject(ApiError::forbidden('Indisponível no modo demonstração.'));
        $call(new DemoSource());
    }

    public function testDemoSessionUsesDemoSourceWithoutNetwork(): void
    {
        [$ctx, $http] = $this->demoContext();
        self::assertInstanceOf(DemoSource::class, $ctx->source());
        self::assertSame(['orderId' => 900002, 'domain' => 'pet-feliz.example', 'username' => 'u900002'], $ctx->catalog->site(900002, 'pet-feliz.example'));
        self::assertSame(102400, $ctx->catalog->account(900002)['value']['usage']['storage']['limit']);
        self::assertSame([], $http->calls);
    }

    public function testFreshTokenIsForbiddenInDemo(): void
    {
        [$ctx] = $this->demoContext();
        $this->expectExceptionObject(ApiError::forbidden('Indisponível no modo demonstração.'));
        $ctx->freshToken();
    }

    /** @return array{0: Context, 1: FakeTransport} */
    private function demoContext(): array
    {
        $secret = random_bytes(32);
        $store = new SessionStore($this->dir . '/sessions', $secret);
        $session = new Session($store, null, '/', false, static function (): void {}, static fn() => 1000);
        $session->start(['demo' => true]);
        $http = new FakeTransport([]);
        $ctx = new Context(Config::fromArray(['app_secret' => bin2hex($secret)], $this->dir), $session, $http, new RateLimit($this->dir . '/rl', $secret), static fn() => 1000);
        return [$ctx, $http];
    }
}
