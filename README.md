# Utopia Pools

[![Build Status](https://travis-ci.org/utopia-php/abuse.svg?branch=master)](https://travis-ci.com/utopia-php/pools)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/pools.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia pools library is simple and lite library for managing long living connection pools. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/pools
```
## Examples


## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Tests

To run all unit tests, use the following Docker command:

```bash
docker-compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run static code analysis, use the following Psalm command:

```bash
docker-compose exec tests vendor/bin/psalm --show-info=true
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
