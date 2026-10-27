<?php

declare(strict_types=1);

namespace HPanel;

/** Dados fictícios (lib/demo/fixtures.json) para o modo demonstração. Nunca acessa a rede. */
final class DemoSource implements DataSource
{
    public const BLOCKED = 'Indisponível no modo demonstração.';

    /** limite, uso típico (fração do limite), pico (fração) por série LVE. */
    private const METRIC_SHAPES = [
        'cpu' => [100, 0.08, 0.7],
        'memory' => [4096, 0.12, 0.55],
        'ep' => [50, 0.1, 0.6],
        'nproc' => [100, 0.08, 0.5],
        'io' => [20480, 0.01, 0.4],
        'iops' => [1024, 0.02, 0.35],
    ];

    private array $fx;

    public function __construct()
    {
        $json = file_get_contents(__DIR__ . '/demo/fixtures.json');
        $this->fx = json_decode($json === false ? '' : $json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function servers(): array
    {
        return $this->fx['servers'];
    }

    public function account(string $username, string $domain, int $orderId): array
    {
        return $this->fx['accounts'][(string) $orderId] ?? throw ApiError::notFound('Servidor não encontrado nesta conta.');
    }

    public function databases(string $username, string $domain, int $orderId): array
    {
        return $this->fx['databases'][(string) $orderId] ?? throw ApiError::notFound('Servidor não encontrado nesta conta.');
    }

    public function phpMyAdminLink(string $username, string $db, string $domain, int $orderId): string
    {
        throw self::blocked();
    }

    public function fileBrowserLink(string $username, string $domain, int $orderId): string
    {
        throw self::blocked();
    }

    public function rootFileBrowserLink(string $username, string $domain, int $orderId): string
    {
        throw self::blocked();
    }

    /** Séries sintéticas e determinísticas (mesma entrada, mesmo gráfico), com picos e alguns limites atingidos. */
    public function metrics(string $username, string $domain, int $orderId, int $rangeMinutes, int $stepMinutes): array
    {
        $this->account($username, $domain, $orderId);
        $step = max(1, $stepMinutes) * 60;
        $end = intdiv(time(), $step) * $step;
        $n = intdiv($rangeMinutes, max(1, $stepMinutes)) + 1;
        $series = [];
        foreach (self::METRIC_SHAPES as $key => [$limit, $base, $peak]) {
            $points = [];
            for ($i = 0; $i < $n; $i++) {
                $t = $end - ($n - 1 - $i) * $step;
                $seed = crc32("{$orderId}:{$key}:{$t}");
                $wave = 0.5 + 0.5 * sin($t / 21600 * M_PI); // ciclo diário
                $value = $limit * ($base * (0.6 + 0.8 * $wave) + (($seed % 100) / 100) * $base * 0.6);
                $fault = $seed % 401 === 0;
                if ($fault || $seed % 23 === 0) {
                    $value = $limit * ($fault ? 1 : $peak);
                }
                $points[] = [$t, round(min($value, $limit), 1), $fault ? 1 + $seed % 3 : 0];
            }
            $series[$key] = ['limit' => (float) $limit, 'points' => $points];
        }
        return $series;
    }

    public function malware(string $username, string $domain, int $orderId): array
    {
        return $this->fx['malware'][(string) $orderId] ?? throw ApiError::notFound('Servidor não encontrado nesta conta.');
    }

    public function cronJobs(string $username, string $domain, int $orderId): array
    {
        return $this->fx['cronJobs'][(string) $orderId] ?? [];
    }

    public function createCronJob(string $username, string $domain, int $orderId, string $time, string $command): void
    {
        throw self::blocked();
    }

    public function deleteCronJob(string $username, string $domain, int $orderId, string $id): void
    {
        throw self::blocked();
    }

    public function cronJobOutput(string $username, string $domain, int $orderId, string $id): string
    {
        return $this->fx['cronOutput'][$id] ?? '';
    }

    public function phpVersion(string $username, string $domain, int $orderId): array
    {
        return $this->fx['phpVersion'];
    }

    public function setPhpVersion(string $username, string $domain, int $orderId, string $version): void
    {
        throw self::blocked();
    }

    public function gitRepos(string $username, string $domain, int $orderId): array
    {
        return $this->fx['gitRepos'][$domain] ?? [];
    }

    public function createGitRepo(string $username, string $domain, int $orderId, string $repository, string $branch, string $directory): void
    {
        throw self::blocked();
    }

    public function deleteGitRepo(string $username, string $domain, int $orderId, int $repoId): void
    {
        throw self::blocked();
    }

    public function deployGitRepo(string $username, string $domain, int $orderId, int $repoId): void
    {
        throw self::blocked();
    }

    public function gitRepoOutput(string $username, string $domain, int $orderId, int $repoId): string
    {
        return $this->fx['gitOutput'];
    }

    public function gitKey(string $username, string $domain, int $orderId): ?string
    {
        return $this->fx['gitKey'];
    }

    public function createGitKey(string $username, string $domain, int $orderId): string
    {
        throw self::blocked();
    }

    public function deleteGitKey(string $username, string $domain, int $orderId): void
    {
        throw self::blocked();
    }

    public function dnsZone(string $domain): array
    {
        return $this->fx['dnsZone'][$domain] ?? ['nameservers' => [], 'records' => []];
    }

    public function saveDnsRecords(string $domain, string $name, string $type, int $ttl, array $contents, bool $overwrite): void
    {
        throw self::blocked();
    }

    public function deleteDnsRecords(string $domain, string $name, string $type): void
    {
        throw self::blocked();
    }

    public static function blocked(): ApiError
    {
        return ApiError::forbidden(self::BLOCKED);
    }
}
