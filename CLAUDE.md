# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Purpose

This repo is both a **data publication pipeline** and a **distributable Composer library**. It produces three artifacts consumed by other projects (notably MahoCommerce):

- `countries.json` — 249 ISO 3166-1 countries with multilingual names
- `regions/{CC}.json` — one file per country with shipping-relevant administrative subdivisions, multilingual
- `formats/{CC}.json` — per-country address format metadata (postcode regex, required fields, format templates) mirrored from Google's libaddressinput

A small PHP shim `Maho\DirectoryData\Paths` (in `src/`) lets consumers locate these data files without hardcoding `vendor/mahocommerce/directory-data/...` paths.

## Commands

```bash
composer install              # one-time, requires PHP >=8.3 with ext-intl
php generate-formats.php      # regenerates formats/*.json from libaddressinput (network) — run first
php generate.php              # regenerates countries.json + regions/*.json (reads formats/ for augmentations)
php validate.php              # asserts output is well-formed and counts match expected-counts.json
```

Run `generate-formats.php` before `generate.php`: the regions generator augments a curated set of countries (currently US, AU) by reading non-ISO entries from `formats/{CC}.json`. If `formats/` is missing, the augmentation is skipped with a warning and `regions/` is generated without the libaddressinput-only entries.

Both generators are idempotent and overwrite outputs. After running, inspect `git diff` to see what changed, then run `php validate.php`.

`generate-formats.php` makes ~240 HTTP requests to `chromium-i18n.appspot.com` with a 100ms delay between calls. Expect ~30s wall time. A handful of countries occasionally fail upstream — the script logs them and continues; the workflow's validation step enforces a minimum success rate (200+ files).

## Architecture

### `generate.php` (countries + regions)

Two near-parallel passes (countries, then subdivisions), each structured the same way:

1. **English baseline pass** — runs the `en` locale first to establish the canonical set of keys (country alpha-2 codes, or subdivision codes). Every later locale is filtered against this set; codes not seen in `en` are dropped.
2. **Per-locale translation passes** — iterates every locale from `ResourceBundle::getLocales('')` and merges localized names in.

#### Translation deduplication (important)

To keep file sizes small, the generator omits redundant translations. Two rules, applied in order:

- If a locale's translation **equals the English name**, skip it.
- If a variant locale (e.g. `de_AT`) has the **same translation as its base language** (`de`), skip it.

This is why consumers must apply the documented fallback chain when reading the JSON: `locale → language → en`. Don't "fix" missing keys by re-adding them — the absence is intentional.

`@`-suffixed locales (script variants like `sr@latin`) are detected from the translation directory but **explicitly skipped** during processing. The detection code is inert; leave it unless you intend to add script-variant support end-to-end.

#### Subdivision type selection (the non-obvious part)

ISO 3166-2 lists subdivisions at multiple administrative levels per country (e.g. Italy has both regions and provinces), and a single country may also include shipping-relevant entries at *different* levels (e.g. `US` needs the 50 states AND DC AND its outlying-area territories). The generator picks via two mechanisms:

1. **`$shippingTypes` map** (hardcoded around line 214) — for ~25 major countries, the relevant type(s) are listed explicitly. Often a country needs multiple types (e.g. `US → State + District + Outlying area`, `IT → Province + Metropolitan city + ...`, `KR → Province + Metropolitan city + Special city + Special self-governing province + Special self-governing city`). When adding or expanding an entry, list **every** type that should appear; types not listed are dropped silently.
2. **Fallback heuristic** for countries not in the map: skip the most general types (`Region`, `Country`, `Nation`) and the most specific (`Municipality`, `City`, `Commune`, ...), then take what's left with ≥3 entries. The script logs `Unknown country XX - types available: ...` for these — those log lines are the signal that a country should probably be added to `$shippingTypes`. The script also logs `Note: XX has additional types: ...` for countries already in the map whose iso-codes data contains types the map doesn't list — review these periodically to catch shipping-relevant additions (e.g. `'Arctic region'` in Norway covers Svalbard/Jan Mayen).

The `$typeHierarchy` array exists to score types by specificity for the fallback path; it does not affect countries in `$shippingTypes`.

**Type names must match iso-codes exactly** — a typo (e.g. `'Voivodeship'` vs the actual `'Voivodship'`) silently produces zero matches for that country, and the fallback heuristic quietly rescues it. If a country in `$shippingTypes` is also showing up in `Unknown country XX` logs, the map entry isn't matching anything.

#### Augmentation from `formats/` (the non-ISO-3166-2 escape hatch)

A small `$formatsAugmentations` map (just below `$shippingTypes`) carries entries that are real postal jurisdictions but **not in ISO 3166-2**, so they cannot come from iso-codes. Currently:

- `US`: `AA`, `AE`, `AP` — US military APO/FPO/DPO codes
- `AU`: `JBT` — Jervis Bay Territory

For each listed key, generate.php reads `sub_names` from `formats/{CC}.json` (libaddressinput) and adds the entry to `regions/{CC}.json` with an English-only name. Later translation passes naturally skip these entries because iso-codes has no record of them.

This is a **curated allowlist, not a generic merge** — most entries in libaddressinput's `sub_keys` with empty `sub_isoids` are *not* safe to add (admin-level mismatches like BS districts-vs-island-groups, dual-listed ISO 3166-1 countries like FM/MH/PW under US, or politically loaded entries like Crimea under RU). When considering an addition, verify the key is not already provided by iso-codes under a different type filter (often the right fix is updating `$shippingTypes` instead).

### `generate-formats.php` (libaddressinput mirror)

Fetches the country list from `https://chromium-i18n.appspot.com/ssl-address/data` and then the per-country aggregate from `/ssl-aggregate-address/data/{CC}` for each. Each response contains:

- `data/<CC>` — the country-level format metadata
- `data/<CC>/<SUB>` — one entry per subdivision

The script flattens this into a `{ country: {...}, subdivisions: { <key>: {...}, ... } }` shape per output file, preserving all upstream fields verbatim. Don't add transformations here — fidelity to upstream is the contract; if a consumer needs a different shape, they wrap the data in their own helper.

### `src/Paths.php`

The only PHP code shipped to consumers. Pure path resolution (no IO, no caching). When adding a new data artifact, add a corresponding `*Dir()` / `*File()` accessor here — never let consumers hardcode paths.

### Output keying

- `countries.json`: `{ "<alpha-2>": { "<locale>": "<name>", ... } }`, sorted by alpha-2.
- `regions/<alpha-2>.json`: `{ "<subdivision-suffix>": { "<locale>": "<name>", ... } }`, sorted by suffix. The suffix is the part after the hyphen in the ISO 3166-2 code (e.g. `IT-TO` → key `TO`).
- `formats/<alpha-2>.json`: `{ "country": {...}, "subdivisions": { "<key>": {...}, ... } }`, subdivisions sorted by key.

## Releases & versioning

This repo uses **automated releases** — every weekly upstream-data update produces a new tagged release.

- Versioning: `1.0.YYYYMMDD` (patch = snapshot date). Manually bump minor for additive schema changes, major for breaking.
- The workflow `.github/workflows/update-data.yml` runs both generators, validates output via `validate.php`, commits to `main`, tags, and creates a GitHub release. Packagist auto-pulls via webhook.
- **`validate.php` is load-bearing.** It enforces minimum file counts, presence of canonical entries (US, IT) with required fields, and per-country region-count bounds via `expected-counts.json`. If you change the data shape, update the validation in lockstep — otherwise a regression silently ships to every Maho merchant on `composer update`.

### `expected-counts.json` — region-count manifest

A `{cc: {min, max}}` map. Bounds start strict (`min == max == current count`). Any generator change that legitimately moves a count must update this file in the same PR — otherwise CI fails. Catches both upstream surprises (iso-codes silently drops a subdivision) and accidental generator regressions (a refactor of `$shippingTypes` flips a country's count to zero). New countries appearing in `regions/` must be added to the manifest explicitly.

## Distribution (`.gitattributes`)

`generate.php`, `generate-formats.php`, `.github/`, `composer.lock`, `CLAUDE.md`, etc. are marked `export-ignore` so they don't ship in the Packagist tarball. Consumers only get data + `src/Paths.php` + `composer.json` + `LICENSE` + `README.md`. When adding new generator-only / dev-only files, remember to extend `.gitattributes`.

## When making changes

- Edits should almost always be to the generator scripts. The JSON outputs are generated artifacts — don't hand-edit them; regenerate. The exception is if you want to confirm a diff before committing the script change.
- After modifying a generator, run it locally and review `git diff` to verify the change is what you intended and didn't unintentionally drop entries elsewhere.
- The README's translation-lookup fallback contract (`locale → language → en`) is load-bearing for consumers. Any change to dedup logic must preserve it.
- If you add a new data artifact: extend `Paths.php`, extend the validation step in the workflow, extend `.gitattributes` if there are new dev-only files, and document the new file in README.
