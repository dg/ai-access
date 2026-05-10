<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function is_array, is_string;


/**
 * Response from an OpenAI-compatible endpoint.
 */
final class ChatResponse implements Chat\Response
{
	public const Provider = 'openai-compatible';

	private ?string $text = null;

	/** @var list<Chat\Part> */
	private array $parts = [];


	public function __construct(
		/** @var mixed[] */
		private readonly array $rawResponse,
	) {
		$text = $this->rawResponse['choices'][0]['message']['content'] ?? null;
		$this->text = $text === '' ? null : $text;

		if (($reasoning = $this->getReasoning()) !== null) {
			$this->parts[] = new Chat\ReasoningPart($reasoning, self::Provider, $reasoning);
		}
		if (is_string($this->text)) {
			$this->parts[] = new Chat\TextPart($this->text, self::Provider);
		}

		foreach ($this->rawResponse['choices'][0]['message']['tool_calls'] ?? [] as $call) {
			[$arguments, $error] = Helpers::decodeArguments($call['function']['arguments'] ?? null);
			$this->parts[] = new Chat\ToolCallPart(
				(string) ($call['id'] ?? ''),
				(string) ($call['function']['name'] ?? ''),
				$arguments,
				$error,
				self::Provider,
				$call,
			);
		}
	}


	public function getMessage(): Chat\Message
	{
		return new Chat\Message($this->parts, Chat\Role::Model);
	}


	/** @return list<Chat\ToolCallPart> */
	public function getToolCalls(): array
	{
		return array_values(array_filter($this->parts, fn($part) => $part instanceof Chat\ToolCallPart));
	}


	public function getText(): ?string
	{
		return $this->text;
	}


	public function getFinishReason(): FinishReason
	{
		return match ($this->getRawFinishReason()) {
			'stop', 'end_turn', null => FinishReason::Complete,
			'length' => FinishReason::TokenLimit,
			'tool_calls' => FinishReason::ToolCall,
			'content_filter' => FinishReason::ContentFiltered,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['choices'][0]['finish_reason'] ?? null;
	}


	/**
	 * Chain of thought, if the endpoint reports one.
	 */
	public function getReasoning(): ?string
	{
		$content = $this->rawResponse['choices'][0]['message']['reasoning_content'] ?? null;
		return is_string($content) && $content !== '' ? $content : null;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: $usage['prompt_tokens'] ?? null,
				outputTokens: $usage['completion_tokens'] ?? null,
				reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
				cacheReadTokens: $usage['prompt_tokens_details']['cached_tokens'] ?? null,
				raw: $usage,
			)
			: null;
	}


	public function getJson(): mixed
	{
		return Helpers::decodeResponseJson($this->getText());
	}


	public function getRawResponse(): mixed
	{
		return $this->rawResponse;
	}
}
