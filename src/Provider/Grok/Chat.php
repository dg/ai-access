<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Grok;

use AIAccess;
use AIAccess\Provider\OpenAICompatible;
use function array_filter, array_merge;


/**
 * Grok (xAI) implementation of a chat session state container.
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
	 * Sets options specific to this Grok chat session.
	 *
	 * @param  ?int  $maxOutputTokens  Maximum completion tokens (max_completion_tokens).
	 * @param  ?float  $temperature  Sampling temperature (0.0-2.0).
	 * @param  ?float  $topP  Nucleus sampling parameter (0.0-1.0).
	 * @param  ?float  $frequencyPenalty  Penalizes new tokens based on frequency (-2.0 to 2.0). Not supported by reasoning models.
	 * @param  ?float  $presencePenalty  Penalizes new tokens based on presence (-2.0 to 2.0). Not supported by reasoning models.
	 * @param  string|string[]|null  $stopSequences  Sequences where the API will stop generating. Not supported by reasoning models.
	 * @param  ?int  $seed  Seed for deterministic sampling (best effort).
	 * @param  ?mixed[]  $responseFormat  Specify output format (e.g., ['type' => 'json_object']).
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?float $temperature = null,
		?float $topP = null,
		?float $frequencyPenalty = null,
		?float $presencePenalty = null,
		string|array|null $stopSequences = null,
		?int $seed = null,
		?array $responseFormat = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_completion_tokens' => $maxOutputTokens,
				'temperature' => $temperature,
				'top_p' => $topP,
				'frequency_penalty' => $frequencyPenalty,
				'presence_penalty' => $presencePenalty,
				'stop' => $stopSequences,
				'seed' => $seed,
				'response_format' => $responseFormat,
			],
			fn($value) => $value !== null,
		));
		if ($responseFormat !== null) {
			$this->responseSchema = null; // the raw format replaced the schema on the wire, so it must not judge the answer either
		}
		return $this;
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
		return new ChatResponse($raw, $cancelled, $this->responseSchema);
	}


	protected function provider(): string
	{
		return ChatResponse::Provider;
	}


	protected function mediaContent(AIAccess\Media $part): array
	{
		if (!$part->isImage()) {
			throw new AIAccess\LogicException('Grok cannot send ' . $part->getMimeType() . ' content, only images.');
		}
		return parent::mediaContent($part);
	}
}
