<?php

namespace App\Filament\Resources;

use App\Exceptions\ParkingException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\TicketResource\Pages;
use App\Models\ParkingSession;
use App\Models\VehicleType;
use App\Services\CheckoutService;
use App\Services\PriceResult;
use App\Services\TicketEditor;
use App\Support\Format;
use App\Support\Permissions;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class TicketResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = ParkingSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Operacionet';

    protected static ?string $navigationLabel = 'Biletat';

    protected static ?string $modelLabel = 'biletë';

    protected static ?string $pluralModelLabel = 'biletat';

    protected static ?int $navigationSort = 2;

    protected static function requiredPermission(): string
    {
        return Permissions::EDIT_TICKET;
    }

    /**
     * Tickets are issued and closed on the operator screens, never created or deleted here.
     * Row actions handle corrections instead: "Ndrysho" (plate/vehicle/entry time for an active
     * ticket, final price for a closed one — see the action below) and "Printo".
     */
    protected static function allowsWrites(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('entered_at', 'desc')
            ->columns([
                TextColumn::make('ticket_code')->label('Bileta')->searchable()->fontFamily('mono'),
                TextColumn::make('plate')->label('Targa')->searchable()->placeholder('—'),
                TextColumn::make('vehicleType.name')->label('Mjeti'),
                TextColumn::make('entered_at')->label('Hyrja')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('final_price')
                    ->label('Çmimi')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Statusi')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ParkingSession::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        ParkingSession::STATUS_ACTIVE => 'success',
                        ParkingSession::STATUS_PAID => 'gray',
                        ParkingSession::STATUS_VOID => 'danger',
                        ParkingSession::STATUS_LOST => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                // No default: Printo and Ndrysho work on closed tickets too, so those must be
                // visible without the admin having to know to clear a filter first.
                SelectFilter::make('status')
                    ->label('Statusi')
                    ->options([
                        ParkingSession::STATUS_ACTIVE => ParkingSession::statusLabel(ParkingSession::STATUS_ACTIVE),
                        ParkingSession::STATUS_PAID => ParkingSession::statusLabel(ParkingSession::STATUS_PAID),
                        ParkingSession::STATUS_VOID => ParkingSession::statusLabel(ParkingSession::STATUS_VOID),
                        ParkingSession::STATUS_LOST => ParkingSession::statusLabel(ParkingSession::STATUS_LOST),
                    ]),
            ])
            ->actions([
                Action::make('print')
                    ->label('Printo')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (ParkingSession $record) => $record->status !== ParkingSession::STATUS_VOID)
                    ->url(fn (ParkingSession $record) => ($record->status === ParkingSession::STATUS_ACTIVE
                        ? route('print.entry', $record)
                        : route('print.receipt', $record)).'?print=1') // same autoprint flag the operator screens use
                    ->openUrlInNewTab(),
                // Lets an admin close an active ticket right here — same CheckoutService the
                // operator screen uses, so the same cash handling, same price-override handling,
                // same permission/reason/discount-limit rules — just without needing to go sign
                // into the separate operator screen and open a shift first (ensureOpen does that
                // silently for whoever completes the checkout, admin included).
                Action::make('pay')
                    ->label('Paguaj')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (ParkingSession $record) => $record->status === ParkingSession::STATUS_ACTIVE)
                    ->fillForm(function (ParkingSession $record) {
                        $due = $record->final_price !== null
                            ? (float) $record->final_price
                            : app(CheckoutService::class)->calculate($record)->total();

                        return ['amount_received' => $due > 0 ? $due : null];
                    })
                    ->form(function (ParkingSession $record): array {
                        $result = app(CheckoutService::class)->calculate($record);
                        $due = $record->final_price !== null ? (float) $record->final_price : $result->total();

                        return [
                            Placeholder::make('breakdown')
                                ->label('Zbërthimi i çmimit')
                                ->content(fn () => static::breakdownHtml($result)),
                            Placeholder::make('amount_due')
                                ->label('Shuma për t\'u paguar')
                                ->content($record->final_price !== null
                                    ? new HtmlString(e(Format::money($due)).' <span class="text-amber-700">(çmim i caktuar)</span>')
                                    : Format::money($due)),
                            TextInput::make('final_price')
                                ->label('Çmimi përfundimtar (lëreni bosh për shumën e mësipërme)')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->nullable(),
                            Textarea::make('reason')
                                ->label('Arsyeja (vetëm nëse ndryshoni çmimin)')
                                ->rows(2),
                            TextInput::make('amount_received')
                                ->label('Shuma e marrë nga klienti')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->nullable(),
                        ];
                    })
                    ->modalHeading('Arkëto biletën')
                    ->modalDescription('Mbyll biletën dhe regjistron pagesën — njësoj si arkëtimi i operatorit.')
                    ->action(function (ParkingSession $record, array $data, CheckoutService $checkout) {
                        Gate::authorize(Permissions::CHECKOUT);

                        $toFloat = fn ($v) => $v === null || $v === '' ? null : (float) $v;

                        try {
                            $closed = $checkout->complete(
                                $record,
                                auth()->user(),
                                $toFloat($data['final_price'] ?? null),
                                $data['reason'] ?? null,
                                now(),
                                $toFloat($data['amount_received'] ?? null),
                            );
                        } catch (ParkingException|AuthorizationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Bileta u arkëtua')
                            ->body($record->ticket_code.' — Totali '.Format::money($closed->final_price))
                            ->success()
                            ->actions([
                                NotificationAction::make('print')
                                    ->label('Printo kuponin')
                                    ->url(route('print.receipt', $closed).'?print=1')
                                    ->openUrlInNewTab(),
                            ])
                            ->send();
                    }),
                // One button per ticket, not two: it used to be "Ndrysho" for an active ticket vs.
                // "Korrigjo çmimin" for a closed one, which meant clicking the wrong one looked
                // like there was no way to change the price. Now there's only "Ndrysho", and the
                // form inside adapts: an active ticket gets vehicle/plate/time fields, the full
                // price breakdown so far, and a free-text price override (any number, overriding
                // the calculation entirely — see TicketEditor::edit); a closed ticket gets the
                // editable final price instead.
                Action::make('edit')
                    ->label('Ndrysho')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (ParkingSession $record) => $record->status !== ParkingSession::STATUS_VOID)
                    ->fillForm(function (ParkingSession $record) {
                        if ($record->status === ParkingSession::STATUS_ACTIVE) {
                            return [
                                'vehicle_type_id' => $record->vehicle_type_id,
                                'plate' => $record->plate,
                                'entered_at' => $record->entered_at,
                                'price_override' => $record->final_price,
                                'price_override_reason' => $record->adjustment_reason,
                            ];
                        }

                        return ['final_price' => $record->final_price];
                    })
                    ->form(function (ParkingSession $record): array {
                        if ($record->status === ParkingSession::STATUS_ACTIVE) {
                            $breakdown = app(CheckoutService::class)->calculate($record);
                            $canVoid = auth()->user()->can(Permissions::VOID_TICKET);

                            return [
                                // Only shown to someone who actually holds void_ticket — VoidService
                                // enforces this too, but there is no point showing a toggle that
                                // would just fail on submit. Toggling this hides the normal edit
                                // fields below (there is nothing left to correct on a ticket about
                                // to be voided) and asks for a reason instead, same as anulo biletën
                                // on the operator's own Biletë e humbur screen.
                                ...($canVoid ? [
                                    \Filament\Forms\Components\Toggle::make('void')
                                        ->label('Anulo këtë biletë (e lëshuar gabimisht)')
                                        ->live()
                                        ->default(false),
                                    Textarea::make('void_reason')
                                        ->label('Arsyeja e anulimit')
                                        ->rows(2)
                                        ->required(fn (Get $get) => (bool) $get('void'))
                                        ->visible(fn (Get $get) => (bool) $get('void')),
                                ] : []),
                                Select::make('vehicle_type_id')
                                    ->label('Lloji i mjetit')
                                    ->options(VehicleType::query()->orderBy('sort_order')->pluck('name', 'id'))
                                    ->required()
                                    ->visible(fn (Get $get) => ! $get('void')),
                                TextInput::make('plate')
                                    ->label('Targa')
                                    ->maxLength(20)
                                    ->nullable()
                                    ->visible(fn (Get $get) => ! $get('void')),
                                DateTimePicker::make('entered_at')
                                    ->label('Ora e hyrjes')
                                    ->native(false)
                                    ->seconds(false)
                                    ->required(fn (Get $get) => ! $get('void'))
                                    ->maxDate(now())
                                    ->visible(fn (Get $get) => ! $get('void')),
                                Placeholder::make('breakdown')
                                    ->label('Zbërthimi i çmimit')
                                    ->content(fn () => static::breakdownHtml($breakdown))
                                    ->visible(fn (Get $get) => ! $get('void')),
                                TextInput::make('price_override')
                                    ->label('Çmimi i personalizuar (opsionale)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->nullable()
                                    ->helperText('Lëreni bosh për të lënë çmimin e llogaritur si zakonisht. Çfarëdo shume këtu arkëtohet pikërisht ashtu — pavarësisht orëve — kur bileta të mbyllet, përveç nëse operatori shkruan një çmim tjetër në arkëtim.')
                                    ->visible(fn (Get $get) => ! $get('void')),
                                Textarea::make('price_override_reason')
                                    ->label('Arsyeja e çmimit të personalizuar')
                                    ->rows(2)
                                    ->required(fn (Get $get) => ! $get('void') && filled($get('price_override')))
                                    ->visible(fn (Get $get) => ! $get('void') && filled($get('price_override'))),
                            ];
                        }

                        return [
                            TextInput::make('final_price')
                                ->label('Çmimi përfundimtar')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->required(),
                            Textarea::make('reason')
                                ->label('Arsyeja')
                                ->required()
                                ->rows(2),
                        ];
                    })
                    ->modalHeading(fn (ParkingSession $record) => $record->status === ParkingSession::STATUS_ACTIVE
                        ? 'Ndrysho biletën'
                        : 'Korrigjo çmimin')
                    ->modalDescription(fn (ParkingSession $record) => $record->status === ParkingSession::STATUS_ACTIVE
                        ? 'Ndryshimet regjistrohen në regjistrin e auditimit.'
                        : 'Çmimi i llogaritur origjinal mbetet i paprekur; ndryshon vetëm çmimi përfundimtar. Regjistrohet në regjistrin e auditimit.')
                    ->modalWidth(fn (ParkingSession $record) => $record->status === ParkingSession::STATUS_ACTIVE ? 'lg' : 'md')
                    ->action(function (ParkingSession $record, array $data, TicketEditor $editor, \App\Services\VoidService $voidService) {
                        try {
                            if ($record->status === ParkingSession::STATUS_ACTIVE && ($data['void'] ?? false)) {
                                $voidService->void($record, auth()->user(), $data['void_reason'] ?? '');

                                Notification::make()->title('Bileta u anulua')->success()->send();

                                return;
                            }

                            if ($record->status === ParkingSession::STATUS_ACTIVE) {
                                $override = $data['price_override'] ?? null;

                                $editor->edit(
                                    $record,
                                    auth()->user(),
                                    VehicleType::query()->findOrFail($data['vehicle_type_id']),
                                    $data['plate'] ?? null,
                                    Carbon::parse($data['entered_at']),
                                    $override === null || $override === '' ? null : (float) $override,
                                    $data['price_override_reason'] ?? null,
                                );
                            } else {
                                $editor->correctPrice($record, auth()->user(), (float) $data['final_price'], $data['reason']);
                            }
                        } catch (ParkingException|AuthorizationException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Bileta u përditësua')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTickets::route('/'),
        ];
    }

    /** The same line-by-line price breakdown the operator's checkout screen shows, as static HTML for a form Placeholder. */
    private static function breakdownHtml(PriceResult $result): HtmlString
    {
        if ($result->graceApplied) {
            return new HtmlString('<p class="text-sm text-gray-500">Brenda periudhës falas — ende falas.</p>');
        }

        $rows = '';

        foreach ($result->toArray()['days'] as $day) {
            $date = e(Carbon::parse($day['date'])->locale('sq')->translatedFormat('d M'));

            if ($day['long_stay_applied']) {
                $rows .= sprintf(
                    '<div class="flex justify-between gap-3 py-1"><span>%s · Tarifa ditore</span><span>%s</span></div>',
                    $date,
                    e(Format::amount($day['charged'], 2)),
                );
            } else {
                foreach ($day['lines'] as $line) {
                    $rows .= sprintf(
                        '<div class="flex justify-between gap-3 py-1"><span>%s · %s · %s × %s</span><span>%s</span></div>',
                        $date,
                        e($line['label']),
                        e((string) $line['units']),
                        e(Format::amount($line['unit_price'], 2)),
                        e(Format::amount($line['amount'], 2)),
                    );
                }

                if ($day['daily_cap_applied']) {
                    $rows .= sprintf(
                        '<div class="flex justify-between gap-3 py-1"><span>Maksimumi ditor u aplikua</span><span>−%s</span></div>',
                        e(Format::amount($day['gross'] - $day['charged'], 2)),
                    );
                }
            }
        }

        $rows .= sprintf(
            '<div class="mt-1 flex justify-between gap-3 border-t pt-1 font-semibold"><span>Të llogaritur</span><span>%s</span></div>',
            e(Format::money($result->total())),
        );

        return new HtmlString('<div class="space-y-0.5 text-sm">'.$rows.'</div>');
    }
}
