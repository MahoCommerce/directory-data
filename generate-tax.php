<?php

/**
 * Writes the tax rates of each EU member state into tax/<CC>.json.
 *
 * EU member states: the rates and the goods categories come from TEDB, the Taxes in Europe Database of
 * the European Commission, through the vatcomply API (https://www.vatcomply.com, MIT), which mirrors it.
 * The member states keep TEDB up to date. TEDB data is © European Union, reused under the Commission
 * reuse policy (Decision 2011/833/EU). See LICENSE for attribution.
 *
 * CORRECTIONS fixes the rates of a category where the feed is wrong or out of date.
 * TERRITORIES adds the postcodes outside the tax area and the countries inside it, which TEDB does not have.
 *
 * The files of the other countries have "source": "manual". They are kept by hand, and this script leaves them alone.
 *
 * Output shape per file:
 * {
 *   "standard": 22,
 *   "reduced": [ { "rate": 10, "categories": ["foodstuffs", ...] }, ... ],
 *   "prices_include_tax": true,
 *   "regions": { "ON": [ { "name": "HST", "rate": 13 } ], ... },
 *   "home_region_only": true,
 *   "outside_tax_area": { "23041": "Livigno" },
 *   "same_tax_area": ["MC"],
 *   "source": "tedb"
 * }
 *
 * "reduced" holds every rate below the standard rate, 0% included, from the highest to the lowest.
 * A category can appear at more than one rate, because a country taxes parts of it at different rates.
 * The TEDB "region" category is left out: it holds the rates of a region, such as Corsica or Madeira.
 * "prices_include_tax" says whether consumer prices in the country include the tax.
 * "regions" lists, for each region code, the taxes that replace the standard rate there. A store adds them up.
 * "home_region_only" says that a store charges the tax of its own region only, as in the US.
 * "outside_tax_area" lists postcodes, with * as a wildcard, where the tax of the country does not apply.
 * "same_tax_area" lists other countries where the tax of the country applies.
 */

declare(strict_types=1);

const VATCOMPLY_URL = 'https://api.vatcomply.com/vat_rates';
const OUTPUT_DIR = __DIR__ . '/tax';
const MAX_RETRIES = 3;
const RETRY_BACKOFF_SEC = 2;

/** TEDB uses EL for Greece, ISO 3166-1 uses GR. */
const COUNTRY_CODE_MAP = ['EL' => 'GR'];

const IGNORED_CATEGORIES = ['region'];

/** The feed must list exactly these member states, or the generator changes no file. */
const EU_MEMBER_STATES = [
    'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
    'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
];

/** What TEDB does not have: the postcodes outside the tax area, and the countries inside it. */
const TERRITORIES = [
    'DE' => [
        'outside_tax_area' => [
            '27498' => 'Helgoland',
            '78266' => 'Büsingen am Hochrhein',
        ],
    ],
    'ES' => [
        'outside_tax_area' => [
            '35*' => 'Las Palmas (Canary Islands)',
            '38*' => 'Santa Cruz de Tenerife (Canary Islands)',
            '51*' => 'Ceuta',
            '52*' => 'Melilla',
        ],
    ],
    'FR' => [
        'outside_tax_area' => [
            '971*' => 'Guadeloupe',
            '972*' => 'Martinique',
            '973*' => 'Guyane',
            '974*' => 'La Réunion',
            '976*' => 'Mayotte',
        ],
        'same_tax_area' => ['MC'],
    ],
    'FI' => [
        'outside_tax_area' => [
            '22*' => 'Åland',
        ],
    ],
    'GR' => [
        'outside_tax_area' => [
            '63086' => 'Mount Athos',
        ],
    ],
    'IT' => [
        'outside_tax_area' => [
            '22061' => "Campione d'Italia",
            '23041' => 'Livigno',
        ],
    ],
];

/**
 * Rates of a TEDB category that the feed has wrong, with the source of each fix. An empty list removes the category.
 * The generator prints a notice when the feed matches a correction: delete the correction then.
 *
 * @var array<string, array<string, list<float|int>>>
 */
const CORRECTIONS = [
    // UStG § 10 Anlage 1 Z 34: medicines 10%. § 6 Abs. 1 Z 5b: contraceptives and menstrual products 0% since 2026-01-01.
    // The 0% for food is for donations only. The 0% for photovoltaic modules ended on 2025-12-31 (§ 28 Abs. 62).
    'AT' => [
        'pharmaceutical_products' => [10],
        'sanitary_products' => [0],
        'foodstuffs' => [4.9, 10, 13],
        'solar_panels' => [],
    ],
    // ZDDS Art. 66a: 9% for books, newspapers, periodicals, baby food and baby diapers. Other food and medicines are at 20%.
    'BG' => [
        'foodstuffs' => [],
        'pharmaceutical_products' => [],
        'newspapers' => [9],
        'periodicals' => [9],
    ],
    // AEAT Manual IVA 2026: 4% for basic food, books, newspapers, human medicines and menstrual products.
    'ES' => [
        'exemption_superreduced' => [],
        'foodstuffs' => [4, 10],
        'loan_libraries' => [4],
        'newspapers' => [4],
        'periodicals' => [4],
        'pharmaceutical_products' => [4, 10],
        'sanitary_products' => [4],
    ],
    // Revenue: clothing and shoe repairs 13.5%, children's clothing and footwear 0%, livestock 4.8%.
    'IE' => [
        'clothing_repair' => [13.5],
        'shoes_repair' => [13.5],
        'child_wear' => [0],
        'livestock' => [4.8],
    ],
    // Belastingdienst: short-stay accommodation 21% since 2026-01-01.
    'NL' => [
        'accommodation' => [],
    ],
    // Annex 10: 5% for menstrual products and diapers. Medicines are at 8%.
    'PL' => [
        'pharmaceutical_products' => [8],
        'sanitary_products' => [5],
    ],
    // FURS: the 9.5% covers minor repairs of bicycles only. The sale and the rental of bicycles are at 22%.
    'SI' => [
        'bicycles_rental' => [],
    ],
];

function httpGetJson(string $url): array
{
    $lastError = '';
    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: mahocommerce/directory-data (https://github.com/MahoCommerce/directory-data)\r\n",
                'timeout' => 30,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $lastError = "fetch failed: $url";
            sleep(RETRY_BACKOFF_SEC * $attempt);
            continue;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $lastError = 'invalid JSON: ' . json_last_error_msg();
            sleep(RETRY_BACKOFF_SEC * $attempt);
            continue;
        }
        return $decoded;
    }
    throw new RuntimeException("Giving up on $url after " . MAX_RETRIES . " attempts: $lastError");
}

function normalizeRate(float|int $rate): float|int
{
    $rate = round((float) $rate, 2);
    return floor($rate) === $rate ? (int) $rate : $rate;
}

/**
 * @param array<string, list<float|int>> $rateCategories TEDB category => rates
 * @return list<array{rate: float|int, categories: list<string>}>
 */
function reducedRates(array $rateCategories, float|int $standard): array
{
    $byRate = [];
    foreach ($rateCategories as $category => $rates) {
        if (in_array($category, IGNORED_CATEGORIES, true)) {
            continue;
        }
        foreach ($rates as $rate) {
            $rate = normalizeRate($rate);
            if ($rate >= $standard) {
                continue;
            }
            $byRate[(string) $rate][] = $category;
        }
    }

    $reduced = [];
    foreach ($byRate as $rate => $categories) {
        $categories = array_values(array_unique($categories));
        sort($categories);
        $reduced[] = ['rate' => normalizeRate((float) $rate), 'categories' => $categories];
    }
    usort($reduced, static fn(array $a, array $b): int => $b['rate'] <=> $a['rate']);
    return $reduced;
}

/**
 * @param array<string, list<float|int>> $rateCategories
 * @return array<string, list<float|int>>
 */
function correctCategories(string $countryCode, array $rateCategories): array
{
    foreach (CORRECTIONS[$countryCode] ?? [] as $category => $rates) {
        $feed = array_map(normalizeRate(...), $rateCategories[$category] ?? []);
        $fixed = array_map(normalizeRate(...), $rates);
        sort($feed);
        sort($fixed);
        if ($feed === $fixed) {
            echo "  Notice: the feed now matches the correction of $category in $countryCode. Delete the correction.\n";
        }
        if ($rates === []) {
            unset($rateCategories[$category]);
        } else {
            $rateCategories[$category] = $rates;
        }
    }
    return $rateCategories;
}

function encodeCountry(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
}

echo "Fetching EU VAT rates from vatcomply (TEDB)...\n";
$tedb = httpGetJson(VATCOMPLY_URL);

if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0o755, true);
}
$records = [];
foreach ($tedb as $country) {
    if (empty($country['member_state'])) {
        continue;
    }
    $countryCode = COUNTRY_CODE_MAP[$country['country_code'] ?? ''] ?? (string) ($country['country_code'] ?? '');
    if (!in_array($countryCode, EU_MEMBER_STATES, true)) {
        throw new RuntimeException("The feed has an unknown member state: \"$countryCode\". No file was changed.");
    }
    if (isset($records[$countryCode])) {
        throw new RuntimeException("The feed has $countryCode twice. No file was changed.");
    }
    if (!is_numeric($country['standard_rate'] ?? null)) {
        throw new RuntimeException("The feed has no standard rate for $countryCode.");
    }
    $standard = normalizeRate($country['standard_rate']);
    $data = [
        'standard' => $standard,
        'reduced' => reducedRates(correctCategories($countryCode, $country['rate_categories'] ?? []), $standard),
        'prices_include_tax' => true,
    ];
    $data += TERRITORIES[$countryCode] ?? [];
    $data['source'] = 'tedb';
    $records[$countryCode] = encodeCountry($data);
}
$missing = array_diff(EU_MEMBER_STATES, array_keys($records));
if ($missing !== []) {
    throw new RuntimeException('The feed has no data for ' . implode(', ', $missing) . '. No file was changed.');
}

$manualCodes = [];
foreach (glob(OUTPUT_DIR . '/*.json') ?: [] as $path) {
    $countryCode = basename($path, '.json');
    $source = json_decode((string) file_get_contents($path), true)['source'] ?? null;
    if ($source === 'tedb') {
        continue;
    }
    if (isset($records[$countryCode])) {
        throw new RuntimeException("tax/$countryCode.json is kept by hand, but TEDB has $countryCode. Delete the file.");
    }
    $manualCodes[$countryCode] = true;
}

foreach ($records as $countryCode => $json) {
    if (file_put_contents(OUTPUT_DIR . "/$countryCode.json.tmp", $json) !== strlen($json)) {
        array_map(unlink(...), glob(OUTPUT_DIR . '/*.json.tmp') ?: []);
        throw new RuntimeException("Could not write tax/$countryCode.json.tmp. No file was changed.");
    }
}
foreach (array_keys($records) as $countryCode) {
    if (!rename(OUTPUT_DIR . "/$countryCode.json.tmp", OUTPUT_DIR . "/$countryCode.json")) {
        throw new RuntimeException("Could not replace tax/$countryCode.json.");
    }
}
foreach (glob(OUTPUT_DIR . '/*.json') ?: [] as $path) {
    $countryCode = basename($path, '.json');
    if (!isset($manualCodes[$countryCode]) && !isset($records[$countryCode])) {
        unlink($path);
    }
}
echo 'Wrote ' . count($records) . ' EU files to tax/, kept ' . count($manualCodes) . " hand-kept files\n";
