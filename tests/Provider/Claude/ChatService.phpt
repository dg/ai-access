<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\Claude\Chat;
use AIAccess\Provider\Claude\ChatResponse;
use AIAccess\Provider\Claude\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Chat initialization with model', function () {
	$modelName = 'claude-3-sonnet-20240229';

	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $modelName);

	// This is mostly a smoke test since model is private property
	Assert::type(Chat::class, $chat);
});


test('Chat setOptions returns self for fluent interface', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'claude-3-opus-20240229');

	$result = $chat->setOptions(
		maxTokens: 100,
		stopSequences: ['STOP'],
		temperature: 0.7,
		topK: 10,
		topP: 0.9,
	);

	Assert::same($chat, $result);
});


test('Chat builds correct API payload with minimal options', function () {
	$modelName = 'claude-3-sonnet-20240229';
	$userMessage = 'Hello Claude';
	$systemInstruction = 'You are a helpful assistant';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('v1/messages', Mockery::capture($capturedPayload))
		->andReturn(['content' => [['text' => 'Response text', 'type' => 'text']], 'stop_reason' => 'end_turn']);

	$chat = new Chat($clientMock, $modelName);
	$chat->setSystemInstruction($systemInstruction);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction by generating response
	$chat->sendMessage(null);

	// Now verify the captured payload
	Assert::same($modelName, $capturedPayload['model']);
	Assert::same($systemInstruction, $capturedPayload['system']);
	Assert::count(1, $capturedPayload['messages']);
	Assert::same('user', $capturedPayload['messages'][0]['role']);
	Assert::same($userMessage, $capturedPayload['messages'][0]['content']);
	Assert::same(1024, $capturedPayload['max_tokens']); // Default value
});


test('Chat builds correct API payload with all options', function () {
	$modelName = 'claude-3-opus-20240229';
	$userMessage = 'Hello Claude';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('v1/messages', Mockery::capture($capturedPayload))
		->andReturn(['content' => [['text' => 'Response text', 'type' => 'text']], 'stop_reason' => 'end_turn']);

	$chat = new Chat($clientMock, $modelName);
	$chat->setOptions(
		maxTokens: 500,
		stopSequences: ['STOP', 'END'],
		temperature: 0.5,
		topK: 20,
		topP: 0.8,
	);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction
	$chat->sendMessage(null);

	// Verify all options
	Assert::same(500, $capturedPayload['max_tokens']);
	Assert::same(['STOP', 'END'], $capturedPayload['stop_sequences']);
	Assert::same(0.5, $capturedPayload['temperature']);
	Assert::same(20.0, $capturedPayload['top_k']);
	Assert::same(0.8, $capturedPayload['top_p']);
});


test('Chat sendMessage correctly updates message history with model response', function () {
	$userMessage = 'Tell me a joke';
	$modelResponse = 'Why did the chicken cross the road?';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->allows('callApi')
		->andReturn(['content' => [['text' => $modelResponse, 'type' => 'text']], 'stop_reason' => 'end_turn']);

	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');
	$response = $chat->sendMessage($userMessage);

	Assert::type(ChatResponse::class, $response);
	Assert::same($modelResponse, $response->getText());

	// Check message history
	$messages = $chat->getMessages();
	Assert::count(2, $messages);
	Assert::same($userMessage, $messages[0]->getText());
	Assert::same(Role::User, $messages[0]->getRole());
	Assert::same($modelResponse, $messages[1]->getText());
	Assert::same(Role::Model, $messages[1]->getRole());
});


test('Chat throws exception on empty message history', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');

	Assert::exception(
		fn() => $chat->sendMessage(null),
		LogicException::class,
		'Cannot send request with empty message history.',
	);
});


test('Chat correctly builds message history with multiple exchanges', function () {
	$modelResponses = [
		'I can help with that.',
		'Here is more information.',
	];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->twice()
		->andReturnValues([
			['content' => [['text' => $modelResponses[0], 'type' => 'text']], 'stop_reason' => 'end_turn'],
			['content' => [['text' => $modelResponses[1], 'type' => 'text']], 'stop_reason' => 'end_turn'],
		]);

	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');

	// First exchange
	$chat->sendMessage('First question');

	// Second exchange
	$chat->sendMessage('Follow up question');

	// Verify full history
	$messages = $chat->getMessages();
	Assert::count(4, $messages);
	Assert::same('First question', $messages[0]->getText());
	Assert::same($modelResponses[0], $messages[1]->getText());
	Assert::same('Follow up question', $messages[2]->getText());
	Assert::same($modelResponses[1], $messages[3]->getText());
});


test('Chat handles empty system instruction', function () {
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('v1/messages', Mockery::capture($capturedPayload))
		->andReturn(['content' => [['text' => 'Response', 'type' => 'text']], 'stop_reason' => 'end_turn']);

	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');
	$chat->addMessage('Hello', Role::User);
	$chat->sendMessage(null);

	Assert::same('', $capturedPayload['system']);
});


test('Chat preserves messages when API call fails', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->andThrow(new AIAccess\ApiException('API Error', 500));

	$chat = new Chat($clientMock, 'claude-3-sonnet-20240229');
	$chat->addMessage('Initial message', Role::User);

	// Message count before attempting to send
	Assert::count(1, $chat->getMessages());

	// Attempt to send should fail
	Assert::exception(
		fn() => $chat->sendMessage('This will fail'),
		AIAccess\ApiException::class,
	);

	// Message count should remain at 1 (API error rolled back the new message)
	Assert::count(1, $chat->getMessages());
	Assert::same('Initial message', $chat->getMessages()[0]->getText());
});
