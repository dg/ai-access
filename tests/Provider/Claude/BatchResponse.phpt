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
		'canceling' => Status::Failed,
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


test('getMessages returns null for non-completed batch', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getMessages());
});


test('getMessages returns null for completed batch without results_url', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		// No results_url field
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getMessages());
});


test('getMessages fetches and parses JSONL for completed batch', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response to task 1"}]}}}
		{"custom_id":"task2","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response to task 2"}]}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with($batchData['results_url'], null, false)
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);
	$messages = $response->getMessages();

	Assert::count(2, $messages);
	Assert::type(Message::class, $messages['task1']);
	Assert::type(Message::class, $messages['task2']);
	Assert::same('Response to task 1', $messages['task1']->getText());
	Assert::same('Response to task 2', $messages['task2']->getText());
	Assert::same(Role::Model, $messages['task1']->getRole());
});


test('getMessages only calls API once', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Response"}]}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once() // Should only be called once regardless of how many times getMessages is called
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);

	// Call multiple times
	$response->getMessages();
	$response->getMessages();
	$messages = $response->getMessages();

	Assert::count(1, $messages);
});


test('getMessages handles error responses in JSONL', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","result":{"type":"succeeded","message":{"content":[{"type":"text","text":"Success response"}]}}}
		{"custom_id":"task2","result":{"type":"errored","error":{"message":"Content policy violation","type":"content_policy"}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);

	Assert::error(function () use ($response) {
		$messages = $response->getMessages();

		// Should still return the successful message
		Assert::count(1, $messages);
		Assert::true(isset($messages['task1']));
		Assert::false(isset($messages['task2']));
	}, E_USER_WARNING, "Error in request 'task2': Content policy violation (type: content_policy)");
});


test('getMessages throws exception on API error', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'ended',
		'results_url' => 'https://results.url/download',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with($batchData['results_url'], null, false)
		->andThrow(new AIAccess\ApiException('API Error', 500));

	$response = new BatchResponse($clientMock, $batchData);

	Assert::exception(
		fn() => $response->getMessages(),
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


test('getError returns error message for problematic batch', function () {
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

	$error = $response->getError();
	Assert::type('string', $error);
	Assert::contains('1 requests encountered errors', $error);
	Assert::contains('3 requests expired', $error);
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


test('getRawResult returns original batch data', function () {
	$batchData = [
		'id' => 'batch-123',
		'processing_status' => 'in_progress',
		'custom_field' => 'custom_value',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same($batchData, $response->getRawResult());
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
