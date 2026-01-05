<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use function implode, is_array;


/**
 * Represents a response from the Claude API.
 */
final class ChatResponse implements Chat\Response
{
	private ?string $text = null;

	/** @var mixed[]|null */
	private ?array $contentBlocks = null;


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
		return match ($this->getRawFinishReason()) {
			'end_turn', 'stop_sequence' => FinishReason::Complete,
			'max_tokens' => FinishReason::TokenLimit,
			'tool_use' => FinishReason::ToolCall,
			'content_filtered' => FinishReason::ContentFiltered,
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
				inputTokens: $usage['input_tokens'] ?? null,
				outputTokens: $usage['output_tokens'] ?? null,
				reasoningTokens: $usage['reasoning_tokens'] ?? null,
				raw: $usage,
			)
			: null;
	}


	/**
	 * Gets all content blocks from the response, which may include text, tool_use, thinking, etc.
	 * @return mixed[]|null
	 */
	public function getContentBlocks(): ?array
	{
		return $this->contentBlocks;
	}


	public function getRawResponse(): mixed
	{
		return $this->rawResponse;
	}


	/** @param mixed[] $data */
	private function parseRawResponse(array $data): void
	{
		if (is_array($data['content'] ?? null)) {
			$this->contentBlocks = $data['content'];

			$textParts = [];
			foreach ($data['content'] as $block) {
				$type = $block['type'] ?? null;
				if ($type === 'text' && isset($block['text'])) {
					$textParts[] = $block['text'];
				} elseif ($type === 'thinking') {
					$textParts[] = '[Thinking: ' . ($block['text'] ?? '') . ']';
				}
			}

			$text = implode("\n", $textParts);
			$this->text = $text === '' ? null : $text;
		}
	}
}
