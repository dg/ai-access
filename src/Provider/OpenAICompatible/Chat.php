<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess\Chat\Effort;
use function array_filter, array_merge;


/**
 * Chat session against an OpenAI-compatible endpoint.
 */
final class Chat extends BaseChat
{
	/** @var mixed[]|null */
	private ?array $responseSchema = null;


	public function __construct(
		private readonly Client $client,
		string $model,
	) {
		parent::__construct($model);
	}


	/**
	 * @param  string|string[]|null  $stop  Sequences where the API will stop generating.
	 * @param  ?mixed[]  $responseFormat  Specify output format (e.g., ['type' => 'json_object']).
	 * @param  ?mixed[]  $custom  Anything else the endpoint accepts, merged into the payload as is.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?float $temperature = null,
		?float $topP = null,
		?float $frequencyPenalty = null,
		?float $presencePenalty = null,
		string|array|null $stop = null,
		?int $seed = null,
		?array $responseFormat = null,
		?array $custom = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_tokens' => $maxOutputTokens,
				'temperature' => $temperature,
				'top_p' => $topP,
				'frequency_penalty' => $frequencyPenalty,
				'presence_penalty' => $presencePenalty,
				'stop' => $stop,
				'seed' => $seed,
				'response_format' => $responseFormat,
			],
			fn($value) => $value !== null,
		), $custom ?? []);
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


	protected function callApi(array $payload): array
	{
		return $this->client->callApi('chat/completions', $payload);
	}


	protected function createResponse(array $raw): ChatResponse
	{
		return new ChatResponse($raw);
	}


	protected function provider(): string
	{
		return ChatResponse::Provider;
	}


	protected function amendPayload(array &$payload): void
	{
		if ($this->responseSchema !== null) {
			$payload['response_format'] = [
				'type' => 'json_schema',
				'json_schema' => ['name' => 'response', 'schema' => $this->responseSchema, 'strict' => true],
			];
		}

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
