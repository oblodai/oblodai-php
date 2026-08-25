<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchElement;
use Oblodai\Contract\Model\ClaimPreview;
use Oblodai\Contract\Model\ClaimResult;
use Oblodai\Contract\Model\PayoutLink;
use Oblodai\Contract\Request\ClaimRequest;
use Oblodai\Contract\Request\PayoutLinkBatchRequest;
use Oblodai\Contract\Request\PayoutLinkChequeRequest;
use Oblodai\Contract\Request\PayoutLinkListRequest;
use Oblodai\Contract\Request\PayoutLinkRequest;
use Oblodai\Core\Envelope;
use Oblodai\Core\FileResult;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/**
 * Payout links (cheques): funds reserved now, claimed later by whoever holds the token. Payout key.
 *
 * A link argument is either the `link_id` as a string or an array carrying `link_id`.
 */
final class PayoutLinks extends Resource
{
    /**
     * `POST /v1/payout/link` — reserve funds and mint a claim token (`claim_token`/`claim_url` are
     * returned once). Idempotent by `reference`.
     *
     * @param array<string, mixed>|PayoutLinkRequest $params
     */
    public function create(array|PayoutLinkRequest $params, ?RequestOptions $options = null): PayoutLink
    {
        return $this->call('POST /v1/payout/link', $params, $options, PayoutLink::fromArray(...));
    }

    /**
     * `POST /v1/payout/link/info`.
     *
     * @param string|array<string, mixed> $link
     */
    public function info(string|array $link, ?RequestOptions $options = null): PayoutLink
    {
        return $this->call(
            'POST /v1/payout/link/info',
            ['link_id' => self::idOf($link, 'link_id')],
            $options,
            PayoutLink::fromArray(...)
        );
    }

    /**
     * Alias of `info()`.
     *
     * @param string|array<string, mixed> $link
     */
    public function get(string|array $link, ?RequestOptions $options = null): PayoutLink
    {
        return $this->info($link, $options);
    }

    /**
     * `POST /v1/payout/link/list`.
     *
     * @param  array<string, mixed>|PayoutLinkListRequest $params
     * @return Page<PayoutLink>
     */
    public function list(array|PayoutLinkListRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payout/link/list', $params, PayoutLink::fromArray(...), $options);
    }

    /**
     * `POST /v1/payout/link/cancel` — release the reserved funds of an unclaimed link.
     *
     * @param string|array<string, mixed> $link
     */
    public function cancel(string|array $link, ?RequestOptions $options = null): PayoutLink
    {
        return $this->call(
            'POST /v1/payout/link/cancel',
            ['link_id' => self::idOf($link, 'link_id')],
            $options,
            PayoutLink::fromArray(...)
        );
    }

    /**
     * `POST /v1/payout/link/batch` — SYNCHRONOUS: many links in one signed call, per-element
     * outcomes. `reference` is required on every item.
     *
     * @param  array<string, mixed>|PayoutLinkBatchRequest $params
     * @return list<BatchElement>
     */
    public function batch(array|PayoutLinkBatchRequest $params, ?RequestOptions $options = null): array
    {
        $result = $this->call('POST /v1/payout/link/batch', $params, $options);
        $items = [];
        foreach (Envelope::asPlainList($result) as $item) {
            $items[] = BatchElement::fromArray(
                self::asObject($item, 'POST /v1/payout/link/batch'),
                static fn (array $row): PayoutLink => PayoutLink::fromArray($row)
            );
        }

        return $items;
    }

    /**
     * `POST /v1/payout/link/cheque` — printable PDF cheque for a claim token.
     *
     * @param array<string, mixed>|PayoutLinkChequeRequest $params
     */
    public function cheque(array|PayoutLinkChequeRequest $params, ?RequestOptions $options = null): FileResult
    {
        return $this->file('POST /v1/payout/link/cheque', [], [], $params, $options);
    }

    // --- recipient side (public, unsigned) ---

    /** `GET /v1/claim/{token}` — what the recipient sees before claiming. No credentials needed. */
    public function claimPreview(string $token, ?RequestOptions $options = null): ClaimPreview
    {
        return $this->call(
            'GET /v1/claim/{token}',
            null,
            $options,
            ClaimPreview::fromArray(...),
            ['token' => $token]
        );
    }

    /**
     * `POST /v1/claim/{token}` — claim to an address (and passcode when the link has one).
     * No credentials needed.
     *
     * @param array<string, mixed>|ClaimRequest $params
     */
    public function claim(
        string $token,
        array|ClaimRequest $params,
        ?RequestOptions $options = null,
    ): ClaimResult {
        return $this->call(
            'POST /v1/claim/{token}',
            $params,
            $options,
            ClaimResult::fromArray(...),
            ['token' => $token]
        );
    }
}
