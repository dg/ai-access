<?php declare(strict_types=1);

use AIAccess\Media;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


function imageResponse(string $binary, string $mime = 'image/png'): array
{
	return ['candidates' => [['content' => ['parts' => [
		['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($binary)]],
	]]]]];
}


test('an image model is asked through the chat endpoint', function () {
	$http = (new FakeHttpClient)->queue(imageResponse('PNGDATA'));
	$image = (new Client('key', $http))->generateImage('gemini-3.1-flash-image', 'a red circle');

	Assert::same('PNGDATA', $image->getData());
	Assert::same('image/png', $image->getMimeType());

	// no dedicated image endpoint exists here, unlike OpenAI and xAI
	Assert::same(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent',
		$http->lastRequest()['url'],
	);
	Assert::same(['IMAGE'], $http->lastPayload()['generationConfig']['responseModalities']);
	Assert::same('a red circle', $http->lastPayload()['contents'][0]['parts'][0]['text']);
});


test('references ride along as ordinary content parts', function () {
	$http = (new FakeHttpClient)->queue(imageResponse('EDITED', 'image/jpeg'));
	$client = new Client('key', $http);

	$image = $client->generateImage('gemini-3.1-flash-image', 'make it winter', [
		Media::fromBinary('REF', 'image/png'),
	]);

	Assert::same('EDITED', $image->getData());
	Assert::same('image/jpeg', $image->getMimeType());

	$parts = $http->lastPayload()['contents'][0]['parts'];
	Assert::same('make it winter', $parts[0]['text']);
	Assert::same(base64_encode('REF'), $parts[1]['inlineData']['data']);
});


test('a text-only answer means the model refused to draw', function () {
	$http = (new FakeHttpClient)->queue([
		'candidates' => [[
			'content' => ['parts' => [['text' => 'I cannot create that image.']]],
			'finishReason' => 'IMAGE_SAFETY',
		]],
	]);

	Assert::exception(
		fn() => (new Client('key', $http))->generateImage('gemini-3.1-flash-image', 'something forbidden'),
		AIAccess\UnexpectedResponseException::class,
		'No image in the response, finish reason IMAGE_SAFETY.',
	);
});


test('data that is not base64 is reported', function () {
	$http = (new FakeHttpClient)->queue(['candidates' => [['content' => ['parts' => [
		['inlineData' => ['mimeType' => 'image/png', 'data' => 'not!base64']],
	]]]]]);

	Assert::exception(
		fn() => (new Client('key', $http))->generateImage('gemini-3.1-flash-image', 'a circle'),
		AIAccess\UnexpectedResponseException::class,
		'Image data is not valid base64.',
	);
});
