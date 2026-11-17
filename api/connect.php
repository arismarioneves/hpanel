<?php

declare(strict_types=1);

require __DIR__ . '/../lib/autoload.php';

use HPanel\{ApiError, Context, HostingerAuth, HostingerSource, Http, Jwt, Request, Validate};

const DEFAULT_GAID = 'GA1.1.000000000.0000000000';

Http::handle(['POST'], static function (Request $req, Context $ctx): array {
    if (!$ctx->rateLimit->hit('connect', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 10, 600, $ctx->now())) {
        throw ApiError::rateLimited();
    }
    $token = Validate::jwt($req->get('token'));
    $exp = Jwt::expiry($token);
    if ($exp !== null && $exp <= $ctx->now()) {
        throw ApiError::invalid('Esse token já expirou. Entre no hPanel e copie um novo.');
    }

    try {
        $servers = (new HostingerSource($ctx->http, $token, DEFAULT_GAID))->servers();
    } catch (ApiError $e) {
        if ($e->errorCode === 'session_expired') {
            throw ApiError::invalid('A Hostinger recusou esse token. Entre no hPanel e copie um novo.');
        }
        throw $e;
    }

    $data = [
        'token' => $token,
        'gaid' => DEFAULT_GAID,
        'cache' => ['servers' => ['at' => $ctx->now(), 'value' => $servers]],
    ];
    if ($req->flag('extension')) {
        $data['via'] = HostingerAuth::VIA_EXTENSION;
    }
    $ctx->session->start($data);
    return ['servers' => count($servers)];
}, false);
