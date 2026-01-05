<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\Grok\Chat;
use AIAccess\Provider\Grok\ChatResponse;
use AIAccess\Provider\Grok\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Chat initialization with model', function () {
	$modelName = 'grok-1';

	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $modelName);

	Assert::type(Chat::class, $chat);
});


test('Chat setOptions returns self for fluent interface', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'grok-1');

	$result = $chat->setOptions(
		maxOutputTokens: 100,
		temperature: 0.7,
		topP: 0.9,
		frequencyPenalty: 0.5,
		presencePenalty: 0.5,
		stop: ['STOP'],
		stream: false,
		seed: 12345,
		responseFormat: ['type' => 'json_object'],
		reasoningEffort: 'high',
	);

	Assert::same($chat, $result);
});


test('Chat builds correct API payload with minimal options', function () {
	$modelName = 'grok-1';
	$userMessage = 'Hello Grok';
	$systemInstruction = 'You are a helpful assistant';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('chat/completions', Mockery::capture($capturedPayload))
		->andReturn([
			'choices' => [['message' => ['content' => 'Response text']]],
			'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
		]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setSystemInstruction($systemInstruction);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction by generating response
	$chat->sendMessage(null);

	// Now verify the captured payload
	Assert::same($modelName, $capturedPayload['model']);
	Assert::count(2, $capturedPayload['messages']);

	// System message should be first
	Assert::same('system', $capturedPayload['messages'][0]['role']);
	Assert::same($systemInstruction, $capturedPayload['messages'][0]['content']);

	// User message should be second
	Assert::same('user', $capturedPayload['messages'][1]['role']);
	Assert::same($userMessage, $capturedPayload['messages'][1]['content']);
});


test('Chat builds correct API payload with all options', function () {
	$modelName = 'grok-1';
	$userMessage = 'Hello Grok';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('chat/completions', Mockery::capture($capturedPayload))
		->andReturn([
			'choices' => [['message' => ['content' => 'Response text']]],
			'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
		]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setOptions(
		maxOutputTokens: 500,
		temperature: 0.5,
		topP: 0.8,
		frequencyPenalty: 0.3,
		presencePenalty: 0.2,
		stop: ['STOP', 'END'],
		stream: false,
		seed: 12345,
		responseFormat: ['type' => 'json_object'],
		reasoningEffort: 'high',
		tools: [['type' => 'function', 'function' => ['name' => 'get_weather']]],
		toolChoice: 'auto',
	);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction
	$chat->sendMessage(null);

	// Verify all options
	Assert::same(500, $capturedPayload['max_completion_tokens']);
	Assert::same(0.5, $capturedPayload['temperature']);
	Assert::same(0.8, $capturedPayload['top_p']);
	Assert::same(0.3, $capturedPayload['frequency_penalty']);
	Assert::same(0.2, $capturedPayload['presence_penalty']);
	Assert::same(['STOP', 'END'], $capturedPayload['stop']);
	Assert::false($capturedPayload['stream']);
	Assert::same(12345, $capturedPayload['seed']);
	Assert::same(['type' => 'json_object'], $capturedPayload['response_format']);
	Assert::same('high', $capturedPayload['reasoning_effort']);
	Assert::count(1, $capturedPayload['tools']);
	Assert::same('auto', $capturedPayload['tool_choice']);
});


test('Chat sendMessage correctly updates message history with model response', function () {
	$userMessage = 'Tell me a joke';
	$modelResponse = 'Why did the chicken cross the road?';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->allows('callApi')
		->andReturn([
			'choices' => [
				['message' => ['content' => $modelResponse]],
			],
		]);

	$chat = new Chat($clientMock, 'grok-1');
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
	$chat = new Chat($clientMock, 'grok-1');

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
			['choices' => [['message' => ['content' => $modelResponses[0]]]]],
			['choices' => [['message' => ['content' => $modelResponses[1]]]]],
		]);

	$chat = new Chat($clientMock, 'grok-1');

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


test('Chat handles system instruction correctly', function () {
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('chat/completions', Mockery::capture($capturedPayload))
		->andReturn([
			'choices' => [['message' => ['content' => 'Response']]],
		]);

	$chat = new Chat($clientMock, 'grok-1');
	$chat->setSystemInstruction('Custom system instruction');
	$chat->addMessage('Hello', Role::User);
	$chat->sendMessage(null);

	// Verify system message is included
	Assert::true(isset($capturedPayload['messages'][0]));
	Assert::same('system', $capturedPayload['messages'][0]['role']);
	Assert::same('Custom system instruction', $capturedPayload['messages'][0]['content']);
});


test('Chat preserves messages when API call fails', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->andThrow(new AIAccess\ApiException('API Error', 500));

	$chat = new Chat($clientMock, 'grok-1');
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
