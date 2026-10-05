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
