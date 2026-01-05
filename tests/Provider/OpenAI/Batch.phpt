<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\OpenAI\Batch;
use AIAccess\Provider\OpenAI\BatchResponse;
use AIAccess\Provider\OpenAI\Chat;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Batch initialization', function () {
	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	Assert::type(Batch::class, $batch);
});


test('addChat adds chat with correct model and ID', function () {
	$modelName = 'gpt-4o';
	$customId = 'test-chat-1';

	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	$chat = $batch->addChat($modelName, $customId);

	Assert::type(Chat::class, $chat);
});


test('addChat throws exception for duplicate IDs', function () {
	$modelName = 'gpt-4o';
	$customId = 'duplicate-id';

	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	// Add first chat with this ID
	$batch->addChat($modelName, $customId);

	// Try to add second chat with same ID, should throw exception
	Assert::exception(
		fn() => $batch->addChat($modelName, $customId),
		LogicException::class,
		"Chat with custom ID '{$customId}' already exists in this batch.",
	);
});


test('setMetadata sets metadata for batch job', function () {
	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	$metadata = ['user_id' => '12345', 'project' => 'test-project'];
	$result = $batch->setMetadata($metadata);

	// Check fluent interface
	Assert::same($batch, $result);
});


test('submit throws exception for empty batch', function () {
	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	Assert::exception(
		fn() => $batch->submit(),
		LogicException::class,
		'Cannot submit batch job: No chat requests added.',
	);
});


test('submit creates content file and submits batch request', function () {
	$modelName = 'gpt-4o';
	$customId1 = 'chat-1';
	$customId2 = 'chat-2';
	$userMessage1 = 'Hello GPT';
	$userMessage2 = 'Another message';
	$systemInstruction = 'You are a helpful assistant';
	$fileId = 'file-12345';

	// Expected response from API
	$apiResponse = [
		'id' => 'batch-123456',
		'status' => 'validating',
	];

	$clientMock = Mockery::mock(Client::class);

	// Set up the first chat
	$batch = new Batch($clientMock);
	$chat1 = $batch->addChat($modelName, $customId1);
	$chat1->setSystemInstruction($systemInstruction);
	$chat1->addMessage($userMessage1, Role::User);

	// Set up the second chat
	$chat2 = $batch->addChat($modelName, $customId2);
	$chat2->addMessage($userMessage2, Role::User);

	// Set metadata
	$metadata = ['user_id' => '12345'];
	$batch->setMetadata($metadata);

	// Setup expectations for upload and API calls
	$clientMock->expects('uploadContent')
		->once()
		->with(
			Mockery::type('string'), // JSONL content
			'batch_requests.jsonl',
			'batch',
			'text/jsonl',
		)
		->andReturn($fileId);

	$clientMock->expects('callApi')
		->once()
		->with('batches', Mockery::on(fn($payload) => $payload['input_file_id'] === $fileId && $payload['endpoint'] === '/v1/responses' && $payload['completion_window'] === '24h' && $payload['metadata'] === $metadata))
		->andReturn($apiResponse);

	$response = $batch->submit();

	Assert::type(BatchResponse::class, $response);
});
