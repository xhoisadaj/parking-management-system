<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Support\Permissions;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Administrimi';

    protected static ?string $navigationLabel = 'Regjistri i auditimit';

    protected static ?string $pluralModelLabel = 'regjistrimet e auditimit';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'regjistrim auditimi';

    protected static function requiredPermission(): string
    {
        return Permissions::VIEW_AUDIT_LOG;
    }

    /** Append-only: nothing can be created, edited or deleted from the panel. */
    protected static function allowsWrites(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Kur')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('user.name')->label('Përdoruesi')->placeholder('Sistemi'),
                TextColumn::make('event')->label('Ngjarja')->formatStateUsing(fn (string $state) => AuditLog::eventLabel($state))->badge()->searchable(),
                TextColumn::make('auditable_type')->label('Objekti')->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
                TextColumn::make('auditable_id')->label('ID')->placeholder('—'),
                TextColumn::make('reason')->label('Arsyeja')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->label('Ngjarja')->options(fn () => AuditLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
