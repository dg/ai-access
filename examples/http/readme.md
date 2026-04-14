# The HTTP layer

Every client takes an `Http\Client` as its second argument. The default is
`CurlClient`, but anything implementing the interface will do, and three
decorators ship with the library. They compose, so you can stack them.

```shell
php examples/http/retry.php claude
php examples/http/logging.php openai
```

## retry.php

Rate limits and overloads are routine with AI APIs, and both are worth
retrying. `RetryClient` does it with exponential backoff and jitter, honours a
`Retry-After` header when the provider sends one, and gives up after three
attempts by default.

```php
$client = new AIAccess\Provider\Claude\Client($apiKey, new AIAccess\Http\RetryClient(
	new AIAccess\Http\CurlClient,
));
```

It only retries what is safe to retry: 408, 429, 5xx and network failures that
happened before any response arrived. A 400 or a 401 comes back untouched,
because trying again would fail the same way.

## logging.php

`ObservableClient` reports each request and each response with the time it
took. Useful for a debug bar, for cost accounting, or for finding out which
call is the slow one.

Note what the callback does not get: the headers. They carry the API key, and
a logger is exactly the place where a key should never end up.

## Caching during development

`CachingClient` writes responses to disk and replays them for identical
requests. Re-running a script while you fix the parsing around it then costs
nothing and returns instantly.

```php
$http = new AIAccess\Http\CachingClient(new AIAccess\Http\CurlClient, __DIR__ . '/cache');
```

This is for development and tests. In production a model that answers exactly
the same thing every time is a bug, not a feature, so there is no example for
it here.

## Timeouts and proxies

`CurlClient` itself takes the settings you would expect. The default request
timeout is three minutes, which reasoning models genuinely need:

```php
$http = (new AIAccess\Http\CurlClient)->setOptions(
	connectTimeout: 5,
	requestTimeout: 600,
	proxy: 'http://proxy.local:3128',
);
```
