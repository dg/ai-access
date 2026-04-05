<?php declare(strict_types=1);

use AIAccess\Provider;
use AIAccess\UnexpectedResponseException;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


$schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']];


test('each provider sends the schema in its own shape', function () use ($schema) {
	$http = (new FakeHttpClient)
		->queue(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{}']]]]])
		->queue(['content' => [['type' => 'text', 'text' => '{}']]])
		->queue(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]])
		->queue(['choices' => [['message' => ['content' => '{}']]]]);

	(new Provider\OpenAI\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');
	Assert::same('json_schema', $http->lastPayload()['text']['format']['type']);
	Assert::same($schema, $http->lastPayload()['text']['format']['schema']);

	(new Provider\Claude\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');
	Assert::same($schema, $http->lastPayload()['output_config']['format']['schema']);

	(new Provider\Gemini\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');
	Assert::same('application/json', $http->lastPayload()['generationConfig']['responseMimeType']);
	Assert::same($schema, $http->lastPayload()['generationConfig']['responseJsonSchema']);

	(new Provider\Grok\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');
	Assert::same($schema, $http->lastPayload()['response_format']['json_schema']['schema']);
});


test('getJson decodes the answer', function () {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{"a":"b","n":[1,2]}']]]]);
	$response = (new Provider\Grok\Client('k', $http))->createChat('m')->sendMessage('x');

	Assert::same(['a' => 'b', 'n' => [1, 2]], $response->getJson());
});


test('getJson reports text that is not JSON, and keeps the cause', function () {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => 'sorry, no']]]]);
	$response = (new Provider\Grok\Client('k', $http))->createChat('m')->sendMessage('x');

	$e = Assert::exception(fn() => $response->getJson(), UnexpectedResponseException::class);
	Assert::type(JsonException::class, $e->getPrevious());
});


test('getJson returns null when there is no text', function () {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => null]]]]);
	$response = (new Provider\Grok\Client('k', $http))->createChat('m')->sendMessage('x');

	Assert::null($response->getJson());
});
