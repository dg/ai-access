<?php declare(strict_types=1);

use AIAccess\Provider\Claude\Batch;
use AIAccess\Provider\Claude\BatchResponse;
use AIAccess\Provider\Claude\Client;
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
	$before = 'batch-before-456';

	$apiResponse = [
		'data' => [
			[
				'id' => 'batch-1',
				'processing_status' => 'in_progress',
			],
			[
				'id' => 'batch-2',
				'processing_status' => 'ended',
			],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("v1/messages/batches?limit={$limit}&after_id={$after}&before_id={$before}")
		->andReturn($apiResponse);

	$batches = $clientMock->listBatches($limit, $after, $before);

	Assert::count(2, $batches);
	Assert::type(BatchResponse::class, $batches[0]);
	Assert::type(BatchResponse::class, $batches[1]);
});


test('Client retrieveBatch fetches specific batch by ID', function () {
	$batchId = 'batch-retrieve-123';

	$apiResponse = [
		'id' => $batchId,
		'processing_status' => 'ended',
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("v1/messages/batches/{$batchId}")
		->andReturn($apiResponse);

	$batch = $clientMock->retrieveBatch($batchId);

	Assert::type(BatchResponse::class, $batch);
	Assert::same($batchId, $batch->getId());
});


test('Client cancelBatch sends cancellation request', function () {
	$batchId = 'batch-cancel-123';

	$apiResponse = [
		'id' => $batchId,
		'cancel_initiated_at' => '2024-04-20T15:30:00Z',
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("v1/messages/batches/{$batchId}/cancel", [])
		->andReturn($apiResponse);

	$result = $clientMock->cancelBatch($batchId);

	Assert::true($result);
});


test('Client cancelBatch returns false when cancellation fails', function () {
	$batchId = 'batch-cancel-fail-123';

	$apiResponse = [
		'id' => $batchId,
		// No cancel_initiated_at field
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("v1/messages/batches/{$batchId}/cancel", [])
		->andReturn($apiResponse);

	$result = $clientMock->cancelBatch($batchId);

	Assert::false($result);
});
