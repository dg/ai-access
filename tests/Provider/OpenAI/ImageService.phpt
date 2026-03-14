<?php declare(strict_types=1);

use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


test('generateImage decodes the returned image', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => base64_encode('PNGDATA')]]]);
	$client = new Client('key', $http);

	$image = $client->generateImage('gpt-image-2', 'a cat', size: '1024x1024');

	Assert::same('PNGDATA', $image->getData());
	Assert::same('image/png', $image->getMimeType());

	$request = $http->lastRequest();
	Assert::same('https://api.openai.com/v1/images/generations', $request['url']);
	Assert::same('a cat', $http->lastPayload()['prompt']);
	Assert::same('1024x1024', $http->lastPayload()['size']);
});


test('references switch to the edits endpoint', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => base64_encode('EDITED')]]]);
	$client = new Client('key', $http);

	$image = $client->generateImage('gpt-image-2', 'winter', [AIAccess\Media::fromBinary('x', 'image/png')]);

	Assert::same('EDITED', $image->getData());
	Assert::same('https://api.openai.com/v1/images/edits', $http->lastRequest()['url']);
	Assert::type(AIAccess\Http\FormData::class, $http->lastRequest()['payload']);
});


test('unsupported reference type is rejected before the call', function () {
	$client = new Client('key', new FakeHttpClient);

	Assert::exception(
		fn() => $client->generateImage('gpt-image-2', 'winter', [AIAccess\Media::fromBinary('x', 'image/bmp')]),
		AIAccess\LogicException::class,
	);
});


test('a malformed response is reported', function () {
	$http = (new FakeHttpClient)->queue(['data' => []]);
	$client = new Client('key', $http);

	Assert::exception(
		fn() => $client->generateImage('gpt-image-2', 'a cat'),
		AIAccess\UnexpectedResponseException::class,
	);
});
