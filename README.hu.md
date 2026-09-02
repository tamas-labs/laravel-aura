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
  - [Generálás](#generalas)
- [Oszlopok](#oszlopok)
- [Következtetés a modellből](#következtetés-a-modellből)
- [Enumok](#enumok)
- [Presetek](#presetek)
- [Cella-renderelés](#cella-renderelés)
  - [A kilenc típus](#a-kilenc-típus)
  - [Minden builder-metódus](#minden-builder-metódus)
  - [Többmezős oszlopok](#többmezős-oszlopok)
  - [Feltételek](#feltételek)
  - [Numerikus összehasonlítás](#numerikus-összehasonlítás)
  - [Cella- és sorszabályok](#cella--és-sorszabályok)
  - [Route-ok](#route-ok)
  - [Bootstrap a szerződésben](#bootstrap-a-szerződésben)
- [Action-oszlopok](#action-oszlopok)
  - [A kulcs placeholder, nem név](#a-kulcs-placeholder-nem-név)
  - [Eszkaláció](#eszkaláció)
  - [Route-ok](#action-route-ok)
- [Soronkénti jogosultság](#soronkénti-jogosultság)
  - [Hogyan megy ki](#hogyan-megy-ki)
  - [Egy lekérdezés a lapra, nem soronként egy](#egy-lekérdezés-a-lapra-nem-soronként-egy)
  - [Cache](#cache)
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
- [Verziózás](#verziózás)
  - [Mit fed a semver](#mit-fed-a-semver)
  - [Mit fed a szerződés saját verziója](#mit-fed-a-szerződés-saját-verziója)
  - [A tag előtt](#a-tag-előtt)
- [A saját payloadod validálása](#a-saját-payloadod-validálása)
- [Fejlesztés](#fejlesztés)
  - [A demo-alkalmazás](#a-demo-alkalmazas)
- [Ütemterv](#ütemterv)
- [Licenc](#licenc)

---

## Állapot

A csomag a tervének **F6.4** fázisánál tart: a tábla osztály, végponttól végpontig kiszolgál egy
kérést, a cellái többet renderelnek szövegnél, a négy resource-akció egyetlen hívás — testreszabva
is —, egy cella az egyik sornak felkínálható, a másiknak nem, a `make:aura-table` megírja az első
vázlatot, a referencia-dokumentációt — és alatta a verzió-ígéretet — pedig már nem a fegyelem
tartja, hanem egy teszt.

| Ma működik | Még nincs kész |
| --- | --- |
| `AuraTable` — táblánként egy osztály, `respond($request)` | A Packagist-kiadás (F6.5) |
| Oszlopok, csoportok, footer, tábla-beállítások | |
| Oszlop-defaultok a modell castjaiból | |
| A mező-whitelist, az oszlopokból származtatva | |
| Rendezés, keresés, szűrés, globális keresés | |
| Relációk mind a négy műveletben | |
| A kilenc cella-renderelő, feltételekkel és cella-szabályokkal | |
| Action-oszlopok — konvenció-mód és eszkaláció teljes konfigurációra | |
| Route a `$resource`-ból, nevesített route-ból vagy kiírva | |
| Soronkénti jogosultság — `allowedWhen()`, kötegelve vagy sem | |
| Cache-elhető, kérésfüggetlen definíció | |
| `make:aura-table`, a modell táblájából felskiccelve | |
| Futtatható demo-alkalmazás (`composer serve`) | |
| Dokumentáció-lefedettségi őr mindkét referencia felett | |
| Kimondott és teszttel őrzött verzió-ígéret | |

Ami hátravan, az maga a kiadás: a tag.

A csomag **nincs kiadva**: nincs tag, nincs fenn Packagiston. A repóból telepítsd.

---

## Követelmények

- **PHP** `^8.3` — a CI-mátrix 8.3-at és 8.4-et futtat; a constraint a 8.5-öt is engedi, az még nincs tesztelve
- **Laravel** `^12.0 || ^13.0` — az `illuminate/*` komponensek, nem a framework-csomag
- Bármilyen Eloquent által támogatott adatbázis-driver; a teszt-suite SQLite-on fut, a `LIKE`
  escape-elés pedig úgy van megírva, hogy MySQL/MariaDB-n, PostgreSQL-en és SQLite-on egyformán
  viselkedjen
- A böngésző oldalán olyan Aura, ami az **1.0 szerződést** olvassa — lásd a
  [Verziózás](#verziózás) szakaszt, mert valójában ez a verziószám dönti el a kompatibilitást

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

<a id="generalas"></a>
### Generálás

```bash
php artisan make:aura-table UserTable --model=User
```

Kiírja az `app/Tables/UserTable.php`-t, a modell **saját adatbázistáblájából** felskiccelve:
oszloponként egy `Column::make()`, azokkal a flagekkel, amiket a típusa és a castja indokol.

| Amit talál | Amit ír |
| --- | --- |
| `BackedEnum` cast vagy boolean | `->filterable()` — a legördülő `elements`-ét a cast tölti fel build-időben |
| minden más olvasható: szöveg, szám, dátum | `->sortable()->searchable()`; a `currency`, az igazítás és a tartomány-beviteli mező a castból érkezik, amikor a definíció felépül |
| az elsődleges kulcsot | semmit — a kijelölő és az action-oszlop már olvassa |
| `*_id` idegen kulcsot | kommentet, ami a `Column::make('company.name')`-re mutat: az a kapcsolt sort rendereli, és alkérdéssel rendezi |
| `json` / `blob` oszlopot, vagy amit a modell `$hidden`-je rejt | kommentet, vagy semmit |

Két sor azért van benne, mert minden táblának kell, és az egyik csapda:
`Column::selection()->key('select')` és `Column::actions('id', …)`. Mindkettő alapból a modell
kulcsát venné, és az action-oszlop az, amelyik nem mozdulhat — a kulcsa *maga* a
route-placeholder —, ezért a generátor a kijelölő oszlopot kulcsolja át, ami ingyen van: az Aura a
sor azonosítóját annak az oszlopnak a `field`-jéből olvassa.

**Ez első vázlat, és semmi szerkesztői döntést nem talál ki.** Semmi nem kerül a globális
keresésbe, egyik oszlop sem kap cella-renderelőt, és `$resource` sem íródik ki; a generált osztály
docblockja ki is mondja. Elérhetetlen adatbázis esetén helyőrzőt ír, és szól róla.

A `--model` elhagyható — a modellt az osztálynévből találja ki, ahogy a `make:policy` teszi, a
záró `Table` levágásával. Az alkalmazás névterén kívüli modellt úgy veszi, ahogy megadtad. A
projekt gyökerébe tett `stubs/aura-table.stub` felülírja a sablont.

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

### Minden builder-metódus

Amit egy konfiguráció tud, annak a java négy közös blokkból jön; minden típus ezen felül tesz hozzá
néhányat a sajátjából. Ami itt következik, az a cella-réteg teljes publikus felülete — minden más
metódus ezeken az osztályokon `@internal`, és nem tartozik a verzió-ígéret alá.

**Minden konfiguráción.**

| Metódus | Mit csinál |
| --- | --- |
| `when(Condition $c, callable $branch)` | egy ág, ami illeszkedéskor ráolvad az alapra; az első találat nyer |
| `otherwise(callable $branch)` | ami akkor érvényes, ha egy ág sem illeszkedett — az elhagyása a cella elrejtésének módja |
| `on(string $field)` | a mező, amit a feltételek olvasnak; alapból az, amire a konfiguráció rá van akasztva |
| `rules(CellRules $rules)` | a tartalmat tartó `<td>` feltételes formázása |
| `allowedWhen(callable $allowed)` | csak azoknak a soroknak renderel, amelyeket a callback enged |
| `allowedWhenAll(callable $resolver)` | ugyanaz, egyszer előkészítve az egész lapra |
| `set(string $key, mixed $value)` | egy szerződéskulcs, kézzel |
| `merge(array $attributes)` | több egyszerre — a validálatlan vészkijárat |
| `class(string\|array $class)`, `style(string $style)` | CSS a típus által rajzolt elemen |

**A formázó-lánc** — a `Text`, `Reference`, `Badge`, `Link`, `Button` és `Custom` típusokon. Az
`Icon`, a `Modal` és a `Progress` nem kap ilyet, és az oszloptól sem örököl formázást.

| Metódus | Mit csinál |
| --- | --- |
| `number()` | a host app számformátuma |
| `currency()` | a host app `currencyCode`-ja |
| `date()`, `datetime()` | dátum, illetve dátum + idő |
| `time()` | másodpercek `HH:mm:ss` alakban |
| `phone()` | telefonszám-formátum |
| `raw()` | HTML-ként rendereli — az Aura előbb tisztítja, de a legrövidebb biztonságos válasz továbbra is az, hogy nem küldesz olyan markupot, amit nem te építettél |
| `unit(string $unit)` | az érték után írt mértékegység: `kg`, `%` |
| `slice(int $characters)` | a szöveg csonkolása |
| `uppercase()`, `lowercase()`, `capitalize()` | kisbetű/nagybetű |
| `monospace()` | fix szélességű számjegyek; az `align('end')` párja |
| `padStart(int $length, ?string $chars = null)`, `padEnd(…)` | kitöltés adott szélességre |
| `chars(string $chars)` | a kitöltő karakter, ha nem közvetlenül a `padStart()`-nak adod |

**Tipográfia** — az `Icon` és a `Modal` kivételével mindenen.

| Metódus | Mit csinál |
| --- | --- |
| `color(string $color)` | szövegszín: Bootstrap téma-színnév vagy CSS-szín |
| `background(string $color)` | a tartalom mögötti szín |
| `align(string $align)` | igazítás a cellán belül |
| `fontSize(string $size)` | `px`/`rem`/`em`/`%` hossz, vagy kulcsszó (`large`) |
| `fontWeight(int\|string $weight)` | 100 és 900 közti százas, vagy kulcsszó |
| `italic()`, `normal()` | dőlt, illetve a dőltség visszavonása egy ágban |
| `lineHeight(float\|int\|string $lineHeight)` | pozitív szám, hossz, vagy `normal` |
| `text(string $utility)` | Bootstrap `text-*` utility-osztály — természeténél fogva keretrendszerhez kötött, egy másképp stílusozott frontendnek nem jelent semmit |

**Mapping** — a `Reference`, `Badge`, `Link`, `Button`, `Icon` és `Custom` típusokon: a `mapping(array
$mapping)` a mező értéke szerint kulcsolt kereső-tábla, minden bejegyzés egy beállítás-csomag arra
az értékre. A `Progress`-nek is van, de más szemantikával — ott a kulcsok tartományok (`"0-25"`),
nem értékek.

**Route-ok** — a `Link`, `Button`, `Icon` és `Modal` típusokon: `route(string $route)`, lásd a
[Route-ok](#route-ok) szakaszt.

És amit az egyes típusok hozzátesznek:

**`Text`** — a szerződés `static` típusa.

| Metódus | Mit csinál |
| --- | --- |
| `Text::make(?string $value = null)` | a builder |
| `value(string $value)` | a megjelenő szöveg; a sort sosem olvassa |

**`Reference`** — a sor saját értéke.

| Metódus | Mit csinál |
| --- | --- |
| `Reference::make(?string $field = null)` | a builder; a mező alapból az oszlopé |
| `Reference::combined(array $fields, string $separator = ' ')` | több mező, összefűzve |
| `value(string $value)` | fix szöveg, a sort figyelmen kívül hagyva — erősebb, mint a `fields` és a `field` |
| `separator(string $separator)` | ami az összefűzött `fields` értékek közé kerül |

**`Badge`**

| Metódus | Mit csinál |
| --- | --- |
| `Badge::make(?string $field = null)` | a builder |
| `Badge::fromEnum(string $enum, ?string $field = null)` | esetenként egy badge: felirat, plusz szín és ikon, ha az enum kínálja |
| `value(string $value)` | fix felirat a mező értéke helyett |
| `variant(string $variant)` | alapszín, `text-bg-{variant}` alakban |
| `pill(bool $pill = true)` | pill-forma |
| `size(string $size)` | `xs`, `sm`, `md`, `lg` vagy `xl` |
| `icon(string $icon, ?string $position = null)` | glyph a felirat mellé — kulcs a host app `icons` regiszterébe, nem CSS-osztály |
| `whenTrue(array $badge)`, `whenFalse(array $badge)` | az igaz, illetve hamis értékhez tartozó badge |
| `showZero(bool $show = true)` | `0` esetén is renderel; alapból be van kapcsolva |
| `maxValue(int $max, string $suffix = '+')` | a szám felső korlátja; felette `{max}{suffix}` látszik |
| `prefix(string $prefix)` | a felirat elé írt szöveg, például `#` |

**`Link`**

| Metódus | Mit csinál |
| --- | --- |
| `Link::make(?string $field = null)` | a builder |
| `value(string $value)` | fix linkszöveg a mező értéke helyett |
| `variant(string $variant)` | szín: kulcs a host app `variants` regiszterébe, vagy Bootstrap színnév |
| `title(string $title)` | tooltip |
| `target(string $target)` | az anchor `target`-je — a `_blank` biztonságos `rel`-t is beállít, különben a megnyitott oldal fogást kapna ezen |
| `rel(string $rel)` | az anchor `rel`-je |

**`Button`**

| Metódus | Mit csinál |
| --- | --- |
| `Button::make(?string $label = null)` | a builder |
| `value(string $value)` | fix gombfelirat |
| `variant(string $variant)` | szín: `variants`-kulcs vagy Bootstrap színnév |
| `size(string $size)` | `xs`…`xl` |
| `rounded(bool $rounded = true)`, `pill(bool $pill = true)` | sarokforma |
| `icon(string $icon, ?string $position = null)` | glyph — `icons`-regiszterkulcs |
| `disabled(bool $disabled = true)` | letiltva rendereli; csak megjelenés, a sor adata ettől még ott van |
| `title(string $title)` | tooltip |
| `htmlType(string $type)` | az elem `type` attribútuma: `button`, `submit` vagy `reset` |

**`Icon`** — se formázó-lánc, se tipográfia.

| Metódus | Mit csinál |
| --- | --- |
| `Icon::make(?string $icon = null)` | a builder; a glyph `icons`-regiszterkulcs |
| `variant(string $variant)`, `color(string $color)` | szín, ugyanúgy feloldva |
| `size(string $size)` | `xs`…`xl` |
| `alt(string $alt)` | akadálymentes felirat, `aria-label`-ként — érdemes megadni: egy glyph önmagában semmit nem mond a képernyőolvasónak |
| `title(string $title)` | tooltip |

**`Modal`** — egy trigger, plusz a modal azonosítója, amit megnyit.

| Metódus | Mit csinál |
| --- | --- |
| `Modal::make(string $id)` | a builder |
| `Modal::destroy()` | az Aura beépített törlés-megerősítése |
| `icon(string $icon, ?string $variant = null)` | ikon-trigger rövidítés |
| `button(string $variant, ?string $label = null)` | gomb-trigger rövidítés |
| `content(CellConfig $content)` | trigger bármelyik másik cella-konfigurációból |
| `size(string $size)` | a trigger mérete |
| `alt(string $alt)`, `title(string $title)` | akadálymentes felirat és tooltip a triggeren |
| `target(string $target)` | anchor `target`, link-triggerhez |

**`Progress`**

| Metódus | Mit csinál |
| --- | --- |
| `Progress::make(?string $field = null)` | a builder |
| `Progress::stacked(array $bars)` | egy sáv több szegmensből, mindegyik a saját mezőjét olvassa |
| `value(float\|int $value)` | fix érték a sorból olvasott helyett |
| `max(float\|int\|string $max)`, `min(…)` | a skála; szám, vagy az azt tartó mező neve. Alapból `100` és `0` |
| `variant(string $variant)` | a sáv színe |
| `height(string $height)` | a sín magassága, például `20px` |
| `striped(bool $striped = true, bool $animated = false)` | csíkos, opcionálisan animált |
| `label(bool\|string $label = true, ?string $position = null)` | `true` az értéket írja ki, vagy fix szöveg |
| `showValue(bool $show = true)` | a nyers érték a feliratban |
| `showPercent(bool $show = true, ?int $decimals = null)` | a százalék, ennyi tizedesre |
| `affixes(?string $prefix = null, ?string $suffix = null)` | a felirat köré írt szöveg |
| `thresholds(array $thresholds)` | Bootstrap szín → zárt `[min, max]` tartomány; az első tartomány színez, amibe az érték beleesik |
| `mapping(array $mapping)` | tartomány szerint (`"0-25"`) kulcsolt sáv-beállítások |

**`Custom`** — az egyetlen típus, aminek a tartalmát a PHP nem tudja ellenőrizni.

| Metódus | Mit csinál |
| --- | --- |
| `Custom::template(string $template)` | sablonszöveg; a `{placeholder}` tokenek a sorból és a `params()`-ból jönnek |
| `Custom::renderer(string $name)` | függvény a host app `renderers` regiszterében, ami node-ot ad vissza |
| `Custom::callback(string $name)` | függvény a `callbacks` regiszterében, ami szöveget ad vissza |
| `field(string $field)` | az értéket tartó item-mező |
| `fields(array $fields)` | több item-mező, a renderernek átadva |
| `value(string $value)` | fix szöveg |
| `params(array $params)` | további értékek a sablonnak és a regisztrált függvényeknek |

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

A `CellRules` ugyanazt a `when()` / `otherwise()` / `on()` / `set()` / `merge()` hívássort viszi,
mint minden konfiguráció, és ezekkel egészíti ki:

| Metódus | Mit csinál |
| --- | --- |
| `CellRules::make()` | üres szabálykészlet, ágakra készen |
| `background(string $color)`, `color(string $color)` | a cella háttér- és szövegszíne |
| `borderTop()`, `borderBottom()`, `borderLeft()`, `borderRight()` | keret az adott oldalon; mindegyik `bool $border = true` paramétert vesz |
| `borderColor(string $color)`, `borderWidth(string $width)` | a keret színe és vastagsága, például `3px` |
| `padding(string $padding)` | belső margó, például `8px 16px` |
| `opacity(float $opacity)` | 0 és 1 között |

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

### Eszkaláció

A konvenció-mód a böngésző saját alapértelmezéseinél megáll. Amint bármit testreszabsz — route,
ikon, szín, felirat, tooltip, modal-id —, a mezőt már nem lehet a böngészőre hagyni, mert a generált
konfiguráció nem vinné magával a testreszabást. Az akció ezért **eszkalál**: maga adja ki a teljes
`body.columnConfigs` bejegyzést, a preprocesszor pedig kihagyja azt a mezőt, amihez már van
konfiguráció.

A hívási felület nem változik, csak a payload.

```php
Column::actions('id',
    Action::show(),                                    // konvenció: nincs config
    Action::edit()->title('Felhasználó szerkesztése'), // eszkalált
    Action::destroy()->asButton()->variant('danger'),  // eszkalált
)
```

Az eszkaláció akciónként történik, nem oszloponként: a fenti `show` továbbra sem kerül semmibe.

Az `asIcon()` / `asLink()` / `asButton()` az alakot választja — az `_icon`, `_link` vagy `_button`
utótagot —, és **nem** testreszabás, mert az Aura mindhármat generálja. A `set()` a trigger
konfigurációjának bármelyik további kulcsát eléri (`size`, `target`, `rounded`, `data-*` attribútum),
és a többihez hasonlóan eszkalál.

Amit egy akció elfogad:

| Metódus | Mit csinál | Eszkalál |
| --- | --- | --- |
| `Action::create()`, `show()`, `edit()`, `destroy()` | a négy resource-akció | — |
| `asIcon()`, `asLink()`, `asButton()` | az alak, és vele a mező utótagja | nem |
| `icon(string $icon)` | a glyph — `icons`-regiszterkulcs, nem CSS-osztály | igen |
| `variant(string $variant)` | a szín; gombon közvetlenül `btn-{variant}` lesz | igen |
| `label(string $label)` | a link vagy gomb látható szövege — az Aura generált felirata a puszta prefix (`edit`) | igen |
| `title(string $title)` | tooltip | igen |
| `alt(string $alt)` | akadálymentes felirat; ikonon érdemes, mert az önmagában semmit nem mond a képernyőolvasónak | igen |
| `route(string $route)`, `routeName(string $name, array $parameters = [])` | hová megy | igen |
| `modal(string $id)` | a modal, amit egy `destroy` megnyit — magától eszkalál, mert egy generált konfiguráció csak az Aura beépítettjét tudná megnevezni | igen |
| `allowedWhen(callable $allowed)`, `allowedWhenAll(callable $resolver)` | [soronkénti jogosultság](#soronkénti-jogosultság) | igen |
| `set(string $key, mixed $value)` | a trigger konfigurációjának bármelyik további kulcsa | igen |

Az eszkalált akciónak olyan route kell, amit a **szerver** tud felépíteni — erre való a `$resource`:

```php
final class UserTable extends AuraTable
{
    protected ?string $resource = 'admin/users';
    // …
}
```

Enélkül — és az akció saját route-ja nélkül — a build elszáll, és megmondja, mit kell beállítani.
Konvenció-módban a `$resource` soha nem kell: a böngésző a saját `urlParameter`-éből építi az
útvonalat. Ha a bázis nem állandó — mondjuk bérlőnként más —, a property helyett a `resource():
?string` metódust írd felül.

Két dolgot az eszkaláció nem tud bájtra reprodukálni, mindkettőt azért, mert a regiszterek a
böngészőben élnek:

| | Amit az Aura generál | Amit a szerver eszkalál |
| --- | --- | --- |
| ikon glyph | `class: ['fas','fa-pen','text-primary']`, az `icons` / `variants` regiszterből | `icon` és `variant`, amit a `normalizeIconConfigs` ugyanabban a menetben, ugyanazokból a regiszterekből old fel |
| gomb színe | `variants[prefix]`, `variants.primary`-re, majd `primary`-ra visszaesve | `primary`, hacsak a `->variant()` mást nem mond |
| `destroy` modal | visz egy dekoratív `key`-t | elhagyja — a `resolveRoute` az útvonalat és a sort olvassa, a kulcsot soha |

Az első és a harmadik a payloadon változtat, máson nem. A második az egyetlen hely, ahol az
eszkaláció azon is változtat, amit a felhasználó lát — és csak akkor, ha a gazdaalkalmazás
regisztrált variánst az akció saját neve alatt.

<a id="action-route-ok"></a>
### Route-ok

Három módon mondhatod meg, hová megy egy akció, preferencia szerint:

```php
Action::edit()                                        // a konvenció — a böngésző építi
Action::edit()->routeName('admin.users.edit')         // a már regisztrált route
Action::edit()->route('admin/users/{id}/edit')        // kiírt útvonal
```

A `routeName()` a route URI-ját **úgy olvassa, ahogy regisztrálva lett** —
`admin/users/{user}/edit` —, soha nem a `route()` helperen keresztül, aminek az abszolút URL-jéből
az Aura `/https://app/example/com/admin/users/5/edit`-et csinálna. A megnevezett paramétereket
behelyettesíti; ami nyitva marad, abból lesz a placeholder, amit az Aura a sorból tölt ki, az
action-oszlop kulcsa alatt:

```php
Action::show()->routeName('companies.users.show', ['company' => $company->id]);
// companies/7/users/{id}
```

Az az érték, ami maga is `{placeholder}`, érintetlenül megy át — így egy második sormező tölthet ki
egy második paramétert. Egynél több nyitva hagyott paraméter elutasítva: csak egy jöhet a sorból.

**Az action-route-ban a pont tilos**, szemben a csomag többi részével. Az Aura minden pontot perjelre
cserél, tehát egy útvonal helyére írt Laravel route-**név** (`users.edit`) `/users/edit`-re oldódik
fel: valódi URL, hiányzó azonosítóval, és sehol egy hiba. A route-nevekhez a `routeName()` való.

---

## Soronkénti jogosultság

Egyes sorok szerkeszthetők, mások nem. Az `allowedWhen()` mondja meg, melyik — az akción vagy a
cella-konfiguráción —, és amelyik sort elutasítja, ott a cella egyszerűen nincs ott:

```php
use Illuminate\Support\Facades\Gate;

Column::actions('id',
    Action::show(),
    Action::edit()->allowedWhen(fn (User $user) => Gate::allows('update', $user)),
    Action::destroy()->allowedWhen(fn (User $user) => Gate::allows('delete', $user)),
)
```

A callback a sor **modelljét** kapja, nem a tömböt, amivé az kilapul — egy policy az objektumot
akarja. Bármi igaz értékű engedélyez.

> **A cella elrejtése nem jogosultság.** A sor benne van a payloadban, az azonosító is, és a route
> is ott van a `columnConfigs`-ban annak, aki elolvassa a választ. Ez attól óvja meg a táblát, hogy
> olyan akciót kínáljon, amit a szerver aztán megtagadna; magának a megtagadásnak a route-on kell
> lennie. Azt a policy-t add az `allowedWhen()`-nek, ami a route-ot védi — ne egy másodikat, ami ma
> véletlenül egyetért vele.

Ugyanez a hívás bármelyik cella-konfiguráción működik:

```php
Column::make('email')->as(
    Link::make()->route('users/{id}')->allowedWhen(fn (User $user) => Gate::allows('view', $user)),
)
```

### Hogyan megy ki

Az Aura semmit nem renderel, ha egyik `if` ág sem illeszkedett és nincs `else`
(`resolve-conditional-config.ts`). Ez maga a mechanizmus. A kapu egy rejtett, soronkénti flag, a
konfiguráció pedig egyetlen feltétel fölötte:

```json
{
  "type": "icon",
  "key": "_allowed_edit_icon",
  "if": [{ "true": true, "icon": "edit", "route": "admin/users/{id}/edit", "key": "id" }]
}
```

a sorok pedig viszik a flaget:

```json
{ "id": 1, "last_name": "Lovelace", "_allowed_edit_icon": true }
```

Ennek a payloadnak négy tulajdonsága szándékos, és mindegyik egy-egy mód, ahogy ez különben némán
elromolhatna:

- **A flag minden sorban ott van, a `false` is.** A hiányzó mező `undefined`-ként olvasódik, a
  definiálatlan flag pedig ugyanúgy elrejti a cellát, mint egy tiltás — egy leállt kapu tehát
  pontosan úgy nézne ki, mint egy tábla, ahol senkinek semmi nem szabad. Így mindig ott van, hogy
  ránézhess.
- **Valódi `bool`.** Az Aura `true` operátora egzakt összehasonlítás (`fieldValue === true`), tehát
  egy `tinyint` `1`, vagy a driver által visszaadott `"1"` minden sort megtagadna, egy szó nélkül.
  Amit a callback visszaad, az castolódik.
- **A kapu körbeveszi a konfigurációt, nem mellé áll.** Minden, amit a cella renderel, az ágon belül
  van — a hívó saját `when()` / `otherwise()` hívásaival együtt. Egy konfigurációnak egy feltétel-
  mezője van, tehát egy azonos szinten álló kapunak ugyanazt a mezőt kellene olvasnia, mint a saját
  feltételeidnek, egy alatta lévő `otherwise()` pedig pont azoknak a soroknak renderelné a cellát,
  amelyeket a kapu megtagadott. Kívülről nem lehet megkerülni — ezért nem tiltjuk a kettőt egymás
  mellett.
- **A `cellRules` a gyökérben marad.** Az nem tartalom: az Aura a `columnConfigs[column.key]` alól
  olvassa, és a `<td>`-t attól függetlenül stílusozza, hogy renderelődik-e benne bármi.

A flag arról a mezőről kapja a nevét, amit őriz, a pontokat kilapítva — a
`Column::make('company.name')` a `_allowed_company_name`-re kapuz, mert egy pontozott név az Aura
`resolveValue`-jét egy olyan `_allowed_company`-n belüli `name` keresésére küldené, amit egy sor sem
visz. Két olyan kaput, ami egy flaget írna, elutasítunk — nem összeolvasztunk.

A kapuzott akció **eszkalál**, mint bármelyik másik testreszabás: a generált konfiguráció nem visz
feltételt, tehát a szervernek kell kiadnia az egész bejegyzést — ehhez pedig `$resource` kell, vagy
route az akción. A mellette álló kapuzatlan akció továbbra sem kerül semmibe.

### Egy lekérdezés a lapra, nem soronként egy

Az `allowedWhen()` egy már memóriában lévő modellt kap, és semmibe nem kerül. Ha a döntéshez olyan
keresés kell, amit a sorok maguktól nem tudnak megválaszolni, az `allowedWhenAll()` egyszer készíti
elő az egész lapra, és a soronkénti tesztet adja vissza:

```php
use Illuminate\Database\Eloquent\Collection;

Action::destroy()->allowedWhenAll(function (Collection $rows) {
    $locked = Lock::whereIn('post_id', $rows->modelKeys())->pluck('post_id')->flip();

    return fn (Post $post) => ! $locked->has($post->id);
});
```

A kollekció a lap Eloquent-modellekként, tehát a `modelKeys()`, a `loadMissing()` és a többi ott
van. A külső callback válaszonként egyszer fut; csak a belső fut soronként. Ha nem callable-t ad
vissza, azt elutasítjuk.

### Cache

A kapu closure, a [cache-elt definíció](#a-definíció-cache-elése) pedig sima tömb. A cache a flag
*nevét* tartja — a definícióba írt feltételként —; a callbacket, ami kitölti, minden kérésnél
frissen szedjük össze, tehát a `$cache = true` és az `allowedWhen()` együtt működik.

Elcsúszni csak egy irányba tudnak. Egy olyan cache-elt definíció, ami még megnevez egy flaget, amit
már egyik oszlop sem tölt ki, olyan sorokat termel, amikben nincs az a mező — a hiányzó flag pedig
nem `true`, tehát a cella rejtve marad. Az a kapu, aminek a flagjét már egy feltétel sem olvassa,
egy olvasatlan mezőt tesz a sorba. Egyik sem fed fel semmit.

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
szabályt is, hogy a mezőt nem nevező cellának legalább két oszlopot kell átfognia. A
`Footer::make()` az első sort deklarálja; a `row(Column ...$cells)` tesz alá még egyet, annyiszor,
ahányszor a footernek kell.

```php
public function settings(): TableSettings
{
    return TableSettings::make()
        ->stickyHeader()->headerHeight('48px')
        ->striped()->hoverable()
        ->stickyFooter();
}
```

A teljes készlet: `stickyHeader()`, `headerHeight(string $height)`, `striped()`, `hoverable()`,
`stickyFooter()` és `footerHeight(string $height)` — a három kapcsoló `bool $x = true` paramétert
vesz, a két magasság CSS-hosszt, például `48px`.

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
| Testreszabott akció `$resource` és saját route nélkül | az eszkalált akció maga adja ki az útvonalat, a szervernek pedig nincs miből felépítenie |
| Abszolút vagy pontot tartalmazó `$resource` vagy action-route | az Aura maga teszi elé a `siteName`-et, és minden pontot perjelre cserél — az útvonal helyére írt route-név valódi URL-re oldódik fel, hiányzó azonosítóval |
| `routeName()` nem regisztrált route-ra | az akció üres útvonalra mutatna |
| `routeName()`, ami egynél több paramétert hagy nyitva | csak egy tölthető ki a sorból: amire az action-oszlop kulcsol |
| Két jogosultsági kapu, ami egy flaget írna | a flag arról a mezőről kapja a nevét, amit őriz, tehát két, csak egy pontban különböző mező ütközik, és egy kapu döntene mindkét celláról |
| `allowedWhen()` olyan oszlopon, ami nem nevez meg mezőt | a konfiguráció mezőnév alatt jut el a böngészőhöz, és a flag arról kapja a nevét |
| `allowedWhenAll()`, ami nem callable-t ad vissza | a lapot kapja, és a soronkénti tesztet kell visszaadnia |
| Kapu olyan feltételek fölé, amik már öt mélyek | a kapu a hatodik szint, az Aura pedig ötöt old fel |

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

Az `allowsSort(string $field)`, `allowsSearch(string $field)` és `allowsFilter(string $field)`
külön-külön is megválaszolja a három kérdést, ha ugyanarra a döntésre máshol is szükség van — egy
policyben, vagy egy controllerben, ami olyat állít össze, amit a tábla nem szolgál ki.

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

## Verziózás

Három verziószám találkozik itt, és egymástól függetlenül mozognak:

| Szám | Mit számol | Ma |
| --- | --- | --- |
| `tamas-labs/laravel-aura` | ez a csomag — semver, a git tagből | kiadatlan (`dev-main`) |
| `AuraContract::VERSION` | a szerződés, amit ír | **1.0** |
| `@tamas-labs/aura` | a Vue-tábla, ami ezt a szerződést olvassa | **1.0** |

**A kompatibilitást a középső sor dönti el, nem a másik kettő.** Bármelyik Aura-kiadás, ami az 1.0
szerződést olvassa, együtt működik ennek a csomagnak bármelyik olyan kiadásával, ami azt írja —
függetlenül attól, mit mond a két csomagverzió. Pont ezért él a séma külön csomagban, a
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema)-ban, ahelyett hogy mindkét
oldal a saját másolatát hordozná. A kérdés tehát nem az, hogy „melyik Aura-verzió", hanem az, hogy
„melyik szerződés-verzió" — és arra a konstans válaszol.

### Mit fed a semver

**A publikus felület két felület.** Az egyik a PHP, amit hívsz; a másik a JSON, ami a böngészőbe
ér. Az a változás, ami a tábla-osztályodban minden hívást változatlanul hagy, de mást rajzoltat a
böngészővel, ugyanúgy törő változás — a payload **ez a csomag kimenete**, és a definíció ráadásul
gyakran cache-elt, tehát a gazdaalkalmazás észre sem venné.

| Változás | Milyen |
| --- | --- |
| Új builder-metódus, új `Condition`, új preset | minor |
| Új következtetési szabály, ami eddig üres helyet tölt ki | minor — a kimaradás a `->withoutInference()` |
| Új kulcs egy kiadott konfigurációban, amit a renderelő figyelmen kívül hagy | minor |
| Ugyanaz a definíció más alakú `columnConfigs`-ot, header-cellát vagy whitelistet ad | **major** |
| Dokumentált metódus átnevezése, törlése vagy a paramétereinek szűkítése | **major** |
| `@internal`-nak jelölt metódus alakváltása vagy eltűnése | egyáltalán nem verzió-esemény |
| Új PHP- vagy Laravel-verzió a mátrixba | minor |
| Egy meglévő elhagyása | **major** |

Az `@internal` határ nem szándék kérdése: a `tests/DocsCoverageTest.php` reflexióval végigmegy a
`src/`-en, és elbukik, ha egy metódus se nincs benne mindkét teljes referenciában, se nincs
`@internal`-nak jelölve — a két lista tehát ugyanaz a lista. Amit itt dokumentálunk, azt fedi a
verzió-ígéret; mást nem. És a fedett halmaz le is van írva: a `tests/Docs/public-surface.txt`
mind a 246-ot tartalmazza, és az a build, amelyik egyet hozzátesz vagy elveszít, névvel mondja
meg — abban az irányban, ami a kiadást eldönti. Lásd a [Fejlesztés](#fejlesztés) szakaszt.

### Mit fed a szerződés saját verziója

A válasz-séma `additionalProperties: true`, tehát **a böngésző elviseli az általa nem ismert
kulcsokat** — egy payload az 1.0 szerződésen belül bővülhet anélkül, hogy bármelyik olvasója
eltörne. A kérés-séma viszont `additionalProperties: false`, tehát befelé az ellenkezője igaz:
amit ez a csomag a kérés-oldalon kitalálna, az szerződésváltozás, nem bővítés.

A 2.0 szerződésre lépés ennek a csomagnak **major** verziója lenne, mert a payload, amit ír, már nem
olyan, amit egy 1.0-t olvasó Aura renderelni tud.

A verziót ma semmi nem viszi át a dróton. Annak a gazdaalkalmazásnak, ami állítást akar tenni a
párosításról, ott az `AuraContract::VERSION` konstans; egyeztetés, fejléc és payload-mező
szándékosan nincs.

### A tag előtt

- **A szerződés rögzítve van.** A `tamas-labs/aura-schema` `dev-main`-en jött, amíg 2026-09-02-án
  meg nem született rá a `v1.0.0` tag; a megkötés azóta `^1.0`. Azért számított, mert a
  `composer.lock` nincs commitolva (ez a könyvtár-konvenció), tehát semmi nem rögzítette a felsőbb
  revíziót: egy séma-változás úgy tudta pirosra fordítani az itteni CI-t, hogy ebben a repóban
  egyetlen commit sem történt, egy régi CI-futás pedig nem volt újrajátszható. Egy VCS repository a
  git tageket semver-verziókká oldja fel, registry nélkül — ennyi kellett hozzá.
- **Ugyanabban a tagben hét szerződés-leírás is javult**, mindegyik az ellenkezőjét mondta annak,
  amit az Aura csinál: a `columnConfigs` mező szerint van kulcsolva, nem `key` szerint; a `key`-nek
  nincs alapértelmezése; a `true` / `false` / `eq` egzakt; az `empty` a `0`-t és a `false`-t is
  üresnek számolja; a `null` nem fedi az `undefined`-ot; a rendezett operátorok mindkét oldalon
  számot várnak. Validáció nem változott: a dokumentumok pontosan ugyanazt fogadják el és utasítják
  el, mint eddig. Két nyilvános repó két egymásnak ellentmondó igazsággal rosszabb, mint ha az egyik
  hallgat — és egy 1.0-s tag az a pillanat, amikor ez már nem javítható csendben.
- **A kompatibilitás-ellenőrzésnek még nincs mihez hasonlítania.** A `composer bc-check` az utolsó
  taggelt kiadáshoz méri az API-t, tag pedig nincs; a CI-job ezt kiírja és nem csinál semmit —
  ahelyett, hogy csendben zöldre menne —, az első tagnél viszont magától elindul. Lásd a
  [Fejlesztés](#fejlesztés) szakaszt.
- **A csomag nincs fenn Packagiston**, tehát a `composer require tamas-labs/laravel-aura:dev-main`
  egy `repositories` bejegyzés mellett az egyetlen telepítési mód *ehhez* a csomaghoz — a `dev-main`
  pedig azt jelenti: „amit a `main` ma mond", beleértve egy egy órája bekerült törő változást is.

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
docker compose run --rm php composer test:coverage   # a suite a lefedettségi küszöbbel
docker compose run --rm php composer bc-check     # API-törések az utolsó kiadáshoz képest
docker compose run --rm php vendor/bin/pest --filter "clamps paginate"
docker compose run --rm php vendor/bin/phpstan analyse
docker compose run --rm php vendor/bin/pint      # formázás alkalmazása
```

Egyetlen service, a `php`, `php:8.4-cli-alpine`-on. **Adatbázis-konténer nincs** — a suite
in-memory SQLite-on fut, így a `docker compose up`-ra soha nincs szükség.

A minőségi kapu: Laravel Pint (`laravel` preset), PHPStan/Larastan **max** szinten a `src/`, a
`tests/` és a `workbench/` felett, valamint Pest. A CI a mátrixot natívan futtatja
(PHP 8.3/8.4/8.5 × Laravel 12/13), és külön építi ezt az image-et, hogy a Dockerfile ne rothadjon el.

**A lefedettségnek van alsó küszöbe, és a küszöb maga a kapu.** A `composer test:coverage` a
suite-ot `--min=90`-nel futtatja, ami a szám alatt elbukik, nem pedig kiír egy riportot, amit senki
nem olvas. A küszöb nem a `phpunit.xml`-ben van, mert a PHPUnit-nak nincs saját fail-under
mechanizmusa — a Pest `--min` kapcsolója az. Az image **pcov**-ot visz, nem Xdebugot (itt egyedül a
sorszámlálás a cél, azt pedig a pcov töredék költséggel méri), és a `pcov.directory` ugyanarra a
`src/`-re szűkíti, amit a `phpunit.xml` is deklarál — így sem a `vendor/`, sem a tesztek nincsenek
műszerezve. A CI egyszer mér, a PHP 8.4-es ágon: a szám mátrixáganként nem tér el, a lefedettség-
mérő pedig az a része egy eszközláncnak, ami egy új PHP-kiadás után késve érkezik — a legfrissebb ág
szándékosan nem az, amelyiken mérünk.

A küszöb és a 100 közötti kilenc pont szinte teljes egészében a fluent setterek: egysoros
`return $this->set('align', $align)` metódusok a cella-típusokon és a trait-jeiken, amiket a
dokumentáció leír, de teszt sosem hív. Ezeken a metódusokon él a szerződés 73 property-neve, és egy
őr, ami azt bizonyítja, hogy minden setter azt a slotot írja, amit állít, többet ér, mint a
lefedettségi szám, amit elmozdítana.

**A publikus felület fel van jegyezve, nem emlékezetből él.** A `tests/Docs/public-surface.txt`
felsorolja az összes metódust, amit a verzió-ígéret fed — ugyanazt a halmazt, amit a
dokumentáció-őr is bejár —, a `tests/PublicSurfaceTest.php` pedig újraépíti, és bármilyen eltérésre
elbukik. Semmit nem tilt: csak kimondatja az irányt, mert egy megjelent metódus minor kiadás, egy
eltűnt viszont major — és egy dokumentált metódusra tett `@internal` a másodikból való. A feljegyzés
nélkül mindkettő hétköznapi commitnak látszik.

**És nem csak a nevek, az alak is ellenőrzött.** A `composer bc-check` a
[`roave/backward-compatibility-check`](https://github.com/Roave/BackwardCompatibilityCheck)-et
futtatja az utolsó taggelt minor verzió ellen. Az `autoload` útvonalakat a `composer.json`-ból
olvassa, tehát a `src/`-et látja, a teszteket és a workbenchet nem — és azt fogja meg, amit egy
névlista nem tud: egy hozzávett paramétert, egy szűkített típust, egy tágított visszatérési típust,
egy `final`-lá tett osztályt. Mindent kihagy, ami `@internal`, és jelenti, ha egy olyan szimbólum
*kap* `@internal`-t, amin eddig nem volt — pontosan az a határ, amit ez a szakasz húz, csak
egymástól függetlenül. A CI külön jobként futtatja minden push-ra és pull requestre, az első tagtől
kezdve.

Az ellenőrző **nem** függősége ennek a csomagnak, és nem is válhat azzá: PHP `~8.4.0 || ~8.5.0`-t
kér az itteni `^8.3` küszöb mellett, a Composer- és Symfony-megkötései pedig minden eséllyel
ütköznek a Laravelével. A `composer bc-check` a `build/bc-check`-be telepíti, külön, ahol ezek
közül semmi nem találkozik semmivel. Az első futás előtt két dolgot érdemes tudni: **commitolt**
revíziókat hasonlít össze — a repót egy ideiglenes könyvtárba klónozza, tehát a nem commitolt munka
láthatatlan neki —, és szüksége van a tagekre, ezért klónozza a CI-job a teljes history-t a sekély
alapértelmezett helyett.

**Közreműködés és bejelentés.** A [CONTRIBUTING.md](./CONTRIBUTING.md) abban a formában gyűjti
össze a fentieket, amire egy első változtatásnak szüksége van: a parancstábla, amit egy pull
requestnek vinnie kell, és a kiadás menete. A [SECURITY.md](./SECURITY.md) a sérülékenységekről
szól, és megnevezi azt a két szabályt, ami a határ (a `FieldPermissions` és az egyetlen nyers
SQL-kifejezés) — meg azt a kettőt, ami szándékosan **nem**: az `allowedWhen()` elrejt egy cellát,
de nem jogosít, és minden kiadott payload nyilvános adat.

**A kiadás egy tag, és a tag az utolsó lépés.** A Composer a git tagből telepít egy csomagot, tehát
nincs mit publikálni és nincs mit megszakítani: mire a
[`.github/workflows/release.yml`](./.github/workflows/release.yml) elindul, a kiadás már létezik.
Amit csinál: újrafuttatja a kaput a lefedettségi változatával, elutasít egy olyan taget, aminek
nincs saját dátumozott `CHANGELOG.md` szakasza — a manifest nem visz `version` kulcsot, tehát a
változásnapló az egyetlen feljegyzés, amihez egy tag mérhető —, és azt a szakaszt teszi ki
kiadási jegyzetnek.

**A dokumentáció a kapu része.** A `tests/DocsCoverageTest.php` végigmegy reflexióval a `src/`
minden osztályán, és elbukik, ha egy publikus metódust egyik teljes referencia sem említ — vagy ha
csak az egyik, mert az angol és a magyar szöveg így csúszik szét. Ami kimarad ebből a
számításból, az azért marad ki, mert `@internal` — a metóduson vagy az osztályon: ugyanaz a jelölés,
ami azt mondja, hogy a verzió-ígéret sem fedi. Egy új builder-metódus tehát vagy bekerül a
`README.en.md`-be **és** a `README.hu.md`-be ugyanabban a változtatásban, vagy a kódban ki van
mondva, hogy nem a hívóra tartozik.

<a id="a-demo-alkalmazas"></a>
### A demo-alkalmazás

A `workbench/` egy kis Laravel-alkalmazás — egy modell, egy tábla-osztály, egy route —, ami azért
van, hogy böngészőnek lehessen mutatni. Ez az az egy kérdés, amit a teszt-suite nem tud
megválaszolni: hogy az Aura saját preprocesszora tényleg linket csinál-e a `show_icon`-ból, és hogy
az eszkalált konfiguráció ugyanúgy renderel-e, mint a generált.

```bash
docker compose run --rm php composer build      # SQLite-fájl, migráció, seed
docker compose run --rm php composer serve      # build, majd kiszolgálás a http://localhost:8000-en
```

Utána a `v1.0/`-ban futó Aura dev-szervert a `http://localhost:8000/api/employees` címre kell
állítani. A CORS minden origin előtt nyitva van — ez az alkalmazás sosem hagy el egy laptopot —,
tehát a saját portján futó Vite dev-szerver rögtön hívhatja.

A `workbench/app/Tables/EmployeeTable.php` a demo-tábla, és szándékosan mindenből egy: enum-alapú
badge és szűrő, pénznemes oszlop feltételes színnel, folyamatjelző küszöbökkel, alkérdéssel
rendezett reláció, és egy action-oszlop, amiben egyszerre van jelen mind a három akció-mód — a
`show` konvenció-módban, az `edit` tooltip miatt eszkalálva, a `destroy` soronkénti jogosultsággal
kapuzva.

A `workbench/` alól semmi nem kerül a csomagba: a `.gitattributes` `export-ignore`-nak jelöli, és
nincs benne a csomag autoloadjában.

> A demo egy `.env`-et ír a Testbench `vendor/`-on belüli skeletonjába, egy skeleton-`.env`-et
> pedig minden későbbi teszt-futás beolvas. A `phpunit.xml` ezért rögzíti azt a cache-, queue- és
> session-drivert, amire a suite támaszkodik — így a demo futtatása nem tudja megváltoztatni, mit
> csinálnak a tesztek.

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
| **F5b** | Eszkaláció explicit `columnConfig`-ra, és route-építés | ✅ kész |
| **F5c** | Soronkénti jogosultság — a válasz-oldal | ✅ kész |
| **F6.1** | Demo workbench-app | ✅ kész |
| **F6.2** | `make:aura-table` | ✅ kész |
| **F6.3** | Dokumentáció-lefedettségi őr, és az `@internal` határ a verzió-ígéret alatt | ✅ kész |
| **F6.4** | Verziózás és a szerződés-verzióhoz kötés | ✅ kész |
| **F6.5** | A Packagist-kiadás | tervezett |

---

## Licenc

MIT — lásd a [LICENSE](./LICENSE) fájlt.

## Szerző

Tamas Balint
