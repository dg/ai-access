<?php declare(strict_types=1);

use AIAccess\Batch\Status;
use AIAccess\Chat\Role;
use AIAccess\Media;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


test('image requests ride in the ordinary batch, they just ask for pictures', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'batches/abc']);
	$batch = (new Client('key', $http))->createBatch();
	$batch->addImageRequest('hero', 'A red fox', 'gemini-3.1-flash-image')
		->setOptions(aspectRatio: '16:9', imageSize: '2K');
	$batch->addImageRequest('thumb', 'A red fox, small', 'gemini-3.1-flash-image')
		->addReference(Media::fromBinary('REF', 'image/png'));

	$response = $batch->submit();

	Assert::same(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:batchGenerateContent',
		$http->lastRequest()['url'],
	);

	$requests = $http->lastPayload()['batch']['inputConfig']['requests']['requests'];
	Assert::count(2, $requests);
	Assert::same('hero', $requests[0]['metadata']['key']);
	Assert::same('models/gemini-3.1-flash-image', $requests[0]['request']['model']);
	Assert::same('A red fox', $requests[0]['request']['contents'][0]['parts'][0]['text']);

	$config = $requests[0]['request']['generationConfig'];
	Assert::same(['IMAGE'], $config['responseModalities']);
	Assert::same(['aspectRatio' => '16:9', 'imageSize' => '2K'], $config['imageConfig']);

	// a reference is an ordinary content part here, exactly as in a chat
	Assert::same(base64_encode('REF'), $requests[1]['request']['contents'][0]['parts'][1]['inlineData']['data']);

	Assert::same('batches/abc', $response->getId());
});


test('text and pictures share one job, because Gemini draws through the chat endpoint', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'batches/abc']);
	$batch = (new Client('key', $http))->createBatch();
	$batch->addChat('question', 'gemini-3.1-flash-image')->addMessage('Describe a fox.', Role::User);
	$batch->addImageRequest('drawing', 'A red fox', 'gemini-3.1-flash-image');

	$batch->submit();

	$requests = $http->lastPayload()['batch']['inputConfig']['requests']['requests'];
	Assert::count(2, $requests);
	// only the image request asks for a picture back, the chat one is untouched
	Assert::false(isset($requests[0]['request']['generationConfig']['responseModalities']));
	Assert::same(['IMAGE'], $requests[1]['request']['generationConfig']['responseModalities']);
});


test('one job is one model, whatever the requests are', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addImageRequest('a', 'A fox', 'gemini-3.1-flash-image');

	Assert::exception(
		fn() => $batch->addChat('b', 'gemini-3-pro-image'),
		AIAccess\LogicException::class,
		'%a%single model%a%',
	);
});


test('the finished job hands the pictures over as messages', function () {
	$http = (new FakeHttpClient)->queue([
		'name' => 'batches/abc',
		'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED'],
		'response' => ['inlinedResponses' => ['inlinedResponses' => [
			[
				'metadata' => ['key' => 'hero'],
				'response' => ['candidates' => [['content' => ['parts' => [
					['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('JPEGDATA')]],
				]]]]],
			],
			[
				'metadata' => ['key' => 'broken'],
				'error' => ['code' => 400, 'message' => 'Prompt was blocked'],
			],
		]]],
	]);

	$response = (new Client('key', $http))->retrieveBatch('batches/abc');

	Assert::same(Status::Completed, $response->getStatus());
	$results = iterator_to_array($response->getResults());
	$media = $results['hero']->message->getMedia();
	Assert::count(1, $media);
	Assert::same('JPEGDATA', $media[0]->getData());
	Assert::same('image/jpeg', $media[0]->getMimeType());
	Assert::same('Prompt was blocked', $results['broken']->error);
});
