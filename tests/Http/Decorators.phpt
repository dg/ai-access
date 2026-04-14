<?php declare(strict_types=1);

use AIAccess\CommunicationException;
use AIAccess\Http\CachingClient;
use AIAccess\Http\ObservableClient;
use AIAccess\Http\Response;
use AIAccess\Http\RetryClient;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


/**
 * Deliberately not getTempDir(): that directory has a garbage collector any test
 * process can trigger, and a cache purged mid-run is indistinguishable from a miss.
 */
/** Stands in for the gadget a tampered cache file would try to build. */
class CacheGadget
{
	public static bool $woken = false;


	public function __wakeup(): void
	{
		self::$woken = true;
	}
}


function cacheDir(string $name): string
{
	static $dir = null;
	if ($dir === null) {
		$dir = sys_get_temp_dir() . '/aiaccess-http-cache-' . getmypid();
		Tester\Helpers::purge($dir);
	}
	return $dir . '/' . $name;
}


test('retries a rate limit and returns the successful response', function () {
	$slept = [];
	$http = (new FakeHttpClient)
		->queue(['error' => 'slow down'], 429)
		->queue(['ok' => true]);

	$client = new RetryClient($http, sleep: function ($s) use (&$slept) { $slept[] = $s; });
	$response = $client->fetch('https://api.example.com/x', ['a' => 1]);

	Assert::same(['ok' => true], $response->getData());
	Assert::count(2, $http->requests);
	Assert::count(1, $slept);
});


test('honours retry-after', function () {
	$slept = [];
	$http = (new FakeHttpClient)
		->queue([], 429, ['retry-after' => ['7']])
		->queue(['ok' => true]);

	(new RetryClient($http, sleep: function ($s) use (&$slept) { $slept[] = $s; }))
		->fetch('https://api.example.com/x');

	Assert::same([7.0], $slept);
});


test('gives up after maxAttempts and returns the last response', function () {
	$http = (new FakeHttpClient)->queue([], 500)->queue([], 500)->queue([], 500);

	$response = (new RetryClient($http, maxAttempts: 3, sleep: fn() => null))
		->fetch('https://api.example.com/x');

	Assert::same(500, $response->getStatusCode());
	Assert::count(3, $http->requests);
});


test('does not retry a client error', function () {
	$http = (new FakeHttpClient)->queue([], 400);

	$response = (new RetryClient($http, sleep: fn() => null))->fetch('https://api.example.com/x');

	Assert::same(400, $response->getStatusCode());
	Assert::count(1, $http->requests);
});


test('retries a network failure', function () {
	$http = new class extends FakeHttpClient {
		public int $calls = 0;


		public function fetch(
		    string $url,
		    string|array|AIAccess\Http\FormData|null $payload = null,
		    array $headers = [],
		    ?string $method = null,
		): Response
		{
			$this->calls++;
			if ($this->calls === 1) {
				throw new CommunicationException('connection reset');
			}
			return new Response(200, [], ['ok' => true]);
		}
	};

	$response = (new RetryClient($http, sleep: fn() => null))->fetch('https://api.example.com/x');

	Assert::same(['ok' => true], $response->getData());
	Assert::same(2, $http->calls);
});


test('observes requests without leaking headers', function () {
	$seen = [];
	$http = (new FakeHttpClient)->queue(['ok' => true]);

	$client = new ObservableClient(
		$http,
		onRequest: function ($url, $payload) use (&$seen) {
			$seen['url'] = $url;
			$seen['payload'] = $payload;
		},
		onResponse: function (Response $r, float $elapsed) use (&$seen) {
			$seen['status'] = $r->getStatusCode();
			$seen['elapsed'] = $elapsed;
		},
	);
	$client->fetch('https://api.example.com/x', ['a' => 1], ['Authorization' => 'Bearer secret']);

	Assert::same('https://api.example.com/x', $seen['url']);
	Assert::same(['a' => 1], $seen['payload']);
	Assert::same(200, $seen['status']);
	Assert::true($seen['elapsed'] >= 0);
});


test('serves an identical request from the cache', function () {
	$http = (new FakeHttpClient)->queue(['answer' => 42]);
	$client = new CachingClient($http, cacheDir('http-cache'));

	$first = $client->fetch('https://api.example.com/x', ['q' => 'a']);
	$second = $client->fetch('https://api.example.com/x', ['q' => 'a']);

	Assert::same(['answer' => 42], $first->getData());
	Assert::same(['answer' => 42], $second->getData());
	Assert::count(1, $http->requests); // the second call never reached the network
});


test('a different payload is a different entry, and errors are not cached', function () {
	$http = (new FakeHttpClient)->queue(['a' => 1])->queue(['b' => 2])->queue([], 500)->queue(['c' => 3]);
	$client = new CachingClient($http, cacheDir('http-cache2'));

	Assert::same(['a' => 1], $client->fetch('https://x', ['q' => 'a'])->getData());
	Assert::same(['b' => 2], $client->fetch('https://x', ['q' => 'b'])->getData());
	Assert::same(500, $client->fetch('https://x', ['q' => 'c'])->getStatusCode());
	Assert::same(['c' => 3], $client->fetch('https://x', ['q' => 'c'])->getData());
});


test('a negative retry-after from a confused proxy is clamped, not slept', function () {
	$slept = [];
	$http = (new FakeHttpClient)
		->queue([], 429, ['retry-after' => ['-5']])
		->queue(['ok' => true]);

	(new RetryClient($http, sleep: function ($s) use (&$slept) { $slept[] = $s; }))
		->fetch('https://api.example.com/x');

	Assert::same([0.0], $slept);
});


test('501 is the server saying never, so it is not retried', function () {
	$http = (new FakeHttpClient)->queue([], 501);

	$response = (new RetryClient($http, sleep: fn() => null))->fetch('https://api.example.com/x');

	Assert::same(501, $response->getStatusCode());
	Assert::count(1, $http->requests);
});


test('the cache key tells apart requests differing only in a beta header', function () {
	$http = (new FakeHttpClient)
		->queue(['answer' => 'plain'])
		->queue(['answer' => 'beta']);
	$client = new CachingClient($http, cacheDir('cache-headers'));

	$plain = $client->fetch('https://api.example.com/x', ['q' => 1], ['x-api-key' => 'secret']);
	$beta = $client->fetch('https://api.example.com/x', ['q' => 1], ['x-api-key' => 'secret', 'anthropic-beta' => 'files-api']);
	$plainAgain = $client->fetch('https://api.example.com/x', ['q' => 1], ['x-api-key' => 'other']);

	Assert::same(['answer' => 'plain'], $plain->getData());
	Assert::same(['answer' => 'beta'], $beta->getData());
	// the API key stays out of the key, so this one is a cache hit
	Assert::same(['answer' => 'plain'], $plainAgain->getData());
	Assert::count(2, $http->requests);
});


test('a tampered cache file is a miss, and nothing it names gets built', function () {
	$dir = cacheDir('cache-tampered');
	$http = (new FakeHttpClient)->queue(['answer' => 'first'])->queue(['answer' => 'second']);
	$client = new CachingClient($http, $dir);

	$client->fetch('https://api.example.com/x', ['q' => 1]);
	$files = glob($dir . '/*.cache');
	Assert::count(1, $files);

	// a class named by the file must not be constructed, or a gadget chain starts here
	file_put_contents($files[0], serialize(new CacheGadget));
	$response = $client->fetch('https://api.example.com/x', ['q' => 1]);

	Assert::false(CacheGadget::$woken);
	Assert::same(['answer' => 'second'], $response->getData());
	Assert::count(2, $http->requests);
});
