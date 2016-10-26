# Install
Sorry guys but for now you have to download and copy the `Iazel` directory into `app/code/`.
Then call:
```sh
php bin/magento setup:upgrade
```

# How to use
```
Usage:
 iazel:regenurl [-s|--store="..."] [pids1] ... [pidsN]

Arguments:
 pids                  Products to regenerate (default: [])

Options:
 --store (-s)          Use the specific Store View (default: 0)
 --help (-h)           Display this help message
```

Eg:
```sh
# Regenerate url for products with id (1, 2, 3, 4) of store 1
php bin/magento iazel:regenurl -s1 1 2 3 4
```
