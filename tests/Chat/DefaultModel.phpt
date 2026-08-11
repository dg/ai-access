<?php declare(strict_types=1);

use AIAccess\LogicException;
use AIAccess\Provider;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


test('a client carrying a model creates chats without being told one', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$client = new Provider\Claude\Client('key', $http, chatModel: 'claude-sonnet-5');

	$client->createChat()->sendMessage('Q');

	Assert::same('claude-sonnet-5', $http->lastPayload()['model']);
});


test('the call still wins over the client default', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$client = new Provider\Claude\Client('key', $http, chatModel: 'claude-sonnet-5');

	$client->createChat('claude-opus-5')->sendMessage('Q');

	Assert::same('claude-opus-5', $http->lastPayload()['model']);
});


test('a client with no default says so instead of sending a nameless request', function () {
	$clients = [
		fn() => (new Provider\OpenAI\Client('key'))->createChat(),
		fn() => (new Provider\Claude\Client('key'))->createChat(),
		fn() => (new Provider\Gemini\Client('key'))->createChat(),
		fn() => (new Provider\DeepSeek\Client('key'))->createChat(),
		fn() => (new Provider\Grok\Client('key'))->createChat(),
		fn() => (new Provider\OpenAICompatible\Client('key', 'https://example.com'))->createChat(),
	];

	foreach ($clients as $create) {
		Assert::exception($create, LogicException::class, '%a%no default%a%');
	}
});


test('image and embedding defaults work the same way', function () {
	Assert::exception(
		fn() => (new Provider\OpenAI\Client('key'))->generateImage('a fox'),
		LogicException::class,
		'%a%image model%a%',
	);
	Assert::exception(
		fn() => (new Provider\OpenAI\Client('key'))->calculateEmbeddings(['text']),
		LogicException::class,
		'%a%embedding model%a%',
	);
});


test('the batch inherits the defaults from the client that made it', function () {
	$http = (new FakeHttpClient)->queue(['id' => 'batch-1', 'processing_status' => 'in_progress']);
	$batch = (new Provider\Claude\Client('key', $http, chatModel: 'claude-sonnet-5'))->createBatch();

	$batch->addChat('first')->addMessage('Q', AIAccess\Chat\Role::User);
	$batch->submit();

	Assert::same('claude-sonnet-5', $http->lastPayload()['requests'][0]['params']['model']);
});
