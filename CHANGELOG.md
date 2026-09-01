# Változásnapló

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi.
A csomag a **szerződés 1.0** verzióját célozza (`TamasLabs\Aura\AuraContract::VERSION`); a
szerződés verziója külön él a csomagverziótól.

## [Unreleased]

### Hozzáadva

- **Dokumentáció-lefedettségi őr (F6.3).** A `tests/DocsCoverageTest.php` reflexióval végigmegy a
  `src/` minden osztályán, és elbukik, ha egy publikus metódus egyik teljes referenciában sem
  szerepel — vagy ha csak az egyikben, mert a két nyelv így csúszik szét. A kiadás előtti audit
  **284 publikus metódusból 130-at** talált olyannak, amit egyik README sem említ; ez a szám most
  **nulla**, és nem kézi fegyelem tartja.
    - **A kimaradás jelölése az `@internal`**, a metóduson vagy az osztályon — ugyanaz a jelölés,
      ami azt mondja, hogy a `v1.0.0` verzió-ígérete sem fedi. A csomag **83 új jelölést** kapott:
      a kivételek 38 nevesített konstruktorát, a `Column` / `Action` / `CellConfig`
      névtérhatárt lépő `resolve()`-jait, a `TableBlueprint` cache-szerializálását, valamint a
      `RowPermissions`, `NumericFields`, `EnumPresentation` és `Inference` osztályt egészében.
    - **A dokumentálandó felület így 249 metódus**, és mind a kettő referenciában szerepel. Az őr
      három dolgot még kihagy, mindegyiket indokkal: egy trait metódusát a traiten számolja, nem
      minden használó osztályon; egy `@internal` őst felülíró metódust az ős után enged el (ezért
      elég a `CellConfig::type()`-ot megjelölni a kilenc típusé helyett); és nem kéri számon a
      keretrendszer horgait (`register()`, `boot()`, `handle()`), amiket nem az olvasó hív.
    - **Új szakasz mindkét referenciában** (*Every builder method* / *Minden builder-metódus*): a
      cella-réteg teljes felülete, a négy közös blokk (formázó-lánc, tipográfia, mapping, route)
      és típusonként az, amit hozzátesz. Ezen felül a `CellRules` keret- és paddingmetódusai, a
      `Footer::row()`, a `TableSettings::footerHeight()`, az akciók teljes hívástáblája (`alt()`,
      `modal()` és az, hogy melyik eszkalál), a `FieldPermissions` három `allows*()` kérdése, és a
      `resource()` felülírása property helyett.

- **`make:aura-table` (F6.2).** `php artisan make:aura-table UserTable --model=User` — a modell
  **saját adatbázistáblájából** skicceli fel az osztályt, nem property-nevekből találgatva: egy
  `Column::make()` oszloponként, azzal a flaggel, amit a típusa és a castja indokol.
    - `BackedEnum` cast vagy boolean → `->filterable()`; minden más olvasható típus →
      `->sortable()->searchable()`. A `currency`, az igazítás és a tartomány-beviteli mező a
      castból érkezik build-időben, tehát a generátor nem ismétli meg őket.
    - **Az elsődleges kulcs kimarad, az idegen kulcs kommentet kap** (`Column::make('company.name')`),
      a `json` / `blob` és a modell `$hidden`-je szintén. A generátor ott hagy kommentet, ahol nem
      volt hajlandó dönteni.
    - **A kijelölő oszlop `key('select')`-tel generálódik.** A kijelölés és az action-oszlop kulcsa
      is a modell kulcsára esne, és az action-oszlopé nem mozdulhat — a kulcsa maga a
      route-placeholder. Az átkulcsolás ingyen van: az Aura a sor azonosítóját a `field`-ből olvassa.
    - **Semmi szerkesztői döntést nem talál ki**: nincs `globalSearch()`, nincs `->as(…)`, nincs
      `$resource` — a generált docblock ki is mondja, mit érdemes kézzel hozzátenni. Elérhetetlen
      adatbázisnál helyőrzőt ír, és figyelmeztet.
    - A `--model` elhagyható (az osztálynévből következtet, mint a `make:policy`), az alkalmazás
      névterén kívüli modellt úgy veszi, ahogy megadtad, és a `stubs/aura-table.stub` felülírja a
      sablont.
    - **Hibás `--model` esetén nem nulla a kilépési kód.** A Laravel `(int)`-tel castolja a
      parancs visszatérési értékét, tehát a generátorok `false`-a **0**-val lép ki — egy szkript
      nem tudná megkülönböztetni az elgépelt modellt a megírt fájltól.

### Változott

- **A `composer.json` mostantól megnevezi az összes Illuminate-komponenst, amit a `src/` használ**:
  az `illuminate/database`, az `illuminate/http` és az `illuminate/validation` eddig hiányzott a
  `require`-ből, pedig a csomag Eloquentet, `Request`-et és `ValidationException`-t is használ; az
  `illuminate/console` most kerül be a parancs miatt. Laravel-alkalmazásban ez nem változtat semmin
  (a `laravel/framework` mindegyiket hozza), egy komponens-alapú telepítésnél viszont eddig hiányos
  volt a függőséglista.

- **Demo-alkalmazás (F6.1).** A `workbench/` egy Testbench-workbench: egy modell, egy tábla-osztály,
  egy route — `composer serve`, és a `v1.0/`-ban futó Aura dev-szerver a
  `http://localhost:8000/api/employees` címre mutathat. Ez az az egy kérdés, amit a teszt-suite nem
  tud megválaszolni: hogy az Aura preprocesszora tényleg azt rendereli-e, amit ez a csomag kiad.
    - Az `EmployeeTable` szándékosan mindenből egy: enum-alapú badge és szűrő, pénznemes oszlop
      feltételes színnel, folyamatjelző küszöbökkel, alkérdéssel rendezett reláció, és egy
      action-oszlop, amiben egyszerre van jelen mind a három akció-mód (`show` konvencióban, `edit`
      tooltip miatt eszkalálva, `destroy` soronkénti jogosultsággal kapuzva).
    - A `workbench/` alól semmi nem kerül a csomagba (`.gitattributes` `export-ignore`), de benne
      van a kapuban: Pint, PHPStan `max`, és a `tests/Workbench/DemoAppTest.php` — ami a route-okat,
      a CORS-preflightot, a whitelistet és a kapuzott akciót is végigjárja egy futó HTTP-kérésen.
    - **A `HandleCors` globálisan kerül be**, nem route-middleware-ként: a preflight `OPTIONS` olyan
      útvonalra megy, amire nincs route, tehát a route-middleware sosem futna le. Az `api`
      middleware-csoportot is kézzel kell regisztrálni — a Testbench üres middleware-veremmel bootol.

### Javítva

- **A `phpunit.xml` mostantól rögzíti a `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER`
  értékeket.** A demo buildje egy `.env`-et ír a Testbench `vendor/`-on belüli skeletonjába, amit
  minden későbbi teszt-futás beolvas; az abban lévő `CACHE_STORE=database` miatt a cache-elést
  vizsgáló tesztek egy `cache` táblát kerestek, amit itt semmi nem hoz létre. A demo futtatása így
  többé nem változtatja meg, mit csinálnak a tesztek.

- **Soronkénti jogosultság (F5c).** `allowedWhen()` az akción vagy bármelyik cella-konfiguráción:
  amelyik sort a callback elutasítja, ott a cella nincs ott. A callback a sor **modelljét** kapja.
    - **A kapu körbeveszi a konfigurációt, nem mellé áll.** A gyökér a `type`, a rejtett flag mint
      `key`, és egyetlen `if` ág, **`else` nélkül** — az Aura pontosan ilyenkor renderel semmit
      (INV3, `resolve-conditional-config.ts:94`). Minden más, a hívó saját `when()` / `otherwise()`
      hívásaival együtt, az ágon belülre kerül. Egy konfigurációnak egy feltétel-mezője van, tehát
      egy azonos szinten álló kapu ugyanazt a mezőt olvasná, mint a saját feltételeid, egy alatta
      lévő `otherwise()` pedig pont az elutasított soroknak renderelné a cellát. Kívülről nem
      megkerülhető — ezért nem tiltjuk a kettőt egymás mellett, ahogy a terv F5c.3 eredetileg
      kilátásba helyezte.
    - **A flag minden sorban ott van, a `false` is**, és **valódi `bool`** (INV4): az Aura `true`
      operátora egzakt (`fieldValue === true`), tehát egy `tinyint` `1` vagy egy `"1"` minden sort
      megtagadna. A hiányzó mező is rejt, tehát a leállt kapu és a „senkinek semmi nem szabad"
      megkülönböztethetetlen lenne — ezért van mindig kiírva.
    - **A route-placeholder az ágba kerül** (INV13/INV14): a gyökérben a `key` a feltétel-szelektor,
      amit a `stripLogicProps` eltávolít, tehát a kapuzott ikon a gyökérben hagyott kulccsal link
      nélkül renderelődne — némán. Ezt a meglévő ág-kulcsozás intézi, változtatás nélkül.
    - **`allowedWhenAll()`** egyszer készíti elő a döntést az egész lapra (Eloquent-kollekciót kap,
      a soronkénti tesztet adja vissza), a soronkénti forma pedig egy már memóriában lévő modellt
      kap — egyik sem indít lekérdezést soronként; `DB::listen`-es teszt rögzíti.
    - **A flag a mezőről kapja a nevét, a pontokat kilapítva** (`company.name` →
      `_allowed_company_name`): a pontozott név az Aura `resolveValue`-jét beágyazott objektumban
      kerestetné, és minden sort megtagadna. Két, egy flaget író kaput elutasítunk.
    - **A kapuzott akció eszkalál**, mint bármelyik testreszabás — a generált konfiguráció nem
      vinne feltételt. A `destroy` kapuja a modalra kerül, nem a benne lévő ikonra.
    - **A cache-szel elcsúszni csak befelé lehet.** A cache a flag *nevét* tartja, a kitöltő closure
      minden kérésnél frissen áll össze; ha a definíció olyan flaget nevez meg, amit már senki nem
      tölt, a mező hiányzik, a hiányzó mező pedig nem `true` — a cella rejtve marad.
    - A dokumentáció kimondja: **a rejtés nem jogosultság**. A sor, az azonosító és a route ott van
      a payloadban; a megtagadásnak a route-on kell lennie, és az `allowedWhen()` ugyanazt a policy-t
      kapja.

- **Akció-eszkaláció és route-építés (F5b).** Bármilyen testreszabás — route, ikon, szín, felirat,
  tooltip, modal-id — hatására az akció maga adja ki a teljes `columnConfigs` bejegyzést, mert a
  generált konfiguráció nem vinné magával a testreszabást. A hívási felület nem változik, csak a
  payload; az eszkaláció **akciónként** történik, nem oszloponként.
    - `Action::asIcon()` / `asLink()` / `asButton()` az alakot választja (`_icon` / `_link` /
      `_button`), és **nem** eszkalál: az Aura mindhármat generálja. Mind a 12 kombinációt
      táblavezérelt teszt rögzíti, az Aura preprocesszorának kimenete szerint.
    - `Action::route()`, `routeName()`, `icon()`, `variant()`, `label()`, `title()`, `alt()`,
      `modal()` és a `set()` escape hatch — ezek eszkalálnak.
    - **`AuraTable::$resource`** az eszkalált route bázisa. Konvenció-módban soha nem kell: az
      útvonalat a böngésző építi a saját `urlParameter`-éből, amit a szerver nem lát. Hiánya
      beszédesen dob; a `$resource` maga akkor is ellenőrzött, ha éppen semmi nem eszkalál, mert a
      definíció cache-elhető, és a hiba különben véletlenszerű kérésnél derülne ki.
    - **`routeName()` a route URI-ját olvassa, ahogy regisztrálva lett** (`admin/users/{user}/edit`),
      nem a `route()` helperen keresztül — annak abszolút URL-jéből az Aura
      `/https://app/example/com/...`-ot csinálna. A megnevezett paramétereket behelyettesíti, a
      nyitva maradó egy lesz a sorból töltött placeholder, az action-oszlop kulcsa alatt. Egynél
      több nyitott paraméter 422 helyett build-idejű hiba.
    - **Az action-route-ban a pont tilos** (a csomag többi részével szemben). Az Aura minden pontot
      perjelre cserél, tehát az útvonal helyére írt route-név (`users.edit`) `/users/edit`-re oldódik
      fel: valódi URL, hiányzó azonosítóval, sehol egy hiba.
    - Amit az eszkaláció nem tud reprodukálni, mert a regiszterek a böngészőben élnek: az ikon
      feloldott `class`-a (helyette `icon` + `variant`, amit a `normalizeIconConfigs` ugyanabban a
      menetben ugyanúgy old fel) és a gomb `variants[prefix]` színe (helyette `primary`). A `destroy`
      modal dekoratív `key`-jét szándékosan elhagyjuk — a `resolveRoute` sosem olvassa.

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

- **A fix `value` nem veszett el többé egy defaultolt `field` mögött.** A `Link::make()->value('X')`,
  a `Button::make('X')` és a `Badge::make()->value('X')` mind megkapta az oszlop mezőjét
  `field`-ként — és mindhárom renderelő **előbb** olvassa a `field`-et, csak utána esik vissza a
  `value`-ra (`action-node-helpers.ts`, `renderBadgeNode`, `renderProgressNode`). A megadott
  felirat így soha nem jelent meg, némán. A `CellConfig::supersedesField()` mostantól a `value`-t is
  felsorolja, és a vizsgálat a **settings**-et nézi, nem csak az explicit attribútumokat. A
  `Reference` a kivétel, és ki is mondja: az ő renderelője a `value`-t olvassa előbb, ott a mező
  ártalmatlan.
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
