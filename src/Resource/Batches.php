<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Массовые операции (батчи): прогресс и результаты по элементам.
 *
 * Постановка пачек — через методы ресурсов:
 * {@see Payments::createBatch()}, {@see Payments::refundBatch()}, {@see Payouts::createBatch()}.
 */
final class Batches extends AbstractResource
{
    /**
     * Прогресс и результаты пачки. POST /v1/batch/info
     *
     * Элементы index-aligned: {idx, status, order_id?, result?, error?}; result —
     * байт-в-байт ответ соответствующего единичного эндпоинта.
     *
     * @param string   $batchId batch_id из createBatch()/refundBatch()
     * @param int|null $limit   элементов на страницу (дефолт бэкенда 100, максимум 500)
     * @param int|null $offset  смещение
     *
     * @return array<string,mixed> {batch_id, kind, status, on_error, total, succeeded, failed,
     *                              created_at, updated_at, items}
     */
    public function info(string $batchId, ?int $limit = null, ?int $offset = null): array
    {
        $p = ['batch_id' => $batchId];
        if ($limit !== null) {
            $p['limit'] = $limit;
        }
        if ($offset !== null) {
            $p['offset'] = $offset;
        }

        return $this->client->request('/v1/batch/info', $p);
    }
}
