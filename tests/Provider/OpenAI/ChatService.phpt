<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\OpenAI\Chat;
use AIAccess\Provider\OpenAI\ChatResponse;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Chat initialization with model', function () {
	$modelName = 'gpt-4o';

	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $modelName);

	Assert::type(Chat::class, $chat);
});


test('Chat setOptions returns self for fluent interface', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'gpt-4o');

	$result = $chat->setOptions(
		maxOutputTokens: 1000,
		temperature: 0.7,
		topP: 0.9,
		truncation: 'auto',
		metadata: ['user_id' => '12345'],
		parallelToolCalls: true,
		previousResponseId: 'resp_1234',
		reasoning: ['enabled' => true],
		store: true,
		stream: false,
		text: ['format' => 'paragraph'],
		include: ['reasoning'],
	);

	Assert::same($chat, $result);
});


test('Chat builds correct API payload with minimal options', function () {
	$modelName = 'gpt-4o';
	$userMessage = 'Hello OpenAI';
	$systemInstruction = 'You are a helpful assistant';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('responses', Mockery::capture($capturedPayload))
		->andReturn([
			'output' => [
				['type' => 'message', 'content' => [
					['type' => 'output_text', 'text' => 'Response text'],
				]],
			],
		]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setSystemInstruction($systemInstruction);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction by generating response
	$chat->sendMessage(null);

	// Now verify the captured payload
	Assert::same($modelName, $capturedPayload['model']);
	Assert::same($systemInstruction, $capturedPayload['instructions']);

	// Check input messages
	Assert::count(1, $capturedPayload['input']);
	Assert::same('user', $capturedPayload['input'][0]['role']);
	Assert::same($userMessage, $capturedPayload['input'][0]['content']);
});


test('Chat builds correct API payload with all options', function () {
	$modelName = 'gpt-4o';
	$userMessage = 'Hello OpenAI';

	// Captured payload to verify
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('responses', Mockery::capture($capturedPayload))
		->andReturn([
			'output' => [
				['type' => 'message', 'content' => [
					['type' => 'output_text', 'text' => 'Response text'],
				]],
			],
		]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setOptions(
		maxOutputTokens: 1000,
		temperature: 0.7,
		topP: 0.9,
		truncation: 'auto',
		metadata: ['user_id' => '12345'],
		parallelToolCalls: true,
		reasoning: ['enabled' => true],
		store: true,
		stream: false,
		text: ['format' => 'paragraph'],
		include: ['reasoning'],
		tools: [['type' => 'function', 'function' => ['name' => 'get_weather']]],
	);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction
	$chat->sendMessage(null);

	// Verify all options
	Assert::same(1000, $capturedPayload['max_output_tokens']);
	Assert::same(0.7, $capturedPayload['temperature']);
	Assert::same(0.9, $capturedPayload['top_p']);
	Assert::same('auto', $capturedPayload['truncation']);
	Assert::same(['user_id' => '12345'], $capturedPayload['metadata']);
	Assert::true($capturedPayload['parallel_tool_calls']);
	Assert::same(['enabled' => true], $capturedPayload['reasoning']);
	Assert::true($capturedPayload['store']);
	Assert::false($capturedPayload['stream']);
	Assert::same(['format' => 'paragraph'], $capturedPayload['text']);
	Assert::same(['reasoning'], $capturedPayload['include']);
	Assert::count(1, $capturedPayload['tools']);
});


test('Chat sendMessage correctly updates message history with model response', function () {
	$userMessage = 'Tell me a joke';
	$modelResponse = 'Why did the chicken cross the road?';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->allows('callApi')
		->andReturn([
			'output' => [
				[
					'type' => 'message',
					'content' => [
						['type' => 'output_text', 'text' => $modelResponse],
					],
				],
			],
		]);

	$chat = new Chat($clientMock, 'gpt-4o');
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
	$chat = new Chat($clientMock, 'gpt-4o');

	Assert::exception(function () use ($chat) {
		$chat->sendMessage(null); // No messages yet, should throw
	}, LogicException::class, 'Cannot send request with empty message history.');
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
			[
				'output' => [
					['type' => 'message', 'content' => [
						['type' => 'output_text', 'text' => $modelResponses[0]],
					]],
				],
			],
			[
				'output' => [
					['type' => 'message', 'content' => [
						['type' => 'output_text', 'text' => $modelResponses[1]],
					]],
				],
			],
		]);

	$chat = new Chat($clientMock, 'gpt-4o');

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


test('Chat handles null system instruction correctly', function () {
	$capturedPayload = null;

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with('responses', Mockery::capture($capturedPayload))
		->andReturn([
			'output' => [
				['type' => 'message', 'content' => [
					['type' => 'output_text', 'text' => 'Response'],
				]],
			],
		]);

	$chat = new Chat($clientMock, 'gpt-4o');
	// Don't set system instruction
	$chat->addMessage('Hello', Role::User);
	$chat->sendMessage(null);

	// Verify system instruction isn't included
	Assert::false(isset($capturedPayload['instructions']));
});


test('Chat preserves messages when API call fails', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->andThrow(new AIAccess\ApiException('API Error', 500));

	$chat = new Chat($clientMock, 'gpt-4o');
	$chat->addMessage('Initial message', Role::User);

	// Message count before attempting to send
	Assert::count(1, $chat->getMessages());

	// Attempt to send should fail
	Assert::exception(function () use ($chat) {
		$chat->sendMessage('This will fail');
	}, AIAccess\ApiException::class);

	// Message count should remain at 1 (API error rolled back the new message)
	Assert::count(1, $chat->getMessages());
	Assert::same('Initial message', $chat->getMessages()[0]->getText());
});


test('Chat handles conversation with alternating roles', function () {
	$userMessages = ['User message 1', 'User message 2'];
	$modelResponses = ['Model response 1', 'Model response 2'];

	// Create separate variables for each captured payload
	$firstPayload = null;
	$secondPayload = null;

	$clientMock = Mockery::mock(Client::class);

	// Set up expectations for the first API call
	$clientMock->expects('callApi')
		->once()
		->with('responses', Mockery::capture($firstPayload))
		->andReturn([
			'output' => [
				['type' => 'message', 'content' => [
					['type' => 'output_text', 'text' => $modelResponses[0]],
				]],
			],
		]);

	// Set up expectations for the second API call
	$clientMock->expects('callApi')
		->once()
		->with('responses', Mockery::capture($secondPayload))
		->andReturn([
			'output' => [
				['type' => 'message', 'content' => [
					['type' => 'output_text', 'text' => $modelResponses[1]],
				]],
			],
		]);

	$chat = new Chat($clientMock, 'gpt-4o');

	// Send first message
	$chat->sendMessage($userMessages[0]);

	// Send second message
	$chat->sendMessage($userMessages[1]);

	// Verify first payload structure
	Assert::true(isset($firstPayload['input']), 'First payload should have input field');
	Assert::count(1, $firstPayload['input']);
	Assert::same('user', $firstPayload['input'][0]['role']);
	Assert::same($userMessages[0], $firstPayload['input'][0]['content']);

	// Verify second payload structure
	Assert::true(isset($secondPayload['input']), 'Second payload should have input field');
	Assert::count(3, $secondPayload['input']);
	Assert::same('user', $secondPayload['input'][0]['role']);
	Assert::same($userMessages[0], $secondPayload['input'][0]['content']);
	Assert::same('assistant', $secondPayload['input'][1]['role']);
	Assert::same($modelResponses[0], $secondPayload['input'][1]['content']);
	Assert::same('user', $secondPayload['input'][2]['role']);
	Assert::same($userMessages[1], $secondPayload['input'][2]['content']);
});
