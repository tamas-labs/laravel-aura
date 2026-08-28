# laravel-aura — teljes referencia

Laravel-csomag, amely azt a JSON-szerződést állítja elő, amit az
[Aura](https://github.com/tamas-labs/aura) Vue 3 adattábla fogyaszt.

> 🇬🇧 **[In English →](./README.en.md)** · 📄 **[Rövid README →](./README.md)**

Az Aura tábláját teljes egészében JSON vezérli: a végpont mondja meg, milyen oszlopok vannak,
hogyan renderel egy cella, és mely sorok látszanak. Ez a csomag ennek a beszélgetésnek a
szerveroldali fele — beolvassa az Aura által küldött kérést, Eloquent-lekérdezéssé alakítja, és a
szerződés által előírt alakban adja vissza a lapozott adatot.

---

## Tartalom

- [Állapot](#állapot)
- [Követelmények](#követelmények)
- [Telepítés](#telepítés)
- [Konfiguráció](#konfiguráció)
- [A három lépés](#a-három-lépés)
- [FieldPermissions — a whitelist](#fieldpermissions--a-whitelist)
- [AuraRequest — a kérés beolvasása](#aurarequest--a-kérés-beolvasása)
- [AuraQuery — a lekérdezés építése](#auraquery--a-lekérdezés-építése)
- [AuraPayload — a válasz adatfele](#aurapayload--a-válasz-adatfele)
- [A válasz másik fele: a `header`](#a-válasz-másik-fele-a-header)
- [Kivételek](#kivételek)
- [Egy teljes controller](#egy-teljes-controller)
- [A dróton lévő szerződés](#a-dróton-lévő-szerződés)
- [A saját payloadod validálása](#a-saját-payloadod-validálása)
- [Fejlesztés](#fejlesztés)
- [Ütemterv](#ütemterv)
- [Licenc](#licenc)

---

## Állapot

A csomag a tervének **F2** fázisánál tart: a query-oldal kész és tesztelt, a csomag innentől
használható. Konkrétan:

| Ma működik | Még nincs kész |
| --- | --- |
| Az Aura-kérés beolvasása és validálása | `AuraTable` / `Column` definíciós objektumok (F3) |
| Rendezés, keresés, szűrés, globális keresés | Oszlop-következtetés a modell castjaiból (F3) |
| Relációk mind a négy műveletben | Cella-builderek — badge, link, progress, … (F4) |
| `items` / `meta` / `links` egy paginátorból | Action-oszlopok és soronkénti jogosultság (F5) |
| Szerződés-validáció a tesztekben | Generált `header` — egyelőre kézzel írod (F3) |

Amíg az F3 el nem készül, a válasz leíró fele (`header`, opcionálisan `body` / `footer`) egy
kézzel írt tömb, amit összefésülsz a payloaddal. Ez elég egy működő táblához — lásd
[Egy teljes controller](#egy-teljes-controller).

A csomag **nincs kiadva**: nincs tag, nincs fenn Packagiston. A repóból telepítsd.

---

## Követelmények

- **PHP** 8.3 vagy 8.4
- **Laravel** 12 vagy 13 (`illuminate/support`, `illuminate/contracts`)
- Bármilyen Eloquent által támogatott adatbázis-driver; a teszt-suite SQLite-on fut, a `LIKE`
  escape-elés úgy van megírva, hogy MySQL/MariaDB-n, PostgreSQL-en és SQLite-on egyformán
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

Az `AuraServiceProvider`-t a package discovery regisztrálja — a `bootstrap/providers.php`-hoz
nem kell hozzányúlni.

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

Alapértelmezett oldalméret szándékosan **nincs.** A kérés-szerződésben a `paginate` kötelező; ha
a hiányzót defaultolnánk, egy hibás kliensből csendben rövid oldal lenne 422 helyett.

A plafon hívásonként felülírható, ami egy export-végponton jól jön:

```php
AuraRequest::fromHttp($request, $fields, maxPaginate: 5000);
```

---

## A három lépés

```php
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

// 1. a kérés beolvasása — validálva és whitelistelve
$aura = AuraRequest::fromHttp($request, new FieldPermissions(
    sortable:     ['last_name', 'created_at', 'company.name'],
    searchable:   ['first_name', 'last_name', 'balance'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name', 'company.name'],
));

// 2. alkalmazás egy lekérdezésre
$paginator = AuraQuery::paginate(User::query(), $aura);

// 3. a válasz alakra hozása
$payload = AuraPayload::fromPaginator($paginator);
```

A három lépés független egymástól. Az `AuraQuery::apply()` a megszorított buildert adja vissza, ha
magad akarsz lapozni; az `AuraPayload::fromPaginator()` bármilyen length-aware paginátort elfogad,
akkor is, ha nem az `AuraQuery` állította elő.

---

## FieldPermissions — a whitelist

Az Aura-kérésben minden `field` a böngészőből érkezik. Ha ilyet adunk közvetlenül az `orderBy()`-nak,
azzal kiszivárogtatjuk olyan oszlopok létezését — a rendezésen keresztül pedig a sorrendjét is —,
amiket a tábla soha nem akart megmutatni. A `FieldPermissions` a whitelist, és **kizárólag** ezen
keresztül jut kliens-mező a lekérdezésbe.

```php
new FieldPermissions(
    sortable:     ['last_name', 'created_at', 'company.name'],
    searchable:   ['first_name', 'last_name'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name'],
);
```

| Argumentum | Mit szabályoz |
| --- | --- |
| `sortable` | mely mezőket nevezheti meg a `sortable[].field` |
| `searchable` | mely mezőket nevezheti meg a `searchable[].field` |
| `filterable` | mely mezőket nevezheti meg a `filterable[].field` |
| `globalSearch` | mely mezőkre terjed ki a toolbar globális keresője |

Négy tulajdonság érvényes, és mindegyiket teszt rögzíti:

- **A listák külön élnek.** Attól, hogy egy mező kereshető, még nem rendezhető.
- **Az üres lista semmit nem enged.** „Engedj mindent" kapcsoló nincs, és a
  `FieldPermissions::none()` — semmi nem engedélyezett — a biztonságos kiindulópont.
- **Az egyezés pontos.** A `last` nem engedélyezett a `last_name` miatt; egy engedélyezett név
  prefixe ugyanúgy elutasításra kerül, mint bármelyik ismeretlen mező.
- **Az elutasított mező 422**, nem csendben eldobott paraméter. A hibaüzenet megnevezi az
  elutasított mezőt, de soha nem sorolja fel az engedélyezetteket — egy hibaválasz nem arra való,
  hogy felsoroljuk benne a sémát.

A `globalSearch` más természetű, mint a másik három: nem egy kérésbeli értéket ellenőriz, hanem
*ő maga* a mezőlista, amin a keresés fut. A kliens csak egy keresőkifejezést küld.

A pontos nevek (`company.name`) megengedettek, és a reláción keresztül oldódnak fel — lásd
[Relációk](#relációk).

---

## AuraRequest — a kérés beolvasása

```php
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?int $maxPaginate = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?int $maxPaginate = null): self
```

A `fromHttp` onnan olvassa a payloadot, ahová a szerződés teszi: `POST` / `PUT` / `PATCH` esetén a
JSON-törzsből, `GET` / `DELETE` esetén a query-paraméterekből. A `fromArray` egy már dekódolt
tömböt vesz át — sorbaállított jobhoz vagy teszthez.

Mindkettő `Illuminate\Validation\ValidationException`-t dob mindenre, amit a szerződés nem enged,
amit a Laravel **422**-ként renderel — soha nem 500, és soha nem csendben eldobott paraméter.

### A kérés alakja

| Kulcs | Típus | Kötelező | Megjegyzés |
| --- | --- | --- | --- |
| `page` | egész ≥ 1 | **igen** | |
| `paginate` | egész ≥ 1 | **igen** | a `pagination.max`-ra vágódik |
| `sortable[]` | `{field, direction}` | nem | a `direction` `asc` vagy `desc` |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | nem | szöveges vagy tartománykeresés |
| `filterable[]` | `{field, values[]}` | nem | a `values` lehet üres, de jelen kell lennie |
| `globalSearch` | string | nem | |
| `selected[]` | string / szám | nem | sor-azonosítók a köteges műveletekhez |

Az ismeretlen property elutasításra kerül — a legfelső szinten *és* a beágyazott objektumokban is,
mert a séma mindkettőn `additionalProperties: false`-t ír elő. A beágyazott ellenőrzés
szándékosan a nyers payloadon fut: a Laravel validátora eldob minden kulcsot, amihez nincs
szabálya, így mire a validáció lefut, egy ismeretlen beágyazott kulcs már eltűnt volna, és soha
nem lehetne jelenteni.

### A feldolgozott eredmény

```php
$aura->page;         // int
$aura->paginate;     // int, már vágva
$aura->sortable;     // list<Sort>    — field, direction
$aura->searchable;   // list<Search>  — field, term, exact, min, max, isRange()
$aura->filterable;   // list<Filter>  — field, values
$aura->globalSearch; // ?string
$aura->selected;     // list<string|int|float>
$aura->fields;       // a FieldPermissions, amivel készült
```

### A `selected` nem kerül a lekérdezésbe

A `selected[]` a felhasználó által kipipált sorokat nevezi meg, a hívó saját köteges műveleteihez.
A DTO-n elérhető, de a lekérdezés közelébe sem megy — teszt rögzíti, hogy a generált SQL
bájtazonos vele és nélküle. A lapot a kijelölésre szűkíteni két okból is hibás lenne: a kijelölés
átnyúlhat több oldalra, és a felhasználó nem ezt kérte.

```php
User::whereKey($aura->selected)->each->archive();
```

### Az `exact` query stringben

A query string minden értéket szövegként hoz, az `exact=true`-t is, a Laravel `boolean` szabálya
viszont a `"true"` *szót* nem fogadja el. A query-paraméteres ág ezért ezt az egy értéket
dekódolja a validálás előtt. A JSON-törzses ág nem: ott a `"true"` string valóban
szerződésszegés, és az is marad.

---

## AuraQuery — a lekérdezés építése

```php
AuraQuery::apply(Builder $query, AuraRequest $request): Builder
AuraQuery::paginate(Builder $query, AuraRequest $request): LengthAwarePaginator
```

Az `apply()` a kereséseket, szűréseket, a globális keresést és a rendezéseket adja hozzá az átadott
builderhez, és visszaadja azt. A `paginate()` ugyanezt teszi, majd lapoz a kérés `page` és
`paginate` értékével.

Ebben az osztályban semmi nem ellenőrzi újra a jogosultságot — minden ideérkező mező már átment a
whitelisten —, de semmi nem is fogad el mezőt máshonnan.

### Keresés

Egy `searchable[]` bejegyzés vagy **szöveges keresés**, vagy **tartománykeresés**:

| Amit küld | SQL |
| --- | --- |
| `{field, term}` | `LIKE '%term%'` — részszöveg, escape-elt wildcardokkal |
| `{field, term, exact: true}` | `= 'term'` |
| `{field, min, max}` | `>= min` és `<= max` |
| `{field, min}` | `>= min` — nyitott felső vég |
| `{field, max}` | `<= max` — nyitott alsó vég |

A tartomány bármelyik végén a `null` azt jelenti: *korlátlan*, nem azt, hogy *illeszkedjen a
null-ra*. Az üres vagy hiányzó keresőkifejezés semmilyen megszorítást nem ad hozzá — nem pedig
mindenre illeszkedik.

**A keresőkifejezésben lévő wildcardok escape-elve vannak.** Nélküle egy `%` keresőkifejezés
minden sorra illeszkedik — ez nem injekció (a kifejezés továbbra is bindingként utazik), hanem egy
keresőmező, ami csendben teljes táblaolvasássá válik, és egy `100%` keresés, ami az `1000`-et is
visszaadja.

Az escape-karakter `!`, nem backslash. A MySQL és az SQLite nem ért egyet abban, hogy a
stringliterálon belüli backslash maga is escape-e, így az `ESCAPE '\'` a kettőn mást jelent; a `!`
minden dialektusban egy karakter. Az `AuraQuery::likeExpression()` a csomag egyetlen raw SQL-je, és
viszi magával az indoklást — az oszlop a whitelistről jön, majd a grammar wrapeli, a
keresőkifejezés pedig bindingként utazik, soha nincs beinterpolálva.

### Szűrés

A `{field, values}` arra a sorra illeszkedik, amelynek az oszlopa a felsorolt értékek bármelyikével
egyenlő.

- **A `null` az értékek között** `OR column IS NULL`-t ad hozzá. Az `IN (…)` soha nem illeszkedik
  `NULL`-ra, így egy kiválasztott „nincs érték" különben csendben pont azokat a sorokat dobná el,
  amiket a felhasználó kért.
- **Az üres `values` tömb semmire nem illeszkedik**, mert az üres kijelölés ezt jelenti. Ezért a
  validációs szabály `present` és nem `required` — a Laravel az üres tömböt hiányzónak veszi, a
  szerződés viszont megköveteli a kulcsot, és megengedi az üres kijelölést.

### Globális keresés

Egy keresőkifejezés, `OR`-ral összefűzve a `FieldPermissions::$globalSearch` minden mezőjén,
ugyanazzal az escape-elt `LIKE`-kal, mint a szöveges keresés. Az OR-ok saját beágyazott `where`-be
vannak csomagolva, hogy ne tudjanak kiszabadulni és kitágítani a körülöttük lévő oszlop-szintű
megszorításokat — teszt rögzíti, hogy egy globális keresés nem tud feltámasztani egy szűrő által
kizárt sort.

### Rendezés

A rendezések abban a sorrendben érvényesülnek, ahogy a kliens küldte őket, így a második
rendezési kulcs az elsőben lévő holtversenyt bontja.

### Relációk

A pontos mező az általa megnevezett reláción keresztül oldódik fel:

| Művelet | Mechanizmus | Mélység | Relációtípusok |
| --- | --- | --- | --- |
| Keresés | `whereHas` | tetszőleges | bármelyik |
| Szűrés | `whereHas` | tetszőleges | bármelyik |
| Globális keresés | `orWhereHas` | tetszőleges | bármelyik |
| **Rendezés** | **korrelált alkérdés** | **egy szint** | **`BelongsTo`, `HasOne`** |

A rendezés a korlátozott, szándékosan. A join olvashatóbb lenne, de to-many reláción
megsokszorozza a sorokat — ez pedig elrontja a `meta.total`-t és minden oldal tartalmát, vagyis
magát a lapozást töri el. A korrelált alkérdésnek nincs ilyen hatása, és nem kell hozzá a
select-listát bűvészkedni; az ára, hogy csak to-one relációra, egy szint mélységben válaszol.

Bármi más `UnsupportedRelation`-t dob fejlesztéskor, konkrét javaslattal:

```
Cannot sort by "posts.title": posts.title is a HasMany, and a to-many relation has no single
value to order on. Expose the value as a real column (a counter cache or a computed column)
and sort on that.
```

---

## AuraPayload — a válasz adatfele

```php
AuraPayload::fromPaginator(Paginator|CursorPaginator $paginator): self
$payload->toArray(): array   // ['items' => …, 'meta' => …, 'links' => …]
```

Az `items` a sorok nyers adattá lapítva (amit lehet, `Arrayable`-ként `toArray()`-el), majd
`array_values`-szal újraindexelve — egy lyukas kulcsú paginátor-oldal JSON-objektummá
szerializálódna tömb helyett, a szerződés viszont tömbként tipizálja az `items`-et.

A `meta` a `current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total` kulcsokat viszi;
a `links` a `first`, `last`, `prev`, `next` kulcsokat.

**Csak a `LengthAwarePaginator` működik.** Az Aura szerződése megköveteli a `meta.last_page`-et és
a `meta.total`-t, ezeket viszont sem a `simplePaginate()`, sem a `cursorPaginate()` nem ismeri,
mert egyik sem futtatja le a count-lekérdezést. Ilyet átadva `UnsupportedPaginator` dobódik, nem
pedig egy olyan payload keletkezik, amit a tábla nem tud beolvasni:

```
Aura needs a LengthAwarePaginator, got Illuminate\Pagination\Paginator. The response contract
requires meta.last_page and meta.total, which simplePaginate() and cursorPaginate() cannot
supply — use paginate().
```

---

## A válasz másik fele: a `header`

Az `AuraPayload` az adatfél. Önmagában **nem érvényes válasz** — a szerződés megköveteli a
`header`-t, ami az oszlopokat írja le. Amíg az F3 nem generálja, írd meg kézzel, és fésüld össze:

```php
$header = [
    'rows' => [[
        'cells' => [
            ['content' => '#',      'key' => 'id',        'field' => 'id', 'sortable' => true],
            ['content' => 'Név',    'key' => 'last_name', 'field' => 'last_name',
             'sortable' => true, 'searchable' => true],
            ['content' => 'Státusz', 'key' => 'status',   'field' => 'status',
             'filterable' => true, 'elements' => ['active' => 'Aktív', 'suspended' => 'Felfüggesztett']],
        ],
    ]],
];

return response()->json(['header' => $header] + $payload->toArray());
```

Két dolgot kell kézzel szinkronban tartani, amíg az F3 meg nem érkezik:

- **A headernek és a whitelistnek egyeznie kell.** Egy oszlop, ami a headerben `sortable`, de
  hiányzik a `FieldPermissions::$sortable`-ből, olyan táblát ad, ahol a rendezőnyilak 422-t
  hoznak.
- **A `header.settings.searchableItems`** azokat a mezőket sorolja, amikre a globális keresőmező
  kiterjed a kliensen; ennek egyeznie kell a `FieldPermissions::$globalSearch`-csel.

A teljes header-, body- és footer-séma a
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) csomagban él, teljes
kidolgozott példával a `schema/examples/response.json` alatt.

---

## Kivételek

Minden kivétel, amit ez a csomag a saját nevében dob, implementálja a
`TamasLabs\Aura\Exceptions\AuraException`-t, így a host-alkalmazás egyben elkaphatja mindet:

| Kivétel | Mikor dobódik |
| --- | --- |
| `UnsupportedRelation` | to-many reláción vagy beágyazott relációs úton keresztüli rendezés |
| `UnsupportedPaginator` | a paginátor nem tudja a `last_page` / `total` értéket |

Mindegyik a **tábla definíciójában** lévő hibát jelent, nem a kliens inputjában lévőt — a hibás
input jóval előbb elbukik a validáción és 422 lesz belőle. Ezért futásidejű kivételek, és ezért
nem kérésenként elkapandók.

---

## Egy teljes controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

final class UserTableController
{
    public function __invoke(Request $request)
    {
        $aura = AuraRequest::fromHttp($request, new FieldPermissions(
            sortable:     ['last_name', 'created_at', 'company.name'],
            searchable:   ['first_name', 'last_name', 'balance'],
            filterable:   ['status'],
            globalSearch: ['first_name', 'last_name', 'company.name'],
        ));

        $payload = AuraPayload::fromPaginator(
            AuraQuery::paginate(User::query()->with('company'), $aura),
        );

        return response()->json(['header' => $this->header()] + $payload->toArray());
    }

    private function header(): array
    {
        return ['rows' => [['cells' => [
            ['content' => 'Név',      'key' => 'last_name',  'field' => 'last_name',  'sortable' => true, 'searchable' => true],
            ['content' => 'Cég',      'key' => 'company',    'field' => 'company.name', 'sortable' => true],
            ['content' => 'Státusz',  'key' => 'status',     'field' => 'status',     'filterable' => true],
            ['content' => 'Létrehozva', 'key' => 'created_at', 'field' => 'created_at', 'sortable' => true, 'datetime' => true],
        ]]]];
    }
}
```

Az Aura alapértelmezésben `POST`-tal kér, ezért így vedd fel a route-ot (vagy állítsd át a
`requestMethod`-ot a kliensen):

```php
Route::post('/users', UserTableController::class);
```

A `with('company')` nem a reláción keresztüli rendezés miatt kell (azt az alkérdés intézi), hanem
azért, hogy ne legyen N+1, amikor a sorok kiírják a cégnevet.

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
| **F3** | Definíciós mag: `AuraTable`, `Column`, következtetés a castokból, cache-elhető `header` | tervezett |
| **F4** | Cella-builderek: badge, link, button, icon, modal, progress, feltételes konfiguráció | tervezett |
| **F5** | Action-réteg (`edit` / `show` / `destroy`) és soronkénti jogosultság | tervezett |
| **F6** | Demo workbench-app, `make:aura-table`, kiadás | tervezett |

Az F3 az, ami megszünteti a kézzel írt headert: egy oszlop-definíció egy sorrá válik, a modell
castjaiból következtetve, a válasz kérésfüggetlen fele pedig cache-elhetővé.

---

## Licenc

MIT

## Szerző

Tamas Balint
