<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function implode, is_array;


/**
 * Represents a response from the Claude API.
 */
final class ChatResponse implements Chat\Response
{
	private ?string $text = null;
	private ?string $reasoning = null;


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
			'end_turn', 'stop_sequence' => FinishReason::Complete,
			'max_tokens', 'model_context_window_exceeded' => FinishReason::TokenLimit,
			'tool_use' => FinishReason::ToolCall,
			'refusal' => FinishReason::ContentFiltered,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['stop_reason'] ?? null;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: Helpers::intOrNull($usage['input_tokens'] ?? null),
				outputTokens: Helpers::intOrNull($usage['output_tokens'] ?? null),
				reasoningTokens: Helpers::intOrNull($usage['output_tokens_details']['thinking_tokens'] ?? null),
				cacheReadTokens: Helpers::intOrNull($usage['cache_read_input_tokens'] ?? null),
				cacheWriteTokens: Helpers::intOrNull($usage['cache_creation_input_tokens'] ?? null),
				raw: $usage,
			)
			: null;
	}


	/**
	 * Summarized chain of thought, if the model produced one. Not part of getText().
	 */
	public function getReasoning(): ?string
	{
		return $this->reasoning;
	}


	public function getRawResponse(): mixed
	{
		return $this->rawResponse;
	}


	/** @param mixed[] $data */
	private function parseRawResponse(array $data): void
	{
		if (!is_array($data['content'] ?? null)) {
			return;
		}

		$textParts = $thinkingParts = [];
		foreach ($data['content'] as $block) {
			if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
				$textParts[] = $block['text'];
			} elseif (($block['type'] ?? null) === 'thinking' && isset($block['thinking'])) {
				$thinkingParts[] = $block['thinking'];
			}
		}

		$this->text = ($text = implode("\n", $textParts)) === '' ? null : $text;
		$this->reasoning = ($thinking = implode("\n", $thinkingParts)) === '' ? null : $thinking;
	}
}
