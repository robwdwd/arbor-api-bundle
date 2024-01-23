# ArborAPI Symfony Bundle

Symfony Bundle for interfacing with the Arbor Sightline APIs.

## What is the ArborAPI Bundle?

ArborAPI is a Symfony bundle to interface with Arbor Sightline deployments using REST, Web Services or SOAP.

## Features

ArborAPI supports the following:

- Support for Arbor REST API as a service.
- Support for Arbor Web services API as a service.
- Support for Arbor SOAP API as a service.
- Optional caching of Sightline responses.
- Currently testing with Arbor SP 9.5 but should work with most 9.x versions and above.

## Requirements

ArborAPI PHP Class requires the following:

- PHP 8.1 or higher
- symfony/http-client
- symfony/cache
- ext/dom
- ext/soap

## Installation

Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Applications that use Symfony Flex

Open a command console, enter your project directory and execute:

```console
composer require robwdwd/arbor-api-bundle
```

### Applications that don't use Symfony Flex

#### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
composer require arbor-api-bundle
```

#### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Robwdwd\ArborApiBundle\ArborApiBundle::class => ['all' => true],
];
```

## Configuration

Configuration is done in config/packages/robwdwd_arbor_api.yaml although this can be any filename.

```yaml
arbor_api:
    hostname:   '%env(string:ARBOR_HOSTNAME)%'
    wskey:      '%env(string:ARBOR_WS_KEY)%'
    resttoken:  '%env(string:ARBOR_REST_TOKEN)%'
    username:   '%env(string:ARBOR_SOAP_USERNAME)%'
    password:   '%env(string:ARBOR_SOAP_PASSWORD)%'
    wsdl:       '%env(string:ARBOR_SOAP_WSDL)%'
    cache:      true
    cache_ttl:  300
```

Then in your .env.local (or any other Environment file you wish to use this in) add the following

```ini
ARBOR_HOSTNAME="sp.example.com"
ARBOR_WS_KEY="pieWoojiekoo2oozooneeThi"
ARBOR_REST_TOKEN="Yohmeishuongoh0goeYu9haeph9goh8oogovaeth"
ARBOR_SOAP_USERNAME="user"
ARBOR_SOAP_PASSWORD="Password1234"
ARBOR_SOAP_WSDL="PeakflowSP.wsdl"
```

## Caching

By default the bundle does not cache the responses from Sightline/SP. Setting cache to to true in the
configuration will cache the responses in the cache.app pool. By default it caches the response for
five minutes (300 seconds). You can change this with the cache_ttl config setting.

You can turn on and off the cache in the current instance using the `setShouldCache(bool)` function.
`$restApi->setShouldCache(false)`

If you are using the filesystem cache on your symfony application you will need to manually prune the cache
to remove stale entries from time to time. You can set this up as a cron job.

```console
php bin/console cache:pool:prune
```

## Usage

[Web Services and SOAP](doc/webservices_soap.md)

[REST API](doc/rest.md)
  