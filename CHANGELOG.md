# Változásnapló

A formátum a [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) ajánlását követi.
A csomag a **szerződés 1.0** verzióját célozza (`TamasLabs\Aura\AuraContract::VERSION`); a
szerződés verziója külön él a csomagverziótól.

## [Unreleased]

### Hozzáadva

- **Docker-alapú fejlesztői környezet** (F0): egyetlen `php` service `php:8.4-cli-alpine`-on,
  `intl` + `zip` fordítva, `mbstring` / `pdo_sqlite` build-time ellenőrizve. Adatbázis-konténer
  nincs — a suite in-memory SQLite-on fut.
- **`AuraServiceProvider`** és a publikálható `config/aura.php` (`aura-config` tag). Egyelőre a
  `pagination.default` / `pagination.max` kulcsokkal; a `max` a kliensről érkező `paginate`
  felső korlátja, nem javaslat.
- **`AuraContract::VERSION`** — a célzott szerződésverzió.
- **Contract-teszt harness** (F1): `opis/json-schema` a `tamas-labs/aura-schema` csomag
  dokumentumaira kötve, hálózati hívás nélkül; `assertMatchesAuraResponseSchema()` és
  `assertMatchesAuraRequestSchema()` teszt-helperek; nevesített regressziós teszt arra, hogy a
  `simplePaginate()` `meta`-ja nem elégíti ki a szerződést.
- **Minőségi kapu**: Pint (`laravel` preset), PHPStan/Larastan level `max`, Pest.
  `composer quality` futtatja mindhármat.
- **CI**: PHP 8.3/8.4 × Laravel 12/13 mátrix natívan, plusz egy külön job, amely a fejlesztői
  image-et építi és abban futtatja a kaput.

### Megjegyzés

A szerződés schema-dokumentumai **nem ebben a repóban élnek**, hanem a
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) csomagban, amely
`require-dev` — a csomag fogyasztóihoz nem jut el. Mivel az `aura-schema` szándékosan nincs
Packagiston, a `composer.json` egy `repositories` VCS-bejegyzésen keresztül, `dev-main`-ként
húzza be.
