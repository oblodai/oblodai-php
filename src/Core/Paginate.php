<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** The `paginate` block of a list envelope: offset pagination as the core reports it. */
final class Paginate
{
    public function __construct(
        /** Total rows matching the filter. */
        public readonly int $total,
        /** Rows per page as the core applied it (its own cap may be lower than the requested limit). */
        public readonly int $per_page,
        /** Offset of this page. */
        public readonly int $offset,
        /** The server's own "there is more" flag. */
        public readonly bool $has_pages,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $int = static function (mixed $value): int {
            return is_numeric($value) ? (int) $value : 0;
        };

        return new self(
            $int($data['total'] ?? 0),
            $int($data['per_page'] ?? 0),
            $int($data['offset'] ?? 0),
            ($data['has_pages'] ?? false) === true,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->per_page,
            'offset' => $this->offset,
            'has_pages' => $this->has_pages,
        ];
    }
}
