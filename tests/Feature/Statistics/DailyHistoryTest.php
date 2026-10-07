<?php

namespace Tests\Feature\Statistics;

use App\Filament\Pages\DailyHistory;
use App\Models\ParkingSession;
use App\Services\CheckoutService;
use App\Services\Statistics\StatisticsService;
use App\Services\TicketIssuer;
use Livewire\Livewire;
use Tests\Feature\Operations\OperationsTestCase;

class DailyHistoryTest extends OperationsTestCase
{
    private \App\Models\User $ana;

    private \App\Models\User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = $this->userWith('Operator');
        $this->ben = $this->userWith('Operator');

        $issuer = app(TicketIssuer::class);

        // 2 June: three tickets, one closed by Ana.
        $a1 = $issuer->issue($this->car, 'A1', $this->ana, $this->at('2026-06-02 09:00'));
        $issuer->issue($this->car, 'A2', $this->ana, $this->at('2026-06-02 10:00'));
        $issuer->issue($this->car, 'B1', $this->ben, $this->at('2026-06-02 11:00'));
        app(CheckoutService::class)->complete($a1, $this->ana, null, null, $this->at('2026-06-02 11:00'));

        // 3 June: one more ticket, by Ben, so a range can cross both days.
        $issuer->issue($this->car, 'B2', $this->ben, $this->at('2026-06-03 08:00'));
    }

    public function test_summary_counts_tickets_released_that_day(): void
    {
        $from = $this->at('2026-06-02');
        $to = $this->at('2026-06-02 23:59:59');
        $summary = app(StatisticsService::class)->daySummary($from, $to);

        $this->assertSame(3, $summary['issued']);
        $this->assertSame(2, $summary['still_parked']);
        $this->assertSame(1, $summary['closed']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame(300.0, $summary['revenue']);
    }

    public function test_summary_for_one_operator_counts_their_entries_and_exits(): void
    {
        $summary = app(StatisticsService::class)->daySummary(
            $this->at('2026-06-02'),
            $this->at('2026-06-02 23:59:59'),
            $this->ana->id,
        );

        $this->assertSame(2, $summary['issued']);
        $this->assertSame(1, $summary['closed']);
        $this->assertSame(300.0, $summary['revenue']);
    }

    public function test_summary_over_a_range_covers_every_day_in_it(): void
    {
        $summary = app(StatisticsService::class)->daySummary(
            $this->at('2026-06-02'),
            $this->at('2026-06-03 23:59:59'),
        );

        $this->assertSame(4, $summary['issued']);
    }

    private function openCustomRange(string $from, string $to)
    {
        return Livewire::actingAs($this->userWith('Manager'))
            ->test(DailyHistory::class)
            ->set('data.preset', 'custom')
            ->set('data.from', $from)
            ->set('data.to', $to);
    }

    public function test_history_page_lists_the_tickets_of_a_single_day(): void
    {
        $this->openCustomRange('2026-06-02', '2026-06-02')->assertCountTableRecords(3);
    }

    public function test_history_page_lists_the_tickets_of_a_multi_day_range(): void
    {
        $this->openCustomRange('2026-06-02', '2026-06-03')->assertCountTableRecords(4);
    }

    public function test_history_page_filters_a_range_to_one_operator(): void
    {
        // Ben: B1 on the 2nd and B2 on the 3rd.
        $this->openCustomRange('2026-06-02', '2026-06-03')
            ->set('data.operator_id', $this->ben->id)
            ->assertCountTableRecords(2);
    }

    public function test_history_page_is_empty_for_a_range_with_no_tickets(): void
    {
        $this->openCustomRange('2026-06-10', '2026-06-12')->assertCountTableRecords(0);
    }

    public function test_history_page_defaults_to_today_when_no_period_is_chosen(): void
    {
        // The fixture tickets are in June; "today" (from the base test clock) has none.
        Livewire::actingAs($this->userWith('Manager'))
            ->test(DailyHistory::class)
            ->assertCountTableRecords(0);
    }

    public function test_history_page_opens_for_a_chosen_operator_from_the_link(): void
    {
        $this->actingAs($this->userWith('Manager'))
            ->get('/admin/daily-history?preset=custom&from=2026-06-02&to=2026-06-02&operator='.$this->ana->id)
            ->assertOk();
    }

    public function test_only_statistics_roles_can_open_the_history(): void
    {
        $this->actingAs($this->userWith('Manager'))->get('/admin/daily-history')->assertOk();
        $this->actingAs($this->userWith('Operator'))->get('/admin/daily-history')->assertForbidden();
    }
}
