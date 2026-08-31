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
  - [`limits` — a payload többi része](#limits--a-payload-többi-része)
- [Egy tábla definiálása](#egy-tábla-definiálása)
- [Oszlopok](#oszlopok)
- [Következtetés a modellből](#következtetés-a-modellből)
- [Enumok](#enumok)
- [Presetek](#presetek)
- [Cella-renderelés](#cella-renderelés)
  - [A kilenc típus](#a-kilenc-típus)
  - [Többmezős oszlopok](#többmezős-oszlopok)
  - [Feltételek](#feltételek)
  - [Numerikus összehasonlítás](#numerikus-összehasonlítás)
  - [Cella- és sorszabályok](#cella--és-sorszabályok)
  - [Route-ok](#route-ok)
  - [Bootstrap a szerződésben](#bootstrap-a-szerződésben)
- [Action-oszlopok](#action-oszlopok)
  - [A kulcs placeholder, nem név](#a-kulcs-placeholder-nem-név)
- [Csoportos header](#csoportos-header)
- [Footer és beállítások](#footer-és-beállítások)
- [A definíció cache-elése](#a-definíció-cache-elése)
- [Amit a tábla nem hajlandó felépíteni](#amit-a-tábla-nem-hajlandó-felépíteni)
- [A query-réteg önmagában](#a-query-réteg-önmagában)
  - [FieldPermissions](#fieldpermissions)
  - [AuraRequest](#aurarequest)
  - [Mi korlátozza a kérést](#mi-korlátozza-a-kérést)
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

A csomag a tervének **F5a** fázisánál tart: a tábla osztály, végponttól végpontig kiszolgál egy
kérést, a cellái többet renderelnek szövegnél, és a négy resource-akció egyetlen hívás.

| Ma működik | Még nincs kész |
| --- | --- |
| `AuraTable` — táblánként egy osztály, `respond($request)` | Saját route és ikon egy akción (F5b) |
| Oszlopok, csoportok, footer, tábla-beállítások | Soronkénti jogosultság (F5c) |
| Oszlop-defaultok a modell castjaiból | `make:aura-table` és a demo-app (F6) |
| A mező-whitelist, az oszlopokból származtatva | |
| Rendezés, keresés, szűrés, globális keresés | |
| Relációk mind a négy műveletben | |
| A kilenc cella-renderelő, feltételekkel és cella-szabályokkal | |
| Action-oszlopok konvenció-módban — `create` / `show` / `edit` / `destroy` | |
| Cache-elhető, kérésfüggetlen definíció | |

Az az action-oszlop, aminek sajátja kell, ma is megépíthető kézzel: egy `Icon`, `Button` vagy
`Modal` route-tal elég hozzá. Az F5b azt teszi hozzá, hogy az eszkaláció ezt elvégzi helyetted, az
F5c pedig a soronkénti jogosultsági gépezetet, ami eldönti, melyik sor kap egyáltalán linket.

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

```php
return [
    'pagination' => [
        'max' => 100,          // plafon a kliens `paginate`-jére — vágódik
    ],

    'limits' => [
        'selected' => 1000,    // azonosítók a `selected`-ben
        'values' => 200,       // értékek egy szűrő-legördülőben
        'term' => 255,         // karakterek a `globalSearch`-ben és a `searchable[].term`-ben
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

### `limits` — a payload többi része

A `paginate` nem az egyetlen támadó által vezérelt szám a kérésben. A **`limits`** azt a három
dolgot korlátozza, amit semmi más nem tud:

| Kulcs | Alapérték | Mit korlátoz |
| --- | --- | --- |
| `limits.selected` | 1000 | azonosítók a `selected[]`-ben |
| `limits.values` | 200 | értékek egy `filterable[].values`-ban |
| `limits.term` | 255 | karakterek a `globalSearch`-ben és egy `searchable[].term`-ben |

Ezek túllépése **422, nem vágás.** A `paginate`-nél a vágás mellett az szól, hogy az elavult
kliens tovább működik; egy 200 000 karakteres keresőkifejezést viszont semmi legitim nem állít elő,
tehát nincs is mit működésben tartani.

**Figyeljük meg, mi *nincs* itt.** A `sortable`, `searchable` és `filterable` listáknak nincs
kulcsuk, mert nincs is rá szükségük — lásd [Mi korlátozza a kérést](#mi-korlátozza-a-kérést).

A `values` azért configérték és nem az oszlop saját `elements`-e, mert az `elements` opcionális: ha
egy oszlop nem ad meg egyet sem, az Aura a betöltött sorokból építi a szűrő opcióit — a szervernek
nincs miből plafont származtatnia.

A hiányzó vagy nem pozitív configérték a csomagolt alapértékre esik vissza, nem a „nincs korlát"-ra
— az a korlát, amit egy elrontott config ki tud kapcsolni, nem korlát.

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
Column::actions('id', Action::edit())        // a resource-linkek — lásd Action-oszlopok
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

Két további interfész is van, mindkettő opcionális és mindkettő külön az `AuraOption`-től — mert
egy szűrőlistának felirat kell és semmi más, és minden benne szereplő enumtól színt követelni a
legtöbbjükön halott metódust jelentene:

```php
enum Status: string implements AuraOption, AuraVariant, AuraIcon
{
    public function variant(): string { … }   // 'success' — vagy kulcs az app variants-regiszterébe
    public function icon(): string { … }      // kulcs az app icons-regiszterébe
}
```

A `Badge::fromEnum(Status::class)` mind a hármat kiolvassa, és minden case-hez felépíti a badge-et.
Az az enum, amelyik egyiket sem implementálja, szintén használható badge-térképet ad a
case-neveiből — amit nem tud adni, az a szín, és azt case-enként kitalálni rosszabb, mint kihagyni.

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

## Cella-renderelés

A cella alapesetben szövegként mutatja az értékét. A **cella-konfiguráció** ezt lecseréli a
szerződés kilenc renderelőjének egyikére, és az oszlophoz kapcsolódik:

```php
use TamasLabs\Aura\Cell\{Badge, Icon, Modal, Reference};

public function columns(): array
{
    return [
        Column::make('status')->filterable()->as(Badge::fromEnum(Status::class)),
        Column::make('email')->as(Reference::make()->lowercase()),
        Column::make('edit')->content(null)->as(Icon::make('pencil')->route('users.{id}.edit')),
        Column::make('delete')->content(null)->as(Modal::destroy()->route('users.{id}.destroy')->icon('trash', 'danger')),
    ];
}
```

A konfiguráció szándékosan külön objektum az oszloptól. Az oszlop a **fejlécet** írja le és azt,
mit enged a szerver a mezővel csinálni; a konfiguráció a **cellát**. Közös kulcsneveik vannak
(`align`, `currency`, `class`) különböző célponttal, és egyetlen builder mindkettőre azt jelentené,
hogy találgatni kell, épp melyiket állítod.

Két dolgot érdemes tudni, mielőtt hozzácsatolsz egyet.

**A fejléc formázása nem jut el a celláig.** Az Aura a konfigurációt **önmagában** adja át a
renderelőnek, tehát egy `currency()` oszlop sima konfigurációval hirtelen nyers számokat mutatna.
Ezért az oszlop formázó-beállításai alapértelmezésként bekerülnek a konfigurációba — az explicit
hívás a konfiguráción továbbra is nyer:

```php
Column::make('balance')->as(Reference::make());                   // továbbra is pénznemként formázva
Column::make('balance')->as(Reference::make()->currency(false));  // szándékosan nem
```

**Konfigurációt hordozó oszlopnál a `key` és a `field` nem térhet el.** Az Aura a renderelőt a
`columnConfigs[column.field]` alól olvassa, a cella-szabályokat a `columnConfigs[column.key]` alól
— egy oszlop, ahol a kettő különbözik, mindkét bejegyzést igényelné, és azt kapná, amelyikhez a
keresés épp eljut. Alapból amúgy is egyeznek; egy ezzel ellentétes explicit `->key()` inkább
elutasításra kerül, mint hogy félig érvényesüljön.

### A kilenc típus

| Típus | Builder | Mit renderel |
| --- | --- | --- |
| `static` | `Text::make('—')` | rögzített szöveget — **nem** a sor értékét |
| `reference` | `Reference::make()` | a sor értékét, a formázóláncon át |
| `badge` | `Badge::make()` | színes pirulát |
| `link` | `Link::make()->route(…)` | linket |
| `button` | `Button::make('Szerkesztés')->route(…)` | gombot |
| `icon` | `Icon::make('pencil')` | ikont, opcionálisan linkkel |
| `modal` | `Modal::destroy()` | modal-nyitó triggert |
| `progress` | `Progress::make()` | folyamatjelzőt |
| `custom` | `Custom::template(…)` | amit a host app regisztere renderel |

A `Text` a szerződés `static` típusa; a `Static` foglalt szó PHP-ban. A `value`-t rendereli, a sort
soha nem olvassa — a sor saját adatához ugyanazzal a formázással a `Reference` való, aminek a
`field`-je az oszlopéból jön.

Minden típus osztozik a formázóláncon (`currency`, `date`, `datetime`, `time`, `slice`,
`uppercase`, `padStart`, `raw`, …), a tipográfiablokkon (`color`, `align`, `fontSize`, `italic`,
…), ahol a szerződés ad neki ilyet, és a `class()` / `style()` páron az általa rajzolt elemen.
Az `Icon`, a `Modal` és a `Progress` formázóláncot nem ismer, és nem örököl semmit az oszloptól.

A `datetime`, a `time` és a `raw` hiányzik a config-sémákból, a renderelő viszont olvassa őket
(`buildFormatConfig.ts`), és minden config enged további property-ket — ezért itt a dokumentált
kulcsok mellett szerepelnek. További három, amit a renderelő olvas, de buildernek nincs:
`currencyCode`, `sliceEnd`, `pad` — ezek a `merge()`-dzsel érhetők el.

A `Custom` szándékosan vékony. A `renderer` és a `callback` a host app JavaScript-regiszterének
függvényeit nevezi meg, és PHP-ból egyik létezése sem ellenőrizhető — egy elgépelés olyan cella,
ami semmit nem renderel, és a böngészőben derül ki. A csatolás iránya itt fordított mindenhez
képest: a PHP-ban leírt név ígéret a frontend buildről.

Amire nincs builder-metódus, arra a `merge()` a szelep — ellenőrizetlen, és ezt vállalja is: a
sémában mind a kilenc config `additionalProperties: true`, és **egyedül a `type` kötelező**, tehát
a séma szinte bármit átengedne. Csak a szerkezeti kulcsokat (`type`, `key`, `if`, `else`) utasítja
vissza, mert azok döntik el, hogy a többit hogyan kell olvasni — és a `set()`-ben utasítja vissza,
mert oda fut be a `merge()` és a közvetlen hívás is. Egy kézzel írt `key` egyébként legyőzné azt,
amivel a feltételek kimennek, és vinné magával a feltételeket.

### Többmezős oszlopok

Egy `combined()` oszlop tagmezőnként egy szegmenst renderel, és az Aura mindegyiket az adott mező
neve alatt keresi. Ezért mezőnként konfigurálható:

```php
Column::combined('name', ['first_name', 'last_name'])
    ->reference('last_name')
    ->sortable()
    ->configure('last_name', Reference::make()->uppercase());
```

Egyetlen `->as()` egy ilyen oszlopon nem tud hová csatlakozni, ezért elutasításra kerül.

### Feltételek

Bármelyik konfiguráció változhat soronként. Az ágak sorrendben értékelődnek, az első találat nyer:

```php
use TamasLabs\Aura\Cell\Condition;

Column::make('balance')->as(
    Reference::make()
        ->when(Condition::lt(0), fn (Reference $r) => $r->color('danger')->fontWeight(700))
        ->when(Condition::gt(1_000_000), fn (Reference $r) => $r->color('success'))
        ->otherwise(fn (Reference $r) => $r->color('dark'))
);
```

A `when()` a feltételt és az ágat konfiguráló callbacket kapja; az `otherwise()` az, ami akkor
érvényesül, ha egyik ág sem illeszkedett. **Az `otherwise()` elhagyása jelentéssel bír**: az
egyetlen ágra sem illeszkedő sor üres cellát kap, és ez a támogatott módja annak, hogy egy cella
soronként elrejtőzzön.

A feltételek az oszlop saját mezőjét olvassák, hacsak az `on()` mást nem nevez meg:

```php
Badge::make()->on('archived_at')->when(Condition::notNull(), fn (Badge $b) => $b->variant('secondary'));
```

Tizenkilenc operátor — a szerződés összes operátora, miután az öt aliasa kikerült:

| | | |
| --- | --- | --- |
| `eq($v)` `ne($v)` | `gt($v)` `gte($v)` `lt($v)` `lte($v)` | `between($min, $max)` |
| `in($values)` `notIn($values)` | `contains($s)` `startsWith($s)` `endsWith($s)` | `regex($pattern)` |
| `isNull()` `notNull()` | `isEmpty()` `notEmpty()` | `isTrue()` `isFalse()` |

Négyük úgy viselkedik, ahogy a szerződés saját leírása **nem** mondja, és a különbség számít:

- **Az `isTrue()` / `isFalse()` egzakt.** Az `1` nem `true`. Egy `tinyint` oszlopot `bool`-ra kell
  castolni a modellen, különben az ág mindig hamis.
- **Az `isEmpty()` a `0`-t és a `false`-t üresnek számítja**, a `[]`-t és a `{}`-t viszont *nem*.
- **Az `eq()` szigorú.** Az `1` nem egyezik az `'1'`-gyel.
- **A `regex()` JavaScript-mintát vár** — határolók és PHP-módosítók nélkül. Egy a böngészőben
  lefordíthatatlan minta némán hamissá teszi az ágat.

Öt szintnél mélyebb egymásba ágyazás dob: az Aura ötöt old fel, és a csonkolt konfigurációt
rendereli, némán.

### Numerikus összehasonlítás

A `gt`, `gte`, `lt`, `lte` és `between` mindkét oldalon valódi számot követel — vagy két olyan
értéket, ami dátumként parse-olható —, és **egyébként hamis, mindenféle naplózás nélkül**. A
Laravel `decimal:2` castja stringként szerializálódik:

```json
{ "balance": "1234.50" }
```

Tehát a `Condition::gt(1000)` a pénzoszlopon soha nem illeszkedne — pont azon a típuson, ami a
leginkább kér egy ilyen feltételt. Ezért a csomag összegyűjti azokat a mezőket, amiket a feltételei
numerikusan hasonlítanak, és a válaszban **csak azokat** alakítja át. Két korlát szándékos: csak
numerikus stringet érint, tehát egy `gt`-vel hasonlított dátum sértetlen marad; és csak azokat a
mezőket, amiket egy feltétel tényleg olvas, tehát a payload többi része változatlan.

Egy `Progress` sáv `field`, `min` és `max` mezője ugyanezért alakul át, feltétel ide vagy oda.

### Cella- és sorszabályok

A `CellRules` a tartalom körüli `<td>`-t formázza, ugyanazzal a feltételes felülettel:

```php
use TamasLabs\Aura\Cell\CellRules;

Column::make('balance')->rules(
    CellRules::make()->when(Condition::lt(0), fn (CellRules $r) => $r->background('#fee'))
);
```

Ugyanez az objektum formázza a teljes sort, a tábláról:

```php
public function rowRules(): ?CellRules
{
    return CellRules::make()
        ->on('status')
        ->when(Condition::eq('suspended'), fn (CellRules $r) => $r->opacity(0.5));
}
```

A sorszabályoknak nincs oszlopuk, amitől mezőt kölcsönözhetnének, ezért `on()`-nal meg kell
nevezniük egyet.

**A sorszabály csak formázás.** Sort elrejteni nem tud, és a formázással eltüntetett sor adata ott
marad a payloadban bárkinek, aki elolvassa a választ. Az a sor, amit a felhasználó nem láthat, a
`query()`-n kívülre való.

### Route-ok

A route soronként feloldott sablon. Az Aura behelyettesíti a `{placeholder}` tokeneket, **minden
megmaradt pontot perjelre cserél**, majd elé fűzi a host app `siteName`-jét:

```php
Icon::make('pencil')->route('users.{id}.edit');   // → /users/5/edit
Icon::make('pencil')->route('/users/{id}/edit');  // → ugyanaz
```

Ezért utasítja el a csomag a Laravel `route()` helperének abszolút URL-jét: az
`/https://app/example/com/users/5/edit`-ként jönne ki. Az Aura `[\w.]+` ábécéjén kívüli placeholder
szintén elutasításra kerül — az szó szerint bennmaradna az URL-ben. Amit a csomag nem tud
ellenőrizni, az az érték: egy pontot tartalmazó behelyettesítés (mondjuk egy e-mail cím) szétszedi
az útvonalat.

**Egy ikonból csak `key`-jel lesz link.** A `renderIconNode` akkor csomagolja `<a>`-ba a glifát, ha
a `route` **és** a `key` is megvan — a `link`, a `button` és a `modal` beéri a route-tal. A csomag
kiadja helyetted, a route első placeholderéről elnevezve (`users.{id}.edit` → `id`), placeholder
nélküli route-nál pedig az oszlop kulcsáról, pontosan úgy, ahogy az Aura saját preprocesszora. Ha
mapping is van, a kulcs a mapping szelektora marad: az az egyetlen szerep, amiben az *értékét* is
olvassák, a linknek elég, hogy létezzen.

**Feltételes** konfigurációnál ez a kulcs nem maradhat a gyökérben: ott a `key` a feltételek által
olvasott mezőt jelöli, és az Aura eltávolítja, mielőtt a renderelő elindulna (`stripLogicProps`).
Ezért minden ág megkapja a sajátját, azok szerint a beállítások szerint, amikkel az az ág valóban
feloldódik — a route lehet az ágban is, nem csak az alapban. Enélkül egy soronkénti feltétel a
linkelő ikon fölött szabályosan elrejtené a cellát, majd minden engedélyezett sort link nélkül
renderelne:

```php
Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
    ->when(Condition::isTrue(), fn (Icon $i) => $i);

// {"type":"icon","icon":"pencil","route":"users.{id}.edit",
//  "key":"can_edit","if":[{"true":true,"key":"id"}]}
//                                      ^ a route kulcsa, ott, ahol az Aura megtartja
```

A saját `on()`-t kapott ág megtartja a magáét, a saját feltételekkel bíró ág pedig nem levél — az ő
`key`-je az alatta lévő szint szelektora, a keresés eggyel lejjebb folytatódik.

### Bootstrap a szerződésben

A `class`, a `text` és a Bootstrap-színnevek (`primary`, `success`, `danger`, …) a szerződés
kulcsai, nem ennek a csomagnak a kitalált absztrakciója. Változatlanul mennek át a dróton, és egy
másképp stílusozott frontendnek semmit nem jelentenek. A `variant` és az `icon` egy lépéssel
odébb van — azok a host app saját `variants` és `icons` regiszterének kulcsai, amiket az Aura az ő
oldalán old fel osztályokká, tehát egy ikonnévként átadott nyers CSS-osztály semmit nem renderel.

---

## Action-oszlopok

Az Aura maga építi meg a resource-linkeket. Egy header-cella, ami `edit_icon` nevű mezőt nevez meg,
és amihez sehol nincs konfiguráció, arra veszi a böngészőt, hogy generáljon egyet: az ikont a
gazdaalkalmazás ikon-regiszteréből, az útvonalat pedig abból a resource-bázisból, ami már nála van.
A konvenció-módban a szerver azt mondja meg, **melyik** akciókat kínálja az oszlop — és itt meg is
áll.

```php
use TamasLabs\Aura\Table\{Action, Column};

Column::actions('id', Action::show(), Action::edit(), Action::destroy())
```

Ebből egyetlen header-cella lesz, és semmi más:

```json
{ "content": null, "key": "id", "fields": ["show_icon", "edit_icon", "destroy_icon"] }
```

Se `body.columnConfigs` bejegyzés, se plusz kulcs a sorokban.

| Akció | A böngésző által épített útvonal | Ahogy renderel |
| --- | --- | --- |
| `Action::create()` | `{base}/create` | link |
| `Action::show()` | `{base}/{key}` | link |
| `Action::edit()` | `{base}/{key}/edit` | link |
| `Action::destroy()` | `{base}/{key}/destroy` | trigger az Aura beépített megerősítő modaljához |

A `{base}` az `urlParameter`, ami az Aura **kliens**-oldali config-propja. A szerver soha nem látja
— ezért lehet a konvenció-mód ilyen vékony, és ezért nem tud többet ennél: saját route, más ikon,
saját modal-id — azokhoz teljes konfiguráció kell, és az az F5b.

A `create` a kakukktojás. Az útvonalában nincs placeholder, az Aura mégis minden sorban rendereli.
Ez a kliens viselkedése, amit ez a csomag reprodukál és nem javít; egy létrehozás-gomb a toolbarban
van otthon.

Az oszlop-metódusok magára a cellára továbbra is működnek — fejléc, igazítás, szélesség:

```php
Column::actions('id', Action::edit(), Action::destroy())
    ->content('Műveletek')
    ->align('end')
    ->width('90px');
```

### A kulcs placeholder, nem név

A `Column::actions('id', …)` nem rendszeretetből kulcsolja az oszlopot `id`-re. Az Aura ezt a
kulcsot írja bele a generált útvonalba — `{base}/{id}/edit` —, és soronként az ugyanilyen nevű
item-mezőből tölti ki. A kulcsnak tehát annak az azonosítónak kell lennie, amit a sorok valóban
visznek: rendszerint az elsődleges kulcsnak.

Így más oszlop nem foglalhatja el, és az ütközés, amibe szinte minden tábla elsőként belefut, a
kijelölő oszlop — annak a kulcsa szintén a modell elsődleges kulcsára áll be:

```php
Column::selection()->key('select'),          // ← ezt kulcsold át
Column::actions('id', Action::edit()),
```

A kijelölő oszlop átkulcsolása a kijelölésen nem változtat semmit: az Aura a sor azonosítóját az
oszlop `field`-jéből olvassa, soha nem a kulcsából (`resolve-row-id.ts`). A kulcs csak a payloadon
belül azonosítja az oszlopot.

Ugyanez áll egy látható `id` oszlopra is — `Column::make('id')->key('identifier')` —, ami továbbra
is `id` szerint rendez és keres, mert azok a mező mentén utaznak.

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
| Üres stringes fejléc | a szerződés nem üres stringet vagy `null`-t vár; az üres az Aura saját validációján bukik el, és az egész táblát viszi |
| Cella, ami `field`-et és `fields`-et is megnevez | a szerződés a kettő közül egyet enged (`not: {required: [field, fields]}`); a mindkettőt vivő cella ugyanígy bukik az Aura validációján |
| Cella-konfiguráció olyan oszlopon, ahol a `key` és a `field` eltér | a renderelőt a mező, a cella-szabályokat a kulcs alól olvassa, tehát csak a fele érvényesülne |
| Egyetlen konfiguráció `combined()` oszlopon | az Aura tagmezőnként renderel szegmenst, és mindegyiket név szerint keresi — `configure()` kell |
| `configure()` olyan mezőre, amit az oszlop nem olvas | nincs hozzá szegmens |
| Feltételes cella-szabály `combined()` oszlopon `->on()` nélkül | a feltételek az oszlopkulcs alá kerülnének, ami nem érték a sorban: minden feltétel hamis, semmi nem stílusozódik, némán |
| Konfiguráció, ami semmit nem renderel (`Text` érték nélkül, `Icon` ikon nélkül, `Modal` trigger nélkül) | a cella üresen jönne ki |
| Ötnél mélyebbre ágyazott feltételek | az Aura ötöt old fel, és a csonkolt konfigurációt rendereli, némán |
| Feltételek, amiknek nincs olvasandó mezőjük | `key` nélkül az Aura átugorja őket, és az alapkonfigurációt érvényesíti — fail-open |
| Abszolút route, vagy `[\w.]+`-en kívüli placeholder | az Aura minden pontot perjelre cserél, tehát a `route()` URL-je útvonallá válik; a nem illeszkedő placeholder szó szerint bennmarad |
| Két oszlop, ami ugyanazt a mezőt rendereli | a `columnConfigs` egyetlen, mező szerint kulcsolt map, tehát a második bejegyzés felülírja az elsőt, és a vesztes oszlop a győztes konfigurációját rendereli |
| `merge()` vagy `set()`, ami `type`, `key`, `if` vagy `else` kulcsot állítana | azok döntik el, hogyan kell a többit olvasni; egy kézzel írt `key` legyőzi a kiadottat, és viszi magával a feltételeket |
| Action-oszlop olyan kulccsal, amit már egy másik oszlop visz | a kulcs a route-placeholder, amit az Aura soronként tölt ki, tehát nem szabadon változtatható — a másik oszlop az |
| Ugyanaz az akció két oszlopban, vagy kétszer egyben | az Aura mezőnevenként egy konfigurációt generál, tehát a második előfordulás némán az első útvonalát örökli, az első kulcsával felépítve |
| Action-mezőnév (`edit_icon`, `destroy_link`, …) `Column::actions()`-ön kívül | az Aura arra a cellára építene útvonalat, bármi is a kulcsa, az oszlop saját értéke pedig soha nem renderelődne |
| `sortable`, `searchable`, `filterable` vagy `globalSearch` egy action-oszlopon | ezek a flagek a whitelistbe kerülnek, egy ikon mögött viszont nincs adatbázis-oszlop |
| `Column::actions()` akció nélkül | a `fields` típusa `minItems: 1`, és az Aura csak akkor tekint egy cellát oszlopnak, ha megnevez valamit |
| `combined()` oszlop üres `fields`-szel | ugyanaz a szabály |

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
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?RequestLimits $limits = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?RequestLimits $limits = null): self
```

A `fromHttp` onnan olvassa a payloadot, ahová a szerződés teszi: `POST` / `PUT` / `PATCH` esetén a
JSON-törzsből, `GET` / `DELETE` esetén a query-paraméterekből. Mindkettő `ValidationException`-t —
**422**-t — dob mindenre, amit a szerződés nem enged.

| Kulcs | Típus | Kötelező | Megjegyzés |
| --- | --- | --- | --- |
| `page` | egész ≥ 1 | **igen** | |
| `paginate` | egész ≥ 1 | **igen** | a `pagination.max`-ra vágódik |
| `sortable[]` | `{field, direction}` | nem | a `direction` `asc` vagy `desc`; mezőnként egy bejegyzés |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | nem | szöveges vagy tartománykeresés; mezőnként egy bejegyzés |
| `filterable[]` | `{field, values[]}` | nem | a `values` lehet üres, de jelen kell lennie; mezőnként egy bejegyzés |
| `globalSearch` | string | nem | legfeljebb `limits.term` karakter |
| `selected[]` | string / szám | nem | sor-azonosítók a köteges műveletekhez; legfeljebb `limits.selected` |

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

### Mi korlátozza a kérést

A kérés minden listája és minden sztringje korlátozva van, mielőtt bármelyik elérné a lekérdezést.
Két különböző mechanizmus, és pont a kettő szétválasztása az érdekes.

**A három mezőlistát maga a whitelist korlátozza — nincs hozzájuk configkulcs.** Az Aura mezőnként
pontosan egy bejegyzést tart: a `use-sorting.ts`, a `use-searching.ts` és a `use-filtering.ts` is
megkeresi a mezőt, és a meglévő bejegyzést frissíti ahelyett, hogy másodikat fűzne hozzá. Egy három
rendezhető oszlopot kínáló tábla tehát soha nem állíthatott elő negyedik rendezést, vagyis a
`FieldPermissions` már most is a pontos plafon. A származtatás két okból is jobb a konfigurálásnál:
szorosabb minden észszerű alapértéknél, és nem tud elavulni, amikor az oszlopok változnak.

Két szabály következik belőle, mindkettő **422**:

- a whitelistjénél hosszabb lista — és ez *azelőtt* ellenőrződik, hogy bármi végigmenne a sorain,
  tehát a túlméretes payload akkor kerül elutasításra, amikor a megszámolása még az egyetlen munka,
  amit elvégeztünk rajta;
- ugyanaz a mező kétszer egy listában. Az Aura ilyet sosem küld, és két rendezés ugyanarra a mezőre
  `ORDER BY x ASC, x DESC`-et jelentene.

**Minden más a `limits`-ből jön** — a keresőkifejezések, a kijelölés, egy szűrő értékei. A kulcsokat
lásd a [Konfigurációnál](#limits--a-payload-többi-része).

A `selected` az az egyetlen lista, amire a szerver nem tud plafont származtatni: a kijelölés túléli
a lapozást (az Aura perzisztálja és unióval fésüli össze), tehát azzal nő, amit a felhasználó
kipipál, nem a táblával. Ezért kell hozzá szám.

Egy hívásra bármelyik felülírható `RequestLimits`-szel — ami `null` marad, az a configból jön, tehát
a részleges felülírás nem dobja el a többit:

```php
use TamasLabs\Aura\Request\RequestLimits;

AuraRequest::fromHttp($request, $fields, new RequestLimits(paginate: 50));
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

**A pontozott mező egy metódust nevez meg, és a metódus hívás *előtt* vizsgálatra kerül.** A
`company.name` azt jelenti, hogy „hívd meg a modell `company()`-jét" — a `delete.x` tehát azt
jelentené, hogy meghívjuk a `delete()`-et, és csak utána derül ki, hogy nem reláció jött vissza. A
lekérdezésréteg és az inference is a `Support\Relations`-on megy át, ami előbb ellenőriz: a
metódus legyen publikus, argumentum nélkül hívható, **ne a keretrendszer deklarálja**, és ha
egyáltalán van visszatérési típusa, az legyen `Relation`.

A középső szabály végzi az érdemi munkát: a `Model::delete()`, `save()`, `push()` és további
mintegy száz metódus **típusjelölés nélküli**, tehát a deklaráló osztályon kívül semmi nem
különbözteti meg őket egy ugyanilyen típusjelölés nélküli reláció-metódustól a saját modelleden.
(A Laravel `Model::isRelation()`-je itt nem segít — az `method_exists() || relationResolver()`.) A
csak `@return` docblockot viselő reláció továbbra is működik, ezért áll meg itt az őr; ami elérhető
marad, az egy típusjelölés nélküli, mellékhatásos metódus a saját modelleden, a saját oszlopoddal
megnevezve.

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
| `UnsupportedRelation` | to-many reláción vagy beágyazott relációs úton keresztüli rendezés, illetve ha a pontozott mező első szakasza egyáltalán nem reláció |
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
| **F4** | Cella-builderek: badge, link, button, icon, modal, progress, feltételes konfiguráció | ✅ kész |
| **F5a** | Akciók konvenció-módban: `Action`, `Column::actions()` | ✅ kész |
| **F5b** | Eszkaláció explicit `columnConfig`-ra, és route-építés | tervezett |
| **F5c** | Soronkénti jogosultság — a válasz-oldal | tervezett |
| **F6** | Demo workbench-app, `make:aura-table`, kiadás | tervezett |

---

## Licenc

MIT

## Szerző

Tamas Balint
