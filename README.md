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

## When to Use `regions/` vs `formats/`

Both files carry subdivision data, with different purposes and coverage. Pick based on what you're building:

- **Subdivision dropdown / address-form picker** → `regions/<CC>.json`. Multilingual names. Includes real shipping destinations that aren't in strict ISO 3166-2 (e.g. US military APO codes, US territories, AU Jervis Bay Territory, NO Svalbard).
- **Postcode validation, address-format template, required-field hints** → `formats/<CC>.json` `country` block. libaddressinput postal rules, mirrored verbatim.
- **Subdivision-level postcode hints** (e.g. an Italian province's ZIP prefix) → `formats/<CC>.json` `subdivisions[<key>]`. libaddressinput's subdivision set; in some countries it's at a different administrative level from `regions/`. Join by key when you need both.

`regions/` is the canonical list for *display*; `formats/`'s `subdivisions` map is the canonical source for *postal rules*. The two overlap intentionally.

### Beyond strict ISO 3166-2

`regions/` is built primarily from iso-codes (ISO 3166-2 with 100+ locale translations) plus a small curated set of real postal jurisdictions ISO doesn't cover. The most notable case is **US**: `regions/US.json` includes the 50 states + `DC` + Outlying Areas (`PR`, `GU`, `VI`, `AS`, `MP`, `UM`) + military APO codes (`AA`, `AE`, `AP`). The military codes are sourced from libaddressinput; the territories come from broader iso-codes subdivision types. Note that `PR`/`GU`/`VI`/`AS`/`MP`/`UM` are also separately listed as ISO 3166-1 countries — the dual-listing matches libaddressinput's convention and lets merchants pick the model that fits their checkout flow.

Similar augmentations exist for **AU** (Jervis Bay Territory), **NO** (Svalbard / Jan Mayen), **ES** (Ceuta / Melilla), **BR** (Brasília), **MX** (Ciudad de México), **AR** (CABA), **KR** (Jeju / Gangwon / Sejong), and **BS** (New Providence).

**A note on UK addresses:** `regions/GB.json` lists the 4 UK Constituent Countries (England, Scotland, Wales, Northern Ireland). Real-world UK checkout flows don't actually use a region dropdown — Royal Mail routes by postcode alone, so most major platforms (Magento, WooCommerce, Shopify, BigCommerce, PrestaShop) ship no GB regions and treat county as an optional free-text field after a postcode lookup. The 4 entries here are provided as a minimal option for merchants who do want a dropdown; most won't need them. UK counties (~48 ceremonial or 200+ current administrative units) are deliberately not provided — there's no canonical merchant-facing list and the prevailing convention is to skip the dropdown entirely.

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
