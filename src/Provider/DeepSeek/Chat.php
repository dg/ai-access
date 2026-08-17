<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\DeepSeek;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Provider\OpenAICompatible;
use Nette\Schema\Elements\Structure;
use function array_filter, array_merge;


/**
 * DeepSeek implementation of a chat session state container.
 */
final class Chat extends OpenAICompatible\BaseChat
{
	public function __construct(
		private readonly Client $client,
		string $model,
	) {
		$this->setModel($model);
	}


	/**
	 * Sets options specific to this DeepSeek chat session.
	 *
	 * @param  ?int  $maxOutputTokens  Maximum tokens to generate (max_tokens).
	 * @param  ?float  $temperature  Controls randomness (0.0-2.0). Ignored while thinking is enabled, which is the default.
	 * @param  ?float  $topP  Nucleus sampling parameter (0.0-1.0). Ignored while thinking is enabled.
	 * @param  string|string[]|null  $stopSequences  Sequences where the API will stop generating.
	 * @param  ?mixed[]  $responseFormat  Specify output format. JSON mode is ['type' => 'json_object']
	 *         and demands the word "json" somewhere in the conversation, or the API answers 400.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?float $temperature = null,
		?float $topP = null,
		string|array|null $stopSequences = null,
		?array $responseFormat = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_tokens' => $maxOutputTokens,
				'temperature' => $temperature,
				'top_p' => $topP,
				'stop' => $stopSequences,
				'response_format' => $responseFormat,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	/**
	 * Always throws: measured, a json_schema format answers "This response_format type is
	 * unavailable now", and asking silently would return prose where the caller expects data.
	 * @param  mixed[]|Structure  $schema
	 */
	public function setResponseSchema(array|Structure $schema): static
	{
		throw new AIAccess\LogicException("DeepSeek has no JSON schema; use setOptions(responseFormat: ['type' => 'json_object']) for its JSON mode.");
	}


	protected function callApi(array $payload): array
	{
		return $this->client->callApi('chat/completions', $payload);
	}


	protected function callApiStream(array $payload, \Closure $onChunk): void
	{
		$this->client->callApiStream('chat/completions', $payload, $onChunk);
	}


	protected function createResponse(array $raw, bool $cancelled): ChatResponse
	{
		return new ChatResponse($raw, $cancelled);
	}


	protected function provider(): string
	{
		return ChatResponse::Provider;
	}


	protected function mediaContent(AIAccess\Media $part): array
	{
		throw new AIAccess\LogicException('DeepSeek cannot send ' . $part->getMimeType() . ' content: it has no vision model.');
	}


	protected function amendPayload(array &$payload): void
	{
		if ($this->effort !== null) {
			$payload['thinking'] = $this->effort === Effort::None
				? ['type' => 'disabled']
				: ['type' => 'enabled', 'reasoning_effort' => match ($this->effort) {
					Effort::Low => 'low',
					Effort::Medium, Effort::High => 'high',
					Effort::XHigh, Effort::Max => 'max',
				}];
		}
	}
}
