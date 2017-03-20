# Changelog

## Version 1.4.0
- [Feature] Added new Memcache Server Service with `zend-cache` storage adapters support.
- [Fix] Major performance regression fix for ZEUS Web Server (bug introduced in version 1.3.6)
- [Improvement] Documentation improvements and enhancements

## Version 1.3.7
- [Fix] Various fixes to ZEUS Web Server and Scheduler configuration classes based on static code analysis

## Version 1.3.6
- [Fix] Scheduler ignored custom configuration and used default `LruDiscipline` exclusively
- [Fix] Typo in constructor's name of ProcessTitle class, method removed as it was never executed.
- [Fix] Due to broken gzcompress() function in Facebook HHVM, ZEUS Web Server will now use `deflate` rather than `gzip` to compress HTTP responses 
- [Improvement] Lots of code improvements based on static code analysis, minor performance tweaks
- [Improvement] Better handling of runtime exceptions in ZEUS Web Server
- [Improvement] Changelog extracted from the main README file

## Version 1.3.5
- [Feature] Added `stop` and `stop <service>` CLI commands to ZEUS
- [Feature] Added various sanity checks to IPC adapters
- [Feature] Application exists with appropriate error code when it detects that all of its Server Services have been shut-down externally

## Version 1.3.4
- [Feature] Implemented Scheduler Disciplines functionality
- [Feature] Extracted LRU Discipline from Scheduler core
- [Fix] Scheduler was too aggressive in its calculations of number of spare processes to create
- [Fix] Documentation fixes (added missing namespaces in configuration examples)
- [Tests improvements] Improved code coverage

## Version 1.3.3
- [Tests improvements] Improved code coverage
- [Improvement] Documentation improvements and enhancements

## Version 1.3.2
- [Fix] Quickfix for potential Task Pool exhaustion issue when using slow HTTP keep-alive connections
- [Fix] POSIX Process MPM now uses `SchedulerEvent` just like the rest of ZEUS Scheduler's code
- [Fix] Scheduler Status View console command now properly shows status of Processes in TERMINATED state

## Version 1.3.1
- [Fix] Configured Travis builds to not to use phpunit 6.x 

## Version 1.3.0
- [Feature] Heavy refactoring of `Scheduler` events
- [Feature] Improved `Process` and `Scheduler` life cycle.
- [Feature] New `SchedulerEvent` introduced.
- [Feature] Now its possible to provide text description along the process status
- [Feature] Console Server Service status command shows extended status descriptions
- [Feature] From now on each HTTP keep-alive request handled by ZEUS Web Server will reduce the TTL of entire process.

## Version 1.2.3
- [Feature] Introduced `ON_PROCESS_CREATED` event to `Scheduler`
- [Feature] Now its possible to intercept `ON_SCHEDULER_STOP` event before `exit()`
- [Fix] Fixed various `ON_SCHEDULER_STOP` event triggering inconsistencies
- [Fix] Added `ext-pcntl` to Composer as required PHP extension
- [Tests improvements] Improved code coverage + dead code removed

## Version 1.2.2
- [Feature] Added `StreamLogFormatter` and basic strategy that chooses between `StreamLogFormatter` and `ConsoleLogFormatter` depending on stream type.
- [Fix] Added `zendframework/zend-console` as required Composer package
- [Tests improvements] Improved code coverage

## Version 1.2.1
- [Fix] Renamed status names reported by a Service Status command to be inline with those reported by Proces Title functionality

## Version 1.2.0
- [Feature] Added new commandline options `index.php zeus status` and `index.php zeus status <service_name>`
- [Fix] Fixed Scheduler's `ON_SERVER_START` and `ON_SCHEDULER_START` event triggering inconsistency
- [Fix] Refactor of `FixedCollection` iterator code for improved HHVM compatibility
- [Fix] Fixed request counter in ZEUS Web Server
- [Tests improvements] Improved code coverage

## Version 1.1.8
- [Feature] Added MIME type detection to ZEUS Web Server's `StaticFileDispatcher`
- [Tests improvements] Code coverage for `StaticFileDispatcher`
- [Fix] ZEUS Web Server returned 404 HTTP status code instead of 400 when attempting to list a directory
- [Fix] Fixed compatibility between `FixedCollection` and HHVM
- [Fix] Resolved issue wih invalid handling of first element in `FixedCollection`

## Version 1.1.7
- [Fix] Fixed read/write indexing in `ApcAdapter`
- [Fix] Performance fix in HTTP hosts cache (ZEUS Web Server)
- [Tests improvements] Code coverage tests moved from PHP 5.6 to 7.1
- [Tests improvements] Enabled APCu tests in Travis

## Version 1.1.6
- [Fix] Various fixes for IpcAdapters: `MsgAdapter`, `ApcAdapter`, `FifoAdapter`
- [Fix] Fixed permissions of some PHP files

## Version 1.1.5
- [Feature] New event `Zeus\Kernel\ProcessManager\SchedulerEvent::PROCESS_EXIT` introduced
- [Feature] Improved console help
- [Unclassified] Dead code removal, README tweaks
- [Tests improvements] More `Scheduler` tests added

## Version 1.1.4
- [Unit tests fix] Fix for division by zero error in PHP 5.6 unit tests in `ProcessTitle` class
- [Tests improvements] Added test class for Scheduler, increased tests code coverage
- [Fix] Fixed PHP 5.6 compatibility in `Scheduler` garbage collection mechanism 

## Version 1.1.3
- [Feature] Enabled travis build and improved phpunit configuration
- [Unit tests fix] Fix for failing phpunit tests due to the recent changes in ZEUS Web Server classes and interfaces
- [Security fix] Fixed ZEUS Web Server logger throwing fatal exception and leaving open connection when HTTP request was corrupted
- [Unclassified] Various `composer.json` fixes and tweaks

## Version 1.1.2
- [Composer fix] Corrected PSR4 installation path in `composer.json` file
- [Documentation] Updated road-map

## Version 1.1.1
- [Composer fix] Specified ZEUS license type in `composer.json`
- [Documentation fix] Fixed command syntax that installs ZEUS via Composer

## Version 1.1.0
- [Performance fix] ZEUS Web Server uses a custom React PHP `Buffer` implementation to overcome severe `fwrite` performance penalty when serving large files through a keep-alive connection
- [Security fix] ZEUS Web Server counts the number of keep-alive requests and closes connection when the requests limit is reached.
- [Feature] Implemented `IpcLoggerInterface` and `IpcLoggerWriter` to send logs through IPC to a Scheduler which will act as as a logger sink.
- [Feature] Service name is now reported by a built-in logger processor 

## Version 1.0.0
- Initial revision