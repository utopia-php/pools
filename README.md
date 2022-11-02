# Utopia Pools

[![Build Status](https://travis-ci.org/utopia-php/pools.svg?branch=main)](https://travis-ci.com/utopia-php/pools)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/pools.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia pools library is simple and lite library for managing long living connection pools. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Concepts

* **Pool** - A list of long living connections. You can pop connections out and use them and push them back to the pool for reuse.
* **Connection** - An object that holds a long living database or other external connection in a form of a resource. PDO object or a Redis client are examples of resources that can be used inside a connection.
* **Group** - A group of multiple pools.

## Getting Started

Install using composer:
```bash
composer require utopia-php/pools
```
## Examples

```php
use PDO;
use Utopia\Pools\Pool;
use Utopia\Pools\Group;

$pool = new Pool('mysql-pool', 1 /* number of connections */, function() {
    $host = '127.0.0.1';
    $db   = 'test';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }

    return $pdo;
});

$pool->setReconnectAttempts(3); // number of attempts to reconnect
$pool->setReconnectSleep(5); // seconds to sleep between reconnect attempts

$connection = $pool->pop(); // Get a connection from the pool
$connection->getID(); // Get the connection ID
$connection->getResource(); // Get the connection resource

$pool->push($connection); // Return the connection to the pool

$pool->reclaim(); // Recalim the pool, return all active connections automatically

$pool->count(); // Get the number of available connections

$pool->isEmpty(); // Check if the pool is empty

$pool->isFull(); // Check if the pool is full

$group = new Group(); // Create a group of pools
$group->add($pool); // Add a pool to the group
$group->get('mysql-pool'); // Get a pool from the group
$group->setReconnectAttempts(3); // Set the number of reconnect attempts for all pools
$group->setReconnectSleep(5); // Set the sleep time between reconnect attempts for all pools
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run static code analysis, use the following Psalm command:

```bash
docker compose exec tests vendor/bin/psalm --show-info=true
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
