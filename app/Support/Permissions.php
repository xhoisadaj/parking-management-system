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

    public const ADJUST_OWN_TICKET_PRICE = 'adjust_own_ticket_price';

    public const VOID_TICKET = 'void_ticket';

    public const EDIT_TICKET = 'edit_ticket';

    public const MANAGE_OWN_TICKETS = 'manage_own_tickets';

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
            self::ADJUST_PRICE => 'Ndryshon çmimin (zbritje te arkëtimi)',
            self::ADJUST_OWN_TICKET_PRICE => 'Ndryshon çmimin te Biletat e mia',
            self::VOID_TICKET => 'Anulon biletën',
            self::EDIT_TICKET => 'Ndryshon një biletë të lëshuar (targa, mjeti, ora e hyrjes)',
            self::MANAGE_OWN_TICKETS => 'Sheh biletat e veta (sot/turn/7 ditë) dhe ndryshon targën/mjetin/orën e atyre aktive',
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
            self::ADJUST_OWN_TICKET_PRICE,
            self::VOID_TICKET,
            self::EDIT_TICKET,
            self::MANAGE_OWN_TICKETS,
            self::MANAGE_TARIFFS,
            self::MANAGE_SETTINGS,
            self::MANAGE_USERS,
            self::VIEW_STATISTICS,
            self::VIEW_AUDIT_LOG,
            self::RECONCILE_SHIFTS,
        ];
    }

    /**
     * The only permissions that can be granted to an individual user as an "extra permission"
     * (see the Users form). All four are optional, above-and-beyond abilities: not every operator
     * should be able to discount a sale, void a ticket, review their own ticket history, or correct
     * a price on one of their own tickets, so these are worth toggling per person.
     *
     * adjust_price and adjust_own_ticket_price are deliberately two separate permissions, not one:
     * adjust_price only ever matters at checkout (a discount while taking payment), while
     * adjust_own_ticket_price only ever matters on Biletat e mia (correcting the price on an
     * operator's own still-active ticket, see MyTicketsScreen). Granting one does not imply the
     * other, so an operator can be trusted with one of these without the other.
     *
     * issue_ticket and checkout are deliberately left out, even though they're also
     * operator-screen permissions: every Operator already has both from the role by default
     * (see DefaultRolesSeeder), so granting either one individually is always a no-op — there is
     * no user for whom checking that box changes anything observable.
     *
     * The other seven (adminAreaPermissions(), below) are excluded on purpose too: they're each
     * also a page inside the Menaxhimi panel (see ManagementPanelProvider), so granting one
     * individually would hand that specific operator a page in that panel — a bigger decision than
     * this quick per-user checkbox is meant for. Today those seven only ever come from a role
     * (Admin or Manager); widening this list is how that would change, deliberately.
     *
     * @return list<string>
     */
    public static function individuallyGrantablePermissions(): array
    {
        return [
            self::ADJUST_PRICE,
            self::ADJUST_OWN_TICKET_PRICE,
            self::VOID_TICKET,
            self::MANAGE_OWN_TICKETS,
        ];
    }

    /**
     * The permissions that each correspond to a page inside the Menaxhimi panel (Filament resources
     * and pages, gated per-resource by their own requiredPermission()/canAccess()). Having any one
     * of these is what lets a user open that panel at all — see User::canAccessPanel().
     *
     * @return list<string>
     */
    public static function adminAreaPermissions(): array
    {
        return [
            self::EDIT_TICKET,
            self::MANAGE_TARIFFS,
            self::MANAGE_SETTINGS,
            self::MANAGE_USERS,
            self::VIEW_STATISTICS,
            self::VIEW_AUDIT_LOG,
            self::RECONCILE_SHIFTS,
        ];
    }
}
