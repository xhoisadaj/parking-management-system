<?php

namespace App\Support;

/**
 * Permission names used across the app. Keep in sync with DefaultRolesSeeder.
 */
final class Permissions
{
    public const ISSUE_TICKET = 'issue_ticket';

    public const CHECKOUT = 'checkout';

    public const ADJUST_PRICE = 'adjust_price';

    public const VOID_TICKET = 'void_ticket';

    public const MANAGE_TARIFFS = 'manage_tariffs';

    public const MANAGE_SETTINGS = 'manage_settings';

    public const MANAGE_USERS = 'manage_users';

    public const VIEW_STATISTICS = 'view_statistics';

    public const VIEW_AUDIT_LOG = 'view_audit_log';

    public const RECONCILE_SHIFTS = 'reconcile_shifts';

    /** Albanian label for a permission code, shown in the admin panel. */
    public static function label(string $permission): string
    {
        return match ($permission) {
            self::ISSUE_TICKET => 'Lëshon biletë (hyrje)',
            self::CHECKOUT => 'Arkëtim dhe biletë e humbur',
            self::ADJUST_PRICE => 'Ndryshon çmimin',
            self::VOID_TICKET => 'Anulon biletën',
            self::MANAGE_TARIFFS => 'Menaxhon tarifat dhe llojet e mjeteve',
            self::MANAGE_SETTINGS => 'Menaxhon cilësimet',
            self::MANAGE_USERS => 'Menaxhon përdoruesit dhe rolet',
            self::VIEW_STATISTICS => 'Shikon statistikat',
            self::VIEW_AUDIT_LOG => 'Shikon regjistrin e auditimit',
            self::RECONCILE_SHIFTS => 'Verifikon arkën e turnit',
            default => $permission,
        };
    }

    /** Albanian label for a role name stored in the database. */
    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'Admin' => 'Administrator',
            'Manager' => 'Menaxher',
            'Operator' => 'Operator',
            default => $role,
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::ISSUE_TICKET,
            self::CHECKOUT,
            self::ADJUST_PRICE,
            self::VOID_TICKET,
            self::MANAGE_TARIFFS,
            self::MANAGE_SETTINGS,
            self::MANAGE_USERS,
            self::VIEW_STATISTICS,
            self::VIEW_AUDIT_LOG,
            self::RECONCILE_SHIFTS,
        ];
    }
}
