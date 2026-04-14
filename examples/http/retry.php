<?php declare(strict_types=1);

/**
 * Surviving rate limits and overloads without writing the loop yourself.
 *
 * Demonstrates: Http\RetryClient
 * Providers:    all
 * Usage:        php examples/http/retry.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Http;

$attempts = 0;
$client = createClient(http: new Http\RetryClient(
	new Http\ObservableClient(new Http\CurlClient, onRequest: function () use (&$attempts): void {
		$attempts++;
	}),
	maxAttempts: 5,
));

echo $client->createChat(chatModel($client))->sendMessage('Say hello.')->getText(), "\n";
echo "HTTP requests made: $attempts\n";
