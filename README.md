# Parking Manager

A parking lot management system: ticket entry, checkout with price adjustments, lost tickets,
shift reconciliation, thermal ticket printing, and a statistics dashboard.

- **Backend:** Laravel 11, PHP 8.3, MySQL 8+ (tested on MySQL 9.2)
- **Admin:** Filament v3 at `/admin`
- **Operator screens:** Livewire 3 + Tailwind CSS, mobile-first, usable from 360 px wide
- **Permissions:** spatie/laravel-permission (roles, plus per-user extra permissions)
- **Pricing:** `App\Services\PriceCalculator`, a pure service with its own tests. Every ticket is priced from a snapshot of its tariff taken at entry.

## Requirements

- PHP 8.3 with `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd`, `zip`
- Composer 2, Node.js 20+ and npm
- MySQL 8.0+ (or Docker)
- Optional: [Laravel Herd](https://herd.laravel.com) for a local `.test` domain

## Setup

```bash
git clone <repo> parking && cd parking

composer install
cp .env.example .env
php artisan key:generate

# Database: create the schema and a user for the app
mysql -u root -e "CREATE DATABASE parking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'parking'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON parking.* TO 'parking'@'localhost';"
```

Set the database and app values in `.env`:

```ini
APP_NAME="Parking Manager"
APP_TIMEZONE=Europe/Tirane
APP_URL=http://parking.test          # or http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=parking
DB_USERNAME=parking
DB_PASSWORD=change-me

PARKING_PRINTER=browser              # the only driver today; see "Printers"
ADMIN_EMAIL=admin@parking.test       # used by the first admin account
ADMIN_PASSWORD=change-me-now         # change this before real use
```

Then build the schema and seed it:

```bash
php artisan migrate --seed           # roles, admin, settings, sample tariffs, demo data
npm install && npm run build         # compile Tailwind for the operator screens
php artisan serve                    # or open the Herd site
```

With Herd, link the folder (`herd link parking`) and pin PHP 8.3 (`herd isolate 8.3`).

For production, drop `DemoDataSeeder` from `database/seeders/DatabaseSeeder.php` (it also refuses to
run when `APP_ENV=production`), run `php artisan config:cache route:cache view:cache`, and build
assets with `npm run build`.

## Default logins (development)

| Account | Email | Password | Role |
|---|---|---|---|
| Administrator | `admin@parking.test` | `password` (or `ADMIN_PASSWORD`) | Admin |
| Mira Manager (demo) | `mira@parking.test` | `password` | Manager |
| Ana Operator (demo) | `ana@parking.test` | `password` | Operator |
| Ben Operator (demo) | `ben@parking.test` | `password` | Operator |

Change every password before using the system with real staff. The demo accounts exist only for
seeded demo data; delete them in production.

- **Admin panel:** `/admin` (Admin, Manager, or any role with an admin-area permission)
- **Operator screens:** `/operator` (sign in at `/operator/login`)

## Roles and permissions

| Permission | Admin | Manager | Operator | What it allows |
|---|:-:|:-:|:-:|---|
| `issue_ticket` | ✓ | ✓ | ✓ | Entry screen, entry ticket printing |
| `checkout` | ✓ | ✓ | ✓ | Checkout, lost tickets, receipts |
| `adjust_price` | ✓ | ✓ | | Change the price at checkout (a reason is needed for large changes) |
| `void_ticket` | ✓ | ✓ | | Void an active ticket issued by mistake |
| `manage_tariffs` | ✓ | ✓ | | Vehicle types and tariffs |
| `manage_settings` | ✓ | | | Parking settings, opening hours, role discount limits |
| `manage_users` | ✓ | | | Users, roles, role permissions |
| `view_statistics` | ✓ | ✓ | | Dashboard and exports |
| `view_audit_log` | ✓ | ✓ | | Audit log |
| `reconcile_shifts` | ✓ | ✓ | | Confirm the cash count for a closed shift |

Permissions can also be granted to one user directly (**Users → Extra permissions**). Direct
grants never raise the discount limit, which comes from roles only.

**Discount limits** are per role, in percent. `0` means no discounts, and an empty value means
unlimited. Defaults: Admin unlimited, Manager 20%, Operator 0%. Only users with `manage_settings`
can change them. A user with several roles gets the highest limit, and any unlimited role makes
the user unlimited.

## Using the operator screens

1. **Entry:** pick the vehicle type. The ticket prints automatically. Entry is blocked when the
   type is full, when its dedicated cap is reached, or when no tariff is valid for it.
2. **Checkout:** scan the barcode with a USB scanner, which types the code and presses Enter, or type
   the code. The breakdown and total appear. Confirm to take payment and print the receipt.
   - Enter the **amount received**. The screen shows the change to give back, or how much is still short. Cash is optional; without it, the ticket is simply paid.
   - Operators without `adjust_price` see no price field. The server also refuses any change to the price from them, even if a request is crafted.
   - A **reason** is required only when the price change reaches the threshold in **Parking settings → Tickets** (a percentage of the calculated price; 0 means every change needs one). Smaller changes are still recorded as adjustments.
3. **Lost ticket:** search by plate or code, then charge the lost-ticket fee from settings.
4. **My shift:** shows the work shift assigned to you (for example Morning 06:00 – 14:00) and warns if you are
   outside those hours. The shift opens automatically on first activity. Close it to store its totals. A
   manager then confirms the counted cash in **Operations → Shift reconciliation**, which shows the work shift.

Managers assign work shifts in **Configuration → Work shifts** and pick an operator's shift on the user form.


## Printers

Tickets are printed through a print-optimised page that opens in a hidden frame, so the operator
stays on the screen. The print dialog opens automatically.

### Setting up a thermal printer (58 mm or 80 mm)

1. In **Parking settings → Tickets**, choose the paper width that matches your roll.
2. Install the printer driver for your model and set it as the default printer in the OS.
3. In Chrome (or Edge), in the print dialog:
   - **Destination:** your thermal printer
   - **Paper size:** the same width as the roll, or "Default" if it matches the page's `@page` size
   - **Margins:** None
   - **Scale:** 100%
   - **Headers and footers:** off
   - **Background graphics:** on, so the barcode prints clearly
4. Print a test ticket once to check the width and the barcode scan.

The entry ticket's Code128 barcode contains only the ticket code. The receipt is not barcoded.

### Adding direct (ESC/POS) printing later

Printing sits behind `App\Services\Printing\TicketPrinter`. The operator screens depend only on
that interface. To add direct printing:

1. Implement `TicketPrinter` (for example with `mike42/escpos-php`). Return a `PrintJob` in
   the form you need.
2. Register the driver in `AppServiceProvider` and add a case for it.
3. Set `PARKING_PRINTER=yourdriver` in `.env`.

No screen code needs to change.

## Statistics

The dashboard (`/admin`) covers today, this week, this month, and any custom range:

- Revenue and average stay, and calculated vs actual revenue, with the impact of adjustments.
- Revenue by vehicle type, and live occupancy per type.
- A peak-times heatmap of average occupied spots by weekday and hour.
- A table of operators: tickets issued, checkouts, revenue, and adjustments (count and net value).

**Export:** Excel (one sheet each for tickets, revenue by type, and operators) or CSV (tickets).
Both use the selected period.

Revenue is counted by exit time, and entries by entry time. Voided tickets are excluded.

## Pricing rules

Each tariff sets a billing unit (minutes), a price per unit, an optional first-unit price, a grace
period, an optional daily maximum, and optional time bands.

- **Grace:** a stay within the grace period is free. A stay even one minute longer is charged in full.
- **Rounding:** a partial unit is charged as a full unit.
- **Time bands:** a unit is priced by the band its start time falls in. A band whose end is earlier
  than its start crosses midnight (for example 22:00–06:00).
- **Daily maximum:** caps each calendar day's charges, in the parking's timezone.
- **Elapsed time:** measured in real time, so daylight-saving changes are handled correctly.
- **Tariff edits** apply only to tickets issued afterwards. Existing tickets keep their snapshot.

## Testing

```bash
php artisan test             # or: vendor/bin/phpunit
```

The suite uses an in-memory SQLite database, so it does not need MySQL. It covers:

- the pricing engine (grace, rounding, daily maximum, multi-day stays, midnight time bands, and
  DST transitions in Europe/Tirane);
- capacity limits and sequential entries at the limit;
- permissions, including refused price adjustments through a direct request;
- discount limits by role;
- checkout, lost-ticket, void, and shift flows;
- printing pages and their authorisation;
- statistics figures, the occupancy heatmap, and exports.

## Notes and limits

- **Concurrency:** entries take a row lock on the settings row, and each checkout locks its ticket,
  so parallel operators cannot exceed capacity or close the same ticket twice. Tested sequentially;
  concurrent behaviour should be checked against your MySQL setup before go-live.
- **Occupancy history** uses each vehicle type's current `spots_used`. Changing it alters how past
  hours are reported.
- **Opening hours** are shown on the entry screen as a warning. They do not block entry, so
  operators can still let cars out.
- **Browser printing** depends on the browser's print settings (see "Printers").
- **Operator screens** are light-only and not offline-capable.
