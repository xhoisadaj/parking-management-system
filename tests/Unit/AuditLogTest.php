<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    /**
     * Every event this app actually records needs an Albanian label — otherwise the audit log
     * page in the admin panel falls back to the raw event code (e.g. "ticket.edited").
     */
    #[Test]
    public function every_known_event_has_an_albanian_label(): void
    {
        $events = [
            'price.adjusted',
            'price.corrected',
            'ticket.edited',
            'ticket.price_override_set',
            'ticket.price_override_cleared',
            'ticket.lost',
            'ticket.voided',
            'settings.updated',
        ];

        foreach ($events as $event) {
            $label = AuditLog::eventLabel($event);

            $this->assertNotSame($event, $label, "\"{$event}\" has no Albanian label and falls back to its raw code.");
        }
    }

    #[Test]
    public function an_unknown_event_falls_back_to_its_raw_code(): void
    {
        $this->assertSame('something.new', AuditLog::eventLabel('something.new'));
    }
}
