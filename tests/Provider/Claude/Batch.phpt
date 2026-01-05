<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\Claude\Batch;
use AIAccess\Provider\Claude\BatchResponse;
use AIAccess\Provider\Claude\Chat;
use AIAccess\Provider\Claude\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Batch initialization', function () {
	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	Assert::type(Batch::class, $batch);
});


test('addChat adds chat with correct model and ID', function () {
	$modelName = 'claude-3-sonnet-20240229';
	$customId = 'test-chat-1';

	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	$chat = $batch->addChat($modelName, $customId);

	Assert::type(Chat::class, $chat);
});


test('addChat throws exception for duplicate IDs', function () {
	$modelName = 'claude-3-sonnet-20240229';
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


test('submit throws exception for empty batch', function () {
	$clientMock = Mockery::mock(Client::class);
	$batch = new Batch($clientMock);

	Assert::exception(
		fn() => $batch->submit(),
		LogicException::class,
		'Cannot submit batch job: No chat requests added.',
	);
});


test('submit builds correct payload and returns BatchResponse', function () {
	$modelName = 'claude-3-sonnet-20240229';
	$customId1 = 'chat-1';
	$customId2 = 'chat-2';
	$userMessage1 = 'Hello Claude';
	$userMessage2 = 'Another message';
	$systemInstruction = 'You are a helpful assistant';

	// Expected response from API
	$apiResponse = [
		'id' => 'batch-123456',
		'status' => 'accepted',
		'processing_status' => 'in_progress',
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

	// Setup expectations for API call
	$clientMock->expects('callApi')
		->once()
		->with('v1/messages/batches', Mockery::on(function ($payload) use ($customId1, $customId2) {
			// Verify the structure of the payload
			if (!isset($payload['requests']) || count($payload['requests']) !== 2) {
				return false;
			}

			// Check that both custom IDs are present
			$customIds = array_column($payload['requests'], 'custom_id');
			if (!in_array($customId1, $customIds, true) || !in_array($customId2, $customIds, true)) {
				return false;
			}

			// Verify each request has required parameters
			foreach ($payload['requests'] as $request) {
				$params = $request['params'];
				if (!isset($params['model']) || !isset($params['messages']) || !isset($params['max_tokens'])) {
					return false;
				}
			}

			return true;
		}))
		->andReturn($apiResponse);

	$response = $batch->submit();

	Assert::type(BatchResponse::class, $response);
});
