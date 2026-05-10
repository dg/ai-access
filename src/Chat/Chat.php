<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;
use AIAccess\LogicException;
use AIAccess\ServiceException;
use function array_reverse, count;


/**
 * Conversation.
 */
abstract class Chat
{
	/** @var list<Message> */
	protected array $messages = [];
	protected ?string $systemInstruction = null;
	protected ?Effort $effort = null;

	/** @var array<string, Tool> */
	protected array $tools = [];
	protected ?string $toolChoice = null;


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
	 * Registers a function the model may call.
	 */
	public function addTool(Tool $tool): static
	{
		$this->tools[$tool->name] = $tool;
		return $this;
	}


	/**
	 * Forces the model to call the named tool. Null leaves the choice to the model;
	 * to forbid tools entirely, register none.
	 */
	public function setToolChoice(?string $name): static
	{
		if ($name !== null && !isset($this->tools[$name])) {
			throw new LogicException("Unknown tool '$name'.");
		}
		$this->toolChoice = $name;
		return $this;
	}


	/**
	 * Answers a tool call. Results are gathered into a single message, because Claude
	 * rejects parallel results spread over several turns; merging replaces the previous
	 * Tool message, so do not hold on to an earlier return value.
	 *
	 * Pass the ToolCallPart itself where a provider sends no call ids, as Gemini usually does.
	 * @param  string|mixed[]  $result
	 */
	public function addToolResult(ToolCallPart|string $call, string|array $result, bool $isError = false): Message
	{
		$part = new ToolResultPart(
			$call instanceof ToolCallPart ? $call->callId : $call,
			$call instanceof ToolCallPart ? $call->name : $this->findToolCall($call)->name,
			$result,
			$isError,
		);

		$last = $this->messages[count($this->messages) - 1] ?? null;
		return $last?->getRole() === Role::Tool
			? $this->messages[count($this->messages) - 1] = new Message([...$last->getParts(), $part], Role::Tool)
			: $this->messages[] = new Message($part, Role::Tool);
	}


	private function findToolCall(string $callId): ToolCallPart
	{
		// backwards, because an id is unique only within a turn: Gemini often sends none at
		// all, so a search from the front would answer a call settled several rounds ago
		foreach (array_reverse($this->messages) as $message) {
			foreach (array_reverse($message->getParts()) as $part) {
				if ($part instanceof ToolCallPart && $part->callId === $callId) {
					return $part;
				}
			}
		}
		throw new LogicException("No tool call '$callId' in the history.");
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
