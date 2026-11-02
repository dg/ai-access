<?php declare(strict_types=1);

use AIAccess\Chat\Tool;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


test('create sends the content and the instruction and returns the name', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'cachedContents/abc']);
	$name = (new Client('key', $http))->createCachedContent(
		'Long pages',
		systemInstruction: 'Be brief',
		ttl: 600,
		model: 'gemini-3.8-flash',
	);

	Assert::same('cachedContents/abc', $name);
	Assert::same('https://generativelanguage.googleapis.com/v1beta/cachedContents', $http->lastRequest()['url']);
	Assert::same([
		'model' => 'models/gemini-3.8-flash',
		'contents' => [['role' => 'user', 'parts' => [['text' => 'Long pages']]]],
		'ttl' => '600s',
		'systemInstruction' => ['parts' => [['text' => 'Be brief']]],
	], $http->lastPayload());
});


test('create takes the default chat model and leaves out a missing instruction', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'cachedContents/abc']);
	(new Client('key', $http, chatModel: 'gemini-3.8-flash'))->createCachedContent('Long pages');

	Assert::same('models/gemini-3.8-flash', $http->lastPayload()['model']);
	Assert::same('3600s', $http->lastPayload()['ttl']);
	Assert::false(isset($http->lastPayload()['systemInstruction']));
});


test('create without any model is a mistake of the caller', function () {
	Assert::exception(
		fn() => (new Client('key', new FakeHttpClient))->createCachedContent('Long pages'),
		AIAccess\LogicException::class,
		'No chat model given and the client has no default one.',
	);
});


test('create fails when the answer names nothing', function () {
	$http = (new FakeHttpClient)->queue([]);
	Assert::exception(
		fn() => (new Client('key', $http, chatModel: 'gemini-3.8-flash'))->createCachedContent('Long pages'),
		AIAccess\UnexpectedResponseException::class,
		'Missing or invalid name in API response.',
	);
});


test('delete addresses the content by the DELETE method', function () {
	$http = (new FakeHttpClient)->queue([]);
	(new Client('key', $http))->deleteCachedContent('cachedContents/abc');

	Assert::same('https://generativelanguage.googleapis.com/v1beta/cachedContents/abc', $http->lastRequest()['url']);
	Assert::same('DELETE', $http->lastRequest()['method']);
});


test('a chat names the content in its request', function () {
	$http = (new FakeHttpClient)->queue(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]]);
	$chat = (new Client('key', $http))->createChat('gemini-3.8-flash')->setCachedContent('cachedContents/abc');

	Assert::same('OK', $chat->sendMessage('Hi')->getText());
	Assert::same('cachedContents/abc', $http->lastPayload()['cachedContent']);
	Assert::false(isset($http->lastPayload()['systemInstruction']));
});


test('a chat refuses an instruction or tools next to the content', function () {
	$client = new Client('key', new FakeHttpClient);
	$message = 'A system instruction and tools belong into the cached content, the API refuses them next to it.';

	$chat = $client->createChat('gemini-3.8-flash')->setCachedContent('cachedContents/abc')->setSystemInstruction('Be brief');
	Assert::exception(fn() => $chat->sendMessage('Hi'), AIAccess\LogicException::class, $message);

	$chat = $client->createChat('gemini-3.8-flash')->setCachedContent('cachedContents/abc');
	$chat->addTool(new Tool('lookup', 'Looks up', [], fn() => 'found'));
	Assert::exception(fn() => $chat->sendMessage('Hi'), AIAccess\LogicException::class, $message);
});
