<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\Permissions;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        // Defence in depth: see EditUser::afterSave().
        $kept = $this->record->getDirectPermissions()->pluck('name')
            ->intersect(Permissions::individuallyGrantablePermissions());
        $this->record->syncPermissions($kept);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
