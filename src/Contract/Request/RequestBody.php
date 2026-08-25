<?php

declare(strict_types=1);

namespace Oblodai\Contract\Request;

/**
 * A typed request body. Every resource method also accepts a plain `array<string, mixed>`; the DTOs
 * exist so the editor can show what each field means and so a typo is a static-analysis error.
 */
interface RequestBody
{
    /**
     * The body as the wire carries it: snake_case keys, unset fields omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
