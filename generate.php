<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Sokil\IsoCodes\IsoCodesFactory;
use Sokil\IsoCodes\TranslationDriver\SymfonyTranslationDriver;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\MoFileLoader;

// Get all available locales dynamically using intl extension
$allIntlLocales = ResourceBundle::getLocales('');
echo "Found " . count($allIntlLocales) . " locales from intl extension\n";

$countriesData = [];

// Process each intl locale
foreach ($allIntlLocales as $locale) {
    echo "Processing locale: $locale\n";
    
    try {
        // Create translation driver (no cache directory)
        $translationDriver = new SymfonyTranslationDriver(null);
        
        // Set the locale - Symfony will handle fallback automatically
        $translationDriver->setLocale($locale);
        
        // Create ISO codes factory with the translation driver
        $factory = new IsoCodesFactory(null, $translationDriver);
        $countries = $factory->getCountries();
        
        // Get all countries and their translations
        $hasTranslations = false;
        foreach ($countries as $country) {
            $alpha2 = $country->getAlpha2();
            
            // Initialize country entry if not exists
            if (!isset($countriesData[$alpha2])) {
                $countriesData[$alpha2] = [];
            }
            
            // Get the localized name
            $localizedName = $country->getLocalName();
            if ($localizedName) {
                $countriesData[$alpha2][$locale] = $localizedName;
                $hasTranslations = true;
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Error processing locale $locale: " . $e->getMessage() . "\n";
        continue;
    }
}

// Sort countries by ISO code
ksort($countriesData);

// Generate JSON file
$jsonOutput = json_encode($countriesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($jsonOutput === false) {
    echo "Error encoding JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

file_put_contents('countries.json', $jsonOutput);

echo "Generated countries.json with " . count($countriesData) . " countries\n";

// Generate regions
echo "\nGenerating regions...\n";

// Create regions directory if it doesn't exist
if (!is_dir('regions')) {
    mkdir('regions', 0755, true);
}

// Get all country codes that we processed
$processedCountries = array_keys($countriesData);
$regionsData = [];

// Process each intl locale for subdivisions
foreach ($allIntlLocales as $locale) {
    echo "Processing subdivisions for locale: $locale\n";
    
    try {
        // Create translation driver (no cache directory)
        $translationDriver = new SymfonyTranslationDriver(null);
        
        // Set the locale - Symfony will handle fallback automatically
        $translationDriver->setLocale($locale);
        
        // Create ISO codes factory with the translation driver
        $factory = new IsoCodesFactory(null, $translationDriver);
        $subdivisions = $factory->getSubdivisions();
        
        // Get all subdivisions and their translations
        $hasTranslations = false;
        foreach ($subdivisions as $subdivision) {
            $code = $subdivision->getCode();
            $countryCode = substr($code, 0, 2); // First 2 characters are country code
            $regionCode = substr($code, 3); // After the hyphen
            
            // Only process countries that we have in our countries data
            if (!in_array($countryCode, $processedCountries)) {
                continue;
            }
            
            // Initialize country regions if not exists
            if (!isset($regionsData[$countryCode])) {
                $regionsData[$countryCode] = [];
            }
            
            // Initialize region entry if not exists
            if (!isset($regionsData[$countryCode][$regionCode])) {
                $regionsData[$countryCode][$regionCode] = [];
            }

            $localizedName = $subdivision->getLocalName();
            if ($localizedName) {
                $regionsData[$countryCode][$regionCode][$locale] = $localizedName;
                $hasTranslations = true;
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Error processing subdivisions for locale $locale: " . $e->getMessage() . "\n";
        continue;
    }
}

// Generate individual country region files
foreach ($regionsData as $countryCode => $regions) {
    // Sort regions by code
    ksort($regions);
    
    $regionJsonOutput = json_encode($regions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($regionJsonOutput === false) {
        echo "Error encoding JSON for country $countryCode: " . json_last_error_msg() . "\n";
        continue;
    }
    
    file_put_contents("regions/$countryCode.json", $regionJsonOutput);
    echo "Generated regions/$countryCode.json with " . count($regions) . " regions\n";
}

echo "\nGenerated region files for " . count($regionsData) . " countries\n";
