# Ccommand line options

Since version 1.3.5, the following commands are supported (assuming that Zend Framework's `index.php` application bootstrap file is in the project's `public` directory):

* `public/index.php zeus start` - Starts all ZEUS Server Services
* `public/index.php zeus start <service-name>` - Starts selected Server Service
* `public/index.php zeus list` - Lists all Server Services and their configuration
* `public/index.php zeus list <service-name>` - Shows the configuration of a selected Server Service
* `public/index.php zeus status` - Returns current status of all Server Services
* `public/index.php zeus status <service-name>` - Returns current status of the selected Server Service
* `public/index.php zeus stop` - Stops all ZEUS Server Services
* `public/index.php zeus stop <service-name>` - Stops selected Server Service