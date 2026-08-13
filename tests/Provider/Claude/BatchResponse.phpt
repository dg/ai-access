<?php declare(strict_types=1);

use AIAccess\Batch\Status;
use AIAccess\Chat\Message;
use AIAccess\Chat\Role;
use AIAccess\Provider\Claude\BatchResponse;
use AIAccess\Provider\Claude\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('BatchResponse initialization', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::type(BatchResponse::class, $response);
});


test('getStatus returns correct Status enum for various processing_status values', function () {
	$clientMock = Mockery::mock(Client::class);

	$testCases = [
		'in_progress' => Status::InProgress,
		'ended' => Status::Completed,
		'canceling' => Status::InProgress,
		'unknown_status' => Status::Other,
	];

	foreach ($testCases as $apiStatus => $expectedStatus) {
		$batchData = [
			'id' => 'batch-' . $apiStatus,
			'processing_status' => $apiStatus,
		];

		$response = new BatchResponse($clientMock, $batchData);
		Assert::same($expectedStatus, $response->getStatus());
	}
});


test('an unfinished batch yields nothing', function () {
	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, ['id' => 'batch-123', 'processing_status' => 'in_progress']);

	Assert::same([], iterator_to_array($response->getResults()));
});


test('a completed batch without results_url yields nothing', function () {
	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, ['id' => 'batch-123', 'processing_status' => 'ended']);

	Assert::same([], iterator_to_array($response->getResults()));
});


test('results are read line by line, keyed by custom id', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with($batchData['results_url'])
		->andReturn([
			'{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response to task 1"}]}}}',
			'{"custom_id":"task2","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response to task 2"}]}}}',
		]);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(2, $results);
	Assert::type(Message::class, $results['task1']->message);
	Assert::same('Response to task 1', $results['task1']->message->getText());
	Assert::same('Response to task 2', $results['task2']->message->getText());
	Assert::same(Role::Model, $results['task1']->message->getRole());
	Assert::null($results['task1']->error);
});


test('nothing is memoized, so reading twice fetches twice', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];
	$line = '{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response"}]}}}';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')->twice()->andReturn([$line]);

	$response = new BatchResponse($clientMock, $batchData);

	Assert::count(1, iterator_to_array($response->getResults()));
	Assert::count(1, iterator_to_array($response->getResults()));
});


test('nothing is fetched until the iteration starts', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')->never();

	// building the generator sends nothing, which is what makes early exit cheap
	(new BatchResponse($clientMock, $batchData))->getResults();
});


test('a failed request travels alongside the answers', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')->once()->andReturn([
		'{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Success response"}]}}}',
		// the wire nests errored results one level deeper: result.error = {type: "error", error: {...}}
		'{"custom_id":"task2","result":{"type":"errored","error":{"type":"error","error":{"message":"Content policy violation","type":"content_policy"}}}}',
	]);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(2, $results);
	Assert::null($results['task1']->error);
	Assert::null($results['task2']->message);
	Assert::same('Content policy violation (type: content_policy)', $results['task2']->error);
});


test('a refusal is an empty message, not a failed request', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	// Claude answers a refusal with an empty content array and stop_reason refusal
	$clientMock->expects('streamLines')->once()->andReturn([
		'{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[],"stop_reason":"refusal"}}}',
	]);

	$result = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults())['task1'];

	Assert::same('', $result->message->getText());
	Assert::null($result->error);
});


test('a batched turn carries the parts a live turn would', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')->once()->andReturn([
		'{"custom_id":"task1","result":{"type":"succeeded","message":{"content":['
		. '{"type":"thinking","thinking":"pondering","signature":"SIG"},'
		. '{"type":"text","text":"Answer"},'
		. '{"type":"tool_use","id":"toolu_1","name":"get_weather","input":{"city":"Brno"}}'
		. ']}}}',
	]);

	$parts = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults())['task1']->message->getParts();

	Assert::count(3, $parts);
	Assert::type(AIAccess\Chat\ReasoningPart::class, $parts[0]);
	Assert::type(AIAccess\Chat\TextPart::class, $parts[1]);
	Assert::type(AIAccess\Chat\ToolCallPart::class, $parts[2]);
});


test('an API error surfaces while reading', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->andThrow(new AIAccess\ApiException('API Error', 500));

	$response = new BatchResponse($clientMock, $batchData);

	Assert::exception(
		fn() => iterator_to_array($response->getResults()),
		AIAccess\ServiceException::class,
		'API Error',
	);
});


test('getError returns null for successful batch', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'request_counts' => [
			'completed' => 5,
			'in_progress' => 0,
			'errored' => 0,
			'expired' => 0,
			'canceled' => 0,
		],
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getError());
});


test('getError says nothing about requests that failed one by one', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'request_counts' => [
			'completed' => 2,
			'in_progress' => 0,
			'errored' => 1,
			'expired' => 3,
			'canceled' => 0,
		],
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	// the wire has no batch-level error at all; each failure travels with its own Result
	Assert::null($response->getError());
});


test('getCreatedAt parses timestamp correctly', function () {
	$timestamp = '2024-04-20T12:34:56Z';
	$batchData = [
		'id' => 'batch-123',
		'created_at' => $timestamp,
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	$createdAt = $response->getCreatedAt();
	Assert::type(DateTimeImmutable::class, $createdAt);
	Assert::same('2024-04-20T12:34:56+00:00', $createdAt->format('c'));
});


test('getCreatedAt returns null for invalid or missing timestamp', function () {
	$clientMock = Mockery::mock(Client::class);

	// Missing timestamp
	$response1 = new BatchResponse($clientMock, ['id' => 'batch-1']);
	Assert::null($response1->getCreatedAt());

	// Invalid timestamp
	$response2 = new BatchResponse($clientMock, ['id' => 'batch-2', 'created_at' => 'invalid-date']);
	Assert::null($response2->getCreatedAt());
});


test('getCompletedAt parses timestamp correctly', function () {
	$timestamp = '2024-04-20T13:45:00Z';
	$batchData = [
		'id' => 'batch-123',
		'ended_at' => $timestamp,
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	$completedAt = $response->getCompletedAt();
	Assert::type(DateTimeImmutable::class, $completedAt);
	Assert::same('2024-04-20T13:45:00+00:00', $completedAt->format('c'));
});


test('getCompletedAt returns null for invalid or missing timestamp', function () {
	$clientMock = Mockery::mock(Client::class);

	// Missing timestamp
	$response1 = new BatchResponse($clientMock, ['id' => 'batch-1']);
	Assert::null($response1->getCompletedAt());

	// Invalid timestamp
	$response2 = new BatchResponse($clientMock, ['id' => 'batch-2', 'ended_at' => 'invalid-date']);
	Assert::null($response2->getCompletedAt());
});


test('getRawResponse returns original batch data', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'in_progress',
		'custom_field' => 'custom_value',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same($batchData, $response->getRawResponse());
});


test('getId returns batch ID', function () {
	$batchId = 'batch-id-12345';
	$batchData = [
		'id' => $batchId,
		'processing_status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same($batchId, $response->getId());
});
