<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Grok;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use function is_array;


/**
 * Represents a response from the Grok (xAI) API.
 */
final class ChatResponse implements Chat\Response
{
	private ?string $text = null;


	/** @param mixed[] $rawResponse */
	public function __construct(
		private array $rawResponse,
	) {
		$this->parseRawResponse($this->rawResponse);
	}


	public function getText(): ?string
	{
		return $this->text;
	}


	public function getFinishReason(): FinishReason
	{
		if ($this->text === null && isset($this->rawResponse['choices'][0]['message']['refusal'])) {
			return FinishReason::ContentFiltered;
		}

		return match ($this->getRawFinishReason()) {
			'stop', null => FinishReason::Complete,
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
	 * Gets token usage information.
	 */
	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: $usage['prompt_tokens'] ?? null,
				outputTokens: $usage['completion_tokens'] ?? null,
				reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
				raw: $usage,
			)
			: null;
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
