<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/**
 * Ошибка, вернувшаяся от API (конверт {"error":{"code","message"}}).
 *
 * Ветвитесь по машиночитаемому коду ($e->getErrorCode()), а не по тексту сообщения.
 */
final class ApiException extends OblodaiException
{
    /** @var string Машиночитаемый код вида <домен>.<причина>. */
    private string $errorCode;

    /** @var int HTTP-статус ответа. */
    private int $statusCode;

    /** @var mixed Сырое тело ответа (для отладки). */
    private $raw;

    /** @var float|null Рекомендованная сервером пауза перед повтором в секундах (Retry-After). */
    private ?float $retryAfter;

    /**
     * @param mixed $raw
     */
    public function __construct(string $errorCode, string $message, int $statusCode, $raw = null, ?float $retryAfter = null)
    {
        parent::__construct($message !== '' ? $message : $errorCode);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->raw = $raw;
        $this->retryAfter = $retryAfter;
    }

    /** Машиночитаемый код ошибки, например "payout.insufficient_funds". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return mixed */
    public function getRaw()
    {
        return $this->raw;
    }

    /** Рекомендованная сервером пауза перед повтором (секунды), если была (Retry-After). */
    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /** Временная ли ошибка (стоит ли повторять с backoff). */
    public function isRetriable(): bool
    {
        // Повторяем только транспортные/лимитные сбои. Прикладные коды (в т.ч.
        // payout.funds_maturing) терминальны: повтор их не разрешит.
        return $this->statusCode >= 500 || $this->statusCode === 429;
    }
}
