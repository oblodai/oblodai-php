<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\OkResult;
use Oblodai\Contract\Model\SplitConfig;
use Oblodai\Contract\Model\SplitOptIn;
use Oblodai\Contract\Model\SplitRule;
use Oblodai\Contract\Request\SplitConfigSetRequest;
use Oblodai\Contract\Request\SplitRuleListRequest;
use Oblodai\Contract\Request\SplitRuleRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/** Revenue splits: a percentage of every payment forwarded to a partner. Payout key. */
final class Splits extends Resource
{
    /**
     * `POST /v1/split/rule` — to an external address (`address`+`network`) or a platform merchant
     * (`merchant_id`).
     *
     * @param array<string, mixed>|SplitRuleRequest $params
     */
    public function createRule(array|SplitRuleRequest $params, ?RequestOptions $options = null): SplitRule
    {
        return $this->call('POST /v1/split/rule', $params, $options, SplitRule::fromArray(...));
    }

    /**
     * `POST /v1/split/rule/list`.
     *
     * @param  array<string, mixed>|SplitRuleListRequest $params
     * @return Page<SplitRule>
     */
    public function listRules(array|SplitRuleListRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/split/rule/list', $params, SplitRule::fromArray(...), $options);
    }

    /** `POST /v1/split/rule/delete`. */
    public function deleteRule(string $ruleId, ?RequestOptions $options = null): OkResult
    {
        return $this->call(
            'POST /v1/split/rule/delete',
            ['rule_id' => $ruleId],
            $options,
            OkResult::fromArray(...)
        );
    }

    /** `POST /v1/split/config/get`. */
    public function getConfig(?RequestOptions $options = null): SplitConfig
    {
        return $this->call('POST /v1/split/config/get', null, $options, SplitConfig::fromArray(...));
    }

    /**
     * `POST /v1/split/config/set` — how long split shares are held back for refunds.
     *
     * @param array<string, mixed>|SplitConfigSetRequest $params
     */
    public function setConfig(array|SplitConfigSetRequest $params, ?RequestOptions $options = null): SplitConfig
    {
        return $this->call('POST /v1/split/config/set', $params, $options, SplitConfig::fromArray(...));
    }

    /** `POST /v1/split/recipient/optin/get` — whether this merchant accepts being a split recipient. */
    public function getOptIn(?RequestOptions $options = null): SplitOptIn
    {
        return $this->call('POST /v1/split/recipient/optin/get', null, $options, SplitOptIn::fromArray(...));
    }

    /** `POST /v1/split/recipient/optin`. */
    public function setOptIn(bool $enabled, ?RequestOptions $options = null): SplitOptIn
    {
        return $this->call(
            'POST /v1/split/recipient/optin',
            ['enabled' => $enabled],
            $options,
            SplitOptIn::fromArray(...)
        );
    }
}
