# Változásnapló

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi.
A csomag a **szerződés 1.0** verzióját célozza (`TamasLabs\Aura\AuraContract::VERSION`); a
szerződés verziója külön él a csomagverziótól.

## [Unreleased]

### Hozzáadva

- **Action-oszlopok konvenció-módban (F5a).** `Action::create()` / `show()` / `edit()` /
  `destroy()` és `Column::actions('id', …)`: a négy Laravel-resource akció egyetlen hívásból.
    - **Csak header-mező keletkezik, `columnConfigs` bejegyzés nem.** A cella
      `{"content": null, "key": "id", "fields": ["show_icon", …]}`, a sorokba pedig semmi nem
      kerül. A route-bázis (`urlParameter`) kliensoldali config, amit a szerver nem lát — ezért a
      konvenció-mód ennyi, és ezért nem tud többet. A saját route és ikon az F5b.
    - **A kulcs a route-placeholder, nem név.** Az Aura a `{base}/{id}/edit` útvonalat a cella
      kulcsával építi, és soronként az ugyanilyen nevű item-mezőből tölti ki, tehát a kulcsnak az
      azonosítónak kell lennie. Másik oszlop nem foglalhatja el; a hibaüzenet a kijelölő oszlopra
      külön kitér, mert annak a kulcsa alapból szintén az elsődleges kulcs — és mert átkulcsolni
      biztonságos: az Aura a sor azonosítóját a `field`-ből olvassa (`resolve-row-id.ts`).
    - **Az action-oszlop az utolsó header-sorba kerül** (INV9), csoportos headerben is; a fölé
      tett placeholder ugyanazt a kulcsot viszi, tehát a generált útvonal bájtra ugyanaz.

### Javítva

- **Négy új build-idejű őr az action-konvenció köré.** Mind a négy némán rossz renderelést
  előzött volna meg:
    - **Ugyanaz az akció kétszer** (két oszlopban vagy egyben) `InvalidDefinition`. A
      `columnConfigs` mezőnév szerint kulcsolt, tehát a második előfordulás az *első* útvonalát
      örökölte volna, az első oszlop kulcsával felépítve.
    - **Action-mezőnév `Column::actions()`-ön kívül** (`Column::make('edit_icon')`,
      `Column::combined('x', ['name', 'destroy_link'])`) `InvalidDefinition`. Az Aura arra a
      cellára is útvonalat épített volna, bármi is a kulcsa, az oszlop értéke pedig sosem
      renderelődik. Mind a 12 kombinációt felismeri (négy prefix × `_icon` / `_link` / `_button`);
      a `status_icon`-féle általános prefix érintetlen marad, mert az nem akció.
    - **Rendezhető / kereshető / szűrhető action-oszlop** `InvalidDefinition`. Eddig a
      `multiFieldNeedsReference` üzenetét kapta, ami `->reference('…')`-t javasolt egy ikonoszlopra.
    - **Üres `fields` lista** `InvalidDefinition` (`Column::actions('id')` akció nélkül,
      `Column::combined('x', [])`). A séma `minItems: 1`-et ír elő, és az Aura csak akkor tekint
      egy cellát oszlopnak, ha megnevez valamit.

### Biztonság

- **A kérés minden listája és minden sztringje korlátozva van.** Eddig csak a `paginate` volt az;
  mérve, 5 000 `sortable` bejegyzés **125 kB SQL**-t épített, a `globalSearch` 200 000 karaktert
  fogadott el, a `selected` 50 000 azonosítót. Mindhárom 422 lett.
    - **A három mezőlistát a whitelist korlátozza, configkulcs nélkül.** Az Aura mezőnként egyetlen
      bejegyzést tart (`use-sorting.ts:23`, `use-searching.ts:45`, `use-filtering.ts:41`), tehát a
      `FieldPermissions` már a pontos plafon: három rendezhető oszlop három rendezést enged. A
      hosszellenőrzés **azelőtt** fut, hogy bármi végigmenne a sorokon.
    - **Ugyanaz a mező kétszer egy listában 422.** Az Aura ilyet nem küld, és két rendezés ugyanarra
      a mezőre `ORDER BY x ASC, x DESC`-et jelentene.
    - **`RequestLimits`** value object és **`aura.limits`** configblokk a maradéknak:
      `limits.selected` (1000), `limits.values` (200), `limits.term` (255). A `selected` az egyetlen
      lista, amire a szerver nem tud plafont származtatni — a kijelölés túléli a lapozást. Hiányzó
      vagy nem pozitív configérték a csomagolt alapértékre esik vissza, nem a „nincs korlát"-ra.

### Javítva

- **A pontozott mező metódusa hívás előtt vizsgálatra kerül** (`Support\Relations`, `@internal`).
  A `method_exists()` volt az egyetlen őr két helyen (`Inference::resolve()`,
  `AuraQuery::toOneSubquery()`), így a `Column::make('delete.x')` a header felépítése közben
  **meghívta a modell `delete()`-jét**, és csak utána derült ki, hogy nem reláció jött vissza.
    - A Laravel `Model::isRelation()`-je erre nem jó: az `method_exists() || relationResolver()`,
      a visszatérési típusról semmit nem mond. A `delete()`, `save()`, `push()`, `refresh()` és
      további mintegy száz `Model`-metódus **típusjelölés nélküli**, tehát a visszatérési típus
      vizsgálata sem fogná meg őket — a **deklaráló osztály** az egyetlen elválasztó.
    - Ezért a szabály: publikus, nem statikus, argumentum nélkül hívható, nem `Illuminate\`
      deklarálja, és ha van visszatérési típusa, az `Relation`. A csak `@return` docblockot viselő
      reláció **továbbra is működik** — a szigorúbb szabály minden régebbi modellt eltörne.
    - Új `UnsupportedRelation::notARelation()`: eddig a nem-reláció is „csak egy relációs szint
      támogatott" üzenetet kapott, ami válasz egy fel nem tett kérdésre.
- **A header-séma negyedik strukturális szabálya is ellenőrizve.** A `field` és a `fields`
  kölcsönösen kizárja egymást (`not: {required: [field, fields]}`), és a `Column::assertValid()`
  eddig a négy szabályból hármat nézett. A `set('fields', …)` escape-hatchen át kiadható volt egy
  olyan header-cella, ami a válaszsémán elbukik — és az Aura saját validációján az egész táblát
  viszi, nem az egy oszlopot. Mostantól `InvalidDefinition`.
- **Feltételes cella-szabály többmezős oszlopon `->on()` nélkül build-időben dob.** A szabályok
  kulcsa ilyenkor az oszlopkulcs lett (`full_name`), ami nem érték a sorban: az Aura `undefined`-ot
  olvas, minden feltétel hamis, és a cella soha nem stílusozódik — jelzés nélkül. A csomag a
  `columnConfigs` analóg esetére eddig is dobott (`configNeedsMatchingKey`), erre nem. A
  feltétel nélküli szabályhalmaz továbbra is rendben van: az `key`-t sem emittál.

### Módosítva

- **Az `AuraTable` szétbontva** (692 → 222 sor), az F5 action-rétege előtt, amely ugyanide írna.
  Négy új `@internal` osztály a `Table\` névtérben, viselkedésváltozás nélkül: `DefinitionBuilder`
  (a definíció, a whitelist és a numerikus mezők egy menetben), `CellConfigs` (a `columnConfigs`
  térkép), `ColumnPermissions` (a whitelist, kiolvasva a cellákból) és `ResolvedColumn` (az oszlop
  és a belőle lett header-cella párja — eddig nyolc helyen destrukturált tuple volt). Az
  `AuraTable` maga már csak a tábláról felel: oszlopok, modell, beállítások, cache, `respond()`.
    - A header `searchableItems`-e mostantól **magáról a whitelistről** olvasódik le, nem egy
      második, ugyanúgy felépített listáról — a „egy forrás" tulajdonság strukturális lett.
    - Bizonyítva: a definíció, a whitelist, a `numericFields` és a hibaüzenetek bitre azonosak a
      refaktor előtti kimenettel, több táblán (csoportos header, footer, mind a kilenc cellatípus,
      feltételek, cellaszabályok) és négy hibaágon.

- **`AuraRequest::fromHttp()` / `fromArray()` harmadik paramétere `?int $maxPaginate` helyett
  `?RequestLimits $limits`.** A `paginate` plafonja változatlanul az `aura.pagination.max`, csak már
  a többi korláttal együtt utazik, és egyetlen limit felülírása nem dobja el a többit.

### Hozzáadva

- **Docker-alapú fejlesztői környezet** (F0): egyetlen `php` service `php:8.4-cli-alpine`-on,
  `intl` + `zip` fordítva, `mbstring` / `pdo_sqlite` build-time ellenőrizve. Adatbázis-konténer
  nincs — a suite in-memory SQLite-on fut.
- **`AuraServiceProvider`** és a publikálható `config/aura.php` (`aura-config` tag). Egyelőre
  egyetlen kulccsal: a `pagination.max` a kliensről érkező `paginate` felső korlátja, nem
  javaslat. Alapértelmezett oldalméret szándékosan nincs — a `paginate` a szerződésben kötelező.
- **`AuraContract::VERSION`** — a célzott szerződésverzió.
- **Contract-teszt harness** (F1): `opis/json-schema` a `tamas-labs/aura-schema` csomag
  dokumentumaira kötve, hálózati hívás nélkül; `assertMatchesAuraResponseSchema()` és
  `assertMatchesAuraRequestSchema()` teszt-helperek; nevesített regressziós teszt arra, hogy a
  `simplePaginate()` `meta`-ja nem elégíti ki a szerződést.
- **Query-oldal** (F2): `AuraRequest` (a kérés beolvasása, validálása, `paginate` vágása),
  `FieldPermissions` (mezők whitelistje), `AuraQuery` (rendezés, keresés, szűrés, globális
  keresés, relációk), `AuraPayload` (`items` / `meta` / `links`).
- **Minőségi kapu**: Pint (`laravel` preset), PHPStan/Larastan level `max`, Pest.
  `composer quality` futtatja mindhármat.
- **CI**: PHP 8.3/8.4 × Laravel 12/13 mátrix natívan, plusz egy külön job, amely a fejlesztői
  image-et építi és abban futtatja a kaput.
- **Definíciós mag** (F3): `AuraTable` (a tábla mint osztály, `respond()`-dal), `Column` fluent
  builder, `ColumnGroup` (kétsoros header), `Footer`, `TableSettings`, `Inference` (oszlop-defaultok
  a modell castjaiból), `Preset` + `Money` / `Timestamp` / `Options`, `AuraOption` enum-interfész,
  `TableBlueprint` (a definíció és a whitelist együtt, cache-elhetően).
- **A `header` és a whitelist egy forrásból.** A `FieldPermissions` az oszlopokból származik, abból
  a cella-tömbből, amit a böngésző is megkap; az Aura `reference || field || key` sorrendjét
  követve. Kézzel írt header és kézzel karbantartott whitelist nem kell többé.
- **Build-idejű őrök** (`InvalidDefinition`): duplikált oszlopkulcs, `fields` oszlop `reference`
  nélkül, `fields` oszlop a globális keresésben, egyoszlopos csoport, `colspan` nélküli mezőtlen
  cella, oszlop nélküli tábla.
- **README** három fájlban, a workspace mintája szerint: `README.md` (rövid, telepítés és
  alapok), `README.en.md` és `README.hu.md` (teljes referencia angolul és magyarul).
- **Cella-builderek** (F4): a `body.columnConfigs` mind a kilenc típusa — `Text` (a szerződés
  `static`-ja; a `Static` foglalt szó PHP-ban), `Reference`, `Badge`, `Link`, `Button`, `Icon`,
  `Modal`, `Progress`, `Custom` —, közös trait-ekre bontva (`HasFormatting`, `HasTypography`,
  `HasMapping`, `HasRoute`). Oszlophoz `->as()`-szal kapcsolódnak, többmezős oszlophoz
  `->configure()`-ral.
- **Feltételes konfiguráció**: `Condition` 19 operátorral (a szerződés 24 kulcsából 5 tiszta
  alias), `when()` / `otherwise()` / `on()`, és ugyanez a felület a `CellRules`-on a `<td>`-re,
  illetve `AuraTable::rowRules()`-ként a `<tr>`-re.
- **`AuraVariant` és `AuraIcon`** enum-interfészek az `AuraOption` mellé, külön és opcionálisan.
  A `Badge::fromEnum()` mind a hármat kiolvassa, és egy interfészt sem implementáló enumból is
  használható badge-térképet ad.
- **A header formázása leöröklődik a cella-configba.** Renderelő mellett az Aura a configot
  önmagában adja át, tehát az oszlop `currency()` / `date()` / `slice()` beállítása egyébként
  némán elveszne. Az explicit hívás a configon továbbra is nyer.
- **`datetime()`, `time()` és `raw()` a cella-configokon is**, az oszlop mintájára. A config-sémák
  nem sorolják fel őket, a renderelő viszont olvassa mindhármat (`buildFormatConfig.ts`), és eddig
  csak leöröklődni tudtak — beállítani vagy felülírni nem.

### Javítva

- **A `Modal` beágyazott triggere feltételes ágon belül némán eltűnt.** Egy ág nem a `resolve()`-on
  megy keresztül, hanem a `settings()`-en, tehát a `when(..., fn ($m) => $m->content(...))` csak a
  feltételt adta ki, a triggert nem: az ág illeszkedett, és semmit nem változtatott. A típusok
  mostantól az ágakon is előkészülnek.
- **A `resolve()` nem írja át a buildert, amin meghívták.** A `Modal` a beágyazott triggert
  korábban `$this`-be írta, mielőtt a másolat elkészült volna; a `prepare()` horog a másolaton fut.
- **A route-os `Icon` nem lett link.** A `renderIconNode` csak akkor csomagolja `<a>`-ba a glifát,
  ha a `route` *és* a `key` is megvan (a `link`, a `button` és a `modal` beéri a route-tal), a
  csomag viszont csak mappinghez adott ki `key`-t. Mostantól route esetén is kiadja, a route első
  placeholderéről elnevezve — placeholder nélkül az oszlop kulcsáról, ahogy az Aura preprocesszora.
- **Feltételes konfigurációnál a route kulcsa az ágba kerül.** A gyökér `key`-t az Aura eltávolítja
  (`stripLogicProps`) — ott a feltételek mezőjét jelöli —, tehát egy soronkénti feltétel a linkelő
  ikon fölött szabályosan elrejtette a cellát, majd az engedélyezett sorokat link nélkül
  renderelte. Minden levél-ág a saját kulcsát kapja, annak az ágnak a beállításai szerint.

### Biztonság

- A kérésben érkező `field` értékek kizárólag a `FieldPermissions` whitelistjén keresztül jutnak
  a lekérdezésbe. Üres lista = semmi nem engedélyezett; „engedj mindent" kapcsoló nincs, az
  egyezés pontos (egy engedélyezett név prefixe nem engedélyezett).
- A `paginate` a `aura.pagination.max` értékre vágódik.
- A `LIKE` keresés escape-eli a `%` és `_` karaktereket (`ESCAPE '!'`), így egy `%` a
  keresőmezőben nem alakítja teljes táblaolvasássá a keresést.
- A `selected[]` nem kerül a lekérdezésbe.
- A cache-elt definíció nem tud jogosultságot tágítani: egy nem tömb alakú bejegyzés újraépítést
  vált ki, a whitelist visszaolvasásakor pedig minden nem-string kiesik.
- Minden adatoszlop a `header.rows` **utolsó** sorába kerül. Az Aura csak onnan veszi az
  oszlopokat, tehát egy korábbi sorban ragadt `selectable` cella némán kikapcsolná a
  sor-kijelölést (INV9).
- **`if` sosem kerül ki `key` nélkül.** Az Aura ilyenkor átugorja a feltételeket és az
  alapkonfigurációt érvényesíti — fail-open, és rossz irány, ha épp a feltétel dönt arról, mi
  látszik. Ha nincs honnan venni a mezőt, a definíció build-időben dob (INV6, INV12).
- **A feltételek 5 szintnél mélyebb egymásba ágyazása build-időben dob.** Az Aura `MAX_RECURSION_DEPTH`
  fölött némán csonkolja a konfigurációt (INV5).
- **A route csak relatív lehet.** Az Aura minden pontot perjelre cserél, tehát a `route()`
  abszolút URL-je `/https://app/test/…`-ként renderelődne; abszolút URL és az Aura regexén kívüli
  `{placeholder}` egyaránt build-időben dob (INV2).
- **A numerikusan összehasonlított mezők számként mennek ki.** Az Aura `gt`/`lt`/`between`
  operátorai `number`-t követelnek, a Laravel `decimal` cast viszont stringet ad, tehát a feltétel
  némán soha nem illeszkedne (INV11).
- **A renderelők nem tágítják a whitelistet.** A cella-config azt mondja meg, hogy néz ki egy
  cella, sosem azt, hogy mit fogad el a szerver.
- **A szerkezeti kulcsokat a `set()` utasítja vissza, nem a `merge()`.** Oda fut be mindkét út: a
  `merge()` a `set()`-nek delegál, és a `set()` önmagában is publikus. A kiadott konfiguráció
  `type + settings + conditionals` sorrendben áll össze, tehát egy kézzel írt `key` legyőzte volna
  azt, amivel a feltételek kimennek — vissza az INV6/INV12 fail-open ágára.
- **Két oszlop nem rendereheti ugyanazt a mezőt.** A `columnConfigs` egyetlen, mező szerint
  kulcsolt map; a második bejegyzés nem az első mellé kerül, hanem a helyére, és a vesztes oszlop
  szó nélkül a győztes konfigurációját rendereli (INV10).

### Megjegyzés

A szerződés schema-dokumentumai **nem ebben a repóban élnek**, hanem a
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) csomagban, amely
`require-dev` — a csomag fogyasztóihoz nem jut el. Mivel az `aura-schema` szándékosan nincs
Packagiston, a `composer.json` egy `repositories` VCS-bejegyzésen keresztül, `dev-main`-ként
húzza be.
