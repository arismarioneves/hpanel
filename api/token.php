<?php

declare(strict_types=1);

require __DIR__ . '/../lib/autoload.php';

use HPanel\{ApiError, Context, DemoSource, Http, Jwt, Request, Validate};

// Token novo entregue pela extensão do navegador (renovação ou login refeito no hPanel) para a sessão atual.
Http::handle(['POST'], static function (Request $req, Context $ctx): array {
    if ($ctx->isDemo()) {
        throw DemoSource::blocked();
    }
    if (!$ctx->session->isConnected()) {
        throw ApiError::notConnected();
    }
    if (!$ctx->rateLimit->hit('token', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600, $ctx->now())) {
        throw ApiError::rateLimited();
    }
    $token = Validate::jwt($req->get('token'));
    $exp = Jwt::expiry($token);
    if ($exp !== null && $exp <= $ctx->now()) {
        throw ApiError::sessionExpired();
    }

    $ctx->auth()->adopt($ctx->session, $token);
    return ['expiresAt' => $exp, 'minutesLeft' => $exp === null ? null : max(0, intdiv($exp - $ctx->now(), 60))];
}, false);
