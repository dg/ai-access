<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;
use AIAccess\ServiceException;


/**
 * Conversation.
 */
abstract class Chat
{
	/** @var list<Message> */
	protected array $messages = [];
	protected ?string $systemInstruction = null;
	protected ?Effort $effort = null;


	/**
	 * Sends the next message to the model or continues generation based on history.
	 * Updates internal message history with user input and model response.
	 * @param  string|Part|list<string|Part>|null  $message
	 * @throws ServiceException
	 */
	public function sendMessage(string|Part|array|null $message = null): Response
	{
		$save = $this->messages;
		if ($message !== null) {
			$this->addMessage($message, Role::User);
		}

		try {
			$response = $this->generateResponse();
		} catch (\Throwable $e) {
			$this->messages = $save;
			throw $e;
		}

		// a turn with no text but with reasoning or (later) tool calls still belongs in the history
		$message = $response->getMessage();
		if ($message->getParts()) {
			$this->messages[] = $message;
		}

		return $response;
	}


	/**
	 * Adds a message to the chat history without sending it to the API.
	 * @param  string|Part|list<string|Part>  $message
	 */
	public function addMessage(string|Part|array $message, Role $role): Message
	{
		return $this->messages[] = new Message($message, $role);
	}


	/**
	 * Retrieves the current message history (user and model messages).
	 * @return list<Message>
	 */
	public function getMessages(): array
	{
		return $this->messages;
	}


	/**
	 * Sets a system-level instruction for the model.
	 */
	public function setSystemInstruction(string $instruction): static
	{
		$this->systemInstruction = $instruction;
		return $this;
	}


	/**
	 * Sets how much the model should reason before answering. Null keeps the provider's default.
	 */
	public function setEffort(?Effort $effort): static
	{
		$this->effort = $effort;
		return $this;
	}


	/**
	 * Removes all messages from the history.
	 */
	public function clearMessages(): static
	{
		$this->messages = [];
		return $this;
	}


	/**
	 * Generates the next response based on the current chat history and settings.
	 */
	abstract protected function generateResponse(): Response;
}
