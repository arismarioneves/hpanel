<?php

declare(strict_types=1);

namespace HPanel\Tests;

use HPanel\ApiError;
use HPanel\HostingerSource;
use HPanel\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class HostingerSourceTest extends TestCase
{
    private static function ok(array $json): array
    {
        return ['status' => 200, 'body' => json_encode($json)];
    }

    private static function page(int $n, int $from = 0): array
    {
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            $r[] = ['orderId' => $from + $i];
        }
        return self::ok(['data' => ['resources' => $r]]);
    }

    public function testServersFollowsPaginationUntilShortPage(): void
    {
        $http = new FakeTransport([self::page(25), self::page(3, 25)]);
        $servers = (new HostingerSource($http, 'TOKEN', 'GA'))->servers();

        self::assertCount(28, $servers);
        self::assertCount(2, $http->calls);
        self::assertStringContainsString('page=2', $http->calls[1]['url']);
        self::assertStringContainsString('gaid=GA', $http->calls[1]['url']);
        self::assertSame('jwt=TOKEN; language=pt_BR', $http->calls[0]['cookie']);
    }

    public function test401MeansSessionExpired(): void
    {
        $this->expectExceptionObject(ApiError::sessionExpired());
        (new HostingerSource(new FakeTransport([['status' => 401]]), 't', ''))->servers();
    }

    public function testServerErrorsAndNetworkFailuresAreUpstream(): void
    {
        foreach ([['status' => 500, 'body' => '{"message":"stack trace interno"}'], ['status' => 0], ['status' => 200, 'body' => '<html>']] as $resp) {
            try {
                (new HostingerSource(new FakeTransport([$resp]), 't', ''))->servers();
                self::fail('esperava ApiError');
            } catch (ApiError $e) {
                self::assertSame('upstream_unavailable', $e->errorCode);
                self::assertStringNotContainsString('stack', $e->getMessage());
            }
        }
    }

    public function testAccountScopedCallsSendScopeHeadersAndEncodePath(): void
    {
        $http = new FakeTransport([self::ok(['data' => ['link' => 'https://fm.example/x']])]);
        $link = (new HostingerSource($http, 't', ''))->fileBrowserLink('u123', 'loja.com', 42);

        self::assertSame('https://fm.example/x', $link);
        self::assertContains('x-hpanel-order-id: 42', $http->calls[0]['headers']);
        self::assertContains('x-hpanel-username: u123', $http->calls[0]['headers']);
        self::assertContains('x-hpanel-domain: loja.com', $http->calls[0]['headers']);
        self::assertStringContainsString('vhost=loja.com', $http->calls[0]['url']);
    }

    public function testLinkMustBeHttps(): void
    {
        $this->expectExceptionObject(ApiError::upstream());
        (new HostingerSource(new FakeTransport([self::ok(['data' => ['link' => 'javascript:alert(1)']])]), 't', ''))
            ->fileBrowserLink('u', 'd.com', 1);
    }

    public function testRootFileBrowserLinkOmitsVhost(): void
    {
        $http = new FakeTransport([self::ok(['data' => ['link' => 'https://fm.example/root']])]);
        $link = (new HostingerSource($http, 't', ''))->rootFileBrowserLink('u123', 'loja.com', 42);

        self::assertSame('https://fm.example/root', $link);
        self::assertStringNotContainsString('vhost=', $http->calls[0]['url']);
    }

    public function testMetricsKeepsKnownSeriesAsCompactPoints(): void
    {
        $http = new FakeTransport([self::ok(['data' => [
            'cpu' => ['limit' => 100, 'label' => 'CPU', 'datapoints' => [
                ['timestamp' => 1000, 'usage' => 3, 'faults' => 0],
                ['timestamp' => 2200, 'usage' => 100, 'faults' => 2],
                'lixo',
            ]],
            'memory' => ['limit' => 12288, 'datapoints' => [['timestamp' => 1000, 'usage' => 646.5]]],
            'novaSerie' => ['limit' => 1, 'datapoints' => []],
            'iops' => ['limit' => 3072],
        ]])]);
        $series = (new HostingerSource($http, 't', ''))->metrics('u1', 'd.com', 7, 1440, 20);

        self::assertStringContainsString('/metrics/lve?rangeMinutes=1440&stepSizeMinutes=20', $http->calls[0]['url']);
        self::assertSame(['cpu', 'memory'], array_keys($series));
        self::assertSame(['limit' => 100.0, 'points' => [[1000, 3.0, 0], [2200, 100.0, 2]]], $series['cpu']);
        self::assertSame([[1000, 646.5, 0]], $series['memory']['points']);
    }

    public function testMetricsDropsTrailingBucketThatIsZeroEverywhere(): void
    {
        $pts = static fn(array $u) => array_map(static fn($v, $i) => ['timestamp' => $i, 'usage' => $v], $u, array_keys($u));
        $http = new FakeTransport([self::ok(['data' => [
            'cpu' => ['limit' => 100, 'datapoints' => $pts([2, 0, 0])],
            'memory' => ['limit' => 10, 'datapoints' => $pts([5, 4, 0])],
        ]])]);
        $series = (new HostingerSource($http, 't', ''))->metrics('u1', 'd.com', 7, 60, 1);

        self::assertSame([[0, 2.0, 0], [1, 0.0, 0]], $series['cpu']['points'], 'zero real no meio é mantido');
        self::assertCount(2, $series['memory']['points']);
    }

    public function testMalwarePrefersNonRemediatedCounts(): void
    {
        $http = new FakeTransport([self::ok(['data' => [
            'activeProtection' => 'enabled', 'lastReportedStatus' => 'good', 'scanStatus' => 'running',
            'lastScanEnd' => '2026-10-09T04:51:02Z', 'compromised' => 3, 'compromisedNonRemediated' => 0, 'malicious' => 1,
        ]])]);
        $m = (new HostingerSource($http, 't', ''))->malware('u1', 'd.com', 7);

        self::assertTrue($m['protection']);
        self::assertSame(0, $m['compromised']);
        self::assertSame(1, $m['malicious']);
        self::assertSame('running', $m['scanStatus']);
    }

    public function testCronJobsExposePwkeyAsIdAndDropMalformedRows(): void
    {
        $http = new FakeTransport([self::ok(['data' => [
            ['pwkey' => 'EfMQFV8kc7', 'username' => 'u1', 'time' => '0 0 * * *', 'command' => 'wget -O /dev/null https://a.com'],
            ['time' => '0 0 * * *', 'command' => 'sem pwkey não dá para excluir'],
            ['pwkey' => 'x', 'time' => null, 'command' => 'x'],
        ]])]);
        self::assertSame(
            [['id' => 'EfMQFV8kc7', 'time' => '0 0 * * *', 'command' => 'wget -O /dev/null https://a.com']],
            (new HostingerSource($http, 't', ''))->cronJobs('u1', 'd.com', 7)
        );
    }

    public function testCreateCronJobPostsTimeAndCommand(): void
    {
        $http = new FakeTransport([['status' => 201, 'body' => '{"data":[]}']]);
        (new HostingerSource($http, 't', ''))->createCronJob('u1', 'd.com', 7, '0 0 * * *', '/usr/bin/php /home/u1/public_html/x.php');

        self::assertSame('POST', $http->calls[0]['method']);
        self::assertStringContainsString('/accounts/u1/cron-jobs?', $http->calls[0]['url']);
        self::assertSame(['time' => '0 0 * * *', 'command' => '/usr/bin/php /home/u1/public_html/x.php'], json_decode((string) $http->calls[0]['body'], true));
    }

    public function testCreateCronJobSurfacesTheRefusalFromHostinger(): void
    {
        $http = new FakeTransport([['status' => 422, 'body' => '{"message":"The time format is invalid."}']]);
        $this->expectExceptionObject(ApiError::invalid('The time format is invalid.'));
        (new HostingerSource($http, 't', ''))->createCronJob('u1', 'd.com', 7, '99 * * * *', 'x');
    }

    public function testDeleteAndOutputAddressTheJobByPwkey(): void
    {
        $http = new FakeTransport([['status' => 204], self::ok(['data' => ['output' => 'ERROR 500']])]);
        $source = new HostingerSource($http, 't', '');
        $source->deleteCronJob('u1', 'd.com', 7, 'EfMQFV8kc7');

        self::assertSame('DELETE', $http->calls[0]['method']);
        self::assertStringContainsString('/cron-jobs/EfMQFV8kc7?', $http->calls[0]['url']);
        self::assertSame('ERROR 500', $source->cronJobOutput('u1', 'd.com', 7, 'EfMQFV8kc7'));
        self::assertStringContainsString('/cron-jobs/EfMQFV8kc7/output?', $http->calls[1]['url']);
    }

    public function testPhpVersionMergesAndSortsVersions(): void
    {
        $http = new FakeTransport([self::ok(['data' => [
            'version' => '8.1', 'versionFull' => '8.1.27',
            'olderVersions' => ['7.4' => 'PHP 7.4', '8.0' => 'PHP 8.0'],
            'newerVersions' => ['8.3' => 'PHP 8.3', '8.2' => 'PHP 8.2'],
        ]])]);
        $info = (new HostingerSource($http, 't', ''))->phpVersion('u', 'd.com', 1);

        self::assertSame('8.1', $info['current']);
        self::assertSame(['7.4', '8.0', '8.1', '8.2', '8.3'], array_column($info['versions'], 'version'));
    }

    public function testSetPhpVersionAcceptsEmptySuccessBody(): void
    {
        $http = new FakeTransport([['status' => 200, 'body' => '']]);
        (new HostingerSource($http, 't', ''))->setPhpVersion('u', 'd.com', 1, '8.3');
        self::assertCount(1, $http->calls);
        self::assertSame(['phpVersion' => '8.3'], json_decode((string) $http->calls[0]['body'], true));
    }

    public function testSetPhpVersionWithoutResponseSucceedsWhenVersionWasApplied(): void
    {
        $http = new FakeTransport([['status' => 0], self::ok(['data' => ['version' => '8.3']])]);
        (new HostingerSource($http, 't', ''))->setPhpVersion('u', 'd.com', 1, '8.3');
        self::assertSame('GET', $http->calls[1]['method']);
    }

    public function testSetPhpVersionWithoutResponseFailsWhenVersionDidNotChange(): void
    {
        $http = new FakeTransport([['status' => 0], self::ok(['data' => ['version' => '8.1']])]);
        $this->expectExceptionObject(ApiError::upstream());
        (new HostingerSource($http, 't', ''))->setPhpVersion('u', 'd.com', 1, '8.3');
    }

    public function testSetPhpVersion401IsSessionExpiredWithoutRechecking(): void
    {
        $http = new FakeTransport([['status' => 401]]);
        try {
            (new HostingerSource($http, 't', ''))->setPhpVersion('u', 'd.com', 1, '8.3');
            self::fail('esperava session_expired');
        } catch (ApiError $e) {
            self::assertSame('session_expired', $e->errorCode);
        }
        self::assertCount(1, $http->calls);
    }

    public function testGitReposFlattensAutoDeployInfoAndTrimsInstallPath(): void
    {
        $http = new FakeTransport([self::ok(['data' => [
            [
                'id' => 702822,
                'repoUrl' => 'git@github.com:acme/site.git',
                'branch' => 'review',
                'installPath' => '/',
                'autoDeployInfo' => ['webhookUrl' => 'https://webhooks.hostinger.com/deploy/abc', 'webhookProvider' => 'Github', 'webhookSetupUrl' => 'https://github.com/acme/site/settings/hooks/new'],
            ],
            ['id' => 702823, 'repoUrl' => 'git@github.com:acme/app.git', 'branch' => 'main', 'installPath' => 'app'],
        ]])]);
        $repos = (new HostingerSource($http, 't', ''))->gitRepos('u123', 'loja.com', 42);

        self::assertStringEndsWith('/vhosts/loja.com/git-repos?gaid=', $http->calls[0]['url']);
        self::assertSame('', $repos[0]['installPath']);
        self::assertSame('https://webhooks.hostinger.com/deploy/abc', $repos[0]['webhookUrl']);
        self::assertSame('app', $repos[1]['installPath']);
        self::assertNull($repos[1]['webhookUrl']);
    }

    public function testDeployUsesPutOnRepoId(): void
    {
        $http = new FakeTransport([['status' => 204, 'body' => '']]);
        (new HostingerSource($http, 't', ''))->deployGitRepo('u123', 'loja.com', 42, 702822);

        self::assertSame('PUT', $http->calls[0]['method']);
        self::assertStringContainsString('/git-repos/702822/deploy', $http->calls[0]['url']);
    }

    public function testCreateGitRepoSurfacesTheRefusalFromHostinger(): void
    {
        $http = new FakeTransport([['status' => 422, 'body' => '{"message":"Install path directory is not empty"}']]);
        try {
            (new HostingerSource($http, 't', ''))->createGitRepo('u123', 'loja.com', 42, 'git@github.com:acme/site.git', 'main', 'app');
            self::fail('esperava ApiError');
        } catch (ApiError $e) {
            self::assertSame('invalid_input', $e->errorCode);
            self::assertSame('Install path directory is not empty', $e->getMessage());
        }
        self::assertSame('{"repository":"git@github.com:acme\/site.git","branch":"main","directory":"app"}', $http->calls[0]['body']);
    }

    public function testCreateGitRepoHidesLongOrMissingUpstreamMessages(): void
    {
        foreach ([['status' => 400, 'body' => '{"message":"' . str_repeat('x', 201) . '"}'], ['status' => 400, 'body' => '{"trace":"interno"}']] as $resp) {
            try {
                (new HostingerSource(new FakeTransport([$resp]), 't', ''))->createGitRepo('u', 'd.com', 1, 'git@h:a/b.git', 'main', '');
                self::fail('esperava ApiError');
            } catch (ApiError $e) {
                self::assertStringStartsWith('A Hostinger recusou', $e->getMessage());
            }
        }
    }

    public function testGitRepoOutputReadsTheDeploymentLog(): void
    {
        $http = new FakeTransport([self::ok(['data' => ['output' => "Deployment start
Deployment finished"]])]);
        $out = (new HostingerSource($http, 't', ''))->gitRepoOutput('u123', 'loja.com', 42, 702822);

        self::assertStringContainsString('Deployment finished', $out);
        self::assertStringContainsString('/git-repos/702822/output', $http->calls[0]['url']);
    }

    public function testCreateGitKeyWhenKeyAlreadyExistsReturnsCurrentKey(): void
    {
        $http = new FakeTransport([
            ['status' => 422, 'body' => '{"message":"Key pair already exists","errorCode":9999}'],
            self::ok(['data' => ['publicKey' => 'ssh-rsa AAA']]),
        ]);
        self::assertSame('ssh-rsa AAA', (new HostingerSource($http, 't', ''))->createGitKey('u', 'd.com', 1));
        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame('{}', $http->calls[0]['body']);
        self::assertSame('GET', $http->calls[1]['method']);
    }

    public function testDeleteAcceptsEmptyBody(): void
    {
        $http = new FakeTransport([['status' => 204, 'body' => '']]);
        (new HostingerSource($http, 't', ''))->deleteGitKey('u', 'd.com', 1);
        self::assertSame('DELETE', $http->calls[0]['method']);
    }

    public function testDnsZoneGroupsContentsUnquotesTextAndReadsNameservers(): void
    {
        $http = new FakeTransport([
            self::ok(['data' => [
                ['name' => '@', 'type' => 'TXT', 'ttl' => 14400, 'records' => [['content' => '"v=spf1 ~all"']]],
                ['name' => '@', 'type' => 'MX', 'ttl' => 14400, 'records' => [['content' => '5 mx1.hostinger.com'], ['content' => '10 mx2.hostinger.com']]],
                ['name' => 'quebrado', 'type' => 'A'],
                ['type' => 'A', 'ttl' => 60, 'records' => [['content' => '203.0.113.1']]],
            ]]),
            self::ok(['data' => ['nameservers' => ['ns1.dns-parking.com', 'ns2.dns-parking.com', 42]]]),
        ]);
        $zone = (new HostingerSource($http, 't', ''))->dnsZone('loja.com');

        self::assertSame([
            ['name' => '@', 'type' => 'TXT', 'ttl' => 14400, 'contents' => ['v=spf1 ~all']],
            ['name' => '@', 'type' => 'MX', 'ttl' => 14400, 'contents' => ['5 mx1.hostinger.com', '10 mx2.hostinger.com']],
        ], $zone['records']);
        self::assertSame(['ns1.dns-parking.com', 'ns2.dns-parking.com'], $zone['nameservers']);
        self::assertStringContainsString('domain=loja.com', $http->calls[0]['url']);
    }

    public function testSaveDnsRecordsSendsOneResourceRecordSet(): void
    {
        $http = new FakeTransport([self::ok(['data' => null])]);
        (new HostingerSource($http, 't', ''))->saveDnsRecords('loja.com', 'www', 'A', 3600, ['203.0.113.1', '203.0.113.2'], true);

        self::assertSame('PATCH', $http->calls[0]['method']);
        self::assertSame([
            'domain' => 'loja.com',
            'resource_records' => [[
                'name' => 'www',
                'type' => 'A',
                'ttl' => 3600,
                'records' => [
                    ['content' => '203.0.113.1', 'disabled' => false],
                    ['content' => '203.0.113.2', 'disabled' => false],
                ],
            ]],
            'overwrite' => true,
        ], json_decode($http->calls[0]['body'], true));
    }

    public function testSaveDnsRecordsSurfacesTheRefusalFromHostinger(): void
    {
        $http = new FakeTransport([['status' => 422, 'body' => '{"message":"Invalid record content"}']]);
        try {
            (new HostingerSource($http, 't', ''))->saveDnsRecords('loja.com', '@', 'A', 3600, ['nao-e-ip'], false);
            self::fail('esperava ApiError');
        } catch (ApiError $e) {
            self::assertSame('invalid_input', $e->errorCode);
            self::assertSame('Invalid record content', $e->getMessage());
        }
    }

    public function testDeleteDnsRecordsFiltersByNameAndType(): void
    {
        $http = new FakeTransport([['status' => 204, 'body' => '']]);
        (new HostingerSource($http, 't', ''))->deleteDnsRecords('loja.com', 'www', 'CNAME');

        self::assertSame('DELETE', $http->calls[0]['method']);
        self::assertSame(
            ['domain' => 'loja.com', 'filters' => [['name' => 'www', 'type' => 'CNAME']]],
            json_decode($http->calls[0]['body'], true),
        );
    }
}
