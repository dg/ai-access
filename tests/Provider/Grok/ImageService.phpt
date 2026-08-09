<?php declare(strict_types=1);

use AIAccess\Media;
use AIAccess\Provider\Grok\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


/** a real 1x1 PNG, so the mime sniffing has something to work with */
function onePixelPng(): string
{
	return (string) base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		strict: true,
	);
}


test('generateImage asks for base64 and reads the format out of the bytes', function () {
	$png = onePixelPng();
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => base64_encode($png)]]]);

	$image = (new Client('key', $http))->generateImage('grok-imagine-image', 'a red fox');

	Assert::same($png, $image->getData());
	// the response carries no mime type at all
	Assert::same('image/png', $image->getMimeType());

	Assert::same('https://api.x.ai/v1/images/generations', $http->lastRequest()['url']);
	Assert::same('a red fox', $http->lastPayload()['prompt']);
	Assert::same('b64_json', $http->lastPayload()['response_format']);
	Assert::same(1, $http->lastPayload()['n']);
});


test('the xAI options travel with the prompt', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => base64_encode(onePixelPng())]]]);

	(new Client('key', $http))->generateImage('grok-imagine-image', 'a red fox', aspectRatio: '16:9', resolution: '2k');

	$payload = $http->lastPayload();
	Assert::same('16:9', $payload['aspect_ratio']);
	Assert::same('2k', $payload['resolution']);
	// one picture is what the method promises, so it asks for one
	Assert::same(1, $payload['n']);
});


test('references are refused before the request leaves', function () {
	$client = new Client('key', new FakeHttpClient);

	Assert::exception(
		fn() => $client->generateImage('grok-imagine-image', 'a fox', [Media::fromBinary('x', 'image/png')]),
		AIAccess\LogicException::class,
		'Grok image generation does not accept reference images.',
	);
});


test('data that is not base64 is reported', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['b64_json' => 'not!base64']]]);

	Assert::exception(
		fn() => (new Client('key', $http))->generateImage('grok-imagine-image', 'a fox'),
		AIAccess\UnexpectedResponseException::class,
		'Image data is not valid base64.',
	);
});
