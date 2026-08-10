<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use function array_filter, array_flip, array_intersect_key, array_merge, compact, is_array;


/**
 * Gemini implementation of a chat session state container.
 */
final class Chat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	private array $options = [];

	/** @var mixed[]|null */
	private ?array $responseSchema = null;


	public function __construct(
		private readonly Client $client,
		string $model,
	) {
		$this->setModel($model);
	}


	/**
	 * Sets options specific to this Gemini chat session.
	 *
	 * @param  ?int  $maxOutputTokens  Maximum tokens to generate.
	 * @param  ?mixed[]  $safetySettings  Safety filter settings.
	 * @param  ?string[]  $stopSequences  Sequences where the API will stop generating.
	 * @param  ?float  $temperature  Controls randomness (0.0-2.0). Deprecated, silently ignored by Gemini 3.6 and later.
	 * @param  ?int  $topK  Top-k sampling parameter. Same deprecation as temperature.
	 * @param  ?float  $topP  Nucleus sampling parameter. Same deprecation as temperature.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?array $safetySettings = null,
		?array $stopSequences = null,
		?float $temperature = null,
		?int $topK = null,
		?float $topP = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			compact('maxOutputTokens', 'safetySettings', 'stopSequences', 'temperature', 'topK', 'topP'),
			fn($value) => $value !== null,
		));
		return $this;
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


	/**
	 * Counts tokens of the current history and system instruction.
	 * @throws AIAccess\ServiceException
	 */
	public function countTokens(): int
	{
		$response = $this->client->callApi('models/' . $this->model . ':countTokens', [
			'generateContentRequest' => ['model' => 'models/' . $this->model] + $this->buildPayload(),
		]);
		return AIAccess\Helpers::expectInt($response['totalTokens'] ?? null, 'totalTokens');
	}


	protected function generateResponse(): ChatResponse
	{
		$response = $this->client->callApi('models/' . $this->model . ':generateContent', $this->buildPayload());
		return new ChatResponse($response);
	}


	protected function generateStreamResponse(\Closure $onDelta): ChatResponse
	{
		$accumulator = new StreamAccumulator;
		$stopped = AIAccess\Http\SseStream::consume(
			fn(\Closure $onChunk) => $this->client->callApiStream('models/' . $this->model . ':streamGenerateContent?alt=sse', $this->buildPayload(), $onChunk),
			fn(?string $name, string $json) => $accumulator->event($name, $json, $onDelta),
		);
		return new ChatResponse($accumulator->getResponse(), cancelled: $stopped);
	}


	/**
	 * Builds the payload for the Gemini API generateContent request.
	 * @return mixed[]
	 * @internal  public for Batch, which reuses the very same request shape
	 */
	public function buildPayload(): array
	{
		if (!$this->messages) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		} elseif ($this->messages[0]->getRole() !== Role::User) {
			throw new AIAccess\LogicException('The first message must be from the user role.');
		}

		$payload = [];
		$lastRole = null;
		foreach ($this->messages as $message) {
			// a turn left with nothing to send (only another provider's payload, say)
			// must be dropped, because the API rejects empty parts
			if (!$parts = $this->buildParts($message)) {
				continue;
			}
			// Gemini has no tool role; results travel as a user turn
			$role = match ($message->getRole()) {
				Role::User, Role::Tool => 'user',
				Role::Model => 'model',
			};
			if ($lastRole === $role) {
				// For now, let the API potentially handle it.
				trigger_error("Consecutive messages with the same role ('$role') detected. Gemini requires alternating roles.", E_USER_WARNING);
			}
			$payload['contents'][] = [
				'role' => $role,
				'parts' => $parts,
			];
			$lastRole = $role;
		}

		if ($this->systemInstruction !== null) {
			$payload['systemInstruction'] = [
				'parts' => [['text' => $this->systemInstruction]],
			];
		}

		$generationConfig = array_intersect_key(
			$this->options,
			array_flip(['temperature', 'maxOutputTokens', 'topP', 'topK', 'stopSequences']),
		);
		if ($generationConfig) {
			$payload['generationConfig'] = $generationConfig;
		}

		if ($this->responseSchema !== null) {
			$payload['generationConfig']['responseMimeType'] = 'application/json';
			$payload['generationConfig']['responseJsonSchema'] = $this->responseSchema;
		}

		foreach ($this->tools as $tool) {
			// parametersJsonSchema takes plain JSON Schema; the older parameters field wants
			// OpenAPI with upper-case types
			$payload['tools'][0]['functionDeclarations'][] = [
				'name' => $tool->name,
				'description' => $tool->description,
				'parametersJsonSchema' => $tool->parameters ?: ['type' => 'object', 'properties' => new \stdClass],
			];
		}

		if ($this->toolChoice !== null) {
			$payload['toolConfig']['functionCallingConfig'] = [
				'mode' => 'ANY',
				'allowedFunctionNames' => [$this->toolChoice],
			];
		}

		if ($this->effort !== null) {
			$payload['generationConfig']['thinkingConfig'] = $this->effort === Effort::None
				? ['thinkingBudget' => 0]
				: ['thinkingLevel' => match ($this->effort) {
					Effort::Low => 'LOW',
					Effort::Medium => 'MEDIUM',
					Effort::High, Effort::XHigh, Effort::Max => 'HIGH',
				}];
		}

		if (isset($this->options['safetySettings'])) {
			$payload['safetySettings'] = $this->options['safetySettings'];
		}

		return $payload;
	}


	/**
	 * Raw parts are replayed verbatim because they may carry a thoughtSignature;
	 * without it Gemini answers finishReason: MISSING_THOUGHT_SIGNATURE.
	 * @return list<mixed>
	 */
	private function buildParts(AIAccess\Chat\Message $message): array
	{
		$parts = [];
		foreach ($message->getParts() as $part) {
			if ($part instanceof AIAccess\Chat\ReasoningPart) {
				if ($part->provider === ChatResponse::Provider && $part->raw !== null) {
					$parts[] = $part->raw;
				}
			} elseif ($part instanceof AIAccess\Chat\ToolCallPart) {
				if ($part->provider === ChatResponse::Provider && $part->raw !== null) {
					$parts[] = $part->raw;
				} else {
					// a foreign call keeps its id, so the paired functionResponse below matches;
					// empty args must serialize as an object, not as []
					$call = ['name' => $part->name, 'args' => $part->arguments ?: new \stdClass];
					if (!str_starts_with($part->callId, $part->name . '#')) {
						$call = ['id' => $part->callId] + $call;
					}
					$parts[] = ['functionCall' => $call];
				}
			} elseif ($part instanceof AIAccess\Chat\ToolResultPart) {
				// the response has to be an object - a Struct - so a list or an empty array
				// gets wrapped; the id goes along whenever the call carried one, otherwise
				// parallel calls of the same function could not be told apart
				$response = [
					'name' => $part->name,
					'response' => match (true) {
						$part->isError => ['error' => $part->content],
						!is_array($part->content) => ['result' => $part->content],
						$part->content === [] => new \stdClass,
						array_is_list($part->content) => ['result' => $part->content],
						default => $part->content,
					},
				];
				if (!str_starts_with($part->callId, $part->name . '#')) {
					$response['id'] = $part->callId;
				}
				$parts[] = ['functionResponse' => $response];
			} elseif ($part instanceof AIAccess\Chat\TextPart) {
				if ($part->provider === ChatResponse::Provider && $part->raw !== null) {
					// the raw part travels even with no text: it is where thoughtSignature hangs
					$parts[] = $part->raw;
				} elseif ($part->text !== '') {
					$parts[] = ['text' => $part->text];
				}
			} elseif ($part instanceof AIAccess\Media) {
				// images and documents travel the same way here
				$parts[] = ['inlineData' => ['mimeType' => $part->getMimeType(), 'data' => $part->getBase64()]];
			} else {
				throw new AIAccess\LogicException('Gemini chat cannot send ' . get_debug_type($part) . ' content.');
			}
		}
		return $parts;
	}
}
