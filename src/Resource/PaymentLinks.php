<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\PaymentLink;
use Oblodai\Contract\Model\PaymentLinkCreated;
use Oblodai\Contract\Model\PaymentLinkToggled;
use Oblodai\Contract\Model\PublicPayment;
use Oblodai\Contract\Model\PublicPaymentLink;
use Oblodai\Contract\Request\LinkCheckoutRequest;
use Oblodai\Contract\Request\PaymentLinkListRequest;
use Oblodai\Contract\Request\PaymentLinkRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/**
 * Reusable payment links (tip jars, price tags): each checkout spawns an invoice.
 *
 * A link argument is either the `link_id` as a string or an array carrying `link_id`.
 */
final class PaymentLinks extends Resource
{
    /**
     * `POST /v1/payment/link` — mint a reusable link; each checkout spawns an invoice.
     *
     * Codes worth branching on: `invoice.bad_price`, `payment.bad_amount`,
     * `request.unknown_currency`, `payment.unsupported_network`, `payment.below_minimum`,
     * `idempotency.key_reused`.
     *
     * @param array<string, mixed>|PaymentLinkRequest $params
     */
    public function create(
        array|PaymentLinkRequest $params,
        ?RequestOptions $options = null,
    ): PaymentLinkCreated {
        return $this->call('POST /v1/payment/link', $params, $options, PaymentLinkCreated::fromArray(...));
    }

    /**
     * `POST /v1/payment/link/info` — the link plus a page of the invoices it spawned (`payments`).
     *
     * @param string|array<string, mixed> $link
     * @param array<string, mixed>        $page limit/offset over the link's invoices
     */
    public function info(string|array $link, array $page = [], ?RequestOptions $options = null): PaymentLink
    {
        return $this->call(
            'POST /v1/payment/link/info',
            array_merge(['link_id' => self::idOf($link, 'link_id')], $page),
            $options,
            PaymentLink::fromArray(...)
        );
    }

    /**
     * Alias of `info()`.
     *
     * @param string|array<string, mixed> $link
     * @param array<string, mixed>        $page
     */
    public function get(string|array $link, array $page = [], ?RequestOptions $options = null): PaymentLink
    {
        return $this->info($link, $page, $options);
    }

    /**
     * `POST /v1/payment/link/list`.
     *
     * @param  array<string, mixed>|PaymentLinkListRequest $params
     * @return Page<PaymentLink>
     */
    public function list(array|PaymentLinkListRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payment/link/list', $params, PaymentLink::fromArray(...), $options);
    }

    /**
     * `POST /v1/payment/link/toggle` — enable or disable a link.
     *
     * @param string|array<string, mixed> $link
     */
    public function toggle(
        string|array $link,
        bool $active,
        ?RequestOptions $options = null,
    ): PaymentLinkToggled {
        return $this->call(
            'POST /v1/payment/link/toggle',
            ['link_id' => self::idOf($link, 'link_id'), 'active' => $active],
            $options,
            PaymentLinkToggled::fromArray(...)
        );
    }

    // --- payer side (public, unsigned) ---

    /** `GET /v1/link/{id}` — the link as the payer sees it. No credentials needed. */
    public function publicView(string $linkId, ?RequestOptions $options = null): PublicPaymentLink
    {
        return $this->call(
            'GET /v1/link/{id}',
            null,
            $options,
            PublicPaymentLink::fromArray(...),
            ['id' => $linkId]
        );
    }

    /**
     * `POST /v1/link/{id}/checkout` — spawn an invoice from the link (rate-capped per IP).
     * No credentials needed.
     *
     * @param array<string, mixed>|LinkCheckoutRequest $params
     */
    public function checkout(
        string $linkId,
        array|LinkCheckoutRequest $params = [],
        ?RequestOptions $options = null,
    ): PublicPayment {
        return $this->call(
            'POST /v1/link/{id}/checkout',
            $params,
            $options,
            PublicPayment::fromArray(...),
            ['id' => $linkId]
        );
    }
}
