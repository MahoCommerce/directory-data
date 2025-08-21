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

// Also add locales from translation directories (like @latin variants)
$translationBasePath = 'vendor/sokil/php-isocodes-db-i18n/messages';
if (is_dir($translationBasePath)) {
    $dirs = scandir($translationBasePath);
    foreach ($dirs as $dir) {
        if ($dir !== '.' && $dir !== '..' && is_dir($translationBasePath . '/' . $dir)) {
            // Add special locales with @ suffix that aren't in intl
            if (strpos($dir, '@') !== false && !in_array($dir, $allIntlLocales)) {
                $allIntlLocales[] = $dir;
            }
        }
    }
}

// Sort all locales alphabetically
sort($allIntlLocales);

echo "Total locales to process (including @ variants): " . count($allIntlLocales) . "\n";

$countriesData = [];

// First, process en to establish the base names
echo "Processing base locale: en\n";
try {
    $translationDriver = new SymfonyTranslationDriver(null);
    $translationDriver->setLocale('en');
    $factory = new IsoCodesFactory(null, $translationDriver);
    $countries = $factory->getCountries();
    
    foreach ($countries as $country) {
        $alpha2 = $country->getAlpha2();
        $countriesData[$alpha2] = [];
        $countriesData[$alpha2]['en'] = $country->getLocalName() ?: $country->getName();
    }
    echo "Processed " . count($countriesData) . " countries for en\n";
} catch (Exception $e) {
    echo "  ✗ Error processing en: " . $e->getMessage() . "\n";
    exit(1);
}

// Process all other locales
foreach ($allIntlLocales as $locale) {
    if ($locale === 'en') {
        continue; // Skip, already processed
    }
    
    // Skip locales with @ suffix (script variants like sr@latin, tt@iqtelif)
    if (strpos($locale, '@') !== false) {
        continue;
    }
    
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
        foreach ($countries as $country) {
            $alpha2 = $country->getAlpha2();
            
            // Skip if country not in our base data
            if (!isset($countriesData[$alpha2])) {
                continue;
            }
            
            // Get the localized name
            $localizedName = $country->getLocalName();
            $enName = $countriesData[$alpha2]['en'];
            
            // Skip if translation is same as en (no point in storing duplicate)
            if (!$localizedName || $localizedName === $enName) {
                continue;
            }
            
            // Check if this is a variant locale (e.g., de_AT, de_CH)
            $languageCode = strstr($locale, '_', true); // Get language part (e.g., 'de' from 'de_AT')
            
            // If it's a variant locale, check if base language already exists with same translation
            if ($languageCode && isset($countriesData[$alpha2][$languageCode])) {
                // Skip if the variant has the same translation as the base language
                if ($localizedName === $countriesData[$alpha2][$languageCode]) {
                    continue; // Skip this variant
                }
            }
            
            $countriesData[$alpha2][$locale] = $localizedName;
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

// First, process en subdivisions to establish the base names
echo "Processing base subdivisions for locale: en\n";
try {
    $translationDriver = new SymfonyTranslationDriver(null);
    $translationDriver->setLocale('en');
    $factory = new IsoCodesFactory(null, $translationDriver);
    $subdivisions = $factory->getSubdivisions();
    
    // Define type hierarchy from most general to most specific (based on Maho's approach)
    $typeHierarchy = [
        // Most general
        'Country', 'Nation',
        'Region', 'Autonomous region',
        'State', 'Territory', 'Union territory', 'Federal territory',
        'Autonomous community', 'Autonomous province',
        'Governorate', 'Prefecture', 'Federal district',
        'Province', 'Department', 'County',
        'District', 'Canton', 'Division',
        'Municipality', 'Metropolitan city', 'City',
        'Free municipal consortium', 'Decentralized regional entity',
        'Commune', 'Parish', 'Borough',
        // Most specific
    ];
    
    // Create a map of type to hierarchy level
    $typeScore = array_flip($typeHierarchy);
    
    // Group subdivisions by country and analyze types
    $subdivisionsByCountry = [];
    foreach ($subdivisions as $subdivision) {
        $countryCode = substr($subdivision->getCode(), 0, 2);
        if (!in_array($countryCode, $processedCountries)) {
            continue;
        }
        if (!isset($subdivisionsByCountry[$countryCode])) {
            $subdivisionsByCountry[$countryCode] = [];
        }
        $subdivisionsByCountry[$countryCode][] = $subdivision;
    }
    
    // For each country, select the most appropriate subdivision level
    foreach ($subdivisionsByCountry as $countryCode => $countrySubdivisions) {
        // Count subdivisions by type
        $typeCounts = [];
        foreach ($countrySubdivisions as $subdivision) {
            $type = $subdivision->getType();
            if (!isset($typeCounts[$type])) {
                $typeCounts[$type] = 0;
            }
            $typeCounts[$type]++;
        }
        
        // Find the most specific type with significant coverage (>10 subdivisions or >40% of total)
        $totalCount = count($countrySubdivisions);
        $selectedTypes = [];
        
        // Sort types by specificity (highest score first)
        $scoredTypes = [];
        foreach ($typeCounts as $type => $count) {
            $score = isset($typeScore[$type]) ? $typeScore[$type] : 999;
            $scoredTypes[] = ['type' => $type, 'count' => $count, 'score' => $score];
        }
        usort($scoredTypes, function($a, $b) {
            return $b['score'] - $a['score']; // Higher score = more specific
        });
        
        // Define shipping-relevant subdivision types by country
        // Based on: UPU addressing standards, ISO 19160, e-commerce platforms (Magento/PrestaShop), and postal services
        // These are the administrative levels typically used for shipping/postal addresses
        $shippingTypes = [
            // European countries - typically use provinces/regions for shipping
            'IT' => ['Province', 'Metropolitan city', 'Free municipal consortium', 'Decentralized regional entity', 'Autonomous province'],
            'DE' => ['District', 'Urban district'], // Germany uses Kreise (districts) for postal codes
            'FR' => ['Department'], // France uses departments
            'ES' => ['Province'], // Spain uses provinces
            'GB' => ['Country'], // UK uses constituent countries (England, Scotland, Wales, N.Ireland)
            'NL' => ['Province'], // Netherlands uses provinces
            'BE' => ['Province'], // Belgium uses provinces
            'CH' => ['Canton'], // Switzerland uses cantons
            'AT' => ['State'], // Austria uses states (Bundesländer)
            'PL' => ['Voivodeship'], // Poland uses voivodeships
            'SE' => ['County'], // Sweden uses counties
            'DK' => ['Region'], // Denmark uses regions
            'NO' => ['County'], // Norway uses counties
            'FI' => ['Region'], // Finland uses regions
            
            // North America - typically use states/provinces
            'US' => ['State'], // USA uses states for shipping
            'CA' => ['Province', 'Territory'], // Canada uses provinces and territories
            'MX' => ['State'], // Mexico uses states
            
            // Other major countries
            'AU' => ['State', 'Territory'], // Australia uses states and territories
            'IN' => ['State', 'Union territory'], // India uses states
            'CN' => ['Province', 'Autonomous region', 'Municipality', 'Special administrative region'], // China
            'JP' => ['Prefecture'], // Japan uses prefectures
            'KR' => ['Province', 'Metropolitan city', 'Special city'], // South Korea
            'BR' => ['State'], // Brazil uses states
            'AR' => ['Province'], // Argentina uses provinces
            'ZA' => ['Province'], // South Africa uses provinces
            'RU' => ['Federal subject'], // Russia - complex but federal subjects are main level
        ];
        
        if (isset($shippingTypes[$countryCode])) {
            // Use predefined shipping-relevant types for this country
            $allowedTypes = $shippingTypes[$countryCode];
            foreach ($typeCounts as $type => $count) {
                if (in_array($type, $allowedTypes) && $count > 0) {
                    $selectedTypes[] = $type;
                }
            }
            // Log if we have unexpected types not in our predefined list
            $unexpectedTypes = array_diff(array_keys($typeCounts), $allowedTypes);
            if (!empty($unexpectedTypes)) {
                echo "  Note: $countryCode has additional types: " . implode(', ', $unexpectedTypes) . "\n";
            }
        }
        
        // If no predefined types found, fall back to smart selection
        if (empty($selectedTypes)) {
            echo "  Unknown country $countryCode - types available: " . implode(', ', array_keys($typeCounts)) . "\n";
            // For unknown countries, select the middle administrative level
            // Skip very general (regions/states with <20 subdivisions) and very specific (municipalities)
            $skipGeneral = ['Region', 'Autonomous region', 'Country', 'Nation'];
            $skipSpecific = ['Municipality', 'City', 'Commune', 'Parish', 'Borough', 'Town'];
            
            foreach ($scoredTypes as $typeInfo) {
                if (!in_array($typeInfo['type'], $skipGeneral) && 
                    !in_array($typeInfo['type'], $skipSpecific) &&
                    $typeInfo['count'] >= 3) {
                    $selectedTypes[] = $typeInfo['type'];
                }
            }
            
            // If still nothing, take the most common type
            if (empty($selectedTypes)) {
                $maxCount = max($typeCounts);
                foreach ($typeCounts as $type => $count) {
                    if ($count === $maxCount) {
                        $selectedTypes[] = $type;
                        break;
                    }
                }
            }
        }
        
        // Process subdivisions of selected types
        foreach ($countrySubdivisions as $subdivision) {
            if (!in_array($subdivision->getType(), $selectedTypes)) {
                continue;
            }
            
            $code = $subdivision->getCode();
            $regionCode = substr($code, 3); // After the hyphen
            
            // Initialize country regions if not exists
            if (!isset($regionsData[$countryCode])) {
                $regionsData[$countryCode] = [];
            }
            
            // Initialize region entry if not exists
            if (!isset($regionsData[$countryCode][$regionCode])) {
                $regionsData[$countryCode][$regionCode] = [];
            }
            
            $regionsData[$countryCode][$regionCode]['en'] = $subdivision->getLocalName() ?: $subdivision->getName();
        }
    }
    echo "Processed subdivisions for en\n";
} catch (Exception $e) {
    echo "  ✗ Error processing en subdivisions: " . $e->getMessage() . "\n";
}

// Process all other locales for subdivisions
foreach ($allIntlLocales as $locale) {
    if ($locale === 'en') {
        continue; // Skip, already processed
    }
    
    // Skip locales with @ suffix (script variants like sr@latin, tt@iqtelif)
    if (strpos($locale, '@') !== false) {
        continue;
    }
    
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
        foreach ($subdivisions as $subdivision) {
            $code = $subdivision->getCode();
            $countryCode = substr($code, 0, 2); // First 2 characters are country code
            $regionCode = substr($code, 3); // After the hyphen
            
            // Skip if region not in our base data
            if (!isset($regionsData[$countryCode][$regionCode])) {
                continue;
            }

            $localizedName = $subdivision->getLocalName();
            $enName = $regionsData[$countryCode][$regionCode]['en'];
            
            // Skip if translation is same as en (no point in storing duplicate)
            if (!$localizedName || $localizedName === $enName) {
                continue;
            }
            
            // Check if this is a variant locale (e.g., de_AT, de_CH)
            $languageCode = strstr($locale, '_', true); // Get language part (e.g., 'de' from 'de_AT')
            
            // If it's a variant locale, check if base language already exists with same translation
            if ($languageCode && isset($regionsData[$countryCode][$regionCode][$languageCode])) {
                // Skip if the variant has the same translation as the base language
                if ($localizedName === $regionsData[$countryCode][$regionCode][$languageCode]) {
                    continue; // Skip this variant
                }
            }
            
            $regionsData[$countryCode][$regionCode][$locale] = $localizedName;
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
