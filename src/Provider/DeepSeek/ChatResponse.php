<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\DeepSeek;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function is_array, is_string;


/**
 * Represents a response from the DeepSeek API.
 */
final class ChatResponse implements Chat\Response
{
	private ?string $text = null;


	public function __construct(
		/** @var mixed[] */
		private readonly array $rawResponse,
	) {
		$this->parseRawResponse($this->rawResponse);
	}


	public function getText(): ?string
	{
		return $this->text;
	}


	public function getFinishReason(): FinishReason
	{
		return match ($this->getRawFinishReason()) {
			'stop' => FinishReason::Complete,
			'length' => FinishReason::TokenLimit,
			'content_filter' => FinishReason::ContentFiltered,
			'tool_calls' => FinishReason::ToolCall,
			default => FinishReason::Unknown,
		};
	}


	/**
	 * Chain of thought of a thinking model. Must be passed back in tool call loops.
	 */
	public function getReasoning(): ?string
	{
		$content = $this->rawResponse['choices'][0]['message']['reasoning_content'] ?? null;
		return is_string($content) && $content !== '' ? $content : null;
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['choices'][0]['finish_reason'] ?? null;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: $usage['prompt_tokens'] ?? null,
				outputTokens: $usage['completion_tokens'] ?? null,
				reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
				cacheReadTokens: $usage['prompt_cache_hit_tokens'] ?? null,
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


	/** @param mixed[] $data */
	private function parseRawResponse(array $data): void
	{
		$text = $data['choices'][0]['message']['content'] ?? null;
		$this->text = $text === '' ? null : $text;
	}
}
