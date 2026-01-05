<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\Claude\Chat;
use AIAccess\Provider\Claude\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('countTokens throws exception on empty message history', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');

	Assert::exception(function () use ($chat) {
		$chat->countTokens();
	}, LogicException::class, 'Cannot send request with empty message history.');
});


test('countTokens calls API with correct payload', function () {
	$model = 'claude-3-sonnet-20240229';
	$userMessage = 'Hello Claude';
	$systemInstruction = 'You are a helpful assistant';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with('v1/messages/count_tokens', Mockery::capture($capturedPayload))
		->andReturn(['input_tokens' => 42]);

	$chat = new Chat($clientMock, $model);
	$chat->setSystemInstruction($systemInstruction);
	$chat->addMessage($userMessage, Role::User);

	// Call the countTokens method
	$tokenCount = $chat->countTokens();

	// Verify the token count value
	Assert::same(42, $tokenCount);

	// Verify the payload structure
	Assert::same($model, $capturedPayload['model']);
	Assert::same($systemInstruction, $capturedPayload['system']);
	Assert::count(1, $capturedPayload['messages']);
	Assert::same('user', $capturedPayload['messages'][0]['role']);
	Assert::same($userMessage, $capturedPayload['messages'][0]['content']);

	// Verify that only necessary fields are included
	Assert::count(3, $capturedPayload);
	Assert::true(isset($capturedPayload['model']));
	Assert::true(isset($capturedPayload['messages']));
	Assert::true(isset($capturedPayload['system']));

	// Verify that other fields like max_tokens are not included
	Assert::false(isset($capturedPayload['max_tokens']));
	Assert::false(isset($capturedPayload['temperature']));
	Assert::false(isset($capturedPayload['stop_sequences']));
});


test('countTokens includes only necessary fields when options are set', function () {
	$model = 'claude-3-opus-20240229';
	$userMessage = 'Test message';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->once()
		->with('v1/messages/count_tokens', Mockery::on(function ($payload) {
			// Only these three fields should be present
			$expectedFields = ['model', 'messages', 'system'];
			$actualFields = array_keys($payload);
			sort($expectedFields);
			sort($actualFields);

			return $actualFields === $expectedFields;
		}))
		->andReturn(['input_tokens' => 15]);

	$chat = new Chat($clientMock, $model);
	// Set various options that should NOT be included in token count payload
	$chat->setOptions(
		maxTokens: 500,
		stopSequences: ['STOP'],
		temperature: 0.7,
		topK: 10,
		topP: 0.9,
	);
	$chat->addMessage($userMessage, Role::User);

	$tokenCount = $chat->countTokens();
	Assert::same(15, $tokenCount);
});


test('countTokens handles different message histories', function () {
	$model = 'claude-3-sonnet-20240229';
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $model);

	// Test with single user message
	$clientMock->expects('callApi')
		->once()
		->andReturn(['input_tokens' => 10]);

	$chat->addMessage('Short message', Role::User);
	Assert::same(10, $chat->countTokens());

	// Test with multi-turn conversation
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $model);

	$clientMock->expects('callApi')
		->once()
		->with('v1/messages/count_tokens', Mockery::on(fn($payload) => count($payload['messages']) === 3))
		->andReturn(['input_tokens' => 45]);

	$chat->addMessage('First user message', Role::User);
	$chat->addMessage('First assistant response', Role::Model);
	$chat->addMessage('Second user message', Role::User);

	Assert::same(45, $chat->countTokens());
});
