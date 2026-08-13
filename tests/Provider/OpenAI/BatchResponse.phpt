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
		// still running: the job is being cancelled, its output file is not there yet
		'cancelling' => Status::InProgress,
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


test('an unfinished batch yields nothing', function () {
	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, ['id' => 'batch-123', 'status' => 'in_progress']);

	Assert::same([], iterator_to_array($response->getResults()));
});


test('a cancelled batch still hands over the requests it finished', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->shouldReceive('streamLines')->with('files/out-1/content')->andReturn([
		json_encode(['custom_id' => 'a', 'response' => ['status_code' => 200, 'body' => [
			'status' => 'completed',
			'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'done']]]],
		]]]),
	]);

	$response = new BatchResponse($clientMock, [
		'id' => 'batch-123',
		'status' => 'cancelled',
		'output_file_id' => 'out-1',
	]);

	// the work was done and billed before the cancellation landed
	$results = iterator_to_array($response->getResults());
	Assert::same(['a'], array_keys($results));
	Assert::same('done', $results['a']->message->getText());
});


test('a completed batch with no files yields nothing', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		// no output_file_id or error_file_id
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same([], iterator_to_array($response->getResults()));
});


test('failed requests come from the error file, even when everything failed', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'error_file_id' => 'file-err',
		// all requests failed, so there is no output_file_id at all
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->with('files/file-err/content')
		->once()
		->andReturn(['{"custom_id":"task1","response":{"status_code":400,"body":{"error":{"message":"Invalid model"}}},"error":null}']);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(1, $results);
	Assert::null($results['task1']->message);
	Assert::same('Invalid model', $results['task1']->error);
});


test('results are read line by line, keyed by custom id', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with("files/{$fileId}/content")
		->andReturn([
			'{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response to task 1"}]}]}}}',
			'{"custom_id":"task2","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response to task 2"}]}]}}}',
		]);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(2, $results);
	Assert::type(Message::class, $results['task1']->message);
	Assert::same('Response to task 1', $results['task1']->message->getText());
	Assert::same('Response to task 2', $results['task2']->message->getText());
	Assert::same(Role::Model, $results['task1']->message->getRole());
});


test('nothing is memoized, so reading twice fetches twice', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->twice()
		->andReturn(['{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Response"}]}]}}}']);

	$response = new BatchResponse($clientMock, $batchData);

	Assert::count(1, iterator_to_array($response->getResults()));
	Assert::count(1, iterator_to_array($response->getResults()));
});


test('a failed request travels alongside the answers', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with("files/{$fileId}/content")
		->andReturn([
			'{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Success response"}]}]}}}',
			'{"custom_id":"task2","error":{"message":"Content policy violation"}}',
		]);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(2, $results);
	Assert::null($results['task1']->error);
	Assert::null($results['task2']->message);
	Assert::same('Content policy violation', $results['task2']->error);
});


test('a generation that failed inside HTTP 200 is a failure, not a blank answer', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with('files/out-1/content')
		->andReturn([
			'{"custom_id":"task1","response":{"status_code":200,"body":{"status":"failed","error":{"message":"The model ran out of patience"},"output":[]}}}',
		]);

	$results = iterator_to_array((new BatchResponse($clientMock, [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => 'out-1',
	]))->getResults());

	Assert::null($results['task1']->message);
	Assert::same('The model ran out of patience', $results['task1']->error);
});


test('a complex output structure is parsed as a live one would be', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->andReturn(['{"custom_id":"task1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"First part"},{"type":"output_text","text":" and second part"}]}]}}}']);

	$results = iterator_to_array((new BatchResponse($clientMock, $batchData))->getResults());

	Assert::count(1, $results);
	// joined exactly as live chat joins them, because it is the same parser
	Assert::same("First part\n and second part", $results['task1']->message->getText());
});


test('an API error surfaces while reading', function () {
	$fileId = 'file-output-123';
	$batchData = [
		'id' => 'batch-123',
		'status' => 'completed',
		'output_file_id' => $fileId,
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('streamLines')
		->once()
		->with("files/{$fileId}/content")
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


test('getError says nothing about requests that failed one by one', function () {
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

	// the job itself went through; each failure travels with its own Result
	Assert::null($response->getError());
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


test('getRawResponse returns original batch data', function () {
	$batchData = [
		'id' => 'batch-123',
		'status' => 'in_progress',
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
		'status' => 'in_progress',
	];

	$clientMock = Mockery::mock(Client::class);
	$response = new BatchResponse($clientMock, $batchData);

	Assert::same($batchId, $response->getId());
});
