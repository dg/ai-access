<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess;
use AIAccess\Chat\Role;
use function array_filter, is_array, is_string;


/**
 * Shared request side of the chat/completions dialect, which several providers speak
 * verbatim: message serialization and tool definitions live here once. What genuinely
 * differs - option names, effort mapping, the response subclass - stays in the subclasses.
 *
 * @internal
 */
abstract class BaseChat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	protected array $options = [];


	public function __construct(
		protected readonly string $model,
	) {
	}


	protected function generateResponse(): AIAccess\Chat\Response
	{
		return $this->createResponse($this->callApi($this->buildPayload()));
	}


	/**
	 * @param  mixed[]  $payload
	 * @return mixed[]
	 */
	abstract protected function callApi(array $payload): array;


	/** @param mixed[] $raw */
	abstract protected function createResponse(array $raw): AIAccess\Chat\Response;


	/**
	 * The ChatResponse::Provider tag whose raw parts this chat replays verbatim.
	 */
	abstract protected function provider(): string;


	/**
	 * Finishes the payload with what the dialects spell differently: the response schema
	 * and the reasoning effort.
	 * @param  mixed[]  $payload
	 */
	abstract protected function amendPayload(array &$payload): void;


	/** @return mixed[] */
	protected function buildPayload(): array
	{
		if (!$this->messages) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		}

		$messages = [];
		if ($this->systemInstruction !== null) {
			$messages[] = ['role' => 'system', 'content' => $this->systemInstruction];
		}

		foreach ($this->messages as $message) {
			$this->appendMessage($messages, $message);
		}

		$payload = [
			'model' => $this->model,
			'messages' => $messages,
		] + $this->options;

		foreach ($this->tools as $tool) {
			$payload['tools'][] = [
				'type' => 'function',
				'function' => array_filter([
					'name' => $tool->name,
					'description' => $tool->description,
					'parameters' => $tool->parameters ?: ['type' => 'object', 'properties' => new \stdClass],
					'strict' => $tool->strict ?: null,
				], fn($value) => $value !== null),
			];
		}

		if ($this->toolChoice !== null) {
			$payload['tool_choice'] = ['type' => 'function', 'function' => ['name' => $this->toolChoice]];
		}

		$this->amendPayload($payload);
		return $payload;
	}


	/**
	 * One message can expand into several: every tool result is a message of its own.
	 * @param  list<mixed>  $messages
	 */
	private function appendMessage(array &$messages, AIAccess\Chat\Message $message): void
	{
		if ($message->getRole() === Role::Tool) {
			foreach ($message->getParts() as $part) {
				if (!$part instanceof AIAccess\Chat\ToolResultPart) {
					throw new AIAccess\LogicException('A tool message accepts tool results only, ' . get_debug_type($part) . ' given.');
				}
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $part->callId,
					// there is no error flag here, so the model has to read it in the text
					'content' => ($part->isError ? 'ERROR: ' : '')
						. (is_array($part->content) ? AIAccess\Helpers::encodeJson($part->content) : $part->content),
				];
			}
			return;
		}

		$calls = [];
		$reasoning = null;
		foreach ($message->getParts() as $part) {
			if ($part instanceof AIAccess\Chat\ToolCallPart) {
				$calls[] = $part->provider === $this->provider() && $part->raw !== null
					? $part->raw
					: [
						'id' => $part->callId,
						'type' => 'function',
						// [] would serialize as a JSON array, and arguments must be an object
						'function' => [
							'name' => $part->name,
							'arguments' => $part->arguments ? AIAccess\Helpers::encodeJson($part->arguments) : '{}',
						],
					];
			} elseif ($part instanceof AIAccess\Chat\ReasoningPart) {
				if ($part->provider === $this->provider() && is_string($part->raw)) {
					$reasoning = $part->raw;
				}
			} elseif (!$part instanceof AIAccess\Chat\TextPart) {
				throw new AIAccess\LogicException('This chat supports text content only, ' . get_debug_type($part) . ' given.');
			}
		}

		$text = $message->getText();
		if ($text === '' && !$calls) {
			return;
		}

		$item = [
			'role' => $message->getRole() === Role::User ? 'user' : 'assistant',
			'content' => $text === '' ? null : $text,
		];
		if ($calls) {
			$item['tool_calls'] = $calls;
			// the endpoint accepts a tool call turn without it, but returning it keeps the
			// chain of thought intact across rounds, which is what the docs ask for
			if ($reasoning !== null) {
				$item['reasoning_content'] = $reasoning;
			}
		}
		$messages[] = $item;
	}
}
