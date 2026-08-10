<?php declare(strict_types=1);

use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


test('generateImage decodes the returned image', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => base64_encode('PNGDATA')]]]);
	$client = new Client('key', $http);

	$image = $client->generateImage('a cat', 'gpt-image-2', size: '1024x1024');

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

	$image = $client->generateImage('winter', 'gpt-image-2', [AIAccess\Media::fromBinary('x', 'image/png')]);

	Assert::same('EDITED', $image->getData());
	Assert::same('https://api.openai.com/v1/images/edits', $http->lastRequest()['url']);

	// references travel as data URLs in a JSON body: a batch line cannot carry a multipart
	// upload, and the endpoint takes the bytes this way just as well
	Assert::same(
		[['image_url' => 'data:image/png;base64,' . base64_encode('x')]],
		$http->lastPayload()['images'],
	);
});


test('unsupported reference type is rejected before the call', function () {
	$client = new Client('key', new FakeHttpClient);

	Assert::exception(
		fn() => $client->generateImage('winter', 'gpt-image-2', [AIAccess\Media::fromBinary('x', 'image/bmp')]),
		AIAccess\LogicException::class,
	);
});


test('a malformed response is reported', function () {
	$http = (new FakeHttpClient)->queue(['data' => []]);
	$client = new Client('key', $http);

	Assert::exception(
		fn() => $client->generateImage('a cat', 'gpt-image-2'),
		AIAccess\UnexpectedResponseException::class,
	);
});


test('every option the provider takes is a named argument', function () {
	$http = (new FakeHttpClient)->queue([
		'data' => [['b64_json' => base64_encode('WEBP')]],
		'output_format' => 'webp',
	]);

	$image = (new Client('key', $http))->generateImage(
		'a cat',
		'gpt-image-2',
		size: '1024x1536',
		quality: 'high',
		inputFidelity: 'high',
		moderation: 'low',
	);

	Assert::same('WEBP', $image->getData());
	Assert::same('image/webp', $image->getMimeType());

	$payload = $http->lastPayload();
	Assert::same('gpt-image-2', $payload['model']);
	Assert::same('1024x1536', $payload['size']);
	Assert::same('high', $payload['quality']);
	Assert::same('high', $payload['input_fidelity']);
	Assert::same('low', $payload['moderation']);
});
