<?php

declare(strict_types=1);

require __DIR__ . '/../lib/autoload.php';

use HPanel\{ApiError, Context, Http, Request, Validate};

/**
 * Bancos MySQL. GET com `domain` lista os bancos vinculados ao site; sem, todos da conta.
 * POST `action`: create | delete | password | permissions | repair | site.
 * Prefixo, posse do banco e site de destino vêm do catálogo, nunca do cliente.
 */
Http::handle(['GET', 'POST'], static function (Request $req, Context $ctx): array {
    $orderId = Validate::orderId($req->get('orderId'));
    $source = $ctx->source();

    if ($req->method === 'GET') {
        $domain = $req->get('domain');
        $onlyDomain = $domain !== null && $domain !== '';
        $site = $onlyDomain ? $ctx->catalog->site($orderId, Validate::domain($domain)) : $ctx->catalog->mainSite($orderId);
        return [
            'databases' => $source->databases($site['username'], $site['domain'], $orderId, $onlyDomain),
            'username' => $site['username'],
        ];
    }

    $action = $req->get('action');
    if ($action === 'create') {
        $site = $ctx->catalog->site($orderId, Validate::domain($req->get('domain')));
        $prefix = $site['username'] . '_';
        $name = $prefix . Validate::dbSuffix($req->get('name'), 'Nome do banco');
        $user = $prefix . Validate::dbSuffix($req->get('user'), 'Usuário');
        $source->createDatabase($site['username'], $site['domain'], $orderId, $name, $user, Validate::dbPassword($req->get('password')));
        return ['database' => ['name' => $name, 'user' => $user, 'domain' => $site['domain']]];
    }

    $main = $ctx->catalog->mainSite($orderId);
    [$username, $domain] = [$main['username'], $main['domain']];
    $db = Validate::accountDb($req->get('db'), $username);
    match ($action) {
        'delete' => $source->deleteDatabase($username, $domain, $orderId, $db),
        'password' => $source->changeDatabasePassword($username, $domain, $orderId, $db, Validate::dbPassword($req->get('password'))),
        'permissions' => $source->setDatabasePermissions($username, $domain, $orderId, $db, Validate::dbPermissions($req->get('permissions'))),
        'repair' => $source->repairDatabase($username, $domain, $orderId, $db),
        // A Hostinger aceita qualquer vhost (até inexistente): só sites deste servidor.
        'site' => $source->setDatabaseSite($username, $domain, $orderId, $db, $ctx->catalog->site($orderId, Validate::domain($req->get('site')))['domain']),
        default => throw ApiError::invalid('Ação inválida.'),
    };
    return ['db' => $db];
});
