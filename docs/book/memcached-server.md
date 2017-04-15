# Introduction

ZEUS Memcache Server is a native PHP implementation of the Memcached distributed memory object caching system.

This service is highly integrated with Zend Framework caching mechanism, and allows to use any zend-cache compatible adapter (be it `APC`, `Filesystem`, `Memory` or custom ones) as distributed cache through the memcached protocol.

> **Please note:** 
> Clients of memcached must communicate with ZEUS Memcache Server Service through TCP connections using the memcached text protocol. 
>
> As of version 1.4.0, neither UDP interface nor memcached binary protocol are available. 

# Starting the Service

To run this service, the following requirements must be met:

- a `zendframework/zend-cache` component must be installed and enabled in Zend Application
- when using APC or APCu cache adapters, appropriate PHP extension must be installed and configured: `apc.enabled` and `apc.cli_enable` parameters in `php.ini` file must be set to `1`, and depending on the usage characteristics, `apc.shm_size` parameter should be set to a higher number such as `256M`.
- when using Filesystem based cache adapters, SSD or RAMDISK storage is highly recommended for performance reasons.

**Service will refuse to start if the cache preconditions are not met.**

This service is not enabled by default, but can be configured to auto-start with ZEUS if needed.

To start the service manually, following command must be executed:

`php public/index.php zeus start zeus_memcache`

*In its default configuration, ZEUS Memcache Server uses APCu adapter that is shipped with `zend-cache` component*. This adapter can be replaced anytime by modifying certain service configuration (see "ZEUS Configuration" section for details).

# Configuration

Different cache adapters may be provided through a Zend Framework `Zend\Cache\Service\StorageCacheAbstractServiceFactory` and its configuration files, like so:

```php
<?php 
// contents of "zf3-application-directory/config/some-config.config.php" file:

return [
    'caches' => [
        'custom_internal_cache' => [
            'adapter' => [
                'name'    => 'filesystem',
                'options' => [
                    'cache_dir' => '/tmp/'
                ]
            ],
        ],
        'custom_user_cache' => [
            'adapter' => [
                'name'    => 'apcu',
                'options' => [
                ]
            ],
        ]
    ],
    'zeus_process_manager' => [
        'services' => [
            'custom_memcache' => [
                'auto_start' => false,
                'service_name' => 'custom_memcache',
                'scheduler_name' => 'zeus_web_scheduler',
                'service_adapter' => \Zeus\ServerService\Memcache\Service::class,
                'service_settings' => [
                    'listen_port' => 11211,
                    'listen_address' => '0.0.0.0',
                    'server_cache' => 'custom_internal_cache',
                    'client_cache' => 'custom_user_cache',
                ],
            ]
        ]
    ]
];
```

The table below describes the `service_settings` configuration parameters:

| Parameter          | Required | Description                                                                                 |
|--------------------|:--------:|---------------------------------------------------------------------------------------------|
| `listen_port`      | yes      | The service listen port, 11211 is a default memcached port                                  |
| `listen_address`   | yes      | The service listen address, use 0.0.0.0 to listen on all available network addresses        |
| `server_cache`     | yes      | Name of the `zend-cache` instance. This cache is used for server needs, such as statistics  |
| `client_cache`     | yes      | Name of the `zend-cache` instance. This cache is used to store client cache entries         |

Please check Zend Framework `zend-cache` [documentation](https://framework.zend.com/manual/2.3/en/modules/zend.cache.storage.adapter.html) to read how to configure or implement your own cache instances.

To start such a service, the following command must be issued in a terminal:

```
user@host:/var/www/zf-application$ php public/index.php zeus start custom_memcache
```