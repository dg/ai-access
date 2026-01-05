<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Provider\OpenAI\Batch;
use AIAccess\Provider\OpenAI\BatchResponse;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Client createBatch returns Batch instance', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();

	$batch = $clientMock->createBatch();

	Assert::type(Batch::class, $batch);
});


test('Client listBatches calls API with correct parameters', function () {
	$limit = 10;
	$after = 'batch-after-123';

	$apiResponse = [
		'data' => [
			[
				'id' => 'batch-1',
				'status' => 'in_progress',
			],
			[
				'id' => 'batch-2',
				'status' => 'completed',
			],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("batches?limit={$limit}&after={$after}")
		->andReturn($apiResponse);

	$batches = $clientMock->listBatches($limit, $after);

	Assert::count(2, $batches);
	Assert::type(BatchResponse::class, $batches[0]);
	Assert::type(BatchResponse::class, $batches[1]);
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
		->with("batches/{$batchId}/cancel", '')
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
		->with("batches/{$batchId}/cancel", '')
		->andReturn($apiResponse);

	$result = $clientMock->cancelBatch($batchId);

	Assert::false($result);
});


test('submit validates JSONL content structure', function () {
	$modelName = 'gpt-4o';
	$customId = 'content-test';
	$userMessage = 'Test message';
	$fileId = 'file-content-123';

	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);
	$chat = $batch->addChat($modelName, $customId);
	$chat->addMessage($userMessage, Role::User);

	// Capture the JSONL content
	$capturedContent = null;

	$clientMock->expects('uploadContent')
		->once()
		->with(
			Mockery::capture($capturedContent),
			Mockery::any(),
			Mockery::any(),
			Mockery::any(),
		)
		->andReturn($fileId);

	$clientMock->expects('callApi')
		->once()
		->andReturn(['id' => 'batch-test']);

	$batch->submit();

	// Verify content structure
	Assert::type('string', $capturedContent);

	// Decode JSONL (each line is a JSON object)
	$lines = explode("\n", trim($capturedContent));
	Assert::count(1, $lines); // Should have 1 item

	$requestData = json_decode($lines[0], true);
	Assert::same($customId, $requestData['custom_id']);
	Assert::same('POST', $requestData['method']);
	Assert::same('/v1/responses', $requestData['url']);
	Assert::true(isset($requestData['body']['model']));
	Assert::true(isset($requestData['body']['input']));
});
