# Directory Data — Multilingual Countries, Regions & Address Formats

A data package for international e-commerce: country/region names in 100+ locales plus per-country address format metadata (postcode regex, required fields, format templates) — all distributed as plain JSON, framework-agnostic.

## What This Provides

### `countries.json`
249 ISO 3166-1 countries with multilingual names:
```json
{
  "IT": {
    "en": "Italy",
    "it_IT": "Italia",
    "fr_FR": "Italie",
    "de_DE": "Italien"
  }
}
```

### `regions/{CC}.json`
Shipping-relevant administrative subdivisions per country, multilingual:
```json
{
  "TO": { "en": "Turin",  "it_IT": "Torino" },
  "MI": { "en": "Milan",  "it_IT": "Milano" }
}
```

### `formats/{CC}.json`
Per-country address format metadata sourced from Google's libaddressinput:
```json
{
  "country": {
    "key": "IT",
    "fmt": "%N%n%O%n%A%n%Z %C %S",
    "require": "ACSZ",
    "upper": "CS",
    "zip": "\\d{5}",
    "zipex": "00144,47037,39049",
    "posturl": "http://www.poste.it/online/cercacap/"
  },
  "subdivisions": {
    "AG": { "key": "AG", "name": "Agrigento", "zip": "92" }
  }
}
```

Field semantics follow the [libaddressinput conventions](https://github.com/google/libaddressinput/wiki/AddressValidationMetadata): `fmt` uses `%N` (name), `%O` (organization), `%A` (street), `%Z` (postcode), `%C` (city), `%S` (subdivision), `%n` (newline); `require` lists required field codes; `upper` lists fields that should be uppercased.

## Installation

```bash
composer require mahocommerce/directory-data
```

## Reading the Data

Use `Maho\DirectoryData\Paths` to resolve file locations — never hardcode `vendor/mahocommerce/directory-data/...` since consumers may customize Composer's `vendor-dir`.

```php
use Maho\DirectoryData\Paths;

$countries = json_decode(file_get_contents(Paths::countriesFile()), true);
$itRegions = json_decode(file_get_contents(Paths::regionsFile('IT')), true);
$itFormat  = json_decode(file_get_contents(Paths::formatsFile('IT')), true);
```

### Translation Lookup (countries.json + regions/*.json)

To keep file sizes minimal, redundant translations are omitted. Apply this fallback chain when reading:

1. Exact locale (e.g. `it_IT`)
2. Language code (e.g. `it`)
3. `en` (always present)

```php
$name = $countries['IT']['it_IT']
    ?? $countries['IT']['it']
    ?? $countries['IT']['en'];
```

## Versioning

Releases use the scheme **`1.0.YYYYMMDD`**:

- **Patch** (`YYYYMMDD`) — daily snapshot date. New patch released only when upstream data actually changed.
- **Minor** (`1.X.0`) — additive schema changes (new fields).
- **Major** (`X.0.0`) — breaking schema changes (renames, removals, layout reorganization).

Pin with `^1.0` to receive non-breaking updates automatically.

## Automated Updates

A weekly GitHub Action regenerates the data from upstream sources and, if anything changed and validation passes, commits to `main` and tags a new release. Packagist picks up new tags via webhook.

## Data Attribution & Licensing

Repository code is MIT. Data files carry their upstream licenses — see [LICENSE](LICENSE) for the full attribution table. Summary:

| Files | Source | License |
|---|---|---|
| `countries.json`, `regions/*.json` | [Debian iso-codes](https://salsa.debian.org/iso-codes-team/iso-codes) via [sokil/php-isocodes](https://github.com/sokil/php-isocodes) | LGPL-2.1+ / MIT |
| `formats/*.json` | [Google libaddressinput](https://github.com/google/libaddressinput) (via `chromium-i18n.appspot.com`) | CC-BY 4.0 |

Redistributors of the `formats/*.json` files must preserve attribution to Google's libaddressinput per CC-BY 4.0.
