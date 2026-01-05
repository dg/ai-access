<?php declare(strict_types=1);

use AIAccess\Batch\Status;
use AIAccess\Chat\Message;
use AIAccess\Chat\Role;
use AIAccess\Provider\OpenAI\BatchResponse;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('BatchResponse initialization', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::type(BatchResponse::class, $response);
});


test('getStatus returns correct Status enum for various status values', function () {
	$clientMock = Mockery::mock(Client::class);

	$testCases = [
		'validating' => Status::InProgress,
		'in_progress' => Status::InProgress,
		'finalizing' => Status::InProgress,
		'completed' => Status::Completed,
		'cancelling' => Status::Failed,
		'failed' => Status::Failed,
		'expired' => Status::Failed,
		'cancelled' => Status::Failed,
		'unknown_status' => Status::Other,
	];

	foreach ($testCases as $apiStatus => $expectedStatus) {
		$batchData = [
			'id' => 'batch-' . $apiStatus,
			'status' => $apiStatus,
		];

		$response = new BatchResponse($clientMock, $batchData);
		Assert::same($expectedStatus, $response->getStatus());
	}
});


test('getMessages returns null for non-completed batch', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getMessages());
});


test('getMessages returns null for completed batch without output_file_id', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		// No output_file_id field
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getMessages());
});


test('getMessages fetches and parses JSONL from file content', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response to task 1"}]}]}}}
		{"custom_id":"task2","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response to task 2"}]}]}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with("files/{$fileId}/content", null, [], false)
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
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response"}]}]}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once() // Should only be called once regardless of how many times getMessages is called
		->with("files/{$fileId}/content", null, [], false)
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);

	// Call multiple times
	$response->getMessages();
	$response->getMessages();
	$messages = $response->getMessages();

	Assert::count(1, $messages);
});


test('getMessages handles error responses in JSONL', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Success response"}]}]}}}
		{"custom_id":"task2","error":{"message":"Content policy violation"}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with("files/{$fileId}/content", null, [], false)
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);

	Assert::error(function () use ($response) {
		$messages = $response->getMessages();

		// Should still return the successful message
		Assert::count(1, $messages);
		Assert::true(isset($messages['task1']));
		Assert::false(isset($messages['task2']));
	}, E_USER_WARNING, "Error in request 'task2': Content policy violation");
});


test('getMessages handles complex output structure', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$jsonlResponse = <<<'JSONL'
		{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"First part"},{"type":"output_text","text":" and second part"}]}]}}}
		JSONL;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with("files/{$fileId}/content", null, [], false)
		->andReturn($jsonlResponse);

	$response = new BatchResponse($clientMock, $batchData);
	$messages = $response->getMessages();

	Assert::count(1, $messages);
	Assert::same('First part and second part', $messages['task1']->getText());
});


test('getMessages throws exception on API error', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with("files/{$fileId}/content", null, [], false)
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
		'status' => 'completed',
		'request_counts' => [
			'completed' => 5,
			'failed' => 0,
		],
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::null($response->getError());
});


test('getError returns error message from batch-level errors', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'failed',
		'errors' => [
			'data' => [
				['message' => 'Batch processing timeout'],
				['message' => 'Invalid model specified'],
			],
		],
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	$error = $response->getError();
	Assert::type('string', $error);
	Assert::contains('Batch processing timeout', $error);
	Assert::contains('Invalid model specified', $error);
});


test('getError returns error message based on request counts', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'request_counts' => [
			'completed' => 2,
			'failed' => 3,
		],
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	$error = $response->getError();
	Assert::type('string', $error);
	Assert::contains('3 requests failed', $error);
});


test('getCreatedAt parses timestamp correctly', function () {
	$timestamp = 1_713_702_896; // Unix timestamp for 2024-04-21 13:34:56 UTC
	$batchData = [
		'id' => 'batch-123',
		'created_at' => $timestamp,
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	$createdAt = $response->getCreatedAt();
	Assert::type(DateTimeImmutable::class, $createdAt);
	Assert::same('2024-04-21T12:34:56+00:00', $createdAt->format('c'));
});


test('getCreatedAt returns null for invalid or missing timestamp', function () {
	$clientMock = Mockery::mock(Client::class);

	// Missing timestamp
	$response1 = new BatchResponse($clientMock, ['id' => 'batch-1']);
	Assert::null($response1->getCreatedAt());
});


test('getCompletedAt checks various timestamp fields', function () {
	$timestamp = 1_713_702_896; // Unix timestamp for 2024-04-21 13:34:56 UTC

	$clientMock = Mockery::mock(Client::class);

	// Test completed_at
	$response1 = new BatchResponse($clientMock, [
		'id' => 'batch-1',
		'completed_at' => $timestamp,
	]);
	$completedAt1 = $response1->getCompletedAt();
	Assert::type(DateTimeImmutable::class, $completedAt1);
	Assert::same('2024-04-21T12:34:56+00:00', $completedAt1->format('c'));

	// Test failed_at
	$response2 = new BatchResponse($clientMock, [
		'id' => 'batch-2',
		'failed_at' => $timestamp,
	]);
	$completedAt2 = $response2->getCompletedAt();
	Assert::type(DateTimeImmutable::class, $completedAt2);

	// Test cancelled_at
	$response3 = new BatchResponse($clientMock, [
		'id' => 'batch-3',
		'cancelled_at' => $timestamp,
	]);
	$completedAt3 = $response3->getCompletedAt();
	Assert::type(DateTimeImmutable::class, $completedAt3);
});


test('getCompletedAt returns null for missing timestamp', function () {
	$clientMock = Mockery::mock(Client::class);

	// Missing timestamp
	$response = new BatchResponse($clientMock, ['id' => 'batch-1']);
	Assert::null($response->getCompletedAt());
});


test('getRawResult returns original batch data', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'in_progress',
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
		'status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same($batchId, $response->getId());
});
