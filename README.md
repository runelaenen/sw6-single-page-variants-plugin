# Single Page Variants

**Single Page Variants** is a Shopware 6 plugin that changes how URLs for product variants work. Instead of giving each variant its own SEO URL, only the main product has one.

When a user selects a variant on the product page, the plugin loads the new product data using AJAX. This means the page doesn’t reload, and the URL stays the same.

## Features

- Only the main (parent) product gets an SEO URL.
- Variants no longer have their own URLs.
- The product page updates using AJAX when switching between variants.
- Sitemap includes urls for parent pages, not for specific variant pages.
- Avoids SEO issues with multiple URLs for similar content.

## How It Works

- **Variant Switcher:**  
  The plugin changes the variant switcher (product configurator) so it uses AJAX to load the selected variant’s data without reloading the page.

- **Switch Route:**  
  The controller for the `switch` route is updated to return the full product detail HTML directly, instead of redirecting to a new variant URL.

- **URL Handling:**  
  Variant URLs are replaced with their parent product’s URL using a custom `SeoUrlPlaceholderHandler`.

- **No SEO URLs for Variants:**  
  The plugin stops Shopware from generating any SEO URLs for variants by extending the `ProductPagePageSeoUrlRoute`.

## When to Use This

- You want simpler, cleaner product URLs.
- You don’t want each variant to have its own page.
- You want a faster, more dynamic product page experience.

## Compatibility

- Shopware 6.6
- Not recommended if other plugins or custom code depend on variant-specific URLs. Your mileage may vary.

## Installation

You can install the plugin through the Shopware Administration, or use the command line:

```bash
composer require laenen/sw6-single-page-variants-plugin
bin/console plugin:install --activate LaenenSinglePageVariants