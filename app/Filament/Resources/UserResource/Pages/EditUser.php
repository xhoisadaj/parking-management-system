<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\Permissions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

/**
 * Users are deactivated rather than deleted: past tickets reference them.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        // Defence in depth: the form only shows the two grantable permissions as options, but a
        // crafted request could still submit another permission's ID. Strip anything else from
        // the user's direct grants — admin-only permissions can never end up attached to someone
        // outside the Admin/Manager roles, and issue_ticket/checkout (already on every Operator
        // via the role) never end up as a redundant, confusing direct grant either.
        $kept = $this->record->getDirectPermissions()->pluck('name')
            ->intersect(Permissions::individuallyGrantablePermissions());
        $this->record->syncPermissions($kept);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
