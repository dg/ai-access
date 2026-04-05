<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use function array_filter, array_merge;


/**
 * OpenAI implementation of a chat session state container.
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
	 * Sets options specific to this OpenAI chat session. Options win over what the dedicated
	 * setters build: $text overrides setResponseSchema(), $reasoning overrides setEffort().
	 *
	 * @param  ?int  $maxOutputTokens  An upper bound for tokens in the response (minimum 16).
	 * @param  ?float  $temperature  Sampling temperature (0–2). Rejected by GPT-5.1 and later unless reasoning effort is none.
	 * @param  ?float  $topP  Nucleus sampling parameter. Same restriction as temperature.
	 * @param  ?array<string, string|int|bool>  $metadata  Optional metadata (map of up to 16 key-value pairs).
	 * @param  ?bool  $parallelToolCalls  Whether to allow parallel tool calls.
	 * @param  ?string $previousResponseId The ID of the previous response for multi-turn conversations.
	 * @param  ?mixed[]  $reasoning  Configuration options for reasoning models.
	 * @param  ?bool  $store  Whether to store the generated model response. Defaults to true on the API side.
	 * @param  ?mixed[]  $text  Configuration options for text response formatting.
	 * @param  ?string[]  $include  Specify additional output data to include.
	 * @param  ?mixed[]  $tools  An array of tools the model may call.
	 */
	public function setOptions(
		?int $maxOutputTokens = null,
		?float $temperature = null,
		?float $topP = null,
		?array $metadata = null,
		?bool $parallelToolCalls = null,
		?string $previousResponseId = null,
		?array $reasoning = null,
		?bool $store = null,
		?array $text = null,
		?array $include = null,
		?array $tools = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'max_output_tokens' => $maxOutputTokens,
				'temperature' => $temperature,
				'top_p' => $topP,
				'metadata' => $metadata,
				'parallel_tool_calls' => $parallelToolCalls,
				'previous_response_id' => $previousResponseId,
				'reasoning' => $reasoning,
				'store' => $store,
				'text' => $text,
				'include' => $include,
				'tools' => $tools,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	/**
	 * Constrains the answer to the given JSON Schema. Read the result with Response::getJson().
	 * Sent with strict mode on, so the schema must meet its rules: every property required,
	 * additionalProperties: false.
	 * @param  mixed[]  $schema
	 */
	public function setResponseSchema(array $schema): static
	{
		$this->responseSchema = $schema;
		return $this;
	}


	protected function generateResponse(): ChatResponse
	{
		$response = $this->client->callApi('responses', $this->buildPayload());
		if (($response['status'] ?? null) === 'failed') {
			$error = $response['error'] ?? [];
			throw new AIAccess\ApiException(($error['message'] ?? 'Response generation failed')
				. (isset($error['code']) ? " ({$error['code']})" : ''));
		}
		return new ChatResponse($response);
	}


	/**
	 * Builds the payload for the OpenAI API responses request.
	 * @return mixed[]
	 * @internal
	 */
	public function buildPayload(): array
	{
		if (empty($this->messages)) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		}

		$input = [];
		foreach ($this->messages as $message) {
			$role = match ($message->getRole()) {
				Role::User => 'user',
				Role::Model => 'assistant',
			};
			$input[] = [
				'role' => $role,
				'content' => $message->getText(),
			];
		}

		$payload = [
			'model' => $this->model,
			'input' => $input,
		];

		if ($this->systemInstruction !== null) {
			$payload['instructions'] = $this->systemInstruction;
		}

		if ($this->responseSchema !== null) {
			$payload['text']['format'] = [
				'type' => 'json_schema',
				'name' => 'response',
				'schema' => $this->responseSchema,
				'strict' => true,
			];
		}

		if ($this->effort !== null) {
			$payload['reasoning']['effort'] = match ($this->effort) {
				Effort::None => 'none',
				Effort::Low => 'low',
				Effort::Medium => 'medium',
				Effort::High => 'high',
				Effort::XHigh => 'xhigh',
				Effort::Max => 'max',
			};
		}

		return array_merge($payload, $this->options);
	}
}
