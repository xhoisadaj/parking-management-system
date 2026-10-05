<?php

namespace App\Filament\Pages;

use App\Models\OpeningHour;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Parking settings';

    protected static ?string $title = 'Parking settings';

    protected static string $view = 'filament.pages.manage-settings';

    /** Columns on the settings row that this page edits. */
    private const SETTING_FIELDS = [
        'parking_name',
        'address',
        'total_capacity',
        'currency',
        'lost_ticket_fee',
        'ticket_header',
        'ticket_footer',
        'ticket_paper_width_mm',
        'timezone',
        'reason_threshold_percent',
    ];

    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::MANAGE_SETTINGS) ?? false;
    }

    public function mount(): void
    {
        $setting = Setting::current();
        $hours = OpeningHour::query()->get()->keyBy('weekday');

        $state = $setting->only(self::SETTING_FIELDS);

        foreach (array_keys(self::DAY_NAMES) as $weekday) {
            $hour = $hours->get($weekday);

            $state['hours'][$weekday] = [
                'is_closed' => $hour?->is_closed ?? false,
                'opens_at' => $this->toHi($hour?->opens_at),
                'closes_at' => $this->toHi($hour?->closes_at),
            ];
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Parking')
                    ->columns(2)
                    ->schema([
                        TextInput::make('parking_name')
                            ->label('Parking name')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('address')
                            ->maxLength(255),
                        TextInput::make('total_capacity')
                            ->label('Total capacity (spots)')
                            ->helperText('Shared pool. Vehicle types use fractions of it (see vehicle types).')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.5),
                        TextInput::make('currency')
                            ->label('Currency code')
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->alpha()
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state)),
                        TextInput::make('lost_ticket_fee')
                            ->label('Lost-ticket fee')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01),
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->helperText('Used for opening hours, time bands and daily maximums.'),
                    ]),

                Section::make('Tickets')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reason_threshold_percent')
                            ->label('Reason needed for price changes of at least (%)')
                            ->helperText('0 = every price change needs a reason. Small changes below this percentage can be made without one.')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5),
                        Select::make('ticket_paper_width_mm')
                            ->label('Thermal paper width')
                            ->options([58 => '58 mm', 80 => '80 mm'])
                            ->required(),
                        TextInput::make('ticket_header')
                            ->label('Ticket header')
                            ->maxLength(255),
                        Textarea::make('ticket_footer')
                            ->label('Ticket footer')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                Section::make('Opening hours')
                    ->description('For lots open past midnight, set the closing time earlier than the opening time.')
                    ->schema(array_map(fn (string $name, int $weekday) => $this->dayFieldset($weekday, $name), self::DAY_NAMES, array_keys(self::DAY_NAMES))),
            ]);
    }

    private function dayFieldset(int $weekday, string $name): Fieldset
    {
        $closedPath = "hours.{$weekday}.is_closed";

        return Fieldset::make($name)
            ->columns(3)
            ->schema([
                Toggle::make($closedPath)
                    ->label('Closed')
                    ->live(),
                TimePicker::make("hours.{$weekday}.opens_at")
                    ->label('Opens')
                    ->seconds(false)
                    ->hidden(fn (Get $get) => (bool) $get($closedPath))
                    ->required(fn (Get $get) => ! $get($closedPath)),
                TimePicker::make("hours.{$weekday}.closes_at")
                    ->label('Closes')
                    ->seconds(false)
                    ->hidden(fn (Get $get) => (bool) $get($closedPath))
                    ->required(fn (Get $get) => ! $get($closedPath)),
            ]);
    }

    public function save(AuditLogger $audit): void
    {
        $state = $this->form->getState();
        $setting = Setting::current();

        $oldSettings = $setting->only(self::SETTING_FIELDS);
        $oldHours = $this->hoursSnapshot();

        DB::transaction(function () use ($state, $setting) {
            $setting->update(Arr::only($state, self::SETTING_FIELDS));

            foreach ($state['hours'] as $weekday => $hour) {
                $closed = (bool) $hour['is_closed'];

                OpeningHour::updateOrCreate(['weekday' => $weekday], [
                    'is_closed' => $closed,
                    'opens_at' => $closed ? null : $hour['opens_at'],
                    'closes_at' => $closed ? null : $hour['closes_at'],
                ]);
            }
        });

        $setting->refresh();
        $newHours = $this->hoursSnapshot();

        $audit->record(
            event: 'settings.updated',
            auditable: $setting,
            old: ['settings' => $oldSettings, 'opening_hours' => $oldHours],
            new: ['settings' => $setting->only(self::SETTING_FIELDS), 'opening_hours' => $newHours],
        );

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /** @return array<int, array{is_closed: bool, opens_at: ?string, closes_at: ?string}> */
    private function hoursSnapshot(): array
    {
        return OpeningHour::query()->orderBy('weekday')->get()->mapWithKeys(fn (OpeningHour $hour) => [
            $hour->weekday => [
                'is_closed' => $hour->is_closed,
                'opens_at' => $this->toHi($hour->opens_at),
                'closes_at' => $this->toHi($hour->closes_at),
            ],
        ])->all();
    }

    private function toHi(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
