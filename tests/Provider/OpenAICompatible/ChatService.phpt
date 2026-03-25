<?php declare(strict_types=1);

use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use AIAccess\Provider\OpenAICompatible\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


function reply(): array
{
	return ['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']]];
}


test('talks to the configured base url with a bearer token', function () {
	$http = (new FakeHttpClient)->queue(reply());
	$client = new Client('secret', 'https://openrouter.ai/api/v1', $http);

	$chat = $client->createChat('meta/llama-4');
	$chat->addMessage('Hello', Role::User);
	$response = $chat->sendMessage();

	Assert::same('Hi', $response->getText());

	$request = $http->lastRequest();
	Assert::same('https://openrouter.ai/api/v1/chat/completions', $request['url']);
	Assert::same('Bearer secret', $request['headers']['Authorization']);
	Assert::same('meta/llama-4', $http->lastPayload()['model']);
});


test('auth header and extra headers are configurable', function () {
	$http = (new FakeHttpClient)->queue(reply());
	$client = new Client('azure-key', 'https://res.openai.azure.com/openai/v1', $http);
	$client->setOptions(authHeader: 'api-key', authPrefix: '', extraHeaders: ['HTTP-Referer' => 'https://example.com']);

	$client->createChat('gpt-5.6-luna')->sendMessage('Hello');

	$headers = $http->lastRequest()['headers'];
	Assert::same('azure-key', $headers['api-key']);
	Assert::same('https://example.com', $headers['HTTP-Referer']);
	Assert::false(isset($headers['Authorization']));
});


test('an empty key means no auth header at all, as local servers expect', function () {
	$http = (new FakeHttpClient)->queue(reply());
	$client = new Client('', 'http://localhost:11434/v1', $http);

	$client->createChat('llama3.2')->sendMessage('Hello');

	Assert::same([], $http->lastRequest()['headers']);
});


test('custom options are merged into the payload', function () {
	$http = (new FakeHttpClient)->queue(reply());
	$client = new Client('k', 'https://api.mistral.ai/v1', $http);

	$chat = $client->createChat('mistral-large');
	$chat->setOptions(maxOutputTokens: 100, custom: ['safe_prompt' => true]);
	$chat->setEffort(Effort::Low);
	$chat->sendMessage('Hello');

	$payload = $http->lastPayload();
	Assert::same(100, $payload['max_tokens']);
	Assert::true($payload['safe_prompt']);
	Assert::same('low', $payload['reasoning_effort']);
});
