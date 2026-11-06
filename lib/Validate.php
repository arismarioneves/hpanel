<?php

declare(strict_types=1);

namespace HPanel;

final class Validate
{
    private const DOMAIN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/D';

    public static function orderId(mixed $v): int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return (int) $v;
        }
        throw ApiError::invalid('Servidor inválido.');
    }

    public static function domain(mixed $v): string
    {
        $d = is_string($v) ? strtolower(trim($v)) : '';
        if (preg_match(self::DOMAIN, $d) === 1) {
            return $d;
        }
        throw ApiError::invalid('Domínio inválido.');
    }

    public static function dbName(mixed $v): string
    {
        if (is_string($v) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $v) === 1) {
            return $v;
        }
        throw ApiError::invalid('Banco de dados inválido.');
    }

    /** Banco da própria conta: o nome precisa começar com o prefixo `<username>_`. */
    public static function accountDb(mixed $v, string $username): string
    {
        $db = self::dbName($v);
        if ($username !== '' && str_starts_with($db, $username . '_')) {
            return $db;
        }
        throw ApiError::invalid('Banco de dados inválido.');
    }

    /** Privilégios MySQL que o hPanel controla por usuário de banco. */
    public const DB_PRIVILEGES = [
        'Alter', 'Alter routine', 'Create', 'Create routine', 'Create temporary tables', 'Create view',
        'Delete', 'Drop', 'Event', 'Execute', 'Index', 'Insert', 'Lock tables', 'References',
        'Select', 'Show view', 'Trigger', 'Update',
    ];

    /**
     * Privilégios concedidos (os ausentes são revogados). Nome fora da lista é recusado:
     * a Hostinger aceita chave desconhecida e revoga tudo.
     * @return list<string>
     */
    public static function dbPermissions(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw ApiError::invalid('Permissões inválidas.');
        }
        foreach ($v as $p) {
            if (!is_string($p) || !in_array($p, self::DB_PRIVILEGES, true)) {
                throw ApiError::invalid('Permissões inválidas.');
            }
        }
        return array_values(array_intersect(self::DB_PRIVILEGES, $v));
    }

    /** IPv4/IPv6 de quem acessa o MySQL de fora, ou `%` para qualquer host. */
    public static function remoteIp(mixed $v): string
    {
        $ip = is_string($v) ? trim($v) : '';
        if ($ip === '%' || filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
        throw ApiError::invalid('IP inválido. Informe um IPv4 ou IPv6, como 203.0.113.10, ou marque "Qualquer host".');
    }

    /**
     * Parte do nome (banco ou usuário MySQL) digitada após o prefixo `<username>_` da conta.
     * Mesmas regras do formulário do hPanel: até 14 caracteres, letras, números e `_`, sem começar com `_`.
     */
    public static function dbSuffix(mixed $v, string $what): string
    {
        if (is_string($v) && preg_match('/^(?!_)[A-Za-z0-9_]{1,14}$/D', $v) === 1) {
            return $v;
        }
        throw ApiError::invalid("{$what} inválido. Use até 14 letras, números ou _ (sem começar com _).");
    }

    /** Senha do usuário MySQL: 8+ caracteres com minúscula, maiúscula e número (exigência do hPanel). */
    public static function dbPassword(#[\SensitiveParameter] mixed $v): string
    {
        $p = is_string($v) ? $v : '';
        $ok = strlen($p) >= 8 && strlen($p) <= 128
            && preg_match('/[a-z]/', $p) === 1 && preg_match('/[A-Z]/', $p) === 1 && preg_match('/\d/', $p) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $p) === 0;
        if ($ok) {
            return $p;
        }
        throw ApiError::invalid('Senha inválida. Use de 8 a 128 caracteres, com letra minúscula, maiúscula e número.');
    }

    public static function repoId(mixed $v): int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v) && (int) $v > 0) {
            return (int) $v;
        }
        throw ApiError::invalid('Repositório inválido.');
    }

    /** `https://host/user/repo.git` ou `git@host:user/repo.git` (o hPanel aceita os dois). */
    public static function repoUrl(mixed $v): string
    {
        $u = is_string($v) ? trim($v) : '';
        $ok = $u !== '' && strlen($u) <= 512
            && preg_match('#^(https://[A-Za-z0-9.-]+(?::\d+)?/\S+|(?:ssh://)?[A-Za-z0-9._-]+@[A-Za-z0-9.-]+[:/]\S+)$#D', $u) === 1;
        if ($ok) {
            return $u;
        }
        throw ApiError::invalid('URL do repositório inválida. Use https://github.com/usuario/repo.git ou git@github.com:usuario/repo.git.');
    }

    public static function branch(mixed $v): string
    {
        $b = is_string($v) ? trim($v) : '';
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$#D', $b) === 1 && !str_contains($b, '..')) {
            return $b;
        }
        throw ApiError::invalid('Branch inválida.');
    }

    /** Pasta de destino relativa ao domínio; vazia = `public_html`. */
    public static function directory(mixed $v): string
    {
        $d = trim(is_string($v) ? trim($v) : '', '/');
        if ($d === '') {
            return '';
        }
        if (preg_match('#^[A-Za-z0-9._/-]{1,255}$#D', $d) === 1 && !str_contains($d, '..')) {
            return $d;
        }
        throw ApiError::invalid('Diretório inválido. Use um caminho relativo simples, como "app" ou "site/publico".');
    }

    public static function phpVersion(mixed $v): string
    {
        if (is_string($v) && preg_match('/^\d{1,2}\.\d{1,2}$/D', $v) === 1) {
            return $v;
        }
        throw ApiError::invalid('Versão PHP inválida.');
    }

    /** Expressão de 5 campos (minuto hora dia mês dia-da-semana) com números, `*`, `,`, `-` e `/`. */
    public static function cronTime(mixed $v): string
    {
        $t = is_string($v) ? trim(preg_replace('/\s+/', ' ', $v) ?? '') : '';
        if (strlen($t) <= 100 && preg_match('#^[0-9*,/-]+( [0-9*,/-]+){4}$#D', $t) === 1) {
            return $t;
        }
        throw ApiError::invalid('Horário inválido. Use 5 campos, como "0 0 * * *" (minuto hora dia mês dia-da-semana).');
    }

    /** Comando de uma linha só (a Hostinger grava no crontab da conta). */
    public static function cronCommand(mixed $v): string
    {
        $c = is_string($v) ? trim($v) : '';
        if ($c !== '' && strlen($c) <= 1000 && preg_match('/[\x00-\x1F\x7F]/', $c) === 0) {
            return $c;
        }
        throw ApiError::invalid('Comando inválido. Informe um comando de uma linha só (até 1000 caracteres).');
    }

    public static function cronId(mixed $v): string
    {
        if (is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $v) === 1) {
            return $v;
        }
        throw ApiError::invalid('Tarefa cron inválida.');
    }

    /** Tipos que a Hostinger aceita no editor de zona. */
    public const DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    public static function dnsType(mixed $v): string
    {
        $t = is_string($v) ? strtoupper(trim($v)) : '';
        if (in_array($t, self::DNS_TYPES, true)) {
            return $t;
        }
        throw ApiError::invalid('Tipo de registro inválido.');
    }

    /** `@` para o domínio raiz ou um subdomínio relativo (curingas e rótulos com `_` são aceitos). */
    public static function dnsName(mixed $v): string
    {
        $n = is_string($v) ? trim($v) : null;
        if ($n === '') {
            $n = '@';
        }
        if ($n === null) {
            throw ApiError::invalid('Nome inválido. Use "@" para o domínio ou um subdomínio, como "www".');
        }
        if (strlen($n) <= 253 && preg_match('/^(?:@|[A-Za-z0-9_*-]{1,63}(?:\.[A-Za-z0-9_-]{1,63})*)$/D', $n) === 1) {
            return $n;
        }
        throw ApiError::invalid('Nome inválido. Use "@" para o domínio ou um subdomínio, como "www".');
    }

    public static function dnsTtl(mixed $v): int
    {
        $ttl = is_int($v) || (is_string($v) && preg_match('/^\d{1,7}$/D', $v) === 1) ? (int) $v : -1;
        if ($ttl >= 60 && $ttl <= 86400) {
            return $ttl;
        }
        throw ApiError::invalid('TTL inválido. Informe um valor entre 60 e 86400 segundos.');
    }

    /**
     * Conteúdos do conjunto `name`+`type`: um por linha na interface, sem linha vazia.
     * @return list<string>
     */
    public static function dnsContents(mixed $v): array
    {
        $items = [];
        foreach (is_array($v) ? $v : [$v] as $item) {
            $c = is_string($item) ? trim($item) : '';
            if ($c === '' || strlen($c) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $c) === 1) {
                throw ApiError::invalid('Conteúdo inválido. Informe um valor por linha, de até 2048 caracteres.');
            }
            $items[] = $c;
        }
        if ($items === [] || count($items) > 50) {
            throw ApiError::invalid('Informe de 1 a 50 conteúdos para o registro.');
        }
        return $items;
    }

    public static function jwt(mixed $v): string
    {
        $t = is_string($v) ? trim($v) : '';
        if (strlen($t) <= 8192 && Jwt::isWellFormed($t)) {
            return $t;
        }
        throw ApiError::invalid('Isso não parece um token JWT. Ele começa com "eyJ" e tem três partes separadas por ponto.');
    }
}
