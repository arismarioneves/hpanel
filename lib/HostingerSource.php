<?php

declare(strict_types=1);

namespace HPanel;

/** APIs internas do hPanel. Único lugar que conhece URLs e headers da Hostinger. */
final class HostingerSource implements DataSource
{
    public const BASE = 'https://hpanel.hostinger.com';
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36';
    private const PAGE_SIZE = 25;
    private const MAX_PAGES = 50;
    private const KEY_EXISTS = 9999;
    /** Zona DNS do cliente: leitura, gravação (PATCH) e exclusão (DELETE) no mesmo caminho. */
    private const DNS_PATH = '/api/dns/v1/direct/zone/resource-records';
    /** Séries LVE (CloudLinux) devolvidas por metrics/lve, na ordem exibida. */
    public const METRICS = ['cpu', 'memory', 'ep', 'nproc', 'io', 'iops'];

    public function __construct(private HttpTransport $http, private string $token, private string $gaid)
    {
    }

    public function servers(): array
    {
        $all = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $resources = $this->json('GET', "/api/wh-api/api/hapi/v1/orders/websites?page={$page}&ownership=owned")['data']['resources'] ?? [];
            array_push($all, ...$resources);
            if (count($resources) < self::PAGE_SIZE) {
                break;
            }
        }
        return $all;
    }

    public function account(string $username, string $domain, int $orderId): array
    {
        return $this->json('GET', '/api/rest-hosting/v3/account', self::scope($username, $domain, $orderId))['data'] ?? [];
    }

    public function databases(string $username, string $domain, int $orderId): array
    {
        $path = self::accountPath($username) . '/databases?page=1&perPage=100&onlyAssigned=0&vhost=' . rawurlencode($domain);
        return $this->json('GET', $path, self::scope($username, $domain, $orderId))['data']['resources'] ?? [];
    }

    public function phpMyAdminLink(string $username, string $db, string $domain, int $orderId): string
    {
        $path = self::accountPath($username) . '/databases/' . rawurlencode($db) . '/phpmyadmin-link';
        return self::link($this->json('GET', $path, self::scope($username, $domain, $orderId)));
    }

    public function fileBrowserLink(string $username, string $domain, int $orderId): string
    {
        $path = self::accountPath($username) . '/file-browser-link?vhost=' . rawurlencode($domain) . '&locale=pt_BR';
        return self::link($this->json('GET', $path, self::scope($username, $domain, $orderId)));
    }

    public function rootFileBrowserLink(string $username, string $domain, int $orderId): string
    {
        // Sem `vhost` a Hostinger abre o gerenciador na raiz da conta (todos os sites).
        $path = self::accountPath($username) . '/file-browser-link?locale=pt_BR';
        return self::link($this->json('GET', $path, self::scope($username, $domain, $orderId)));
    }

    public function metrics(string $username, string $domain, int $orderId, int $rangeMinutes, int $stepMinutes): array
    {
        $path = self::accountPath($username) . "/metrics/lve?rangeMinutes={$rangeMinutes}&stepSizeMinutes={$stepMinutes}";
        $data = $this->json('GET', $path, self::scope($username, $domain, $orderId))['data'] ?? [];
        $series = [];
        foreach (self::METRICS as $key) {
            $m = $data[$key] ?? null;
            if (!is_array($m) || !is_array($m['datapoints'] ?? null)) {
                continue;
            }
            $points = [];
            foreach ($m['datapoints'] as $p) {
                if (is_array($p) && isset($p['timestamp'])) {
                    $points[] = [(int) $p['timestamp'], (float) ($p['usage'] ?? 0), (int) ($p['faults'] ?? 0)];
                }
            }
            $series[$key] = ['limit' => (float) ($m['limit'] ?? 0), 'points' => $points];
        }
        // O balde mais recente ainda não foi agregado e chega zerado em todas as séries: descartar.
        while ($series !== [] && self::lastBucketEmpty($series)) {
            foreach ($series as &$s) {
                array_pop($s['points']);
            }
            unset($s);
        }
        return $series;
    }

    private static function lastBucketEmpty(array $series): bool
    {
        foreach ($series as $s) {
            $last = end($s['points']);
            if ($last === false || $last[1] > 0) {
                return false;
            }
        }
        return true;
    }

    public function malware(string $username, string $domain, int $orderId): array
    {
        $d = $this->json('GET', self::accountPath($username) . '/malware/overview', self::scope($username, $domain, $orderId))['data'] ?? [];
        return [
            'protection' => ($d['activeProtection'] ?? null) === 'enabled',
            'status' => self::str($d['lastReportedStatus'] ?? null),
            'scanStatus' => self::str($d['scanStatus'] ?? null),
            'lastScanEnd' => self::str($d['lastScanEnd'] ?? null),
            'compromised' => (int) ($d['compromisedNonRemediated'] ?? $d['compromised'] ?? 0),
            'malicious' => (int) ($d['maliciousNonRemediated'] ?? $d['malicious'] ?? 0),
        ];
    }

    public function cronJobs(string $username, string $domain, int $orderId): array
    {
        $data = $this->json('GET', self::cronPath($username), self::scope($username, $domain, $orderId))['data'] ?? [];
        $jobs = [];
        foreach (is_array($data) ? $data : [] as $job) {
            if (is_array($job) && is_string($job['pwkey'] ?? null) && is_string($job['time'] ?? null) && is_string($job['command'] ?? null)) {
                $jobs[] = ['id' => $job['pwkey'], 'time' => $job['time'], 'command' => $job['command']];
            }
        }
        return $jobs;
    }

    public function createCronJob(string $username, string $domain, int $orderId, string $time, string $command): void
    {
        $r = $this->raw('POST', self::cronPath($username), self::scope($username, $domain, $orderId), ['time' => $time, 'command' => $command]);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return;
        }
        // 4xx (exceto 401) é recusa da própria Hostinger (horário/comando inválido): a mensagem ajuda o usuário.
        if ($r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 401) {
            throw ApiError::invalid(self::reason($r['json']) ?? 'A Hostinger recusou essa tarefa cron. Confira o horário e o comando.');
        }
        $this->fail($r['status'], 'POST cron-jobs');
    }

    public function deleteCronJob(string $username, string $domain, int $orderId, string $id): void
    {
        $this->json('DELETE', self::cronPath($username) . '/' . rawurlencode($id), self::scope($username, $domain, $orderId));
    }

    public function cronJobOutput(string $username, string $domain, int $orderId, string $id): string
    {
        $path = self::cronPath($username) . '/' . rawurlencode($id) . '/output';
        return (string) ($this->json('GET', $path, self::scope($username, $domain, $orderId))['data']['output'] ?? '');
    }

    public function phpVersion(string $username, string $domain, int $orderId): array
    {
        $d = $this->json('GET', self::phpPath($username, $domain), self::scope($username, $domain, $orderId))['data'] ?? [];
        $labels = [];
        foreach ([$d['olderVersions'] ?? [], $d['newerVersions'] ?? []] as $group) {
            foreach (is_array($group) ? $group : [] as $version => $label) {
                $labels[(string) $version] = (string) $label;
            }
        }
        $current = isset($d['version']) ? (string) $d['version'] : null;
        if ($current !== null) {
            $labels[$current] = 'PHP ' . $current;
        }
        uksort($labels, 'version_compare');
        $versions = [];
        foreach ($labels as $version => $label) {
            $versions[] = ['version' => (string) $version, 'label' => $label];
        }
        return ['current' => $current, 'currentFull' => isset($d['versionFull']) ? (string) $d['versionFull'] : null, 'versions' => $versions];
    }

    public function setPhpVersion(string $username, string $domain, int $orderId, string $version): void
    {
        $r = $this->raw('PATCH', self::phpPath($username, $domain), self::scope($username, $domain, $orderId), ['phpVersion' => $version]);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return; // corpo vazio também é sucesso
        }
        if ($r['status'] === 401) {
            $this->fail(401, 'PATCH php/version');
        }
        // A Hostinger aplica a troca mas costuma segurar a resposta até estourar o tempo (ou devolver erro):
        // a versão atual é quem diz se deu certo.
        if ($this->phpVersion($username, $domain, $orderId)['current'] === $version) {
            return;
        }
        $this->fail($r['status'], 'PATCH php/version');
    }

    public function gitRepos(string $username, string $domain, int $orderId): array
    {
        $data = $this->json('GET', self::gitPath($username, $domain), self::scope($username, $domain, $orderId))['data'] ?? [];
        $repos = [];
        foreach (is_array($data) ? $data : [] as $repo) {
            if (!is_array($repo)) {
                continue;
            }
            $auto = is_array($repo['autoDeployInfo'] ?? null) ? $repo['autoDeployInfo'] : [];
            $repos[] = [
                'id' => (int) ($repo['id'] ?? 0),
                'repoUrl' => (string) ($repo['repoUrl'] ?? ''),
                'branch' => (string) ($repo['branch'] ?? ''),
                'installPath' => trim((string) ($repo['installPath'] ?? ''), '/'),
                'webhookUrl' => self::str($auto['webhookUrl'] ?? null),
                'webhookProvider' => self::str($auto['webhookProvider'] ?? null),
                'webhookSetupUrl' => self::str($auto['webhookSetupUrl'] ?? null),
            ];
        }
        return $repos;
    }

    public function createGitRepo(string $username, string $domain, int $orderId, string $repository, string $branch, string $directory): void
    {
        $r = $this->raw('POST', self::gitPath($username, $domain), self::scope($username, $domain, $orderId), [
            'repository' => $repository,
            'branch' => $branch,
            'directory' => $directory,
        ]);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return;
        }
        // 4xx aqui é recusa do próprio Git (repositório inacessível, pasta não vazia): a mensagem ajuda o usuário.
        if ($r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 401) {
            throw ApiError::invalid(self::reason($r['json']) ?? 'A Hostinger recusou esse repositório. Confira a URL, a branch e se a pasta de destino está vazia.');
        }
        $this->fail($r['status'], 'POST git-repos');
    }

    public function deleteGitRepo(string $username, string $domain, int $orderId, int $repoId): void
    {
        $this->json('DELETE', self::gitPath($username, $domain) . '/' . $repoId, self::scope($username, $domain, $orderId));
    }

    public function deployGitRepo(string $username, string $domain, int $orderId, int $repoId): void
    {
        $r = $this->raw('PUT', self::gitPath($username, $domain) . '/' . $repoId . '/deploy', self::scope($username, $domain, $orderId));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $this->fail($r['status'], 'PUT git-repos deploy');
        }
    }

    public function gitRepoOutput(string $username, string $domain, int $orderId, int $repoId): string
    {
        $path = self::gitPath($username, $domain) . '/' . $repoId . '/output';
        return (string) ($this->json('GET', $path, self::scope($username, $domain, $orderId))['data']['output'] ?? '');
    }

    public function gitKey(string $username, string $domain, int $orderId): ?string
    {
        $key = $this->json('GET', self::accountPath($username) . '/git-key', self::scope($username, $domain, $orderId))['data']['publicKey'] ?? null;
        return is_string($key) && $key !== '' ? $key : null;
    }

    public function createGitKey(string $username, string $domain, int $orderId): string
    {
        $r = $this->raw('POST', self::accountPath($username) . '/git-key', self::scope($username, $domain, $orderId));
        $key = $r['json']['data']['publicKey'] ?? null;
        if ($r['status'] >= 200 && $r['status'] < 300 && is_string($key) && $key !== '') {
            return $key;
        }
        if (($r['json']['errorCode'] ?? null) === self::KEY_EXISTS) {
            return $this->gitKey($username, $domain, $orderId) ?? throw ApiError::upstream();
        }
        $this->fail($r['status'], 'POST git-key');
    }

    public function deleteGitKey(string $username, string $domain, int $orderId): void
    {
        $this->json('DELETE', self::accountPath($username) . '/git-key', self::scope($username, $domain, $orderId));
    }

    public function dnsZone(string $domain): array
    {
        $query = '?domain=' . rawurlencode($domain);
        $data = $this->json('GET', self::DNS_PATH . $query)['data'] ?? [];
        $records = [];
        foreach (is_array($data) ? $data : [] as $set) {
            if (!is_array($set) || !is_string($set['name'] ?? null) || !is_string($set['type'] ?? null)) {
                continue;
            }
            $contents = [];
            foreach (is_array($set['records'] ?? null) ? $set['records'] : [] as $record) {
                if (is_array($record) && is_string($record['content'] ?? null)) {
                    $contents[] = self::unquote($record['content']);
                }
            }
            if ($contents !== []) {
                $records[] = ['name' => $set['name'], 'type' => $set['type'], 'ttl' => (int) ($set['ttl'] ?? 0), 'contents' => $contents];
            }
        }
        $ns = $this->json('GET', '/api/dns/v1/direct/nameservers' . $query)['data']['nameservers'] ?? [];
        return [
            'nameservers' => array_values(array_filter(is_array($ns) ? $ns : [], 'is_string')),
            'records' => $records,
        ];
    }

    public function saveDnsRecords(string $domain, string $name, string $type, int $ttl, array $contents, bool $overwrite): void
    {
        $r = $this->raw('PATCH', self::DNS_PATH, [], [
            'domain' => $domain,
            'resource_records' => [[
                'name' => $name,
                'type' => $type,
                'ttl' => $ttl,
                'records' => array_map(static fn(string $c): array => ['content' => $c, 'disabled' => false], array_values($contents)),
            ]],
            'overwrite' => $overwrite,
        ]);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            return;
        }
        // 4xx aqui é recusa da própria zona (conteúdo fora do formato do tipo, conflito com outro registro).
        if ($r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 401) {
            throw ApiError::invalid(self::reason($r['json']) ?? 'A Hostinger recusou esse registro. Confira o tipo, o nome e o conteúdo.');
        }
        $this->fail($r['status'], 'PATCH dns zone');
    }

    public function deleteDnsRecords(string $domain, string $name, string $type): void
    {
        $this->json('DELETE', self::DNS_PATH, [], ['domain' => $domain, 'filters' => [['name' => $name, 'type' => $type]]]);
    }

    /** A Hostinger devolve TXT/SPF entre aspas e aceita a gravação sem elas; o painel trabalha sempre sem. */
    private static function unquote(string $content): string
    {
        $inner = strlen($content) >= 2 && str_starts_with($content, '"') && str_ends_with($content, '"')
            ? substr($content, 1, -1)
            : null;
        return $inner !== null && !str_contains($inner, '"') ? $inner : $content;
    }

    /** @return array{status:int, json:?array} */
    private function raw(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $base = [
            'accept: application/json;charset=utf-8',
            'accept-language: pt-BR,pt;q=0.9,en;q=0.8',
            'referer: ' . self::BASE . '/',
            'user-agent: ' . self::UA,
        ];
        if ($method !== 'GET') {
            $base[] = 'content-type: application/json';
            $base[] = 'origin: ' . self::BASE;
        }
        $url = self::BASE . $path . (str_contains($path, '?') ? '&' : '?') . 'gaid=' . rawurlencode($this->gaid);
        $payload = $method === 'GET' ? null : json_encode($body ?? new \stdClass());
        $r = $this->http->send($method, $url, array_merge($base, $headers), 'jwt=' . $this->token . '; language=pt_BR', $payload);
        $json = $r['body'] !== null && $r['body'] !== '' ? json_decode($r['body'], true) : null;
        return ['status' => $r['status'], 'json' => is_array($json) ? $json : null];
    }

    private function json(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $r = $this->raw($method, $path, $headers, $body);
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        if ($ok && ($r['json'] !== null || $method === 'DELETE')) {
            return $r['json'] ?? [];
        }
        $this->fail($ok ? 502 : $r['status'], "{$method} {$path}");
    }

    private function fail(int $status, string $what): never
    {
        if ($status === 401) {
            throw ApiError::sessionExpired();
        }
        error_log("HPanel upstream {$what}: HTTP {$status}");
        throw ApiError::upstream();
    }

    private static function link(array $json): string
    {
        $link = $json['data']['link'] ?? null;
        if (is_string($link) && str_starts_with($link, 'https://')) {
            return $link;
        }
        throw ApiError::upstream();
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Mensagem de recusa da Hostinger, saneada para exibição. */
    private static function reason(?array $json): ?string
    {
        $msg = is_array($json) ? ($json['message'] ?? null) : null;
        if (!is_string($msg)) {
            return null;
        }
        $msg = trim(preg_replace('/\s+/u', ' ', $msg) ?? '');
        return $msg !== '' && mb_strlen($msg) <= 200 ? $msg : null;
    }

    private static function accountPath(string $username): string
    {
        return '/api/wh-api/api/hapi/v1/accounts/' . rawurlencode($username);
    }

    private static function phpPath(string $username, string $domain): string
    {
        return self::accountPath($username) . '/vhosts/' . rawurlencode($domain) . '/php/version';
    }

    private static function gitPath(string $username, string $domain): string
    {
        return self::accountPath($username) . '/vhosts/' . rawurlencode($domain) . '/git-repos';
    }

    private static function cronPath(string $username): string
    {
        return self::accountPath($username) . '/cron-jobs';
    }

    /** @return list<string> */
    private static function scope(string $username, string $domain, int $orderId): array
    {
        return ["x-hpanel-order-id: {$orderId}", "x-hpanel-username: {$username}", "x-hpanel-domain: {$domain}"];
    }
}
