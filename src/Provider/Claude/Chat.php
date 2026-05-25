<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use AIAccess\ServiceException;
use function array_filter, array_flip, array_intersect_key, array_merge, is_array;


/**
 * Claude implementation of a chat session state container.
 */
final class Chat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	private array $options = [];

	/** @var mixed[]|null */
	private ?array $responseSchema = null;


	public function __construct(
		private readonly Client $client,
		private readonly string $model,
	) {
	}


	/**
	 * Sets options specific to this Claude chat session.
	 * @param  ?int  $maxOutputTokens  Maximum tokens to generate (max_tokens)
	 * @param  ?string[]  $stopSequences  Sequences where the API will stop generating
	 * @param  ?float  $temperature  Controls randomness (0.0-1.0). Works on 4.6 and older only.
	 * @param  ?int  $topK  Top-k sampling parameter. Same restriction as temperature.
	 * @param  ?float  $topP  Nucleus sampling parameter. Same restriction as temperature.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?array $stopSequences = null,
		?float $temperature = null,
		?int $topK = null,
		?float $topP = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_tokens' => $maxOutputTokens,
				'stop_sequences' => $stopSequences,
				'temperature' => $temperature,
				'top_k' => $topK,
				'top_p' => $topP,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	/**
	 * Counts tokens for the current chat history, system instruction, tools and thinking mode.
	 * @throws ServiceException
	 */
	public function countTokens(): int
	{
		$payload = $this->buildPayload();
		$payload = array_intersect_key($payload, array_flip(['model', 'messages', 'system', 'tools', 'tool_choice', 'thinking']));
		$response = $this->client->callApi('v1/messages/count_tokens', $payload);
		return AIAccess\Helpers::expectInt($response['input_tokens'] ?? null, 'input_tokens');
	}


	/**
	 * Constrains the answer to the given JSON Schema. Read the result with Response::getJson().
	 * @param  mixed[]  $schema
	 */
	public function setResponseSchema(array $schema): static
	{
		$this->responseSchema = $schema;
		return $this;
	}


	protected function generateResponse(): ChatResponse
	{
		return new ChatResponse($this->client->callApi('v1/messages', $this->buildPayload()));
	}


	/**
	 * Builds the payload for the Claude API messages request.
	 * @return mixed[]
	 * @internal
	 */
	public function buildPayload(): array
	{
		if (!$this->messages) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		}

		$messages = [];
		foreach ($this->messages as $message) {
			// a turn left with nothing to send (only another provider's payload, say)
			// must be dropped, because the API rejects empty content
			if (($content = $this->buildContent($message)) === '' || $content === []) {
				continue;
			}
			$messages[] = [
				'role' => match ($message->getRole()) {
					Role::User, Role::Tool => 'user',
					Role::Model => 'assistant',
				},
				'content' => $content,
			];
		}

		$payload = [
			'model' => $this->model,
			'messages' => $messages,
			'max_tokens' => $this->options['max_tokens'] ?? 4096,
		] + $this->options;

		if ($this->systemInstruction !== null) {
			$payload['system'] = $this->systemInstruction;
		}

		if ($this->responseSchema !== null) {
			$payload['output_config']['format'] = ['type' => 'json_schema', 'schema' => $this->responseSchema];
		}

		foreach ($this->tools as $tool) {
			$payload['tools'][] = [
				'name' => $tool->name,
				'description' => $tool->description,
				'input_schema' => $tool->parameters ?: ['type' => 'object', 'properties' => new \stdClass],
			];
		}

		if ($this->toolChoice !== null) {
			$payload['tool_choice'] = ['type' => 'tool', 'name' => $this->toolChoice];
		}

		if ($this->effort === Effort::None) {
			$payload['thinking'] = ['type' => 'disabled'];
		} elseif ($this->effort !== null) {
			$payload['output_config']['effort'] = match ($this->effort) {
				Effort::Low => 'low',
				Effort::Medium => 'medium',
				Effort::High => 'high',
				Effort::XHigh => 'xhigh',
				Effort::Max => 'max',
			};
		}

		return $payload;
	}


	/**
	 * A plain string while the message is only text; thinking blocks force the block form
	 * and must be returned byte for byte, signature included, in their original position
	 * (interleaved thinking sits between tool calls), or the API answers 400.
	 * @return string|list<mixed>
	 */
	private function buildContent(AIAccess\Chat\Message $message): string|array
	{
		if ($message->isTextOnly()) {
			return $message->getText();
		}

		$first = $blocks = [];
		foreach ($message->getParts() as $part) {
			if ($part instanceof AIAccess\Chat\ReasoningPart) {
				if ($part->provider === ChatResponse::Provider && $part->raw !== null) {
					$blocks[] = $part->raw;
				}
			} elseif ($part instanceof AIAccess\Chat\ToolResultPart) {
				$first[] = array_filter([
					'type' => 'tool_result',
					'tool_use_id' => $part->callId,
					'content' => is_array($part->content) ? AIAccess\Helpers::encodeJson($part->content) : $part->content,
					'is_error' => $part->isError ?: null,
				], fn($value) => $value !== null);
			} elseif ($part instanceof AIAccess\Chat\ToolCallPart) {
				$block = $part->provider === ChatResponse::Provider && $part->raw !== null
					? $part->raw
					: ['type' => 'tool_use', 'id' => $part->callId, 'name' => $part->name, 'input' => $part->arguments];
				// an empty argument list decodes to [], which would serialize back as a JSON
				// array; the API insists input is an object
				if (($block['input'] ?? null) === []) {
					$block['input'] = new \stdClass;
				}
				$blocks[] = $block;
			} elseif ($part instanceof AIAccess\Chat\TextPart) {
				$blocks[] = ['type' => 'text', 'text' => $part->text];
			} elseif ($part instanceof AIAccess\Media) {
				$blocks[] = [
					'type' => $part->isImage() ? 'image' : 'document',
					'source' => [
						'type' => 'base64',
						'media_type' => $part->getMimeType(),
						'data' => $part->getBase64(),
					],
				];
			} else {
				throw new AIAccess\LogicException('Claude chat cannot send ' . get_debug_type($part) . ' content.');
			}
		}
		// tool_result blocks are rejected unless they lead the turn
		return array_merge($first, $blocks);
	}
}
