<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use AIAccess\Http\SseStream;
use function array_filter, is_array, is_string;


/**
 * Shared request side of the chat/completions dialect, which several providers speak
 * verbatim: message serialization, tool definitions, the response schema and the stream
 * loop live here once. What genuinely differs - option names, the response subclass, and
 * a dialect spelling the effort or the schema its own way - stays in the subclasses.
 *
 * @internal
 */
abstract class BaseChat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	protected array $options = [];


	/**
	 * Constrains the answer to the given JSON Schema. Read the result with Response::getJson().
	 * An endpoint without structured output overrides with a throw.
	 * @param  mixed[]  $schema
	 */
	public function setResponseSchema(array $schema): static
	{
		// the same option setOptions(responseFormat:) writes, so the later call wins
		// instead of one silently overwriting the other
		$this->options['response_format'] = [
			'type' => 'json_schema',
			'json_schema' => ['name' => 'response', 'schema' => $schema, 'strict' => true],
		];
		return $this;
	}


	protected function generateResponse(): BaseChatResponse
	{
		return $this->createResponse($this->callApi($this->buildPayload()), cancelled: false);
	}


	protected function generateStreamResponse(\Closure $onDelta): BaseChatResponse
	{
		$accumulator = new StreamAccumulator;
		$stopped = SseStream::consume(
			fn(\Closure $onChunk) => $this->callApiStream($this->buildPayload(), $onChunk),
			fn(?string $name, string $json) => $accumulator->event($name, $json, $onDelta),
		);
		return $this->createResponse($accumulator->getResponse(), cancelled: $stopped);
	}


	/**
	 * @param  mixed[]  $payload
	 * @return mixed[]
	 */
	abstract protected function callApi(array $payload): array;


	/**
	 * @param  mixed[]  $payload
	 * @param  \Closure(string): (bool|null)  $onChunk
	 */
	abstract protected function callApiStream(array $payload, \Closure $onChunk): void;


	/** @param mixed[] $raw */
	abstract protected function createResponse(array $raw, bool $cancelled): BaseChatResponse;


	/**
	 * The BaseChatResponse::Provider tag whose raw parts this chat replays verbatim.
	 */
	abstract protected function provider(): string;


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

		$calls = $content = [];
		$hasMedia = false;
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
			} elseif ($part instanceof AIAccess\Media) {
				$content[] = $this->mediaContent($part);
				$hasMedia = true;
			} elseif ($part instanceof AIAccess\Chat\TextPart) {
				$content[] = ['type' => 'text', 'text' => $part->text];
			} else {
				throw new AIAccess\LogicException('This chat cannot send ' . get_debug_type($part) . ' content.');
			}
		}

		$text = $message->getText();
		if ($text === '' && !$calls && !$hasMedia) {
			return;
		}

		$item = [
			'role' => $message->getRole() === Role::User ? 'user' : 'assistant',
			// media force the parts form, in the order the parts came in;
			// plain text stays a plain string
			'content' => $hasMedia ? $content : ($text === '' ? null : $text),
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


	/**
	 * How a Media part goes on the wire; a provider that takes none overrides with a throw.
	 * @return mixed[]
	 */
	protected function mediaContent(AIAccess\Media $part): array
	{
		if (!$part->isImage()) {
			throw new AIAccess\LogicException('This endpoint cannot send ' . $part->getMimeType() . ' content, only images.');
		}
		return ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part->getMimeType() . ';base64,' . $part->getBase64()]];
	}


	/**
	 * Finishes the payload with what the dialects spell differently; a provider naming the
	 * reasoning effort otherwise overrides this.
	 * @param  mixed[]  $payload
	 */
	protected function amendPayload(array &$payload): void
	{
		if ($this->effort !== null) {
			$payload['reasoning_effort'] = match ($this->effort) {
				Effort::None => 'none',
				Effort::Low => 'low',
				Effort::Medium => 'medium',
				Effort::High, Effort::XHigh, Effort::Max => 'high',
			};
		}
	}
}
