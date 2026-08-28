# Változásnapló

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi.
A csomag a **szerződés 1.0** verzióját célozza (`TamasLabs\Aura\AuraContract::VERSION`); a
szerződés verziója külön él a csomagverziótól.

## [Unreleased]

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
- **README** három fájlban, a workspace mintája szerint: `README.md` (rövid, telepítés és
  alapok), `README.en.md` és `README.hu.md` (teljes referencia angolul és magyarul).

### Biztonság

- A kérésben érkező `field` értékek kizárólag a `FieldPermissions` whitelistjén keresztül jutnak
  a lekérdezésbe. Üres lista = semmi nem engedélyezett; „engedj mindent" kapcsoló nincs, az
  egyezés pontos (egy engedélyezett név prefixe nem engedélyezett).
- A `paginate` a `aura.pagination.max` értékre vágódik.
- A `LIKE` keresés escape-eli a `%` és `_` karaktereket (`ESCAPE '!'`), így egy `%` a
  keresőmezőben nem alakítja teljes táblaolvasássá a keresést.
- A `selected[]` nem kerül a lekérdezésbe.

### Megjegyzés

A szerződés schema-dokumentumai **nem ebben a repóban élnek**, hanem a
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) csomagban, amely
`require-dev` — a csomag fogyasztóihoz nem jut el. Mivel az `aura-schema` szándékosan nincs
Packagiston, a `composer.json` egy `repositories` VCS-bejegyzésen keresztül, `dev-main`-ként
húzza be.
