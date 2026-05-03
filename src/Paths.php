<?php

declare(strict_types=1);

namespace Maho\DirectoryData;

/**
 * Resolves filesystem paths to the data files shipped by this package, regardless
 * of how the consuming project configures Composer's vendor-dir. Consumers should
 * always go through this class rather than constructing paths to vendor/mahocommerce/...
 */
final class Paths
{
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function countriesFile(): string
    {
        return self::root() . '/countries.json';
    }

    public static function regionsDir(): string
    {
        return self::root() . '/regions';
    }

    public static function regionsFile(string $countryCode): string
    {
        return self::regionsDir() . '/' . strtoupper($countryCode) . '.json';
    }

    public static function formatsDir(): string
    {
        return self::root() . '/formats';
    }

    public static function formatsFile(string $countryCode): string
    {
        return self::formatsDir() . '/' . strtoupper($countryCode) . '.json';
    }
}
