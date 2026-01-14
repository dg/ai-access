<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use function implode, is_array, is_string;


/**
 * Represents a response from the OpenAI API.
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
			'stop', null => FinishReason::Complete,
			'length', 'max_output_tokens' => FinishReason::TokenLimit,
			'content_filter' => FinishReason::ContentFiltered,
			'tool_calls' => FinishReason::ToolCall,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['incomplete_details']['reason'] ?? null;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: $usage['input_tokens'] ?? null,
				outputTokens: $usage['output_tokens'] ?? null,
				reasoningTokens: $usage['reasoning_tokens'] ?? null,
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
		if (isset($data['blocked']) && $data['blocked'] === true) {
			return;
		}

		if (is_array($data['output'] ?? null)) {
			$textParts = [];
			foreach ($data['output'] as $item) {
				if (
					($item['type'] ?? null) === 'message'
					&& is_array($item['content'] ?? null)
				) {
					foreach ($item['content'] as $block) {
						if (
							($block['type'] ?? null) === 'output_text'
							&& is_string($block['text'] ?? null)
						) {
							$textParts[] = $block['text'];
						}
					}
				}
			}

			$this->text = $textParts ? implode("\n", $textParts) : null;
		}
	}
}
