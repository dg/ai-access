<?php declare(strict_types=1);

use AIAccess\Batch\Status;
use AIAccess\Media;
use AIAccess\Provider\OpenAI\BatchResponse;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


/**
 * The JSONL the batch uploaded, one decoded line per request. It travels as a temporary
 * file rather than as a string, so the fake client captures it during the request.
 * @return mixed[]
 */
function uploadedLines(FakeHttpClient $http): array
{
	$content = $http->uploads[0]['file'] ?? throw new LogicException('Nothing was uploaded.');
	return array_map(fn($line) => json_decode($line, true), explode("\n", trim($content)));
}


test('every request becomes one JSONL line and the job declares the endpoint once', function () {
	$http = (new FakeHttpClient)->queue(['id' => 'file-1'])->queue(['id' => 'batch-1', 'status' => 'validating']);
	$batch = (new Client('key', $http))->createBatch();
	$batch->addImageRequest('gpt-image-2', 'hero', 'A red fox')->setOptions(size: '1536x1024', quality: 'high');
	$batch->addImageRequest('gpt-image-2', 'thumb', 'A red fox, small');

	$response = $batch->submit();

	$lines = uploadedLines($http);
	Assert::count(2, $lines);
	Assert::same('hero', $lines[0]['custom_id']);
	Assert::same('POST', $lines[0]['method']);
	Assert::same('/v1/images/generations', $lines[0]['url']);
	Assert::same('A red fox', $lines[0]['body']['prompt']);
	Assert::same('1536x1024', $lines[0]['body']['size']);
	Assert::same('high', $lines[0]['body']['quality']);
	Assert::same('thumb', $lines[1]['custom_id']);

	Assert::same('https://api.openai.com/v1/batches', $http->lastRequest()['url']);
	Assert::same('/v1/images/generations', $http->lastPayload()['endpoint']);
	Assert::same('file-1', $http->lastPayload()['input_file_id']);
	Assert::same('24h', $http->lastPayload()['completion_window']);
	Assert::same('batch-1', $response->getId());
});


test('references move the whole job to the edits endpoint', function () {
	$http = (new FakeHttpClient)->queue(['id' => 'file-1'])->queue(['id' => 'batch-1']);
	$batch = (new Client('key', $http))->createBatch();
	$batch->addImageRequest('gpt-image-2', 'winter', 'The same fox in snow')
		->addReference(Media::fromBinary('REF', 'image/png'));

	$batch->submit();

	// multipart cannot travel in a JSONL line, so the reference goes as a data URL
	$lines = uploadedLines($http);
	Assert::same('/v1/images/edits', $lines[0]['url']);
	Assert::same([['image_url' => 'data:image/png;base64,' . base64_encode('REF')]], $lines[0]['body']['images']);
	Assert::same('/v1/images/edits', $http->lastPayload()['endpoint']);
});


test('generating and editing cannot share one job', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addImageRequest('gpt-image-2', 'plain', 'A fox');
	$batch->addImageRequest('gpt-image-2', 'edited', 'The same fox')->addReference(Media::fromBinary('REF', 'image/png'));

	Assert::exception(
		fn() => $batch->submit(),
		AIAccess\LogicException::class,
		'%a%single endpoint%a%',
	);
});


test('pictures and chats cannot share one job either, for the same reason', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addChat('gpt-image-2', 'talk')->addMessage('Hello', AIAccess\Chat\Role::User);
	$batch->addImageRequest('gpt-image-2', 'draw', 'A fox');

	Assert::exception(
		fn() => $batch->submit(),
		AIAccess\LogicException::class,
		'%a%single endpoint%a%',
	);
});


test('one input file is one model', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addImageRequest('gpt-image-2', 'a', 'A fox');

	Assert::exception(
		fn() => $batch->addImageRequest('gpt-image-1-mini', 'b', 'A fox'),
		AIAccess\LogicException::class,
		'%a%single model%a%',
	);
});


test('an empty batch and a duplicate custom id are refused', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();

	Assert::exception(
		fn() => $batch->submit(),
		AIAccess\LogicException::class,
		'Cannot submit batch job: No requests added.',
	);

	$batch->addImageRequest('gpt-image-2', 'same', 'A fox');
	Assert::exception(
		fn() => $batch->addImageRequest('gpt-image-2', 'same', 'Another fox'),
		AIAccess\LogicException::class,
		"Request with custom ID 'same' already exists in this batch.",
	);
});


test('an unsupported reference type is rejected before the request is built', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();

	Assert::exception(
		fn() => $batch->addImageRequest('gpt-image-2', 'x', 'A fox')->addReference(Media::fromBinary('x', 'image/bmp')),
		AIAccess\LogicException::class,
		'Unsupported reference image type: image/bmp',
	);
});


test('results are messages carrying the pictures, keyed by custom id', function () {
	$batchData = [
		'id' => 'batch-1',
		'status' => 'completed',
		// the endpoint the job declared is what decides how a result line is read
		'endpoint' => '/v1/images/generations',
		'output_file_id' => 'file-out',
	];
	$jsonl = json_encode([
		'custom_id' => 'hero',
		'response' => ['status_code' => 200, 'body' => [
			'data' => [['b64_json' => base64_encode('PNGDATA')], ['b64_json' => base64_encode('SECOND')]],
			'output_format' => 'webp',
		]],
	]) . "\n" . json_encode([
		'custom_id' => 'broken',
		'response' => ['status_code' => 400, 'body' => ['error' => ['message' => 'Prompt was rejected']]],
	]);

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with('files/file-out/content')
		->andReturn(explode("\n", $jsonl));

	$response = new BatchResponse($clientMock, $batchData);

	Assert::same(Status::Completed, $response->getStatus());
	$results = iterator_to_array($response->getResults());
	Assert::same(AIAccess\Chat\Role::Model, $results['hero']->message->getRole());

	// n > 1 is simply more media parts in the one message
	$media = $results['hero']->message->getMedia();
	Assert::count(2, $media);
	Assert::same('PNGDATA', $media[0]->getData());
	Assert::same('SECOND', $media[1]->getData());

	// the mime type is the one the model answered with, not the one that was asked for
	Assert::same('image/webp', $media[0]->getMimeType());

	Assert::same('Prompt was rejected', $results['broken']->error);
});


test('a 200 carrying no picture is an error, not an empty message', function () {
	$batchData = [
		'id' => 'batch-1',
		'status' => 'completed',
		'endpoint' => '/v1/images/generations',
		'output_file_id' => 'file-out',
	];
	$jsonl = json_encode([
		'custom_id' => 'hero',
		'response' => ['status_code' => 200, 'body' => ['data' => [['url' => 'https://example.com/fox.png']]]],
	]);

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')->once()->andReturn([$jsonl]);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::null($results['hero']->message);
	Assert::same('No image data in the response.', $results['hero']->error);
});
