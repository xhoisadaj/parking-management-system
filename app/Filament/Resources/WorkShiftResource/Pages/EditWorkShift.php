<?php

namespace App\Filament\Resources\WorkShiftResource\Pages;

use App\Filament\Resources\WorkShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWorkShift extends EditRecord
{
    protected static string $resource = WorkShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
