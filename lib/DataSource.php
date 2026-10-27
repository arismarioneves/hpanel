<?php

declare(strict_types=1);

namespace HPanel;

/**
 * Fonte de dados do painel. Implementações lançam ApiError
 * (session_expired / upstream_unavailable) — nunca devolvem null para erro.
 */
interface DataSource
{
    /** @return list<array> recursos (servidores) com `websites` */
    public function servers(): array;

    public function account(string $username, string $domain, int $orderId): array;

    /** @return list<array> */
    public function databases(string $username, string $domain, int $orderId): array;

    public function phpMyAdminLink(string $username, string $db, string $domain, int $orderId): string;

    public function fileBrowserLink(string $username, string $domain, int $orderId): string;

    /** Gerenciador de arquivos na raiz da conta (todos os sites do servidor). */
    public function rootFileBrowserLink(string $username, string $domain, int $orderId): string;

    /**
     * Séries LVE do servidor (chaves de HostingerSource::METRICS presentes na resposta).
     * @return array<string, array{limit:float, points:list<array{0:int,1:float,2:int}>}> pontos = [unix, uso, faults]
     */
    public function metrics(string $username, string $domain, int $orderId, int $rangeMinutes, int $stepMinutes): array;

    /** @return array{protection:bool, status:?string, scanStatus:?string, lastScanEnd:?string, compromised:int, malicious:int} */
    public function malware(string $username, string $domain, int $orderId): array;

    /** @return list<array{id:string, time:string, command:string}> id = pwkey da Hostinger */
    public function cronJobs(string $username, string $domain, int $orderId): array;

    /** `time` = expressão de 5 campos (`0 0 * * *`). */
    public function createCronJob(string $username, string $domain, int $orderId, string $time, string $command): void;

    public function deleteCronJob(string $username, string $domain, int $orderId, string $id): void;

    /** Saída da última execução. */
    public function cronJobOutput(string $username, string $domain, int $orderId, string $id): string;

    /** @return array{current:?string, currentFull:?string, versions:list<array{version:string,label:string}>} */
    public function phpVersion(string $username, string $domain, int $orderId): array;

    public function setPhpVersion(string $username, string $domain, int $orderId, string $version): void;

    /** @return list<array{id:int, repoUrl:string, branch:string, installPath:string, webhookUrl:?string, webhookProvider:?string, webhookSetupUrl:?string}> */
    public function gitRepos(string $username, string $domain, int $orderId): array;

    public function createGitRepo(string $username, string $domain, int $orderId, string $repository, string $branch, string $directory): void;

    public function deleteGitRepo(string $username, string $domain, int $orderId, int $repoId): void;

    /** Dispara o deploy (git pull) do repositório. */
    public function deployGitRepo(string $username, string $domain, int $orderId, int $repoId): void;

    /** Saída do último deploy. */
    public function gitRepoOutput(string $username, string $domain, int $orderId, int $repoId): string;

    public function gitKey(string $username, string $domain, int $orderId): ?string;

    public function createGitKey(string $username, string $domain, int $orderId): string;

    public function deleteGitKey(string $username, string $domain, int $orderId): void;

    /**
     * Zona DNS do domínio. Cada item é um conjunto `name`+`type` com um ou mais conteúdos.
     * @return array{nameservers:list<string>, records:list<array{name:string, type:string, ttl:int, contents:list<string>}>}
     */
    public function dnsZone(string $domain): array;

    /**
     * Grava o conjunto `name`+`type` com os conteúdos informados.
     * `overwrite` substitui o conjunto existente (edição); sem ele a Hostinger acrescenta (criação).
     * @param list<string> $contents
     */
    public function saveDnsRecords(string $domain, string $name, string $type, int $ttl, array $contents, bool $overwrite): void;

    /** Remove o conjunto `name`+`type` inteiro. */
    public function deleteDnsRecords(string $domain, string $name, string $type): void;
}
