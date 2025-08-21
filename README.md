# Directory Data - Multilingual Countries & Regions

The most complete database of countries and their administrative subdivisions (provinces, states, regions) with translations in all available locales - **perfect for international e-commerce and shipping applications**.

## 🌍 What This Provides

### Complete Coverage
- **249 countries** with ISO 3166-1 codes
- **Administrative subdivisions** for shipping (provinces, states, departments, etc.)
- **Multilingual translations** in 100+ locales
- **Shipping-optimized** subdivision levels (provinces for Italy, states for USA, etc.)

### Smart Data Selection
- Uses **shipping-relevant administrative levels** for each country
- Automatically selects the appropriate subdivision type (provinces vs municipalities, states vs counties)
- Based on **postal addressing standards** and **e-commerce requirements**
- Eliminates redundant translations and maintains clean, efficient data

## 📁 Generated Files

### `countries.json`
Complete list of countries with 2-character ISO codes:
```json
{
  "IT": {
    "en": "Italy",
    "it_IT": "Italia",
    "fr_FR": "Italie",
    "de_DE": "Italien",
    "es_ES": "Italia"
  }
}
```

### `regions/{COUNTRY}.json`
Administrative subdivisions for each country:
```json
{
  "TO": {
    "en": "Turin", 
    "it_IT": "Torino",
    "fr_FR": "Turin"
  },
  "MI": {
    "en": "Milan",
    "it_IT": "Milano", 
    "fr_FR": "Milan"
  }
}
```

### Translation Lookup Logic
When using these files, follow this fallback pattern to find the best translation:

1. **Look for your exact locale** (e.g., `it_IT` for Italian-Italy)
2. **Fall back to the language code** if not found (e.g., `it`)  
3. **Use `"en"` as the final fallback** (always present)

This approach ensures you always get the most appropriate translation while keeping file sizes minimal by eliminating redundant entries.

## 🔄 Automated Updates

This repository is updated automatically every week.

### Data Attribution

The underlying country and subdivision data is derived from:
- **[Debian's iso-codes project](https://salsa.debian.org/iso-codes-team/iso-codes)** (LGPL-2.1+)
- **[PHP ISO Codes libraries](https://github.com/sokil/php-isocodes)** (MIT)
