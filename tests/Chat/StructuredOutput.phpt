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


test('the generic client shares the dialect shape, DeepSeek refuses it', function () use ($schema) {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{}']]]]);

	(new Provider\OpenAICompatible\Client('k', 'https://x/v1', $http))->createChat('m')
		->setResponseSchema($schema)->sendMessage('x');
	Assert::same($schema, $http->lastPayload()['response_format']['json_schema']['schema']);

	// measured: a json_schema format is answered with "This response_format type is unavailable now"
	Assert::exception(
		fn() => (new Provider\DeepSeek\Client('k', $http))->createChat('m')->setResponseSchema($schema),
		AIAccess\LogicException::class,
		"DeepSeek has no JSON schema; use setOptions(responseFormat: ['type' => 'json_object']) for its JSON mode.",
	);
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


test('the schema and the raw option are one setting, so the later call wins', function () {
	// both write response_format; before, the schema won regardless of the order
	$schemaThenRaw = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{}']]]]);
	(new Provider\Grok\Client('k', $schemaThenRaw))->createChat('m')
		->setResponseSchema(['type' => 'object'])
		->setOptions(responseFormat: ['type' => 'json_object'])
		->sendMessage('x');

	Assert::same(['type' => 'json_object'], $schemaThenRaw->lastPayload()['response_format']);

	$rawThenSchema = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{}']]]]);
	(new Provider\Grok\Client('k', $rawThenSchema))->createChat('m')
		->setOptions(responseFormat: ['type' => 'json_object'])
		->setResponseSchema(['type' => 'object'])
		->sendMessage('x');

	Assert::same('json_schema', $rawThenSchema->lastPayload()['response_format']['type']);
});
