<?php

declare(strict_types=1);

namespace HPanel;

/** Sessão deslizante do hPanel: /auth/refresh troca um JWT ainda válido por outro. */
final class HostingerAuth
{
    public const THRESHOLD = 900; // renova se faltar <= 15 min
    public const VIA_EXTENSION = 'extension';

    public function __construct(private HttpTransport $http)
    {
    }

    public function renew(string $jwt): ?string
    {
        $r = $this->http->send(
            'POST',
            HostingerSource::BASE . '/api/auth/api/external/v1/auth/refresh',
            [
                'accept: application/json;charset=utf-8',
                'content-type: application/json',
                'referer: ' . HostingerSource::BASE . '/',
                'origin: ' . HostingerSource::BASE,
                'user-agent: ' . HostingerSource::UA,
            ],
            'jwt=' . $jwt . '; language=pt_BR',
            ''
        );
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return null;
        }
        $new = $r['cookies']['jwt'] ?? null;
        return is_string($new) && Jwt::isWellFormed($new) ? $new : null;
    }

    /**
     * Sessão conectada pela extensão do navegador: quem renova é a própria extensão, no navegador, junto com o
     * login do usuário no hPanel. O refresh feito pelo servidor trocaria o token e derrubaria aquele login.
     */
    public static function viaExtension(?array $data): bool
    {
        return ($data['via'] ?? null) === self::VIA_EXTENSION;
    }

    /** Token entregue pela extensão: substitui o atual e deixa a renovação com ela. */
    public function adopt(Session $session, string $token): void
    {
        $session->withLock(function () use ($session, $token): void {
            $data = $session->reload() ?? throw ApiError::notConnected();
            if (($data['token'] ?? null) === $token && self::viaExtension($data)) {
                return;
            }
            $data['token'] = $token;
            $data['via'] = self::VIA_EXTENSION;
            // O login no navegador pode ser de outra conta: o cache da sessão é refeito com o token novo.
            unset($data['cache']);
            $session->write($data);
        });
    }

    /** Devolve um token utilizável, renovando-o (com lock) quando preciso. */
    public function ensureFresh(Session $session, int $now, bool $force = false): string
    {
        $data = $session->data() ?? throw ApiError::notConnected();
        $token = (string) ($data['token'] ?? '');
        if (self::viaExtension($data)) {
            if ($force) {
                throw ApiError::renewedByExtension();
            }
            if ((Jwt::expiry($token) ?? PHP_INT_MAX) > $now) {
                return $token;
            }
            throw ApiError::sessionExpired();
        }
        if (!$force && (Jwt::expiry($token) ?? 0) - $now > self::THRESHOLD) {
            return $token;
        }

        return $session->withLock(function () use ($session, $now, $force): string {
            $data = $session->reload() ?? throw ApiError::notConnected();
            $token = (string) ($data['token'] ?? '');
            $exp = Jwt::expiry($token) ?? 0;
            if (!$force && $exp - $now > self::THRESHOLD) {
                return $token; // outra requisição já renovou
            }
            $new = $this->renew($token);
            if ($new !== null) {
                $data['token'] = $new;
                $session->write($data);
                return $new;
            }
            if ($exp <= $now) {
                throw ApiError::sessionExpired();
            }
            if ($force) {
                throw ApiError::upstream();
            }
            return $token;
        });
    }
}
