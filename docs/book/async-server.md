# Introduction

Since version 1.6.0, ZEUS comes equipped with Async Server Service and Controller Plugin. This set of components provides the ability to execute anonymous functions and closures asynchronously.

# Starting the Service

To enable this feature, additional ZEUS instance must be started with the following command:

`php public/index.php zeus start zeus_async`

# Using async plugin in Zend Framework controllers

After starting ZEUS Async Server Service every Zend Framework 3 controller can execute asynchronous code using the `async()` plugin, like so:

```php
<?php 
// contents of "zf3-application-directory/module/SomeModule/src/Controller/SomeController.php" file:

namespace Application\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use ZF\Apigility\Admin\Module as AdminModule;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        for ($i = 0; $i < 12; $i++) {
            // each run() command immediately starts one task in the background and returns a handle ID
            $handles[$i] = $this->async()->run(function () use ($i) {
                sleep($i);
                return "OK$i";
            });
        }

        // join() accepts either an array of handle IDs or a single handle ID (without array)
        // - in case of array of handles, join will return an array of results,
        // - in case of a single handler, join will return a single result (not wrapped into the array)
        $results = $this->async()->join($handles);

        // because of the sleep(11) executed in a last callback, join() command will wait up to 11 seconds to fetch
        // results from all the handles, on success $result variable will contain the following data:
        // ["OK0","OK1","OK2","OK3","OK4","OK5","OK6","OK7","OK8","OK9","OK10","OK11"]

        // please keep in mind that each handle can be joined independently and join() blocks until the slowest
        // callback returns the data, therefore running $this->async()->join($handles[3]) command instead
        // would block this controller only for 3 seconds

        // usual Zend Framework stuff to return data to the view layer
        $view = new ViewModel();
        $view->setVariable('async_results', $results);
    }
}
```

> **Please note:** 
> Asynchronous closures are running outside the scope of the main thread that started them, therefore its not possible to modify variables of the main thread directly in the closure.
> Closures should either return results on their exit (as seen in an example above), or communicate with a main thread using the IPC functionality.
