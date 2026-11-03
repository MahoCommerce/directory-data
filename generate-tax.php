<?php

/**
 * Writes the tax rates of each EU member state into tax/<CC>.json.
 *
 * EU member states: the rates and the goods categories come from TEDB, the Taxes in Europe Database of
 * the European Commission, through the vatcomply API (https://www.vatcomply.com, MIT), which mirrors it.
 * The member states keep TEDB up to date. TEDB data is © European Union, reused under the Commission
 * reuse policy (Decision 2011/833/EU). See LICENSE for attribution.
 *
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
    'GR' => [
        'outside_tax_area' => [
            '63086' => 'Mount Athos',
        ],
    ],
    'IT' => [
        'outside_tax_area' => [
            '23041' => 'Livigno',
        ],
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

function writeCountry(string $countryCode, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    file_put_contents(OUTPUT_DIR . "/$countryCode.json", $json . "\n");
}

echo "Fetching EU VAT rates from vatcomply (TEDB)...\n";
$tedb = httpGetJson(VATCOMPLY_URL);

if (!is_dir(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0o755, true);
}
$manualCodes = [];
foreach (glob(OUTPUT_DIR . '/*.json') ?: [] as $path) {
    $source = json_decode((string) file_get_contents($path), true)['source'] ?? null;
    if ($source === 'tedb') {
        unlink($path);
    } else {
        $manualCodes[basename($path, '.json')] = true;
    }
}

$written = 0;
foreach ($tedb as $country) {
    if (empty($country['member_state'])) {
        continue;
    }
    $countryCode = COUNTRY_CODE_MAP[$country['country_code']] ?? $country['country_code'];
    if (isset($manualCodes[$countryCode])) {
        throw new RuntimeException("tax/$countryCode.json is kept by hand, but TEDB has $countryCode. Delete the file.");
    }
    $standard = normalizeRate($country['standard_rate']);
    $data = [
        'standard' => $standard,
        'reduced' => reducedRates($country['rate_categories'] ?? [], $standard),
        'prices_include_tax' => true,
    ];
    $data += TERRITORIES[$countryCode] ?? [];
    $data['source'] = 'tedb';
    writeCountry($countryCode, $data);
    $written++;
}
echo "Wrote $written EU files to tax/, kept " . count($manualCodes) . " hand-kept files\n";
