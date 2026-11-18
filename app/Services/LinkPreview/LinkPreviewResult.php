<?php

namespace App\Services\LinkPreview;

use App\Support\Money;

/**
 * What could be read from a product page. `ok` is false when nothing useful
 * came back; the user then types the details (D-049).
 */
final readonly class LinkPreviewResult
{
    public function __construct(
        public bool $ok,
        public ?string $title = null,
        public ?Money $price = null,
        public ?string $imageUrl = null,
        public ?string $shop = null,
    ) {}

    public static function failed(?string $shop = null): self
    {
        return new self(false, shop: $shop);
    }
}
