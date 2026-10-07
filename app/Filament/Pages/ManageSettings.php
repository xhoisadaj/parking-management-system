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

    protected static ?string $navigationGroup = 'Konfigurimi';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Cilësimet e parkimit';

    protected static ?string $title = 'Cilësimet e parkimit';

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
        0 => 'E diel',
        1 => 'E hënë',
        2 => 'E martë',
        3 => 'E mërkurë',
        4 => 'E enjte',
        5 => 'E premte',
        6 => 'E shtunë',
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
                Section::make('Parkimi')
                    ->columns(2)
                    ->schema([
                        TextInput::make('parking_name')
                            ->label('Emri i parkimit')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('address')->label('Adresa')
                            ->maxLength(255),
                        TextInput::make('total_capacity')
                            ->label('Kapaciteti total (vende)')
                            ->helperText('Fond i përbashkët. Llojet e mjeteve përdorin një pjesë të tij (shih llojet e mjeteve).')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.5),
                        TextInput::make('currency')
                            ->label('Kodi i monedhës')
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->alpha()
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state)),
                        TextInput::make('lost_ticket_fee')
                            ->label('Tarifa e biletës së humbur')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01),
                        Select::make('timezone')
                            ->label('Zona kohore')
                            ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->searchable()
                            ->required()
                            ->helperText('Përdoret për orarin e punës, intervalet kohore dhe maksimumet ditore.'),
                    ]),

                Section::make('Biletat')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reason_threshold_percent')
                            ->label('Arsyeja kërkohet për ndryshime çmimi prej të paktën (%)')
                            ->helperText('0 = çdo ndryshim çmimi kërkon arsye. Ndryshimet më të vogla se kjo përqindje mund të bëhen pa arsye.')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.5),
                        Select::make('ticket_paper_width_mm')
                            ->label('Gjerësia e letrës termike')
                            ->options([58 => '58 mm', 80 => '80 mm'])
                            ->required(),
                        TextInput::make('ticket_header')
                            ->label('Koka e biletës')
                            ->maxLength(255),
                        Textarea::make('ticket_footer')
                            ->label('Fundi i biletës')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                Section::make('Orari i punës')
                    ->description('Për parkimet e hapura pas mesnate, vendosni orën e mbylljes më herët se ora e hapjes.')
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
                    ->label('Mbyllur')
                    ->live(),
                TimePicker::make("hours.{$weekday}.opens_at")
                    ->label('Hapet')
                    ->seconds(false)
                    ->hidden(fn (Get $get) => (bool) $get($closedPath))
                    ->required(fn (Get $get) => ! $get($closedPath)),
                TimePicker::make("hours.{$weekday}.closes_at")
                    ->label('Mbyllet')
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
            ->title('Cilësimet u ruajtën')
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
