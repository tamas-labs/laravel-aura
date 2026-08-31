# Változásnapló

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi.
A csomag a **szerződés 1.0** verzióját célozza (`TamasLabs\Aura\AuraContract::VERSION`); a
szerződés verziója külön él a csomagverziótól.

## [Unreleased]

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

### Módosítva

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
