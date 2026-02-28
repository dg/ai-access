<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\DeepSeek;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use function array_filter, array_merge;


/**
 * DeepSeek implementation of a chat session state container.
 */
final class Chat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	private array $options = [];


	public function __construct(
		private readonly Client $client,
		private readonly string $model,
	) {
	}


	/**
	 * Sets options specific to this DeepSeek chat session.
	 *
	 * @param  ?int  $maxOutputTokens  Maximum tokens to generate (max_tokens).
	 * @param  ?float  $temperature  Controls randomness (0.0-2.0). Ignored while thinking is enabled, which is the default.
	 * @param  ?float  $topP  Nucleus sampling parameter (0.0-1.0). Ignored while thinking is enabled.
	 * @param  string|string[]|null  $stop  Sequences where the API will stop generating.
	 * @param  ?mixed[]  $responseFormat  Specify output format (e.g., ['type' => 'json_object']).
	 * @param  ?mixed[]  $tools  List of tools the model may call.
	 * @param  string|mixed[]|null  $toolChoice  Controls which tool is called.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?float $temperature = null,
		?float $topP = null,
		string|array|null $stop = null,
		?array $responseFormat = null,
		?array $tools = null,
		string|array|null $toolChoice = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_tokens' => $maxOutputTokens,
				'temperature' => $temperature,
				'top_p' => $topP,
				'stop' => $stop,
				'response_format' => $responseFormat,
				'tools' => $tools,
				'tool_choice' => $toolChoice,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	protected function generateResponse(): ChatResponse
	{
		$response = $this->client->callApi('chat/completions', $this->buildPayload());
		return new ChatResponse($response);
	}


	/**
	 * Builds the payload for the DeepSeek API chat completions request.
	 * @return mixed[]
	 */
	private function buildPayload(): array
	{
		if (!$this->messages) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		}

		$messages = [];
		if ($this->systemInstruction !== null) {
			$messages[] = [
				'role' => 'system',
				'content' => $this->systemInstruction,
			];
		}

		foreach ($this->messages as $message) {
			$messages[] = [
				'role' => match ($message->getRole()) {
					Role::User => 'user',
					Role::Model => 'assistant',
				},
				'content' => $message->getText(),
			];
		}

		$payload = [
			'model' => $this->model,
			'messages' => $messages,
		] + $this->options;

		if ($this->effort !== null) {
			$payload['thinking'] = $this->effort === Effort::None
				? ['type' => 'disabled']
				: ['type' => 'enabled', 'reasoning_effort' => match ($this->effort) {
					Effort::Low => 'low',
					Effort::Medium, Effort::High => 'high',
					Effort::XHigh, Effort::Max => 'max',
				}];
		}

		return $payload;
	}
}
