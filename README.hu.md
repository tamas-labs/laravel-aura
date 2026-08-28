# laravel-aura — teljes referencia

Laravel-csomag, amely azt a JSON-szerződést állítja elő, amit az
[Aura](https://github.com/tamas-labs/aura) Vue 3 adattábla fogyaszt.

> 🇬🇧 **[In English →](./README.en.md)** · 📄 **[Rövid README →](./README.md)**

Az Aura tábláját teljes egészében JSON vezérli: a végpont mondja meg, milyen oszlopok vannak,
hogyan renderel egy cella, és mely sorok látszanak. Ez a csomag ennek a beszélgetésnek a
szerveroldali fele. A táblát egyszer írod le, osztályként, és az kiszolgálja a kérést: a header,
amit a böngésző renderel, és a mezők, amiket a lekérdezés elfogad, ugyanabból a definícióból
származnak — így nem tudnak elcsúszni egymástól.

---

## Tartalom

- [Állapot](#állapot)
- [Követelmények](#követelmények)
- [Telepítés](#telepítés)
- [Konfiguráció](#konfiguráció)
- [Egy tábla definiálása](#egy-tábla-definiálása)
- [Oszlopok](#oszlopok)
- [Következtetés a modellből](#következtetés-a-modellből)
- [Enumok](#enumok)
- [Presetek](#presetek)
- [Csoportos header](#csoportos-header)
- [Footer és beállítások](#footer-és-beállítások)
- [A definíció cache-elése](#a-definíció-cache-elése)
- [Amit a tábla nem hajlandó felépíteni](#amit-a-tábla-nem-hajlandó-felépíteni)
- [A query-réteg önmagában](#a-query-réteg-önmagában)
  - [FieldPermissions](#fieldpermissions)
  - [AuraRequest](#aurarequest)
  - [AuraQuery](#auraquery)
  - [AuraPayload](#aurapayload)
- [Kivételek](#kivételek)
- [A dróton lévő szerződés](#a-dróton-lévő-szerződés)
- [A saját payloadod validálása](#a-saját-payloadod-validálása)
- [Fejlesztés](#fejlesztés)
- [Ütemterv](#ütemterv)
- [Licenc](#licenc)

---

## Állapot

A csomag a tervének **F3** fázisánál tart: a tábla osztály, és végponttól végpontig kiszolgál egy
kérést.

| Ma működik | Még nincs kész |
| --- | --- |
| `AuraTable` — táblánként egy osztály, `respond($request)` | A kilenc cellatípus — badge, link, progress, … (F4) |
| Oszlopok, csoportok, footer, tábla-beállítások | Feltételes cella-konfiguráció (F4) |
| Oszlop-defaultok a modell castjaiból | Action-oszlopok: `edit` / `show` / `destroy` (F5) |
| A mező-whitelist, az oszlopokból származtatva | Soronkénti jogosultság (F5) |
| Rendezés, keresés, szűrés, globális keresés | `make:aura-table` és a demo-app (F6) |
| Relációk mind a négy műveletben | |
| Cache-elhető, kérésfüggetlen definíció | |

A cellák F4-ig sima szövegként renderelnek. Az viszont készen van, hogy *mely* oszlopok vannak,
hogy hívják őket, hogyan vannak formázva, és mit lehet velük csinálni.

A csomag **nincs kiadva**: nincs tag, nincs fenn Packagiston. A repóból telepítsd.

---

## Követelmények

- **PHP** 8.3 vagy 8.4
- **Laravel** 12 vagy 13 (`illuminate/support`, `illuminate/contracts`)
- Bármilyen Eloquent által támogatott adatbázis-driver; a teszt-suite SQLite-on fut, a `LIKE`
  escape-elés pedig úgy van megírva, hogy MySQL/MariaDB-n, PostgreSQL-en és SQLite-on egyformán
  viselkedjen

---

## Telepítés

A csomag nincs Packagiston, ezért a repóra kell mutatni:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-aura" }
    ]
}
```

```bash
composer require tamas-labs/laravel-aura:dev-main
```

Az `AuraServiceProvider`-t a package discovery regisztrálja — a `bootstrap/providers.php`-hoz nem
kell hozzányúlni.

A konfigot akkor publikáld, ha az oldalméret-plafont akarod módosítani:

```bash
php artisan vendor:publish --tag=aura-config
```

---

## Konfiguráció

A `config/aura.php` ma pontosan egy kulcsot tartalmaz:

```php
return [
    'pagination' => [
        'max' => 100,
    ],
];
```

A **`pagination.max`** a klienstől érkező `paginate` kemény felső korlátja. Plafon, nem javaslat:
a `paginate` támadó által vezérelt érték, korlát nélkül egyetlen kéréssel le lehet kérni a teljes
táblát.

A túl nagy érték **vágódik, nem utasítódik el.** Egy elavult kliens, amelyik még 500-as
oldalméretre emlékszik, 100-zal működik tovább, ahelyett hogy a felhasználó arcába hibázna — a
plafon az adatbázist védi, és semmit nem nyerünk azzal, ha közben az oldalt is eltörjük.

Alapértelmezett oldalméret szándékosan **nincs.** A kérés-szerződésben a `paginate` kötelező; ha a
hiányzót defaultolnánk, egy hibás kliensből csendben rövid oldal lenne 422 helyett.

---

## Egy tábla definiálása

```php
<?php

namespace App\Tables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\TableSettings;

/**
 * @extends AuraTable<User>
 */
final class UserTable extends AuraTable
{
    public function query(): Builder
    {
        return User::query()->with('company');
    }

    public function columns(): array
    {
        return [
            Column::selection(),
            Column::make('last_name', 'Vezetéknév')->sortable()->searchable()->globalSearch(),
            Column::make('company.name', 'Cég')->sortable(),
            Column::make('status', 'Státusz')->filterable(),
            Column::make('balance', 'Egyenleg')->sortable()->searchable(),
            Column::make('created_at', 'Létrehozva')->sortable()->searchable(),
        ];
    }

    public function settings(): TableSettings
    {
        return TableSettings::make()->stickyHeader()->striped()->hoverable();
    }
}
```

```php
Route::post('/users', fn (Request $request) => (new UserTable)->respond($request));
```

Ennyi a végpont. Az Aura alapértelmezésben `POST`-tal kér, ezért így vedd fel a route-ot (vagy
állítsd át a `requestMethod`-ot a kliensen).

A `query()`-be azok a megszorítások valók, amik mindig igazak — tenant-szűkítés, eager load, egy
`withTrashed()`. Amit a felhasználó választ, arra épül rá.

### Miért osztály, és nem egy hívásfüzér a controllerben

Mert a szerződés két fele ugyanannak a definíciónak két olvasata. A header azt mondja,
`sortable: true`; a query-réteg dönti el, hogy elfogad-e rendezést arra a mezőre. Külön megírva a
kettő elcsúszik, és a hiba a rosszabbik irányban csendes: egy oszlop, amit a header felkínál, de
a whitelist kifelejtett, minden kattintást 422-vé tesz — egy whitelist-bejegyzés viszont, amihez
nincs oszlop, olyan mező, amit a kliens használhat, pedig a tábla soha nem akarta megmutatni.

Itt a whitelist *abból a cella-tömbből származik, amit a böngésző megkap*, pontosan úgy oldva fel
a mezőt, ahogy az Aura (`reference || field || key`). Egy teszt végigjárja minden oszlop minden
műveletét, és összeveti a kettőt.

A másik ok a cache: a header, a body és a footer nem függ a kéréstől, tehát egyszer is
felépíthető. Egy controllerbeli fluent chain minden lekérésnél újraépítené az egészet.

### Mi jön ki

```php
(new UserTable)->respond($request);   // header + body + items + meta + links
(new UserTable)->definition();        // header + body + footer — a kérésfüggetlen fél
(new UserTable)->permissions();       // a FieldPermissions, amit az oszlopok kijelölnek
```

---

## Oszlopok

Egy oszlop egy header-cella. A `Column::make('last_name')` már teljes: a fejléc a mezőnévből lesz
címsor-formában, a kulcs pedig a mező.

```php
Column::make('last_name')                    // fejléc: "Last Name"
Column::make('last_name', 'Vezetéknév')      // explicit fejléc
Column::selection()                          // a sor-kijelölő checkboxok
Column::combined('full_name', ['first_name', 'last_name'], 'Név')
Column::heading('Számla', colspan: 3)        // fejléc több oszlop fölött
```

### Viselkedés

| Metódus | Hatás |
| --- | --- |
| `sortable()` | rendezést kínál, és engedélyezi a query-oldalon |
| `searchable()` | oszlop-szintű keresőmezőt kínál |
| `filterable()` | oszlop-szintű szűrő-legördülőt kínál |
| `between()` | min–max tartománnyal keres, nem kifejezéssel |
| `globalSearch()` | beveszi a mezőt a toolbar globális keresőjébe |
| `reference('masik_mezo')` | más mezőn műveletezik, mint amit renderel |
| `elements([...])` / `options(Enum::class)` | a szűrő-legördülő opciói |
| `selectable()` | ide kerülnek a sor-kijelölő checkboxok |
| `show(false)` / `hidden()` | rejtve indul — megjelenítés, soha nem jogosultság |

A `reference()` az, amivel egy renderelt oszlop egy mögöttes szerint rendez: a teljes név oszlop
`last_name` szerint. Az Aura a küldendő mezőt `reference || field || key` sorrendben oldja fel, és
a whitelist ugyanezt követi — tehát a reference az, amit a lekérdezés elfogad, nem a renderelt
mező.

A `hidden()` **nem jogosultság.** A rejtett oszlopot a felhasználó visszakapcsolhatja; amit senki
nem láthat, az ne legyen benne a `columns()`-ban.

### Elrendezés és formázás

`width()`, `resizable()`, `colspan()`, `rowspan()`, `align()`, `class()`, `style()`,
`cellClass()`; illetve `number()`, `currency()`, `date()`, `datetime()`, `time()`, `phone()`,
`slice()`, `uppercase()`, `lowercase()`, `capitalize()`, `monospace()`, `raw()`.

A `cellClass()` a kilógó: az oszlop **adat**celláit stílusozza (`body.columnStyles`), míg a
`class()` a fejlécet.

### Minden más

A szerződés több header-cella kulcsot definiál, mint ahány metódus itt van. A `set()` és a
`merge()` mindegyikhez elér, a `data-*` attribútumokat is beleértve:

```php
Column::make('note')
    ->set('data-testid', 'note-column')
    ->merge(['fontWeight' => 700, 'lineHeight' => 1.4]);
```

A `Column` `Macroable`, tehát egy hívás, amit sokszor ismételsz, saját metódussá válhat.

---

## Következtetés a modellből

A modell már tudja a legtöbbet abból, amire egy oszlopnak szüksége van. Ezt a tábla-definícióban
megismételni olyan duplikáció, ami elavul — a cast megváltozik, az oszlop pedig csendben a régi
módon formáz tovább. Ezért a defaultok a modell castjaiból jönnek:

| Cast | Default |
| --- | --- |
| `decimal:*` | `currency`, `align: end` |
| `datetime`, `immutable_datetime`, `timestamp` | `datetime`, és `between`, ha az oszlop kereshető |
| `date`, `immutable_date` | `date`, és `between`, ha az oszlop kereshető |
| egy `BackedEnum` osztály | `elements` — a szűrő-legördülő opciói |
| a modell kulcsneve | a `Column::selection()` mezője |

A pontos mezők egy reláció mélységig oldódnak fel, tehát a `company.tier` a *company* modell
castját veszi fel.

Három tulajdonság, mindegyikre teszttel:

- **A következtetés csak hézagot tölt ki.** Ugyanazon az ajtón ír, mint a presetek, tehát egy
  explicit hívás nyer, akármilyen sorrendben történt — a
  `Column::make('balance')->align('center')` `center` marad, hiába mondana a decimal cast `end`-et.
- **A `->withoutInference()`** egy oszlopra kikapcsolja, annak a `decimal`-nak a kedvéért, ami
  súly és nem pénz.
- **Legjobb szándékú, nem garancia.** Amit nem tud feloldani — számított oszlop, két szint mélyre
  vitt reláció —, az egyszerűen nem kap defaultot. Rosszul tippelni rosszabb, mint nem tippelni.

---

## Enumok

Egy castként használt backed enum a szűrő-legördülő opcióivá válik. Implementáld az
`AuraOption`-t, és a feliratok a tieid:

```php
use TamasLabs\Aura\Contracts\AuraOption;

enum Status: string implements AuraOption
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktív'),
            self::Suspended => __('Felfüggesztett'),
        };
    }
}
```

```php
Column::make('status')->filterable();
// elements: { "active": "Aktív", "suspended": "Felfüggesztett" }
```

A kulcsok a backing value-k — amit az adatbázis tárol és ami a kérésben utazik —, a feliratok
pedig az, amit a felhasználó olvas. Az az enum, amelyik **nem** implementálta az interfészt,
szintén használható listát ad a case-neveiből, tehát ez megfogalmazást vesz, nem viselkedést.

Ha a modell nem castol arra az enumra, kérd közvetlenül: `->options(Status::class)`.

Az opciók az enum case-eiből jönnek, nem a betöltött sorokból. Ez az a különbség, ami számít: a
sorokból származtatott lista nem tud olyan státuszt felkínálni, ami még senkinél nem szerepel.

---

## Presetek

A preset oszlop-beállítások újrahasznosítható köteg — ez akadályozza meg, hogy ugyanaz a négy
hívás minden pénzoszlopon megismétlődjön.

```php
use TamasLabs\Aura\Table\Presets\{Money, Options, Timestamp};

Column::make('minor_units', 'Összesen')->apply(new Money);
Column::make('archived_at')->searchable()->apply(new Timestamp);
Column::make('born_on')->apply(Timestamp::date());
Column::make('plan')->apply(new Options(Tier::class));
```

| Preset | Amit beállít |
| --- | --- |
| `Money` | `currency`, `align: end`, `monospace` |
| `Timestamp` | `datetime` (vagy `date`), és `between` egy kereshető oszlopon |
| `Options` | `filterable`, `elements` egy enumból, `align: center` |

A presetek ugyanazon az ajtón írnak, mint a következtetés, tehát egy explicit hívás mindkét
sorrendben nyer. Sajátot a `Preset::apply(Column $column): void` implementálásával írsz.

---

## Csoportos header

```php
use TamasLabs\Aura\Table\ColumnGroup;

public function columns(): array
{
    return [
        Column::selection(),
        ColumnGroup::make('Felhasználó', [Column::make('first_name'), Column::make('last_name')]),
        ColumnGroup::make('Számla', [Column::make('status'), Column::make('balance')]),
    ];
}
```

Egy csoport deklarálása kétsorossá teszi a headert. A csoport-cella átfogja a gyerekeit; a
gyerekek a második sorban tartják meg a saját cellájukat.

**Minden adatoszlop az utolsó sorba kerül**, és ez nem stílus kérdése. Az Aura a body oszlopait
kizárólag a `header.rows[utolsó]` sorból veszi. Egy `rowspan`-nel az első sorban hagyott oszlop
fejlécet kapna, adatot nem — a body eggyel kevesebb `<td>`-t rajzolna, mint ahány `<th>` van —, az
ott ragadt `selectable` cella pedig szó nélkül kikapcsolná a sor-kijelölést. Egy csoportosítatlan
oszlop fölé ezért üres helyőrző cella kerül, nem rowspan.

Az egyoszlopos csoport elutasításra kerül: a szerződés szerint egy mezőt nem nevező cellának
legalább kettőt kell átfognia, egy egyoszlopos csoport pedig csak egy oszlop fejléccel.

---

## Footer és beállítások

```php
use TamasLabs\Aura\Table\Footer;

public function footer(): ?Footer
{
    return Footer::make(
        Column::heading('Összesen', colspan: 3),
        Column::make('balance_total')->align('end'),
    );
}
```

A footer ugyanabból a cellából épül, mint a header, és ugyanaz a séma validálja — beleértve azt a
szabályt is, hogy a mezőt nem nevező cellának legalább két oszlopot kell átfognia.

```php
public function settings(): TableSettings
{
    return TableSettings::make()
        ->stickyHeader()->headerHeight('48px')
        ->striped()->hoverable()
        ->stickyFooter();
}
```

A szerződés ezeket három blokk közt szórja szét: `header.settings`, `body.settings`,
`footer.settings`. A `TableSettings` összegyűjti őket, és kifelé menet szétosztja; amelyik blokkba
senki nem állított semmit, az kimarad.

---

## A definíció cache-elése

A header, a body és a footer nem függ a kéréstől. Kapcsold be, és egyszer épül fel:

```php
final class UserTable extends AuraTable
{
    protected bool $cache = true;
    protected int $cacheTtl = 3600;
}
```

```php
(new UserTable)->forgetCache();   // olyan deploy után, ami az oszlopokat érinti
```

A definíció **és** a whitelist együtt cache-elődik, mert egy definíció két olvasata; az egyiket a
másik nélkül cache-elve pont az áll elő, hogy a header olyan rendezést kínál, amit a szerver
elutasít. A `cacheKey()`-t akkor írd felül, ha egy tábla-osztály több alakot szolgál ki —
nyelvenként, mondjuk.

Alapból ki van kapcsolva, mert csak akkor biztonságos, ha a `columns()` valóban kérésfüggetlen.
Egy definíció, ami a bejelentkezett felhasználót, a nyelvet vagy egy feature flaget olvas, annak
cache-elődik, aki elsőként kérte.

A cache visszafelé nem megbízható forrás: egy bejegyzés, ami nem az általunk írt tömb,
újraépítést vált ki, és a mezőlistákból minden nem-string kiesik. Egy elavult vagy megpiszkált
bejegyzés nem tud whitelistet tágítani.

---

## Amit a tábla nem hajlandó felépíteni

Ezek `InvalidDefinition`-ök — `LogicException`, mert egyiket sem tudja előidézni, amit a
felhasználó csinál. Mindegyik a tábla-osztályban lévő hibát jelent, az első kérésnél, ami
hozzáér — ahelyett hogy a böngésző rosszat renderelne:

| Elutasítva | Miért |
| --- | --- |
| Két oszlop ugyanazzal a kulccsal | a kulcs azonosítja az oszlopot a `columnConfigs`-ban, a `columnStyles`-ban és az Aura oszlop-szintű session-állapotában |
| `combined()` oszlop `reference` nélkül, rendezhetően vagy kereshetően | az Aura ilyenkor a kulcsra esne vissza, amit az adatbázis nem ismer |
| `combined()` oszlop a globális keresésben | a `searchableItems` egy header-cella `field`-jét nevezi meg, ennek az oszlopnak pedig nincs |
| Kettőnél kevesebb oszlopot átfogó csoport | a mezőtlen cellának legalább kettőt kell átfognia |
| `Column::heading()` `colspan: 1`-gyel | ugyanaz a szabály |
| Oszlop nélküli tábla | a szerződés legalább egy header-sort kér, legalább egy cellával |

---

## A query-réteg önmagában

Az `AuraTable` három darabra épül, amiket közvetlenül is használhatsz — olyan végponthoz, aminek
az alakja egyáltalán nem egy tábla-osztályból jön.

```php
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

$aura = AuraRequest::fromHttp($request, new FieldPermissions(
    sortable:     ['last_name', 'created_at'],
    searchable:   ['first_name', 'last_name'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name'],
));

$payload = AuraPayload::fromPaginator(AuraQuery::paginate(User::query(), $aura));

return ['header' => $kezzelIrtHeader] + $payload->toArray();
```

Így használva a headert és a whitelistet kézzel kell szinkronban tartani — pont ezt hivatott
megszüntetni az `AuraTable`.

### FieldPermissions

Az Aura-kérésben minden `field` a böngészőből érkezik. Ha ilyet adunk közvetlenül az `orderBy()`-nak,
azzal kiszivárogtatjuk olyan oszlopok létezését — a rendezésen keresztül a sorrendjét is —, amiket
a tábla soha nem akart megmutatni. A `FieldPermissions` a whitelist, és **kizárólag** ezen
keresztül jut kliens-mező a lekérdezésbe. Egy tábla-osztály az oszlopaiból építi; kézzel csak itt
kell.

Négy tulajdonság érvényes, mindegyiket teszt rögzíti:

- **A listák külön élnek.** Attól, hogy egy mező kereshető, még nem rendezhető.
- **Az üres lista semmit nem enged.** „Engedj mindent" kapcsoló nincs, és a
  `FieldPermissions::none()` a biztonságos kiindulópont.
- **Az egyezés pontos.** A `last` nem engedélyezett a `last_name` miatt.
- **Az elutasított mező 422**, nem csendben eldobott paraméter. A hibaüzenet megnevezi az
  elutasított mezőt, de soha nem sorolja fel az engedélyezetteket — egy hibaválasz nem arra való,
  hogy felsoroljuk benne a sémát.

### AuraRequest

```php
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?int $maxPaginate = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?int $maxPaginate = null): self
```

A `fromHttp` onnan olvassa a payloadot, ahová a szerződés teszi: `POST` / `PUT` / `PATCH` esetén a
JSON-törzsből, `GET` / `DELETE` esetén a query-paraméterekből. Mindkettő `ValidationException`-t —
**422**-t — dob mindenre, amit a szerződés nem enged.

| Kulcs | Típus | Kötelező | Megjegyzés |
| --- | --- | --- | --- |
| `page` | egész ≥ 1 | **igen** | |
| `paginate` | egész ≥ 1 | **igen** | a `pagination.max`-ra vágódik |
| `sortable[]` | `{field, direction}` | nem | a `direction` `asc` vagy `desc` |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | nem | szöveges vagy tartománykeresés |
| `filterable[]` | `{field, values[]}` | nem | a `values` lehet üres, de jelen kell lennie |
| `globalSearch` | string | nem | |
| `selected[]` | string / szám | nem | sor-azonosítók a köteges műveletekhez |

Az ismeretlen property elutasításra kerül — a legfelső szinten *és* a beágyazott objektumokban is.
A beágyazott ellenőrzés szándékosan a nyers payloadon fut: a Laravel validátora eldob minden
kulcsot, amihez nincs szabálya, így mire a validáció lefut, egy ismeretlen beágyazott kulcs már
eltűnt volna, és soha nem lehetne jelenteni.

**A `selected` nem kerül a lekérdezésbe.** A felhasználó által kipipált sorokat nevezi meg, a hívó
saját köteges műveleteihez; teszt rögzíti, hogy a generált SQL azonos vele és nélküle. A lapot a
kijelölésre szűkíteni két okból is hibás lenne: a kijelölés átnyúlhat több oldalra, és a
felhasználó nem ezt kérte.

```php
User::whereKey($aura->selected)->each->archive();
```

### AuraQuery

```php
AuraQuery::apply(Builder $query, AuraRequest $request): Builder
AuraQuery::paginate(Builder $query, AuraRequest $request): LengthAwarePaginator
```

**Keresés.** Egy `searchable[]` bejegyzés vagy szöveges keresés, vagy tartománykeresés:

| Amit küld | SQL |
| --- | --- |
| `{field, term}` | `LIKE '%term%'` — részszöveg, escape-elt wildcardokkal |
| `{field, term, exact: true}` | `= 'term'` |
| `{field, min, max}` | `>= min` és `<= max` |
| `{field, min}` vagy `{field, max}` | egy nyitott vég |

A tartomány bármelyik végén a `null` azt jelenti: *korlátlan*, nem azt, hogy *illeszkedjen a
null-ra*. Az üres vagy hiányzó keresőkifejezés semmilyen megszorítást nem ad hozzá — nem pedig
mindenre illeszkedik.

**A keresőkifejezésben lévő wildcardok escape-elve vannak.** Nélküle egy `%` keresőkifejezés
minden sorra illeszkedik — ez nem injekció (a kifejezés bindingként utazik), hanem egy keresőmező,
ami csendben teljes táblaolvasássá válik, és egy `100%` keresés, ami az `1000`-et is visszaadja.
Az escape-karakter `!`, nem backslash: a MySQL és az SQLite nem ért egyet abban, hogy a
stringliterálon belüli backslash maga is escape-e. Az `AuraQuery::likeExpression()` a csomag
egyetlen raw SQL-je, és viszi magával az indoklást.

**Szűrés.** A `{field, values}` arra a sorra illeszkedik, amelynek az oszlopa a felsorolt értékek
bármelyikével egyenlő. A `null` köztük `OR column IS NULL`-t ad hozzá — az `IN (…)` soha nem
illeszkedik `NULL`-ra, így egy kiválasztott „nincs érték" különben csendben pont azokat a sorokat
dobná el, amiket a felhasználó kért. Az üres `values` tömb semmire nem illeszkedik, mert az üres
kijelölés ezt jelenti.

**Globális keresés.** Egy keresőkifejezés, `OR`-ral összefűzve a deklarált mezőkön, saját
beágyazott `where`-be csomagolva, hogy az OR-ok ne tudjanak kiszabadulni és kitágítani a
körülöttük lévő oszlop-szintű megszorításokat.

**Relációk.** A pontos mező az általa megnevezett reláción keresztül oldódik fel:

| Művelet | Mechanizmus | Mélység | Relációtípusok |
| --- | --- | --- | --- |
| Keresés | `whereHas` | tetszőleges | bármelyik |
| Szűrés | `whereHas` | tetszőleges | bármelyik |
| Globális keresés | `orWhereHas` | tetszőleges | bármelyik |
| **Rendezés** | **korrelált alkérdés** | **egy szint** | **`BelongsTo`, `HasOne`** |

A rendezés a korlátozott, szándékosan. A join olvashatóbb lenne, de to-many reláción
megsokszorozza a sorokat — ez pedig elrontja a `meta.total`-t és minden oldal tartalmát, vagyis
magát a lapozást töri el. A korrelált alkérdésnek nincs ilyen hatása; az ára, hogy csak to-one
relációra, egy szint mélységben válaszol. Bármi más `UnsupportedRelation`-t dob, konkrét
javaslattal.

### AuraPayload

```php
AuraPayload::fromPaginator(Paginator|CursorPaginator $paginator): self
$payload->toArray(): array   // ['items' => …, 'meta' => …, 'links' => …]
```

Az `items` a sorok nyers adattá lapítva, `array_values`-szal újraindexelve — egy lyukas kulcsú
paginátor-oldal JSON-objektummá szerializálódna tömb helyett.

**Csak a `LengthAwarePaginator` működik.** A szerződés megköveteli a `meta.last_page`-et és a
`meta.total`-t, ezeket viszont sem a `simplePaginate()`, sem a `cursorPaginate()` nem ismeri, mert
egyik sem futtatja le a count-lekérdezést. Ilyet átadva `UnsupportedPaginator` dobódik, nem pedig
egy olyan payload keletkezik, amit a tábla nem tud beolvasni.

---

## Kivételek

Minden kivétel, amit ez a csomag a saját nevében dob, implementálja a
`TamasLabs\Aura\Exceptions\AuraException`-t, így a host-alkalmazás egyben elkaphatja mindet:

| Kivétel | Mikor dobódik |
| --- | --- |
| `InvalidDefinition` | maga a tábla-definíció hibás — lásd [a fenti táblázatot](#amit-a-tábla-nem-hajlandó-felépíteni) |
| `UnsupportedRelation` | to-many reláción vagy beágyazott relációs úton keresztüli rendezés |
| `UnsupportedPaginator` | a paginátor nem tudja a `last_page` / `total` értéket |

Mindegyik a **tábla definíciójában** lévő hibát jelent, nem a kliens inputjában lévőt — a hibás
input jóval előbb elbukik a validáción, és 422 lesz belőle.

---

## A dróton lévő szerződés

A szerződés verziója, amit ez a csomag céloz, a `TamasLabs\Aura\AuraContract::VERSION`, jelenleg
**1.0**.

Maguk a séma-dokumentumok **nincsenek ebben a repóban.** A
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) csomagban élnek — egyetlen
kanonikus halmaz, amin a Vue-csomag és ez a csomag osztozik —, és ide **dev-függőségként**
érkeznek.

Hogy csak dev-függőség, az szándékos: a futásidejű validáció a host-alkalmazás dolga, a Composer
pedig a `repositories`-t kizárólag a *gyökér*csomagból olvassa, így egy publikálatlan csomag
futásidejű `require`-je feloldhatatlan lenne annak, aki ezt telepíti. Ezért `suggest` bejegyzés
van, nem `require`.

Az `AuraContract::VERSION` azért ismétli meg az `AuraSchema::VERSION`-t, mert futásidejű kód nem
olvashat dev-függőséget; egy teszt elbukik abban a pillanatban, ahogy a kettő eltér.

A verzió ma nem utazik a dróton. A válasz-séma `additionalProperties: true`-t állít, így a
verziómező később törésmentesen hozzáadható.

---

## A saját payloadod validálása

Ez a csomag a saját kimenetét a teszt-suite-jában validálja a sémára, hálózati elérés nélkül: az
`opis/json-schema` a csomagolt dokumentumokra van kötve, az `$id` URL-ek pedig a helyi könyvtárra
képződnek, így azonosságok, nem letöltések. Amit a resolver nem talál meg, arra **dob**, tehát egy
zöld szerződésteszt soha nem jelentheti azt, hogy „a séma nem található".

Ugyanezt megteheted a saját alkalmazásod tesztjeiben:

```bash
composer require --dev tamas-labs/aura-schema opis/json-schema
```

```php
use Opis\JsonSchema\Validator;
use TamasLabs\AuraSchema\AuraSchema;

$validator = new Validator;
$validator->resolver()?->registerPrefix(AuraSchema::BASE_URI, AuraSchema::directory());

$result = $validator->validate(
    json_decode($response->getContent()),        // objektumként, nem tömbként — lásd lentebb
    AuraSchema::BASE_URI.'/aura-response.schema.json',
);
```

A fixtúrákat **objektummá** dekódold, ne asszociatív tömbbé: a `json_decode(…, true)` eltünteti a
különbséget az üres objektum és az üres tömb között, a JSON Schema-nak viszont számít.

---

## Fejlesztés

**A hoston nincs PHP és nincs Composer** — minden Dockerben fut:

```bash
docker compose run --rm php composer install     # függőségek telepítése
docker compose run --rm php composer quality     # pint + phpstan + pest — amit a CI futtat
docker compose run --rm php vendor/bin/pest      # a teszt-suite
docker compose run --rm php vendor/bin/pest --filter "clamps paginate"
docker compose run --rm php vendor/bin/phpstan analyse
docker compose run --rm php vendor/bin/pint      # formázás alkalmazása
```

Egyetlen service, a `php`, `php:8.4-cli-alpine`-on. **Adatbázis-konténer nincs** — a suite
in-memory SQLite-on fut, így a `docker compose up`-ra soha nincs szükség.

A minőségi kapu: Laravel Pint (`laravel` preset), PHPStan/Larastan **max** szinten a `src/` és a
`tests/` felett, valamint Pest. A CI a mátrixot natívan futtatja (PHP 8.3/8.4 × Laravel 12/13), és
külön építi ezt az image-et, hogy a Dockerfile ne rothadjon el.

---

## Ütemterv

| Fázis | Tárgya | Állapot |
| --- | --- | --- |
| **F0** | Docker és repo-alap | ✅ kész |
| **F1** | Contract-teszt harness | ✅ kész |
| **F2** | Query-oldal — kérés → Eloquent → `items`/`meta`/`links` | ✅ kész |
| **F3** | Definíciós mag: `AuraTable`, `Column`, következtetés, cache | ✅ kész |
| **F4** | Cella-builderek: badge, link, button, icon, modal, progress, feltételes konfiguráció | tervezett |
| **F5** | Action-réteg (`edit` / `show` / `destroy`) és soronkénti jogosultság | tervezett |
| **F6** | Demo workbench-app, `make:aura-table`, kiadás | tervezett |

---

## Licenc

MIT

## Szerző

Tamas Balint
