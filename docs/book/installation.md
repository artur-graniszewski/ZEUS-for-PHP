# Requirements

### OS requirements
- Linux/Unix/BSD platform
- _Windows platform currently not supported_

### PHP requirements
- PHP 7.0+ or HHVM
- Posix module installed and enabled
- Pcntl module installed and enabled
- socket functions enabled for IPC purposes

### Library requirements
- Zend Framework 3+ application (with the following modules installed: `zend-mvc`, `zend-mvc-console`, `zend-console`, `zend-log`, `zend-config`)
- Opis library (`opis/closure`)

# Installation

ZEUS for PHP is available on [GitHub](https://github.com/artur-graniszewski/ZEUS-for-PHP) and can be installed in two different ways:

## Downloading

### via Composer: 

```
user@host:/var/www/$ cd zf3-application-directory
user@host:/var/www/zf3-application-directory$ composer require zeus-server/zf3-server
```

### by downloading source code

Latest stable source codes can be found in the following [ZIP archive](https://github.com/artur-graniszewski/ZEUS-for-PHP/archive/master.zip) .

After downloading, contents of the compressed `ZEUS-for-PHP-master` directory in ZIP file must be unpacked into a ZF3 `zf3-application-directory/module/Zeus` directory.

## Enabling ZEUS module

After installation, ZEUS for PHP must be activated in Zend Framework's `config/modules.config.php` file, like so:

```php
<?php 
// contents of "zf3-application-directory/config/modules.config.php" file:

return [
    'Zend\\Log',
    'Zend\\Mvc\\Console',
    '...',
    'Zeus' // this line should be added
];
```

This can be achieved either by modifying configuration file in any text editor, or by issuing `sed` command in Application's root directory:
```
user@host:/var/www/zf3-application-directory$ sed -i "s/'Zend\\\Log',/'Zend\\\Log','Zeus',/g" config/modules.config.php
```

If ZEUS for PHP is installed correctly, the following terminal command will show ZEUS version and its services in console:

```
user@host:/var/www/zf3-application-directory$ php public/index.php zeus status
```