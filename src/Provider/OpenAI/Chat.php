<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use AIAccess\Chat\Effort;
use AIAccess\Chat\Role;
use function array_filter, array_merge, count, is_array;


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
			$this->appendMessage($input, $message, match ($message->getRole()) {
				Role::User, Role::Tool => 'user',
				Role::Model => 'assistant',
			});
		}

		$payload = [
			'model' => $this->model,
			'input' => $input,
		];

		if ($this->systemInstruction !== null) {
			$payload['instructions'] = $this->systemInstruction;
		}

		foreach ($this->tools as $tool) {
			// flat here, unlike the chat/completions dialect where it nests under "function";
			// strict goes out even when false, because the API defaults to true
			$payload['tools'][] = [
				'type' => 'function',
				'name' => $tool->name,
				'description' => $tool->description,
				'parameters' => $tool->parameters ?: ['type' => 'object', 'properties' => new \stdClass],
				'strict' => $tool->strict,
			];
		}

		if ($this->toolChoice !== null) {
			$payload['tool_choice'] = ['type' => 'function', 'name' => $this->toolChoice];
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

		$payload = array_merge($payload, $this->options);

		// without store the reasoning items exist nowhere server-side, so their encrypted copy
		// must travel with the answer, or tool loops on reasoning models break with a 400
		if (($payload['store'] ?? true) === false) {
			$payload['include'] = array_values(array_unique(array_merge(
				$payload['include'] ?? [],
				['reasoning.encrypted_content'],
			)));
		}

		return $payload;
	}


	/**
	 * Input is a flat item list, so one message can expand into several items, and they must
	 * keep the order the parts came in: a reasoning item names the item that has to follow it,
	 * so text collected before one goes out ahead of it rather than at the end of the message.
	 * @param  list<mixed>  $input
	 */
	private function appendMessage(array &$input, AIAccess\Chat\Message $message, string $role): void
	{
		if ($message->isTextOnly()) {
			$input[] = ['role' => $role, 'content' => $message->getText()];
			return;
		}

		$content = [];
		foreach ($message->getParts() as $part) {
			if ($part instanceof AIAccess\Chat\ReasoningPart) {
				$this->flushContent($input, $content, $role);
				// with store on (the default) the server resolves the item by its id, so the raw
				// item is replayed as is; without it only an encrypted_content copy can travel
				if ($part->provider === ChatResponse::Provider && $part->raw !== null
					&& (isset($part->raw['encrypted_content']) || ($this->options['store'] ?? true) !== false)) {
					$input[] = $part->raw;
				}
			} elseif ($part instanceof AIAccess\Chat\ToolCallPart) {
				$this->flushContent($input, $content, $role);
				$input[] = $part->provider === ChatResponse::Provider && $part->raw !== null
					? $part->raw
					: [
						'type' => 'function_call',
						'call_id' => $part->callId,
						'name' => $part->name,
						// [] would serialize as a JSON array, and arguments must be an object
						'arguments' => $part->arguments ? AIAccess\Helpers::encodeJson($part->arguments) : '{}',
					];
			} elseif ($part instanceof AIAccess\Chat\ToolResultPart) {
				$this->flushContent($input, $content, $role);
				// there is no error flag here, so the model has to read it in the text
				$input[] = [
					'type' => 'function_call_output',
					'call_id' => $part->callId,
					'output' => ($part->isError ? 'ERROR: ' : '')
						. (is_array($part->content) ? AIAccess\Helpers::encodeJson($part->content) : $part->content),
				];
			} elseif ($part instanceof AIAccess\Chat\TextPart) {
				// the answer side of the history speaks in output_text; input_text there is a 400
				$content[] = ['type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => $part->text];
			} elseif ($part instanceof AIAccess\Media) {
				$content[] = $part->isImage()
					? ['type' => 'input_image', 'image_url' => $this->dataUri($part)]
					: ['type' => 'input_file', 'filename' => $part->getFileName(), 'file_data' => $this->dataUri($part)];
			} else {
				throw new AIAccess\LogicException('OpenAI chat cannot send ' . get_debug_type($part) . ' content.');
			}
		}

		$this->flushContent($input, $content, $role);
	}


	/**
	 * Turns the text and media piled up so far into an item of their own, so that a standalone
	 * item that follows them does not jump ahead of them.
	 * @param  list<mixed>  $input
	 * @param  list<mixed>  $content
	 */
	private function flushContent(array &$input, array &$content, string $role): void
	{
		if (!$content) {
			return;
		}

		// a plain string says the same thing as a lone text block and keeps the payload smaller
		$input[] = [
			'role' => $role,
			'content' => count($content) === 1 && $content[0]['type'] !== 'input_image' && $content[0]['type'] !== 'input_file'
				? $content[0]['text']
				: $content,
		];
		$content = [];
	}


	private function dataUri(AIAccess\Media $media): string
	{
		return 'data:' . $media->getMimeType() . ';base64,' . $media->getBase64();
	}
}
