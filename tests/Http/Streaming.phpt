<?php declare(strict_types=1);

use AIAccess\Http;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


test('chunks arrive one by one and the response carries no body', function () {
	$http = (new FakeHttpClient)->queueStream(['one ', 'two ', 'three']);

	$seen = [];
	$response = $http->fetch('https://x/stream', ['q' => 1], onChunk: function (string $chunk) use (&$seen) {
		$seen[] = $chunk;
	});

	Assert::same(['one ', 'two ', 'three'], $seen);
	Assert::same(200, $response->getStatusCode());
	Assert::null($response->getData());
	Assert::same(['q' => 1], $http->lastPayload());
});


test('returning false stops the transfer', function () {
	$http = (new FakeHttpClient)->queueStream(['one', 'two', 'three']);

	$seen = [];
	$http->fetch('https://x/stream', onChunk: function (string $chunk) use (&$seen) {
		$seen[] = $chunk;
		return count($seen) < 2;
	});

	Assert::same(['one', 'two'], $seen);
});


test('a failed stream delivers its body whole instead of in pieces', function () {
	// the contract CurlClient implements: an error response is a body to read, not a stream
	$http = (new FakeHttpClient)->queueStreamError(['error' => ['message' => 'model not found']], 404);

	$called = 0;
	$response = $http->fetch('https://x/stream', onChunk: function () use (&$called) {
		$called++;
	});

	Assert::same(0, $called);
	Assert::same(404, $response->getStatusCode());
	Assert::same('model not found', $response->getData()['error']['message']);
});


test('retry replays a stream that never started', function () {
	$http = new class extends FakeHttpClient {
		public int $attempts = 0;


		public function fetch(
			string $url,
			string|array|Http\FormData|null $payload = null,
			array $headers = [],
			?string $method = null,
			?Closure $onChunk = null,
		): Http\Response
		{
			$this->attempts++;
			if ($this->attempts < 3) {
				return new Http\Response(429, [], null);
			}
			$onChunk('finally');
			return new Http\Response(200, [], null);
		}
	};

	$delays = [];
	$retry = new Http\RetryClient($http, sleep: function (float $d) use (&$delays) {
		$delays[] = $d;
	});

	$text = '';
	$response = $retry->fetch('https://x/stream', onChunk: function (string $chunk) use (&$text) {
		$text .= $chunk;
	});

	Assert::same(3, $http->attempts);
	Assert::same('finally', $text);
	Assert::same(200, $response->getStatusCode());
	Assert::count(2, $delays);
});


test('retry keeps its hands off a stream that already started', function () {
	$http = new class extends FakeHttpClient {
		public int $attempts = 0;


		public function fetch(
			string $url,
			string|array|Http\FormData|null $payload = null,
			array $headers = [],
			?string $method = null,
			?Closure $onChunk = null,
		): Http\Response
		{
			$this->attempts++;
			$onChunk('half an answer');
			// the model spoke, then the connection died
			throw new AIAccess\CommunicationException('connection reset');
		}
	};

	$retry = new Http\RetryClient($http, sleep: fn() => null);

	// replaying now would bill a second answer and duplicate the text
	Assert::exception(
		fn() => $retry->fetch('https://x/stream', onChunk: fn() => null),
		AIAccess\CommunicationException::class,
	);
	Assert::same(1, $http->attempts);
});


test('the cache stays out of streaming', function () {
	$dir = getTempDir() . '/stream-cache';
	$http = (new FakeHttpClient)->queueStream(['a'])->queueStream(['b']);
	$caching = new Http\CachingClient($http, $dir);

	$first = $second = '';
	$caching->fetch('https://x/same', ['p' => 1], onChunk: function ($c) use (&$first) {
		$first .= $c;
	});
	$caching->fetch('https://x/same', ['p' => 1], onChunk: function ($c) use (&$second) {
		$second .= $c;
	});

	// an identical request twice, and the second one is not replayed from disk
	Assert::same('a', $first);
	Assert::same('b', $second);
	Assert::same([], glob($dir . '/*.cache'));
});


test('observation reports the whole stream', function () {
	$http = (new FakeHttpClient)->queueStream(['x', 'y']);
	$seen = null;
	$observable = new Http\ObservableClient($http, onResponse: function (Http\Response $r, float $elapsed) use (&$seen) {
		$seen = [$r->getStatusCode(), $elapsed];
	});

	$observable->fetch('https://x/stream', onChunk: fn() => null);

	Assert::same(200, $seen[0]);
	Assert::true($seen[1] >= 0);
});
