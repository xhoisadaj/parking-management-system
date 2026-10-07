# Menaxhimi i Parkimit

Sistem për menaxhimin e parkimit: hyrja me biletë, arkëtimi me ndryshime çmimi, biletat e humbura,
verifikimi i arkës së turnit, printimi termik i biletave dhe paneli i statistikave.

- **Backend:** Laravel 11, PHP 8.3, MySQL 8+ (testuar me MySQL 9.2)
- **Paneli i administrimit:** Filament v3 në `/admin`
- **Ekranet e operatorit:** Livewire 3 + Tailwind CSS, të optimizuara për telefon, të përdorshme nga 360 px gjerësi
- **Lejet:** spatie/laravel-permission (role, plus leje shtesë për çdo përdorues)
- **Çmimi:** `App\Services\PriceCalculator`, një shërbim i pastër me testet e veta. Çdo biletë çmohet sipas një kopjeje të tarifës së saj, të marrë në momentin e hyrjes.

## Kërkesat

- PHP 8.3 me `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd`, `zip`
- Composer 2, Node.js 20+ dhe npm
- MySQL 8.0+ (ose Docker)
- Opsionale: [Laravel Herd](https://herd.laravel.com) për një domain lokal `.test`

## Instalimi

```bash
git clone <repo> parking && cd parking

composer install
cp .env.example .env
php artisan key:generate

# Baza e të dhënave: krijo skemën dhe një përdorues për aplikacionin
mysql -u root -e "CREATE DATABASE parking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'parking'@'localhost' IDENTIFIED BY 'ndrysho-me';
GRANT ALL PRIVILEGES ON parking.* TO 'parking'@'localhost';"
```

Vendos vlerat e bazës së të dhënave dhe të aplikacionit në `.env`:

```ini
APP_NAME="Menaxhimi i Parkimit"
APP_LOCALE=sq
APP_TIMEZONE=Europe/Tirane
APP_URL=http://parking.test          # ose http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=parking
DB_USERNAME=parking
DB_PASSWORD=ndrysho-me

PARKING_PRINTER=browser              # e vetmja mënyrë printimi sot; shih "Printimi"
ADMIN_EMAIL=admin@parking.test       # përdoret për llogarinë e parë të administratorit
ADMIN_PASSWORD=ndrysho-tani          # ndryshoje para përdorimit real
```

Më pas krijo skemën dhe mbushe me të dhëna:

```bash
php artisan migrate --seed           # rolet, administratori, cilësimet, tarifat, të dhënat demo
npm install && npm run build         # kompilon Tailwind për ekranet e operatorit
php artisan serve                    # ose hap faqen e Herd
```

Me Herd, lidh folderin (`herd link parking`) dhe fikso PHP 8.3 (`herd isolate 8.3`).

Për prodhim, hiq `DemoDataSeeder` nga `database/seeders/DatabaseSeeder.php` (ai gjithashtu refuzon
të ekzekutohet kur `APP_ENV=production`), ekzekuto `php artisan config:cache route:cache view:cache`,
dhe kompilo asetet me `npm run build`.

## Llogaritë e parazgjedhura (zhvillim)

| Llogaria | Email | Fjalëkalimi | Roli |
|---|---|---|---|
| Administratori | `admin@parking.test` | `password` (ose `ADMIN_PASSWORD`) | Administrator |
| Mira Menaxhere (demo) | `mira@parking.test` | `password` | Menaxher |
| Ana Operatore (demo) | `ana@parking.test` | `password` | Operator |
| Ben Operatori (demo) | `ben@parking.test` | `password` | Operator |

Ndrysho çdo fjalëkalim para se ta përdorësh sistemin me stafin real. Llogaritë demo ekzistojnë vetëm
për të dhënat demo; fshiji ato në prodhim.

- **Paneli i administrimit:** `/admin` (Administrator, Menaxher, ose çdo rol me një leje të zonës së administrimit)
- **Ekranet e operatorit:** `/operator` (hyr te `/operator/login`)

## Rolet dhe lejet

| Leja | Administrator | Menaxher | Operator | Çfarë lejon |
|---|:-:|:-:|:-:|---|
| `issue_ticket` | ✓ | ✓ | ✓ | Ekrani i hyrjes, printimi i biletës së hyrjes |
| `checkout` | ✓ | ✓ | ✓ | Arkëtimi, biletat e humbura, kuponët |
| `adjust_price` | ✓ | ✓ | | Ndryshon çmimin në arkëtim (arsyeja kërkohet për ndryshime të mëdha) |
| `void_ticket` | ✓ | ✓ | | Anulon një biletë aktive të lëshuar gabimisht |
| `manage_tariffs` | ✓ | ✓ | | Llojet e mjeteve dhe tarifat |
| `manage_settings` | ✓ | | | Cilësimet e parkimit, orari i punës, limitet e zbritjes për rol |
| `manage_users` | ✓ | | | Përdoruesit, rolet, lejet e roleve |
| `view_statistics` | ✓ | ✓ | | Paneli dhe eksportimet |
| `view_audit_log` | ✓ | ✓ | | Regjistri i auditimit |
| `reconcile_shifts` | ✓ | ✓ | | Konfirmon numërimin e parave për një turn të mbyllur |

Lejet mund t'i jepen edhe direkt një përdoruesi (**Përdoruesit → Leje shtesë**). Lejet e drejtpërdrejta
nuk rrisin kurrë limitin e zbritjes, i cili vjen vetëm nga rolet.

**Limitet e zbritjes** caktohen për çdo rol, në përqind. `0` do të thotë pa zbritje, dhe një vlerë bosh do
të thotë pa kufi. Parazgjedhjet: Administratori pa kufi, Menaxheri 20%, Operatori 0%. Vetëm përdoruesit me
`manage_settings` mund t'i ndryshojnë. Një përdorues me disa role merr limitin më të lartë, dhe çdo rol pa
kufi e bën përdoruesin pa kufi.

## Përdorimi i ekraneve të operatorit

1. **Hyrja:** zgjidh llojin e mjetit. Biletë printohet automatikisht. Hyrja bllokohet kur lloji është i
   plotë, kur arrihet kufiri i dedikuar, ose kur nuk ka tarifë të vlefshme për të.
2. **Arkëtimi:** skano barkodin me skaner USB, që shkruan kodin dhe shtyp Enter, ose shkruaj kodin. Shfaqen
   zbërthimi dhe totali. Konfirmo për të marrë pagesën dhe për të printuar kuponin.
   - Shkruaj **shumën e marrë**. Ekrani tregon kusurin për t'u kthyer, ose sa mungon akoma. Para e
     marrë është opsionale; pa të, bileta thjesht shënohet si e paguar.
   - Operatorët pa `adjust_price` nuk e shohin fushën e çmimit. Serveri gjithashtu refuzon çdo ndryshim
     çmimi nga ata, edhe nëse kërkesa është manipuluar.
   - **Arsyeja** kërkohet vetëm kur ndryshimi i çmimit arrin pragun te **Cilësimet e parkimit → Biletat** (një
     përqindje e çmimit të llogaritur; 0 do të thotë që çdo ndryshim kërkon arsye). Ndryshimet më të vogla
     regjistrohen akoma si ndryshime.
3. **Biletë e humbur:** kërko sipas targës ose kodit, pastaj arkëto tarifën e biletës së humbur nga cilësimet.
4. **Turni im:** tregon turnin e punës që të është caktuar (p.sh. Mëngjes 06:00 – 14:00) dhe paralajmëron nëse
   je jashtë orarit. Turni hapet automatikisht me veprimin e parë. Mbyll turnin për të ruajtur totalet. Më pas
   një menaxher konfirmon numërimin e parave te **Operacionet → Verifikimi i arkës**, ku shfaqet edhe turni i punës.

Menaxherët caktojnë turnet e punës te **Konfigurimi → Turnet e punës**, dhe zgjedhin turnin e një operatori te
formulari i përdoruesit.

## Printimi

Biletat printohen përmes një faqeje të optimizuar për printim, e cila hapet në një kornizë të fshehur, që
operatori të mbetet në ekran. Dialogu i printimit hapet automatikisht.

### Konfigurimi i printerit termik (58 mm ose 80 mm)

1. Te **Cilësimet e parkimit → Biletat**, zgjidh gjerësinë e letrës që përputhet me rulon.
2. Instalo drejtuesin (driver) e printerit për modelin tënd dhe vendose si printer parazgjedhës në sistem.
3. Në Chrome (ose Edge), në dialogun e printimit:
   - **Destinacioni:** printeri yt termik
   - **Madhësia e letrës:** e njëjta gjerësi me rulon, ose "Parazgjedhja" nëse përputhet me `@page` të faqes
   - **Margjinat:** Asnjë
   - **Shkalla:** 100%
   - **Kokat dhe fundet:** çaktivizuar
   - **Grafika e sfondit:** aktivizuar, që barkodi të printohet qartë
4. Printo një biletë provë një herë për të kontrolluar gjerësinë dhe leximin e barkodit.

Barkodi Code128 i biletës së hyrjes përmban vetëm kodin e biletës. Kuponi nuk ka barkod.

### Shtimi i printimit direkt (ESC/POS) më vonë

Printimi qëndron pas `App\Services\Printing\TicketPrinter`. Ekranet e operatorit varen vetëm nga ky
ndërfaqe. Për të shtuar printimin direkt:

1. Implemento `TicketPrinter` (p.sh. me `mike42/escpos-php`). Kthe një `PrintJob` në formën që të nevojitet.
2. Regjistro drejtuesin në `AppServiceProvider` dhe shto një rast për të.
3. Vendos `PARKING_PRINTER=drejtuesi-yt` në `.env`.

Asnjë kod ekrani nuk duhet të ndryshohet.

## Statistikat

Paneli (`/admin`) mbulon sot, këtë javë, këtë muaj, dhe çdo interval të personalizuar:

- Të ardhurat dhe qëndrimi mesatar, dhe të ardhurat e llogaritura kundrejt atyre reale, me ndikimin e ndryshimeve.
- Të ardhurat sipas llojit të mjetit, dhe zëna aktuale për çdo lloj.
- Një hartë e orëve të pikut me mesataren e vendeve të zëna sipas ditës së javës dhe orës.
- Një tabelë e operatorëve: biletat e lëshuara, arkëtimet, të ardhurat, dhe ndryshimet (numri dhe vlera neto).

**Eksportimi:** Excel (një fletë për biletat, për të ardhurat sipas llojit, dhe për operatorët) ose CSV (biletat).
Të dyja përdorin periudhën e zgjedhur.

Të ardhurat numërohen sipas kohës së daljes, dhe hyrjet sipas kohës së hyrjes. Biletat e anuluara përjashtohen.

## Rregullat e çmimit

Çdo tarifë përcakton një njësi faturimi (minuta), një çmim për njësi, një çmim opsional për njësinë e parë, një
periudhë falas, një maksimum ditor opsional, dhe intervale kohore opsionale.

- **Periudha falas:** një qëndrim brenda periudhës falas është falas. Një qëndrim edhe një minutë më i gjatë paguhet i plotë.
- **Rrumbullakimi:** një njësi e pjesshme paguhet si njësi e plotë.
- **Intervalet kohore:** një njësi çmohet sipas intervalit ku bie fillimi i saj. Një interval që mbaron para se të
  fillojë kalon mesnatën (p.sh. 22:00–06:00).
- **Maksimumi ditor:** kufizon pagesat e çdo dite kalendarike, në zonën kohore të parkimit.
- **Koha e kaluar:** matet në kohën reale, kështu që ndryshimet e orës së verës trajtohen saktë.
- **Ndryshimet e tarifës** zbatohen vetëm për biletat e lëshuara më pas. Biletat ekzistuese mbajnë kopjen e tyre.

## Testimi

```bash
php artisan test             # ose: vendor/bin/phpunit
```

Testet përdorin një bazë të dhënash SQLite në memorie, pra nuk kanë nevojë për MySQL. Ato mbulojnë:

- motorin e çmimit (periudha falas, rrumbullakimi, maksimumi ditor, qëndrimet me shumë ditë, intervalet
  e mesnatës, dhe ndryshimet e orës në Europe/Tirane);
- kufijtë e kapacitetit dhe hyrjet e njëpasnjëshme deri në kufi;
- lejet, përfshirë ndryshimet e çmimit të refuzuara përmes një kërkese të drejtpërdrejtë;
- limitet e zbritjes sipas rolit;
- rrjedhat e arkëtimit, biletës së humbur, anulimit dhe turnit;
- faqet e printimit dhe autorizimi i tyre;
- shifrat e statistikave, harta e orës së pikut, dhe eksportimet.

## Shënime dhe kufizime

- **Njëkohshmëria:** hyrjet marrin një kyçje (lock) te rreshti i cilësimeve, dhe çdo arkëtim kyç biletën e vet,
  kështu që operatorët paralelë nuk mund ta kalojnë kapacitetin ose ta mbyllin të njëjtën biletë dy herë. Është
  testuar në mënyrë sekuenciale; sjellja paralele duhet verifikuar me konfigurimin tënd të MySQL përpara
  nisjes në përdorim real.
- **Historiku i zënies** përdor `spots_used` aktual të çdo lloji mjeti. Ndryshimi i tij ndryshon mënyrën
  si raportohen orët e kaluara.
- **Orari i punës** shfaqet te ekrani i hyrjes si paralajmërim. Nuk bllokon hyrjen, që operatorët
  të mund të lëshojnë makina jashtë orarit.
- **Printimi në shfletues** varet nga cilësimet e printimit të shfletuesit (shih "Printimi").
- **Ekranet e operatorit** janë vetëm me ngjyra të çelëta dhe nuk funksionojnë pa internet.
