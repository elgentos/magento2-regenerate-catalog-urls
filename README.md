# What does it do
This extension adds console commands to be able to regenerate;

- a product rewrite URL based on its url path;
- a category rewrite URL based on its url path;
- a category URL path based on its URL key and its parent categories.

# Install
Using Composer;

```sh
composer require elgentos/regenerate-catalog-urls
php bin/magento module:enable Iazel_RegenProductUrl
php bin/magento setup:upgrade
```

Or download and copy the `Iazel` directory into `app/code/`, enable the module and run `php bin/magento setup:upgrade`.

# How to use
```
Usage:
 regenerate:product:url [-s|--store="..."] [pids1] ... [pidsN]
 regenerate:category:url [-s]--store="..."] [cids1] ... [cidsN]
 regenerate:category:path [-s]--store="..."] [cids1] ... [cidsN]

Arguments:
 pids                  Products to regenerate
 cids                  Categories to regenerate

Options:
 --store (-s)          Use the specific Store View (default: 0)
 --help (-h)           Display this help message
```

Eg:
```sh
# Regenerate url for all products and the global store
php bin/magento regenerate:product:url

# Regenerate url for products with id (1, 2, 3, 4) for store 1
php bin/magento regenerate:product:url -s1 1 2 3 4
```
