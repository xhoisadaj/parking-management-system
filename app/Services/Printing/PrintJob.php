<?php

namespace App\Services\Printing;

/**
 * Describes how a ticket reaches the printer. Browser jobs carry a print-page URL the
 * operator screen loads into a hidden frame. A future ESC/POS driver could return raw bytes instead.
 */
final readonly class PrintJob
{
    private function __construct(
        public string $mode,
        public ?string $url = null,
    ) {}

    public static function browser(string $url): self
    {
        return new self('browser', $url);
    }
}
