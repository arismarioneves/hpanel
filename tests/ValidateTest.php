<?php

declare(strict_types=1);

namespace HPanel\Tests;

use HPanel\ApiError;
use HPanel\Tests\Support\TestJwt;
use HPanel\Validate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase
{
    public function testOrderIdAcceptsPositiveIntegers(): void
    {
        self::assertSame(123, Validate::orderId('123'));
        self::assertSame(7, Validate::orderId(7));
    }

    #[DataProvider('badOrderIds')]
    public function testOrderIdRejects(mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::orderId($v);
    }

    public static function badOrderIds(): array
    {
        return [['0'], ['-1'], ['1e3'], ['12a'], [''], [null], [[1]], [1.5]];
    }

    public function testDomainAcceptsRealWorldNamesAndLowercases(): void
    {
        self::assertSame('loja.exemplo.com.br', Validate::domain('Loja.Exemplo.com.br'));
        self::assertSame('xn--aco-tla.com', Validate::domain('xn--aco-tla.com'));
        self::assertSame('a-b.co', Validate::domain('a-b.co'));
    }

    #[DataProvider('badDomains')]
    public function testDomainRejects(mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::domain($v);
    }

    public static function badDomains(): array
    {
        return [['exemplo'], ['a..b.com'], ['-a.com'], ['a-.com'], ['exa mple.com'], ['javascript:alert(1)'], ['../etc/passwd'], ['a.com/x'], [null]];
    }

    public function testDbNameAndPhpVersion(): void
    {
        self::assertSame('u123_loja', Validate::dbName('u123_loja'));
        self::assertSame('8.2', Validate::phpVersion('8.2'));
        $this->expectException(ApiError::class);
        Validate::dbName('loja`; DROP');
    }

    public function testPhpVersionRejectsGarbage(): void
    {
        $this->expectException(ApiError::class);
        Validate::phpVersion('8.2; rm');
    }

    public function testDbNameRejectsTrailingNewline(): void
    {
        $this->expectException(ApiError::class);
        Validate::dbName("u1_db\n");
    }

    public function testPhpVersionRejectsTrailingNewline(): void
    {
        $this->expectException(ApiError::class);
        Validate::phpVersion("8.2\n");
    }

    public function testRepoUrlAcceptsHttpsAndScpSyntax(): void
    {
        self::assertSame('https://github.com/acme/site.git', Validate::repoUrl(' https://github.com/acme/site.git '));
        self::assertSame('git@github.com:acme/site.git', Validate::repoUrl('git@github.com:acme/site.git'));
        self::assertSame('ssh://git@gitlab.com/acme/site.git', Validate::repoUrl('ssh://git@gitlab.com/acme/site.git'));
    }

    #[DataProvider('badRepoUrls')]
    public function testRepoUrlRejects(mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::repoUrl($v);
    }

    public static function badRepoUrls(): array
    {
        return [[''], [null], ['github.com/acme/site.git'], ['http://github.com/acme/site.git'], ['git@github.com'], ["git@github.com:acme/\nsite.git"], [str_repeat('a', 513)]];
    }

    public function testBranchAndDirectory(): void
    {
        self::assertSame('feature/deploy-v2', Validate::branch(' feature/deploy-v2 '));
        self::assertSame('', Validate::directory(''));
        self::assertSame('', Validate::directory('/'));
        self::assertSame('app/publico', Validate::directory('/app/publico/'));
    }

    #[DataProvider('badPaths')]
    public function testBranchAndDirectoryRejectTraversalAndGarbage(string $method, mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::$method($v);
    }

    public static function badPaths(): array
    {
        return [
            ['branch', ''],
            ['branch', null],
            ['branch', '-rf'],
            ['branch', 'main; rm'],
            ['branch', '../main'],
            ['branch', "ma\nin"],
            ['directory', '../public_html'],
            ['directory', 'app publico'],
            ['directory', 'app;rm'],
        ];
    }

    public function testRepoIdRejectsZeroAndGarbage(): void
    {
        self::assertSame(702822, Validate::repoId('702822'));
        $this->expectException(ApiError::class);
        Validate::repoId('0');
    }

    public function testCronTimeNormalizesSpacingAndAcceptsSteps(): void
    {
        self::assertSame('*/5 * * * *', Validate::cronTime(" */5  *\t* * * "));
        self::assertSame('0 0,12 1-15 * 1-5', Validate::cronTime('0 0,12 1-15 * 1-5'));
    }

    #[DataProvider('badCron')]
    public function testCronInputsRejectGarbage(string $method, mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::$method($v);
    }

    public static function badCron(): array
    {
        return [
            ['cronTime', '0 0 * *'],
            ['cronTime', '0 0 * * * *'],
            ['cronTime', '@daily'],
            ['cronTime', '0 0 * * *; rm -rf ~'],
            ['cronTime', null],
            ['cronCommand', ''],
            ['cronCommand', '   '],
            ['cronCommand', "wget https://a.com\nrm -rf ~"],
            ['cronCommand', str_repeat('a', 1001)],
            ['cronCommand', ['x']],
            ['cronId', '../x'],
            ['cronId', ''],
            ['cronId', 7],
        ];
    }

    public function testDbSuffixFollowsHpanelRules(): void
    {
        self::assertSame('loja_2', Validate::dbSuffix('loja_2', 'Nome'));
        self::assertSame(str_repeat('a', 14), Validate::dbSuffix(str_repeat('a', 14), 'Nome'));
    }

    #[DataProvider('badDbInput')]
    public function testDbInputsRejectGarbage(string $method, mixed $v): void
    {
        $this->expectException(ApiError::class);
        $method === 'dbSuffix' ? Validate::dbSuffix($v, 'Nome') : Validate::dbPassword($v);
    }

    public static function badDbInput(): array
    {
        return [
            ['dbSuffix', ''],
            ['dbSuffix', '_loja'],
            ['dbSuffix', str_repeat('a', 15)],
            ['dbSuffix', 'loja-1'],
            ['dbSuffix', "loja\n"],
            ['dbSuffix', ' loja '],
            ['dbSuffix', 'u1_loja;drop'],
            ['dbSuffix', 7],
            ['dbPassword', 'Curta1'],
            ['dbPassword', 'semmaiuscula1'],
            ['dbPassword', 'SEMMINUSCULA1'],
            ['dbPassword', 'SemNumeroAqui'],
            ['dbPassword', "Quebra\nLinha1"],
            ['dbPassword', 'Aa1' . str_repeat('x', 126)],
            ['dbPassword', null],
        ];
    }

    public function testAccountDbRequiresTheAccountPrefix(): void
    {
        self::assertSame('u1_loja', Validate::accountDb('u1_loja', 'u1'));
        $this->expectException(ApiError::class);
        Validate::accountDb('u2_loja', 'u1');
    }

    public function testDbPermissionsKeepKnownPrivilegesInCanonicalOrder(): void
    {
        self::assertSame(['Insert', 'Select'], Validate::dbPermissions(['Select', 'Insert']));
        self::assertSame([], Validate::dbPermissions([]));
        foreach ([['Foo'], ['select'], ['a' => 'Select'], 'Select', null] as $bad) {
            try {
                Validate::dbPermissions($bad);
                self::fail('esperava ApiError');
            } catch (ApiError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRemoteIpAcceptsIpv4Ipv6AndAnyHost(): void
    {
        self::assertSame('203.0.113.10', Validate::remoteIp(' 203.0.113.10 '));
        self::assertSame('2001:db8::1', Validate::remoteIp('2001:db8::1'));
        self::assertSame('%', Validate::remoteIp('%'));
        $this->expectException(ApiError::class);
        Validate::remoteIp('203.0.113.%');
    }

    public function testDbPasswordKeepsSpacesAndSymbols(): void
    {
        self::assertSame(' Senha #1 ', Validate::dbPassword(' Senha #1 '));
    }

    public function testJwtTrimsAndRejectsNonTokens(): void
    {
        $t = TestJwt::make(2000000000);
        self::assertSame($t, Validate::jwt("  {$t}\n"));
        $this->expectException(ApiError::class);
        Validate::jwt('jwt=' . $t);
    }

    public function testDnsTypeUppercasesKnownTypes(): void
    {
        self::assertSame('CNAME', Validate::dnsType('cname'));
        $this->expectException(ApiError::class);
        Validate::dnsType('ALIAS');
    }

    public function testDnsNameAcceptsRootWildcardAndUnderscoreLabels(): void
    {
        self::assertSame('@', Validate::dnsName(''));
        self::assertSame('@', Validate::dnsName(' @ '));
        self::assertSame('_dmarc', Validate::dnsName('_dmarc'));
        self::assertSame('s1._domainkey', Validate::dnsName('s1._domainkey'));
        self::assertSame('*', Validate::dnsName('*'));
    }

    #[DataProvider('badDnsNames')]
    public function testDnsNameRejects(mixed $v): void
    {
        $this->expectException(ApiError::class);
        Validate::dnsName($v);
    }

    public static function badDnsNames(): array
    {
        return [['www..a'], ['www a'], ['www/@'], [str_repeat('a', 64)], [str_repeat('a.', 200) . 'a'], [null], [['www']]];
    }

    public function testDnsTtlStaysInsideTheRangeHostingerAccepts(): void
    {
        self::assertSame(60, Validate::dnsTtl('60'));
        self::assertSame(86400, Validate::dnsTtl(86400));
        foreach (['59', 86401, '', null, '1.5'] as $bad) {
            try {
                Validate::dnsTtl($bad);
                self::fail('esperava ApiError');
            } catch (ApiError $e) {
                self::assertSame('invalid_input', $e->errorCode);
            }
        }
    }

    public function testDnsContentsTrimsAndRejectsEmptyOrControlCharacters(): void
    {
        self::assertSame(['203.0.113.1', '203.0.113.2'], Validate::dnsContents([' 203.0.113.1 ', '203.0.113.2']));
        self::assertSame(['v=spf1 ~all'], Validate::dnsContents('v=spf1 ~all'));
        foreach ([[], [''], ["linha\nquebrada"], [str_repeat('x', 2049)], array_fill(0, 51, 'x')] as $bad) {
            try {
                Validate::dnsContents($bad);
                self::fail('esperava ApiError');
            } catch (ApiError $e) {
                self::assertSame('invalid_input', $e->errorCode);
            }
        }
    }
}
