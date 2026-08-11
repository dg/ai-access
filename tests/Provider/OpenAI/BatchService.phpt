<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Provider\OpenAI\Batch;
use AIAccess\Provider\OpenAI\BatchResponse;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


test('createBatch hands the default models over to the batch', function () {
	$batch = (new Client('key', chatModel: 'model-from-the-client'))->createBatch();

	Assert::type(Batch::class, $batch);
	// the batch asks for no model of its own, because the client already carries one
	Assert::type(AIAccess\Provider\OpenAI\Chat::class, $batch->addChat('id'));
});


test('listBatches follows the pages on its own', function () {
	$http = (new FakeHttpClient)
		->queue(['data' => [['id' => 'batch-1', 'status' => 'in_progress']], 'has_more' => true, 'last_id' => 'batch-1'])
		->queue(['data' => [['id' => 'batch-2', 'status' => 'completed']], 'has_more' => false]);

	$batches = iterator_to_array((new Client('key', $http))->listBatches(), preserve_keys: false);

	Assert::count(2, $batches);
	Assert::type(BatchResponse::class, $batches[0]);
	Assert::same('batch-1', $batches[0]->getId());
	Assert::same('batch-2', $batches[1]->getId());

	Assert::same(2, $http->count());
	Assert::contains('after=batch-1', $http->lastRequest()['url']);
});


test('the first page asks for no cursor at all', function () {
	$http = (new FakeHttpClient)->queue(['data' => [], 'has_more' => false]);

	iterator_to_array((new Client('key', $http))->listBatches());

	Assert::same('https://api.openai.com/v1/batches', $http->lastRequest()['url']);
});


test('Client retrieveBatch fetches specific batch by ID', function () {
	$batchId = 'batch-retrieve-123';

	$apiResponse = [
		'id' => $batchId,
		'status' => 'completed',
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("batches/{$batchId}")
		->andReturn($apiResponse);

	$batch = $clientMock->retrieveBatch($batchId);

	Assert::type(BatchResponse::class, $batch);
	Assert::same($batchId, $batch->getId());
});


test('Client cancelBatch sends cancellation request', function () {
	$batchId = 'batch-cancel-123';

	$apiResponse = [
		'id' => $batchId,
		'status' => 'cancelling',
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("batches/{$batchId}/cancel", [])
		->andReturn($apiResponse);

	$result = $clientMock->cancelBatch($batchId);

	Assert::true($result);
});


test('Client cancelBatch returns false when cancellation fails', function () {
	$batchId = 'batch-cancel-fail-123';

	$apiResponse = [
		'id' => $batchId,
		'status' => 'completed', // Not cancelling status
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("batches/{$batchId}/cancel", [])
		->andReturn($apiResponse);

	$result = $clientMock->cancelBatch($batchId);

	Assert::false($result);
});


test('submit validates JSONL content structure', function () {
	$modelName = 'gpt-4o';
	$customId = 'content-test';
	$userMessage = 'Test message';

	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);
	$chat = $batch->addChat($customId, $modelName);
	$chat->addMessage($userMessage, Role::User);

	// Capture the lines the batch would upload
	$capturedLines = null;

	$clientMock->expects('submitBatch')
		->once()
		->with(Mockery::any(), Mockery::capture($capturedLines), Mockery::any())
		->andReturn(new BatchResponse($clientMock, ['id' => 'batch-test']));

	$batch->submit();

	// the lines are produced lazily, so that a huge batch never exists as one string
	$lines = iterator_to_array($capturedLines);
	Assert::count(1, $lines); // Should have 1 item

	$requestData = json_decode($lines[0], true);
	Assert::same($customId, $requestData['custom_id']);
	Assert::same('POST', $requestData['method']);
	Assert::same('/v1/responses', $requestData['url']);
	Assert::true(isset($requestData['body']['model']));
	Assert::true(isset($requestData['body']['input']));
});


test('a result file is downloaded as a stream and cut into lines', function () {
	$http = (new FakeHttpClient)->queueStream(['{"a":1}' . "\n" . '{"b', '":2}' . "\n"]);
	$client = new Client('key', $http);

	$lines = iterator_to_array($client->streamLines('files/file-1/content'));

	Assert::same(['{"a":1}', '{"b":2}'], $lines);
	Assert::same('https://api.openai.com/v1/files/file-1/content', $http->lastRequest()['url']);
	Assert::same('Bearer key', $http->lastRequest()['headers']['Authorization']);
	// no payload, so it goes as a GET
	Assert::null($http->lastRequest()['payload']);
});


test('a failed download is reported, not parsed', function () {
	$http = (new FakeHttpClient)->queueStreamError(['error' => ['message' => 'No such File object']], 404);
	$client = new Client('key', $http);

	Assert::exception(
		fn() => iterator_to_array($client->streamLines('files/nope/content')),
		AIAccess\ApiException::class,
		'No such File object',
	);
});
