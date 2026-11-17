<?php

declare(strict_types=1);

namespace HPanel;

/** Erro com código público estável; a mensagem é exibida ao usuário. */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function notConnected(): self
    {
        return new self('not_connected', 'Conecte sua conta Hostinger para continuar.', 401);
    }

    public static function sessionExpired(): self
    {
        return new self('session_expired', 'Sua sessão com a Hostinger terminou. Conecte novamente para continuar.', 401);
    }

    public static function renewedByExtension(): self
    {
        return new self('renewed_by_extension', 'Esta conexão é renovada pela extensão do navegador, junto com o seu login no hPanel.', 409);
    }

    public static function upstream(): self
    {
        return new self('upstream_unavailable', 'A Hostinger não respondeu. Tente novamente em instantes.', 502);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_input', $message, 422);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function rateLimited(): self
    {
        return new self('rate_limited', 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.', 429);
    }

    public static function forbidden(string $message): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function methodNotAllowed(): self
    {
        return new self('method_not_allowed', 'Método não permitido.', 405);
    }
}
