<?php

declare(strict_types=1);

// Validate generated data. Run after generate.php and generate-formats.php.
// Exits non-zero with a summary if anything is off. Used by the release workflow.

$errors = [];

// countries.json
if (!is_file('countries.json')) {
    $errors[] = 'countries.json missing';
} else {
    $countries = json_decode((string)file_get_contents('countries.json'), true);
    if (!is_array($countries) || count($countries) < 240) {
        $errors[] = 'countries.json invalid or too small (got ' . (is_array($countries) ? count($countries) : 'non-array') . ', need >= 240)';
    } else {
        foreach (['US', 'IT'] as $cc) {
            if (empty($countries[$cc]['en'])) {
                $errors[] = "countries.json missing canonical entry $cc.en";
            }
        }
    }
}

// regions/ — structural validity + count manifest
$regionFiles = glob('regions/*.json') ?: [];
if (count($regionFiles) < 100) {
    $errors[] = 'regions/ has fewer than 100 files (got ' . count($regionFiles) . ')';
}

$expected = [];
if (!is_file('expected-counts.json')) {
    $errors[] = 'expected-counts.json missing — region count manifest is required';
} else {
    $expected = json_decode((string)file_get_contents('expected-counts.json'), true);
    if (!is_array($expected)) {
        $errors[] = 'expected-counts.json is not valid JSON';
        $expected = [];
    }
}

$seen = [];
foreach ($regionFiles as $path) {
    $cc = basename($path, '.json');
    $seen[$cc] = true;
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        $errors[] = "$path is not valid JSON";
        continue;
    }
    // Guard against iso-codes annotation leaks (see #5). Post-strip, no value
    // should contain '['; if upstream introduces a new bracket pattern, fail here.
    foreach ($data as $regionKey => $translations) {
        if (!is_array($translations)) {
            continue;
        }
        foreach ($translations as $locale => $name) {
            if (is_string($name) && str_contains($name, '[')) {
                $errors[] = "regions/$cc.json: $regionKey.$locale contains '[' — iso-codes annotation leak: " . json_encode($name);
            }
        }
    }
    $count = count($data);
    if (!isset($expected[$cc])) {
        $errors[] = "regions/$cc.json: no entry in expected-counts.json (got $count). Add { \"min\": $count, \"max\": $count } intentionally.";
        continue;
    }
    $min = $expected[$cc]['min'] ?? null;
    $max = $expected[$cc]['max'] ?? null;
    if (!is_int($min) || !is_int($max)) {
        $errors[] = "expected-counts.json[$cc]: min and max must be integers";
        continue;
    }
    if ($min < 0 || $min > $max) {
        $errors[] = "expected-counts.json[$cc]: invalid bounds min=$min max=$max (require 0 <= min <= max)";
        continue;
    }
    if ($count < $min || $count > $max) {
        $errors[] = "regions/$cc.json: count $count outside expected [$min, $max] — update expected-counts.json if intentional";
    }
}

foreach ($expected as $cc => $_) {
    if (!isset($seen[$cc])) {
        $errors[] = "expected-counts.json[$cc]: no matching regions/$cc.json — remove the entry or restore the file";
    }
}

// regions/ — required keys for high-signal countries. Counts alone don't prove identity:
// a refactor could produce the right count with the wrong entries. Pin the keys that
// the PR's completeness goals depend on.
$requiredKeys = [
    'US' => ['AA', 'AE', 'AP', 'DC', 'PR', 'GU', 'VI', 'AS', 'MP', 'UM'],
    'AU' => ['JBT'],
    'GB' => ['ENG', 'SCT', 'WLS', 'NIR'],
];
foreach ($requiredKeys as $cc => $keys) {
    $path = "regions/$cc.json";
    if (!is_file($path)) {
        $errors[] = "$path missing — required for key assertions";
        continue;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        continue; // already reported above
    }
    foreach ($keys as $key) {
        if (!isset($data[$key])) {
            $errors[] = "regions/$cc.json: missing required key '$key'";
        }
    }
}

// formats/
$formatFiles = glob('formats/*.json') ?: [];
if (count($formatFiles) < 200) {
    $errors[] = 'formats/ has fewer than 200 files (got ' . count($formatFiles) . ')';
}
foreach ($formatFiles as $path) {
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || empty($data['country']['key'])) {
        $errors[] = "$path missing country.key";
    }
}
foreach (['US', 'IT'] as $cc) {
    $data = json_decode((string)file_get_contents("formats/$cc.json"), true);
    if (empty($data['country']['fmt']) || empty($data['country']['zip'])) {
        $errors[] = "formats/$cc.json missing fmt/zip";
    }
}

if ($errors) {
    fwrite(STDERR, "Validation failed:\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - $e\n");
    }
    exit(1);
}

echo "Validation passed (" . count($regionFiles) . " regions, " . count($formatFiles) . " formats).\n";
