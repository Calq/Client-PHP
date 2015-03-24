Calq PHP Client
=================

The full quick start and reference docs can be found at: https://www.calq.io/docs/client/php

Installation
------------

###Using composer (recommended)

Install the [latest release](https://packagist.org/packages/calq/calq) of the library through Composer with the following command:

```bash
composer require calq/calq
```

###Old-style copy-and-paste

Grab the [latest release](https://github.com/Calq/Client-PHP/releases) and add it to your project.

You will need to include the CalqClient library where you intend to use it. The library requires PHP 5.2 or higher.

```php
require_once("/path/to/Client-PHP/lib/CalqClient.php");
```

Getting a client instance
-------------------------

The easiest way to get an instance of the client is to use the static `CalqClient::fromCurrentRequest` method. This will create a client using any cookie already data set for the current web request. If the current user has never been seen before the client will remember them in future by writing a cookie to the response.

You will need to add your Calq project's write key where it says YOUR_WRITE_KEY_HERE. You can find your write key from the project settings option inside Calq.

```php
// Get an instance populated from the current request
$calq = CalqClient::fromCurrentRequest('YOUR_WRITE_KEY_HERE');
```

The PHP client is compatible with the JavaScript client. Any properties set client side using JavaScript will be read by the PHP client when using the `CalqClient::fromCurrentRequest` method. Likewise any properties set server side will be persisted to a cookie to be read browser side.

Tracking actions
----------------

Calq performs analytics based on actions that user's take. You can track an action using `track`. Specify the action and any associated data you wish to record.

```php
// Track a new action called 'Product Review' with a custom rating
$calq->track('Product Review', array('Rating' => 9.0));
```

The array parameter allows you to send additional custom data about the action. This extra data can be used to make advanced queries within Calq.

Documentation
-------------

The full quick start can be found at: https://www.calq.io/docs/client/php

The reference can be found at:  https://www.calq.io/docs/client/php/reference

License
--------

[Licensed under the Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0).




