<?php

namespace App\Filament\Pages;

use App\Models\ParkingSession;
use App\Models\User;
use App\Services\Statistics\DateRange;
use App\Services\Statistics\StatisticsService;
use App\Support\Format;
use App\Support\Permissions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Historiku i biletave: tickets released over a chosen period (a day, a week, a month, or any
 * custom range), per operator, with totals. Opened for everyone, or one operator, from the Users list.
 */
class DailyHistory extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Operacionet';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Historiku i biletave';

    protected static ?string $title = 'Historiku i biletave';

    protected static string $view = 'filament.pages.daily-history';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::VIEW_STATISTICS) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'preset' => request()->query('preset', 'today'),
            'from' => request()->query('from'),
            'to' => request()->query('to'),
            'operator_id' => request()->integer('operator') ?: null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Select::make('preset')
                    ->label('Periudha')
                    ->options([
                        'today' => 'Sot',
                        'week' => 'Këtë javë',
                        'month' => 'Këtë muaj',
                        'custom' => 'Interval i personalizuar',
                    ])
                    ->default('today')
                    ->native(false)
                    ->live(),
                DatePicker::make('from')
                    ->label('Nga')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('preset') === 'custom')
                    ->live(),
                DatePicker::make('to')
                    ->label('Deri')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('preset') === 'custom')
                    ->live(),
                Select::make('operator_id')
                    ->label('Operatori')
                    ->placeholder('Të gjithë operatorët')
                    ->options(fn () => User::query()->whereHas('roles')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->live(),
            ])
            ->columns(4);
    }

    private function range(): DateRange
    {
        return DateRange::fromFilters($this->data);
    }

    private function operatorId(): ?int
    {
        return isset($this->data['operator_id']) && $this->data['operator_id'] !== null
            ? (int) $this->data['operator_id']
            : null;
    }

    /** @return array<string, mixed> */
    public function getSummaryProperty(): array
    {
        $range = $this->range();

        return app(StatisticsService::class)->daySummary($range->from, $range->to, $this->operatorId());
    }

    /** Totals per operator for the period (all operators, so the selected one can be compared). */
    public function getOperatorsProperty(): array
    {
        $range = $this->range();

        return app(StatisticsService::class)->perUser($range->from, $range->to);
    }

    public function getPeriodLabelProperty(): string
    {
        return $this->range()->label();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->ticketsQuery())
            ->defaultSort('entered_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading('Asnjë biletë në këtë periudhë')
            ->emptyStateDescription('Ndrysho periudhën ose operatorin.')
            ->columns([
                TextColumn::make('entered_at')->label('Hyrja')->formatStateUsing(fn ($state) => Format::dateTime($state))->sortable(),
                TextColumn::make('ticket_code')->label('Biletë')->searchable()->fontFamily('mono'),
                TextColumn::make('vehicleType.name')->label('Mjeti'),
                TextColumn::make('plate')->label('Targa')->placeholder('—'),
                TextColumn::make('entryUser.name')->label('Operatori i hyrjes'),
                TextColumn::make('exited_at')->label('Dalja')->formatStateUsing(fn ($state) => Format::dateTime($state))->placeholder('—'),
                TextColumn::make('exitUser.name')->label('Operatori i daljes')->placeholder('—'),
                TextColumn::make('status')->label('Statusi')->badge()
                    ->formatStateUsing(fn (string $state) => ParkingSession::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        ParkingSession::STATUS_PAID => 'success',
                        ParkingSession::STATUS_LOST => 'warning',
                        ParkingSession::STATUS_VOID => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('final_price')->label('Çmimi')->formatStateUsing(fn ($state) => $state === null ? '—' : Format::money($state)),
            ]);
    }

    /** Tickets released in the chosen period. With an operator: where they were the entry or exit operator. */
    private function ticketsQuery(): Builder
    {
        $range = $this->range();
        $operatorId = $this->operatorId();

        return ParkingSession::query()
            ->with(['vehicleType', 'entryUser', 'exitUser'])
            ->whereBetween('entered_at', [$range->from, $range->to])
            ->when($operatorId, fn (Builder $q, int $id) => $q->where(fn (Builder $w) => $w
                ->where('entry_user_id', $id)
                ->orWhere('exit_user_id', $id)));
    }
}
