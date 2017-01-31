# Install
Download and copy the `Iazel` directory into `app/code/` or install using composer

```sh
composer require iazel/module-regen-product-url 
```

Then call:
```sh
php bin/magento setup:upgrade
```

# How to use
```
Usage:
 iazel:regenurl [-s|--store="..."] [pids1] ... [pidsN]

Arguments:
 pids                  Products to regenerate

Options:
 --store (-s)          Use the specific Store View (default: 0)
 --help (-h)           Display this help message
```

Eg:
```sh
# Regenerate url for all products and the global store
php bin/magento iazel:regenurl

# Regenerate url for products with id (1, 2, 3, 4) for store 1
php bin/magento iazel:regenurl -s1 1 2 3 4
```
