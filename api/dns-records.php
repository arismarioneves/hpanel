<?php

declare(strict_types=1);

require __DIR__ . '/../lib/autoload.php';

use HPanel\{ApiError, Context, Http, Request, Validate};

/**
 * Zona DNS do domínio. GET lista os conjuntos de registros e os nameservers;
 * POST `action` save|delete devolve a zona atualizada.
 * `save` sem `overwrite` acrescenta um conjunto; com `overwrite` substitui o conjunto `name`+`type`.
 */
Http::handle(['GET', 'POST'], static function (Request $req, Context $ctx): array {
    $orderId = Validate::orderId($req->get('orderId'));
    $site = $ctx->catalog->site($orderId, Validate::domain($req->get('domain')));
    $source = $ctx->source();

    if ($req->method === 'GET') {
        return $source->dnsZone($site['domain']);
    }

    match ($req->get('action')) {
        'save' => $source->saveDnsRecords(
            $site['domain'],
            Validate::dnsName($req->get('name')),
            Validate::dnsType($req->get('type')),
            Validate::dnsTtl($req->get('ttl')),
            Validate::dnsContents($req->get('contents')),
            $req->flag('overwrite'),
        ),
        'delete' => $source->deleteDnsRecords(
            $site['domain'],
            Validate::dnsName($req->get('name')),
            Validate::dnsType($req->get('type')),
        ),
        default => throw ApiError::invalid('Ação inválida.'),
    };

    return $source->dnsZone($site['domain']);
});
