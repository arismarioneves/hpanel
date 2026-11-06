<?php

declare(strict_types=1);

require __DIR__ . '/../lib/autoload.php';

use HPanel\{ApiError, Context, Http, Request, Validate};

/**
 * Acesso remoto ao MySQL da conta (IPs liberados por banco). GET lista;
 * POST `action` add|delete (`db` + `ip`, `%` = qualquer host) devolve a lista atualizada.
 */
Http::handle(['GET', 'POST'], static function (Request $req, Context $ctx): array {
    $orderId = Validate::orderId($req->get('orderId'));
    $main = $ctx->catalog->mainSite($orderId);
    [$username, $domain] = [$main['username'], $main['domain']];
    $source = $ctx->source();
    $list = static fn(): array => ['connections' => $source->remoteConnections($username, $domain, $orderId)];

    if ($req->method === 'GET') {
        return $list();
    }

    $db = Validate::accountDb($req->get('db'), $username);
    $ip = Validate::remoteIp($req->get('ip'));
    match ($req->get('action')) {
        'add' => $source->addRemoteConnection($username, $domain, $orderId, $db, $ip),
        'delete' => $source->deleteRemoteConnection($username, $domain, $orderId, $db, $ip),
        default => throw ApiError::invalid('Ação inválida.'),
    };
    return $list();
});
