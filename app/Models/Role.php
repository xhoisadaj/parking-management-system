<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'max_discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'max_discount_percent' => 'decimal:2',
        ];
    }
}
