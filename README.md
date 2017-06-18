# PHP Heroku Client
[![Latest Version](https://img.shields.io/github/release/TransitScreen/php-heroku-client.svg?style=flat-square)](https://github.com/TransitScreen/php-heroku-client/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Build Status](https://img.shields.io/travis/TransitScreen/php-heroku-client.svg?style=flat-square)](https://travis-ci.org/TransitScreen/php-heroku-client)
[![Quality Score](https://img.shields.io/scrutinizer/g/TransitScreen/php-heroku-client.svg?style=flat-square)](https://scrutinizer-ci.com/g/TransitScreen/php-heroku-client)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/TransitScreen/php-heroku-client.svg?style=flat-square)](https://scrutinizer-ci.com/g/TransitScreen/php-heroku-client)

A PHP client for the Heroku Platform API, similar to [platform-api](https://github.com/heroku/platform-api) for Ruby and [node-heroku-client](https://github.com/heroku/node-heroku-client) for Node.js. With it you can create and alter Heroku apps, install or remove add-ons, scale resources up and down, and use any other capabilities documented by the [Platform API Reference](https://devcenter.heroku.com/articles/platform-api-reference).

## Features
- Reads `HEROKU_API_KEY` for zero-config use
- Exposes response headers—necessary for some API functionality
- Use the default cURL-based HTTP client or provide your own
- Pass cURL options and custom request headers
- Informative error handling for authentication, JSON, and HTTP errors
- Coded to PSR-7 (Request/Response) and HTTPlug (HttpClient) interfaces

## Requirements
- PHP 5.6+
- cURL (unless providing your own HTTP client)

## Installation
```
$ composer require php-heroku-client/php-heroku-client
```

## Quick start
Instantiate the client:
```php
require_once __DIR__ . '/vendor/autoload.php';

use HerokuClient\Client as HerokuClient;

$heroku = new HerokuClient([
    'apiKey' => 'my-api-key', // Or set the HEROKU_API_KEY environmental variable
]);
```
Find out how many web dynos are currently running:
```php
$currentDynos = $heroku->get('apps/my-heroku-app-name/formation/web')->quantity;
```
Scale up:
```php
// patch() and post() accept an array or object as a body.
$heroku->patch(
    'apps/my-heroku-app-name/formation/web',
    ['quantity' => $currentDynos + 1]
);
```
Find out how many more calls we can make in the near future:
```php
// Underlying PSR-7 Request and Response objects are available for header inspection and general debugging.
$remainingCalls = $heroku->getLastHttpResponse()->getHeaderLine('RateLimit-Remaining');
```

## Configuration
The client can be configured at instantiation with these settings, all of which are optional and have sane defaults:
```php
new HerokuClient([
    'apiKey' => 'my-api-key',                 // If not set, the client finds HEROKU_API_KEY or fails
    'baseUrl' => 'http://custom.base.url/',   // Defaults to https://api.heroku.com/
    'httpClient' => $myFavoriteHttpClient,    // Any client implementing HTTPlug's HttpClient interface
    'curlOptions' => [                        // Options can be set when using the default HTTP client
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'My Agent',
        ...
    ],
]);
```

## Making calls
Make calls using the client's `get()`, `delete()`, `head()`, `patch()`, and `post()` methods. The first argument is always the required `string` path and the last is always an optional `array` of custom headers. `patch()` and `post()` take an `array` or `object` body as the second argument.
- See the [Quick Start](#quick-start) for examples with and without a body.
- See the full [Platform API Reference](https://devcenter.heroku.com/articles/platform-api-reference) for a list of all endpoints and their responses.

## Using headers
The Heroku API uses headers as a separate channel for [range](https://devcenter.heroku.com/articles/platform-api-reference#ranges), [rate limit](https://devcenter.heroku.com/articles/platform-api-reference#rate-limits), and [caching](https://devcenter.heroku.com/articles/platform-api-reference#caching) information. You can read and send headers like this:
```php
$page1 = $heroku->get('apps');

// 206 Partial Content means there are more records to get.
if ($heroku->getLastHttpResponse()->getStatusCode() == 206) {
    $nextRange = $heroku->getLastHttpResponse()->getHeaderLine('Next-Range');
    $page2 = $heroku->get('apps', ['Range' => $nextRange]);
}
```

## Exceptions thrown
- `BadHttpStatusException`
- `JsonDecodingException`
- `JsonEncodingException`
- `MissingApiKeyException`

## Contributing
Pull Requests are welcome. Consider filing an issue first to discuss your needs/plans. Running `vendor/bin/phpunit` will run all tests. This project follows [the PSR-2 coding standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md), so contributions should do likewise.
