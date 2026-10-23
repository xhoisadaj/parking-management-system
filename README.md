# Menaxhimi i Parkimit

Sistem për menaxhimin e parkimit: hyrja me biletë, arkëtimi me ndryshime çmimi, biletat e humbura,
verifikimi i arkës së turnit, printimi termik i biletave dhe paneli i statistikave.

- **Backend:** Laravel 11, PHP 8.3, MySQL 8+ (testuar me MySQL 9.2)
- **Panelet e administrimit:** Filament v3 — `/admin` për Administratorin, `/menaxhimi` për Menaxherët
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
php artisan migrate --seed           # rolet, administratori, cilësimet, turnet e punës
npm install && npm run build         # kompilon Tailwind për ekranet e operatorit
php artisan serve                    # ose hap faqen e Herd
```

Seeder-at krijojnë vetëm atë që i duhet aplikacionit për të punuar: rolet, llogaria e parë e
administratorit, cilësimet, dhe turnet e punës. **Asnjë lloj mjeti ose tarifë nuk krijohet
automatikisht** — hyr si administrator dhe krijoji te **Konfigurimi → Llojet e mjeteve** dhe
**Konfigurimi → Tarifat** para se operatorët të fillojnë punën.

Me Herd, lidh folderin (`herd link parking`) dhe fikso PHP 8.3 (`herd isolate 8.3`).

Për prodhim, ekzekuto `php artisan config:cache route:cache view:cache` dhe kompilo asetet me
`npm run build`.

## Llogaria e parazgjedhur

| Llogaria | Email | Fjalëkalimi | Roli |
|---|---|---|---|
| Administratori | `ADMIN_EMAIL` (parazgjedhje: `admin@parking.test`) | `ADMIN_PASSWORD` (parazgjedhje: `password`) | Administrator |

Kjo është e vetmja llogari që krijohet automatikisht. Vendos `ADMIN_EMAIL`/`ADMIN_PASSWORD` në `.env`
para se të mbushësh bazën e të dhënave, ose ndrysho fjalëkalimin menjëherë pas hyrjes së parë. Llogaritë
e Menaxherëve dhe Operatorëve krijohen te **Administrimi → Përdoruesit**.

- **Paneli i administrimit:** `/admin` — vetëm për rolin Administrator.
- **Paneli i menaxhimit:** `/menaxhimi` — për këdo që ka të paktën një leje të zonës së administrimit
  (`edit_ticket`, `manage_tariffs`, `manage_settings`, `manage_users`, `view_statistics`, `view_audit_log`,
  `reconcile_shifts`), d.m.th. Menaxherët. Ka të njëjtat faqe si `/admin`, por secili sheh vetëm faqet për të
  cilat ka leje. Përdor të njëjtin hyrje si ekranet e operatorit, kështu që një Menaxher që ka hyrë te
  `/operator/login` hyn direkt.
- **Ekranet e operatorit:** `/operator` (hyr te `/operator/login`)

## Rolet dhe lejet

| Leja | Administrator | Menaxher | Operator | Çfarë lejon |
|---|:-:|:-:|:-:|---|
| `issue_ticket` | ✓ | ✓ | ✓ | Ekrani i hyrjes, printimi i biletës së hyrjes |
| `checkout` | ✓ | ✓ | ✓ | Arkëtimi, biletat e humbura, kuponët |
| `adjust_price` | ✓ | ✓ | | Ndryshon çmimin në arkëtim (arsyeja kërkohet për ndryshime të mëdha) |
| `void_ticket` | ✓ | ✓ | | Anulon një biletë aktive të lëshuar gabimisht |
| `manage_own_tickets` | ✓ | | | Ekrani **Biletat e mia**: biletat e veta (sot, turni, 7 ditë) dhe korrigjimi i targës, mjetit ose orës së hyrjes te ato aktive |
| `adjust_own_ticket_price` | ✓ | ✓ | | Vendos një çmim të caktuar te **Biletat e mia** |
| `edit_ticket` | ✓ | ✓ | | Faqja **Operacionet → Biletat**: ndryshon, anulon, korrigjon çmimin ose arkëton çdo biletë |
| `manage_tariffs` | ✓ | ✓ | | Llojet e mjeteve dhe tarifat |
| `manage_settings` | ✓ | | | Cilësimet e parkimit, orari i punës, limitet e zbritjes për rol |
| `manage_users` | ✓ | | | Përdoruesit, rolet, lejet e roleve, turnet e punës |
| `view_statistics` | ✓ | ✓ | | Statistikat, historiku i biletave dhe eksportimet |
| `view_audit_log` | ✓ | ✓ | | Regjistri i auditimit |
| `reconcile_shifts` | ✓ | ✓ | | Konfirmon numërimin e parave për një turn të mbyllur |

Katër leje mund t'i jepen edhe direkt një përdoruesi, veç e veç (**Përdoruesit → Leje shtesë**):
`adjust_price`, `void_ticket`, `manage_own_tickets` dhe `adjust_own_ticket_price` — p.sh. për t'i dhënë
një Operatori të vetëm mundësinë të ndryshojë çmimin, pa e bërë Menaxher. Këto prekin **vetëm** ekranet e
operatorit. `adjust_price` dhe `adjust_own_ticket_price` janë të ndara: e para vlen vetëm te arkëtimi, e
dyta vetëm te **Biletat e mia**, dhe njëra nuk jep tjetrën.

Të gjitha lejet e tjera **nuk mund t'i jepen një përdoruesi individualisht**:
- `issue_ticket` dhe `checkout` jo, sepse çdo Operator i ka që të dyja nga roli vetvetiu — dhënia e
  tyre veç e veç nuk do të ndryshonte asgjë të dukshme për askënd.
- Shtatë lejet e zonës së administrimit (`edit_ticket`, `manage_tariffs`, `manage_settings`,
  `manage_users`, `view_statistics`, `view_audit_log`, `reconcile_shifts`) jo, sepse secila hap një faqe
  te paneli `/menaxhimi` — vendim më i madh se një kuti e shpejtë për një person. Ato vijnë vetëm nga
  roli.

Ndryshimet e çmimit përmes **Biletat e mia** ose **Biletat** — përfshirë ndryshimin e orës së hyrjes ose
të llojit të mjetit kur kjo ul çmimin — kërkojnë lejen përkatëse të çmimit dhe respektojnë limitin e
zbritjes, njësoj si te arkëtimi. Ndryshimi i llojit të mjetit refuzohet kur nuk ka vend për llojin e ri.

Lejet e drejtpërdrejta nuk rrisin kurrë limitin e zbritjes, i cili vjen vetëm nga rolet.

**Limitet e zbritjes** caktohen për çdo rol, në përqind. `0` do të thotë pa zbritje, dhe një vlerë bosh do
të thotë pa kufi. Parazgjedhjet: Administratori pa kufi, Menaxheri 20%, Operatori 0%. Vetëm përdoruesit me
`manage_settings` mund t'i ndryshojnë. Një përdorues me disa role merr limitin më të lartë, dhe çdo rol pa
kufi e bën përdoruesin pa kufi.

## Përdorimi i ekraneve të operatorit

1. **Hyrja:** zgjidh llojin e mjetit. Biletë printohet automatikisht. Hyrja bllokohet kur lloji është i
   plotë, kur arrihet kufiri i dedikuar, ose kur nuk ka tarifë të vlefshme për të.
2. **Arkëtimi:** skano kodin QR të biletës me një skaner USB 2D (që lexon kode QR, jo vetëm barkode), i cili
   shkruan kodin dhe shtyp Enter, ose shkruaj kodin ose targën. Shfaqen
   zbërthimi dhe totali. Konfirmo për të marrë pagesën dhe për të printuar kuponin.
   - Shkruaj **shumën e marrë** — e detyrueshme sa herë që ka një çmim për t'u paguar (jo e nevojshme
     vetëm kur qëndrimi është falas, brenda periudhës falas). Ekrani tregon kusurin për t'u kthyer,
     ose sa mungon akoma. Konfirmimi refuzohet, edhe nga serveri, pa shumën e marrë.
   - Operatorët pa `adjust_price` nuk e shohin fushën e çmimit. Serveri gjithashtu refuzon çdo ndryshim
     çmimi nga ata, edhe nëse kërkesa është manipuluar.
   - **Arsyeja** kërkohet vetëm kur ndryshimi i çmimit arrin pragun te **Cilësimet e parkimit → Biletat** (një
     përqindje e çmimit të llogaritur; 0 do të thotë që çdo ndryshim kërkon arsye). Ndryshimet më të vogla
     regjistrohen akoma si ndryshime.
3. **Biletë e humbur:** kërko sipas targës ose kodit, pastaj arkëto çmimin normal të qëndrimit plus tarifën e
   biletës së humbur nga cilësimet, si shtesë. Kështu humbja e biletës nuk del kurrë më lirë se pagesa normale.
4. **Turni im:** tregon turnin e punës që të është caktuar (p.sh. Mëngjes 06:00 – 14:00) dhe paralajmëron nëse
   je jashtë orarit. Turni hapet automatikisht me veprimin e parë. Mbyll turnin për të ruajtur totalet. Më pas
   një menaxher konfirmon numërimin e parave te **Operacionet → Verifikimi i arkës**, ku shfaqet edhe turni i punës.
   Çdo biletë mban turnin ku u lëshua dhe turnin ku u arkëtua, kështu që totalet e turnit dhe lista e
   biletave te verifikimi përputhen gjithmonë. Një arkëtim që ndodh ndërkohë që turni po mbyllet përfshihet
   ende në atë turn.
5. **Biletat e mia** (vetëm me `manage_own_tickets`): biletat që ke lëshuar vetë. Mund të korrigjosh targën,
   llojin e mjetit ose orën e hyrjes te një biletë aktive. Një ndryshim që ul çmimin refuzohet pa leje çmimi.

Kush ka `manage_users` (Administratori) cakton turnet e punës te **Konfigurimi → Turnet e punës**, dhe zgjedh
turnin e një operatori te formulari i përdoruesit.

Kur Administratori arkëton një biletë nga paneli, hapet automatikisht një turn edhe për të. Mbylle te
**Turni im** që ato pagesa të dalin te **Verifikimi i arkës**.

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
   - **Grafika e sfondit:** aktivizuar, që kodi QR të printohet qartë
4. Printo një biletë provë një herë për të kontrolluar gjerësinë dhe leximin e kodit QR.

Kodi QR i biletës së hyrjes përmban vetëm kodin e biletës (6 shkronja/shifra). Kuponi nuk ka kod QR.

### Shtimi i printimit direkt (ESC/POS) më vonë

Printimi qëndron pas `App\Services\Printing\TicketPrinter`. Ekranet e operatorit varen vetëm nga ky
ndërfaqe. Për të shtuar printimin direkt:

1. Implemento `TicketPrinter` (p.sh. me `mike42/escpos-php`). Kthe një `PrintJob` në formën që të nevojitet.
2. Regjistro drejtuesin në `AppServiceProvider` dhe shto një rast për të.
3. Vendos `PARKING_PRINTER=drejtuesi-yt` në `.env`.

Asnjë kod ekrani nuk duhet të ndryshohet.

## Statistikat

Faqja **Statistikat** (te `/menaxhimi` dhe `/admin`, me `view_statistics`) mbulon sot, këtë javë, këtë muaj,
dhe çdo interval të personalizuar:

- Të ardhurat dhe qëndrimi mesatar, dhe të ardhurat e llogaritura kundrejt atyre reale, me ndikimin e ndryshimeve.
- Të ardhurat sipas llojit të mjetit, dhe zëna aktuale për çdo lloj.
- Një hartë e orëve të pikut me mesataren e vendeve të zëna sipas ditës së javës dhe orës.
- Një tabelë e operatorëve: biletat e lëshuara, arkëtimet, të ardhurat, dhe ndryshimet (numri dhe vlera neto).

**Eksportimi:** Excel (një fletë për biletat, për të ardhurat sipas llojit, dhe për operatorët) ose CSV (biletat).
Të dyja përdorin periudhën e zgjedhur.

Të ardhurat numërohen sipas kohës së daljes, dhe hyrjet sipas kohës së hyrjes. Biletat e anuluara përjashtohen.
Një biletë e humbur numërohet te të ardhurat me shumën e plotë (qëndrimi + tarifa), ndërsa karta **Tarifat e
biletave të humbura** tregon vetëm tarifat shtesë.

## Rregullat e çmimit

Çdo tarifë përcakton një njësi faturimi (minuta), një çmim për njësi, një çmim opsional për njësinë e parë, një
periudhë falas, një maksimum ditor opsional, intervale kohore opsionale, një çmim opsional për qëndrime të gjata,
dhe shkallë opsionale çmimi sipas kohëzgjatjes.

- **Periudha falas:** një qëndrim brenda periudhës falas është falas. Një qëndrim edhe një minutë më i gjatë paguhet i plotë.
- **Rrumbullakimi:** një njësi e pjesshme paguhet si njësi e plotë.
- **Intervalet kohore:** një njësi çmohet sipas intervalit ku bie fillimi i saj. Një interval që mbaron para se të
  fillojë kalon mesnatën (p.sh. 22:00–06:00).
- **Dita** (për maksimumin ditor dhe qëndrimet e gjata) është **24 orë nga momenti i hyrjes**, jo dita
  kalendarike. Numri i ditëve është numri i blloqeve të plota 24-orëshe, plus një ditë më shumë vetëm nëse ajo
  që mbetet është më shumë se 12 orë. P.sh. 13 orë = 1 ditë, 30 orë = 1 ditë, 37 orë = 2 ditë, 48 orë e 17
  minuta = 2 ditë. Koha e mbetur deri në 12 orë i shtohet ditës së fundit.
- **Maksimumi ditor:** kufizon pagesën e çdo dite të tillë.
- **Qëndrimet e gjata** (p.sh. parkimi i aeroportit): kur qëndrimi arrin numrin e ditëve të caktuar te tarifa,
  çdo ditë paguhet me çmimin ditor të qëndrimit të gjatë, në vend të njësive dhe maksimumit ditor.
- **Shkallët sipas kohëzgjatjes:** nëse tarifa ka shkallë, ato zëvendësojnë njësitë, çmimin për njësi, çmimin
  e njësisë së parë dhe intervalet kohore. Çdo ditë paguan një çmim fiks sipas sa minuta zgjati brenda asaj dite,
  p.sh. 100 lekë deri në 2 orë, 200 lekë deri në 10 orë, 400 lekë mbi këtë. Saktësisht një shkallë duhet ta ketë
  "Deri në (minuta)" bosh — ajo mbulon çdo qëndrim më të gjatë — dhe dy shkallë nuk mund të kenë të njëjtat
  minuta; formulari refuzon ndryshe. Periudha falas, maksimumi ditor dhe qëndrimet e gjata vlejnë edhe këtu.
- **Bileta e humbur:** çmimi normal i qëndrimit plus tarifa e biletës së humbur nga cilësimet.
- **Koha e kaluar:** matet në kohën reale, kështu që ndryshimet e orës së verës trajtohen saktë.
- **Ndryshimet e tarifës** zbatohen vetëm për biletat e lëshuara më pas. Biletat ekzistuese mbajnë kopjen e tyre.

## Testimi

```bash
php artisan test             # ose: vendor/bin/phpunit
```

Testet përdorin një bazë të dhënash SQLite në memorie, pra nuk kanë nevojë për MySQL. Ato mbulojnë:

- motorin e çmimit (periudha falas, rrumbullakimi, maksimumi ditor, qëndrimet me shumë ditë, intervalet
  e mesnatës, shkallët sipas kohëzgjatjes, dhe ndryshimet e orës në Europe/Tirane);
- kufijtë e kapacitetit dhe hyrjet e njëpasnjëshme deri në kufi, edhe kur ndryshohet lloji i mjetit të një biletë;
- lejet, përfshirë ndryshimet e çmimit të refuzuara përmes një kërkese të drejtpërdrejtë ose të manipuluar;
- limitet e zbritjes sipas rolit;
- rrjedhat e arkëtimit, biletës së humbur, anulimit, korrigjimit të biletave dhe turnit;
- faqet e printimit dhe autorizimi i tyre;
- shifrat e statistikave, harta e orës së pikut, dhe eksportimet (përfshirë tekstin që Excel do ta lexonte si formulë).

## Shënime dhe kufizime

- **Njëkohshmëria:** hyrjet dhe ndryshimet e llojit të mjetit marrin një kyçje (lock) te rreshti i cilësimeve,
  çdo arkëtim kyç biletën e vet, mbyllja e turnit pret çdo arkëtim në vazhdim të operatorit, dhe verifikimi i
  arkës kyç turnin, kështu që operatorët paralelë nuk mund ta kalojnë kapacitetin, ta mbyllin të njëjtën biletë
  dy herë, ose ta verifikojnë të njëjtin turn dy herë. Është
  testuar në mënyrë sekuenciale; sjellja paralele duhet verifikuar me konfigurimin tënd të MySQL përpara
  nisjes në përdorim real.
- **Historiku i zënies** përdor `spots_used` aktual të çdo lloji mjeti. Ndryshimi i tij ndryshon mënyrën
  si raportohen orët e kaluara.
- **Orari i punës** shfaqet te ekrani i hyrjes si paralajmërim. Nuk bllokon hyrjen, që operatorët
  të mund të lëshojnë makina jashtë orarit.
- **Printimi në shfletues** varet nga cilësimet e printimit të shfletuesit (shih "Printimi").
- **Ekranet e operatorit** janë vetëm me ngjyra të çelëta dhe nuk funksionojnë pa internet.
