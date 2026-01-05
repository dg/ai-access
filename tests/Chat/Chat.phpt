<?php declare(strict_types=1);

use AIAccess\Chat\Chat;
use AIAccess\Chat\Message;
use AIAccess\Chat\Response;
use AIAccess\Chat\Role;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


// Message class tests
test('Message class construction and getters', function () {
	$text = 'Hello, world!';
	$role = Role::User;

	$message = new Message($text, $role);

	Assert::same($text, $message->getText());
	Assert::same($role, $message->getRole());
});


test('Message with empty content', function () {
	$message = new Message('', Role::User);
	Assert::same('', $message->getText());
});


test('Message with special characters', function () {
	$specialText = "Line 1\nLine 2\t\r\nEmoji 🙂";
	$message = new Message($specialText, Role::Model);
	Assert::same($specialText, $message->getText());
});


// Test implementation of the abstract Chat class
class TestChat extends Chat
{
	private ?Response $mockedResponse = null;
	private ?string $lastErrorMessage = null;


	public function setMockedResponse(Response $response): self
	{
		$this->mockedResponse = $response;
		return $this;
	}


	public function setErrorBehavior(string $errorMessage): self
	{
		$this->lastErrorMessage = $errorMessage;
		return $this;
	}


	protected function generateResponse(): Response
	{
		if ($this->lastErrorMessage !== null) {
			throw new RuntimeException($this->lastErrorMessage);
		}

		if ($this->mockedResponse === null) {
			$response = Mockery::mock(Response::class);
			$response->allows()->getText()->andReturn('Default response');
			return $response;
		}

		return $this->mockedResponse;
	}
}


// Chat abstract class functionality tests
test('Chat message history management', function () {
	$chat = new TestChat;

	// Initially empty
	Assert::count(0, $chat->getMessages());

	// Add messages and check storage
	$msg1 = $chat->addMessage('User message', Role::User);
	Assert::count(1, $chat->getMessages());
	Assert::same($msg1, $chat->getMessages()[0]);

	$msg2 = $chat->addMessage('Model response', Role::Model);
	Assert::count(2, $chat->getMessages());
	Assert::same($msg2, $chat->getMessages()[1]);

	// Check the stored messages have correct content
	Assert::same('User message', $chat->getMessages()[0]->getText());
	Assert::same(Role::User, $chat->getMessages()[0]->getRole());

	Assert::same('Model response', $chat->getMessages()[1]->getText());
	Assert::same(Role::Model, $chat->getMessages()[1]->getRole());
});


test('Chat system instruction', function () {
	$chat = new TestChat;
	$instruction = 'You are a helpful AI assistant.';

	// Test fluent interface
	$result = $chat->setSystemInstruction($instruction);
	Assert::same($chat, $result);
});


test('Chat sendMessage basic flow', function () {
	$chat = new TestChat;

	// Mock a response
	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()->getText()->andReturn('Model reply');
	$chat->setMockedResponse($mockResponse);

	// Send a message and check response
	$response = $chat->sendMessage('User query');
	Assert::same($mockResponse, $response);

	// Check message history updated
	Assert::count(2, $chat->getMessages());
	Assert::same('User query', $chat->getMessages()[0]->getText());
	Assert::same('Model reply', $chat->getMessages()[1]->getText());
});


test('Chat sendMessage with null input continues conversation', function () {
	$chat = new TestChat;

	// First add a user message manually
	$chat->addMessage('Initial user message', Role::User);

	// Mock a response
	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()->getText()->andReturn('Model continuation');
	$chat->setMockedResponse($mockResponse);

	// Send null to continue
	$response = $chat->sendMessage(null);
	Assert::same($mockResponse, $response);

	// Check message history
	Assert::count(2, $chat->getMessages());
	Assert::same('Model continuation', $chat->getMessages()[1]->getText());
});


test('Chat sendMessage error handling', function () {
	$chat = new TestChat;

	// Configure error behavior
	$errorMessage = 'API Error';
	$chat->setErrorBehavior($errorMessage);

	// Attempt to send message, should throw exception
	Assert::exception(
		fn() => $chat->sendMessage('This will fail'),
		RuntimeException::class,
		$errorMessage,
	);

	// Message history should remain empty (error occurred during generateResponse)
	Assert::count(0, $chat->getMessages());
});


test('Chat sendMessage with null response text', function () {
	$chat = new TestChat;

	// Mock a response with null text (e.g., content filtered)
	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()->getText()->andReturn(null);
	$chat->setMockedResponse($mockResponse);

	// Send a message
	$response = $chat->sendMessage('User query');
	Assert::same($mockResponse, $response);

	// Only user message should be in history (no null model response added)
	Assert::count(1, $chat->getMessages());
	Assert::same('User query', $chat->getMessages()[0]->getText());
});
