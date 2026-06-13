<?php declare(strict_types=1);

/**
 * Seeing what goes out and how long it takes.
 *
 * Demonstrates: Http\ObservableClient
 * Providers:    all
 * Usage:        php examples/http/logging.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Http;

$client = createClient(http: new Http\ObservableClient(
	new Http\CurlClient,
	// headers are deliberately not offered here: they carry the API key
	onRequest: function (string $url, mixed $payload): void {
		printf("-> %s (%d bytes)\n", $url, strlen((string) json_encode($payload)));
	},
	onResponse: function (Http\Response $response, float $elapsed): void {
		printf("<- HTTP %d in %.2f s\n", $response->getStatusCode(), $elapsed);
	},
	// a transfer that produced no response has nothing for onResponse to describe
	onError: function (Throwable $e, float $elapsed): void {
		printf("!! %s after %.2f s\n", $e->getMessage(), $elapsed);
	},
));

echo "\n", $client->createChat(chatModel($client))->sendMessage('Say hello.')->getText(), "\n";
