<?php

/**
 * Mirrors per-country address format metadata from Google's libaddressinput
 * (https://chromium-i18n.appspot.com/ssl-aggregate-address) into formats/<CC>.json.
 *
 * Source data is licensed CC-BY 4.0 by Google. See LICENSE for attribution.
 *
 * Output shape per file:
 * {
 *   "country": { "key": "IT", "fmt": "%N%n%O%n%A%n%Z %C %S", "require": "ACSZ", "zip": "\\d{5}", ... },
 *   "subdivisions": { "AG": { "key": "AG", "name": "Agrigento", "zip": "92", ... }, ... }
 * }
 *
 * The upstream `data/<CC>` / `data/<CC>/<SUB>` keys are flattened into a country/
 * subdivisions split for readability; all upstream fields are preserved verbatim.
 */

declare(strict_types=1);

const COUNTRY_LIST_URL = 'https://chromium-i18n.appspot.com/ssl-address/data';
const COUNTRY_DATA_URL_TEMPLATE = 'https://chromium-i18n.appspot.com/ssl-aggregate-address/data/%s';
const REQUEST_DELAY_USEC = 100_000; // 100ms between requests, polite client
const MAX_RETRIES = 3;
const RETRY_BACKOFF_SEC = 2;

function httpGetJson(string $url): array
{
    $attempt = 0;
    $lastError = '';
    while ($attempt < MAX_RETRIES) {
        $attempt++;
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

echo "Fetching country list from libaddressinput...\n";
$root = httpGetJson(COUNTRY_LIST_URL);
$countries = explode('~', $root['countries'] ?? '');
$countries = array_values(array_filter($countries, fn($c) => $c !== ''));
echo 'Found ' . count($countries) . " countries\n";

if (!is_dir('formats')) {
    mkdir('formats', 0755, true);
}

$ok = 0;
$errors = [];

foreach ($countries as $cc) {
    echo "  $cc ... ";
    usleep(REQUEST_DELAY_USEC);
    try {
        $raw = httpGetJson(sprintf(COUNTRY_DATA_URL_TEMPLATE, $cc));
    } catch (RuntimeException $e) {
        echo "FAILED ({$e->getMessage()})\n";
        $errors[$cc] = $e->getMessage();
        continue;
    }

    $countryKey = "data/$cc";
    $country = $raw[$countryKey] ?? null;
    if ($country === null) {
        echo "FAILED (missing $countryKey in response)\n";
        $errors[$cc] = "missing $countryKey";
        continue;
    }

    $subdivisions = [];
    $prefix = "data/$cc/";
    $prefixLen = strlen($prefix);
    foreach ($raw as $key => $value) {
        if (str_starts_with($key, $prefix)) {
            $subdivisions[substr($key, $prefixLen)] = $value;
        }
    }
    ksort($subdivisions);

    $payload = [
        'country' => $country,
        'subdivisions' => $subdivisions,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "FAILED (json_encode: " . json_last_error_msg() . ")\n";
        $errors[$cc] = 'json_encode failed';
        continue;
    }

    file_put_contents("formats/$cc.json", $json);
    echo 'OK (' . count($subdivisions) . " subdivisions)\n";
    $ok++;
}

echo "\nGenerated $ok/" . count($countries) . " country format files\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $cc => $msg) {
        echo "  $cc: $msg\n";
    }
    // Soft-fail: a handful of countries occasionally 404 / time out at the upstream.
    // The workflow's sanity check enforces a minimum success rate.
}
