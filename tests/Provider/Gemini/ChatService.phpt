<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\LogicException;
use AIAccess\Provider\Gemini\Chat;
use AIAccess\Provider\Gemini\ChatResponse;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('Chat initialization with model', function () {
	$modelName = 'gemini-1.5-pro';

	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, $modelName);

	Assert::type(Chat::class, $chat);
});


test('Chat setOptions returns self for fluent interface', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'gemini-1.5-pro');

	$result = $chat->setOptions(
		maxOutputTokens: 100,
		temperature: 0.7,
		topK: 10,
		topP: 0.9,
		stopSequences: ['STOP'],
		safetySettings: [['category' => 'HARM_CATEGORY_DANGEROUS', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE']],
	);

	Assert::same($chat, $result);
});


test('Chat builds correct API payload with user message', function () {
	$modelName = 'gemini-1.5-pro';
	$userMessage = 'Hello Gemini';
	$systemInstruction = 'You are a helpful assistant';

	// Captured payload to verify
	$capturedPayload = null;
	$endpoint = 'models/' . $modelName . ':generateContent';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with($endpoint, Mockery::capture($capturedPayload))
		->andReturn(['candidates' => [['content' => ['parts' => [['text' => 'Response text']]]]]]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setSystemInstruction($systemInstruction);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction by generating response
	$chat->sendMessage(null);

	// Now verify the captured payload structure
	Assert::true(isset($capturedPayload['contents']));
	Assert::count(1, $capturedPayload['contents']);
	Assert::same('user', $capturedPayload['contents'][0]['role']);
	Assert::same($userMessage, $capturedPayload['contents'][0]['parts'][0]['text']);
	Assert::true(isset($capturedPayload['systemInstruction']));
	Assert::same($systemInstruction, $capturedPayload['systemInstruction']['parts'][0]['text']);
});


test('Chat builds correct API payload with all options', function () {
	$modelName = 'gemini-1.5-pro';
	$userMessage = 'Hello Gemini';

	// Captured payload to verify
	$capturedPayload = null;
	$endpoint = 'models/' . $modelName . ':generateContent';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->with($endpoint, Mockery::capture($capturedPayload))
		->andReturn(['candidates' => [['content' => ['parts' => [['text' => 'Response text']]]]]]);

	$chat = new Chat($clientMock, $modelName);
	$chat->setOptions(
		maxOutputTokens: 500,
		temperature: 0.5,
		topK: 20,
		topP: 0.8,
		stopSequences: ['STOP', 'END'],
		safetySettings: [
			['category' => 'HARM_CATEGORY_DANGEROUS', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
		],
	);
	$chat->addMessage($userMessage, Role::User);

	// Trigger payload construction
	$chat->sendMessage(null);

	// Verify all options in generationConfig
	Assert::true(isset($capturedPayload['generationConfig']));
	Assert::same(500, $capturedPayload['generationConfig']['maxOutputTokens']);
	Assert::same(0.5, $capturedPayload['generationConfig']['temperature']);
	Assert::same(20.0, $capturedPayload['generationConfig']['topK']);
	Assert::same(0.8, $capturedPayload['generationConfig']['topP']);
	Assert::same(['STOP', 'END'], $capturedPayload['generationConfig']['stopSequences']);

	// Verify safetySettings
	Assert::true(isset($capturedPayload['safetySettings']));
	Assert::count(1, $capturedPayload['safetySettings']);
	Assert::same('HARM_CATEGORY_DANGEROUS', $capturedPayload['safetySettings'][0]['category']);
});


test('Chat sendMessage correctly updates message history with model response', function () {
	$userMessage = 'Tell me a joke';
	$modelResponse = 'Why did the chicken cross the road?';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->allows('callApi')
		->andReturn(['candidates' => [
			['content' => ['parts' => [['text' => $modelResponse]]]],
		]]);

	$chat = new Chat($clientMock, 'gemini-1.5-pro');
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
	$chat = new Chat($clientMock, 'gemini-1.5-pro');

	Assert::exception(
		fn() => $chat->sendMessage(null),
		LogicException::class,
		'Cannot send request with empty message history.',
	);
});


test('Chat throws exception if first message is not from user', function () {
	$clientMock = Mockery::mock(Client::class);
	$chat = new Chat($clientMock, 'gemini-1.5-pro');

	// Add a model message first (not allowed in Gemini)
	$chat->addMessage('Model message', Role::Model);

	Assert::exception(
		fn() => $chat->sendMessage(null),
		LogicException::class,
		'The first message must be from the user role.',
	);
});


test('Chat warns about consecutive messages with same role', function () {
	$endpoint = 'models/gemini-1.5-pro:generateContent';

	$clientMock = Mockery::mock(Client::class);
	$clientMock->allows('callApi')
		->with($endpoint, Mockery::any())
		->andReturn(['candidates' => [
			['content' => ['parts' => [['text' => 'Response']]]],
		]]);

	$chat = new Chat($clientMock, 'gemini-1.5-pro');

	// Add two consecutive user messages
	$chat->addMessage('First user message', Role::User);
	$chat->addMessage('Second user message', Role::User);

	// This should trigger a warning
	Assert::error(function () use ($chat) {
		$chat->sendMessage(null);
	}, E_USER_WARNING, "Consecutive messages with the same role ('user') detected. Gemini requires alternating roles.");
});


test('Chat correctly builds message history with alternating roles', function () {
	$userMessages = ['User question 1', 'User question 2'];
	$modelResponses = ['Model response 1', 'Model response 2'];

	$clientMock = Mockery::mock(Client::class);
	$clientMock->expects('callApi')
		->twice()
		->andReturnValues([
			['candidates' => [['content' => ['parts' => [['text' => $modelResponses[0]]]]]]],
			['candidates' => [['content' => ['parts' => [['text' => $modelResponses[1]]]]]]],
		]);

	$chat = new Chat($clientMock, 'gemini-1.5-pro');

	// First exchange
	$chat->sendMessage($userMessages[0]);

	// Second exchange
	$chat->sendMessage($userMessages[1]);

	// Verify full history has alternating roles
	$messages = $chat->getMessages();
	Assert::count(4, $messages);
	Assert::same(Role::User, $messages[0]->getRole());
	Assert::same(Role::Model, $messages[1]->getRole());
	Assert::same(Role::User, $messages[2]->getRole());
	Assert::same(Role::Model, $messages[3]->getRole());
});
