<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use function implode, is_array, is_string;


/**
 * Represents a response from the Gemini API.
 */
final class ChatResponse implements Chat\Response
{
	private ?string $text = null;


	public function __construct(
		/** @var mixed[] */
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
			'STOP' => FinishReason::Complete,
			'MAX_TOKENS' => FinishReason::TokenLimit,
			'SAFETY', 'RECITATION' => FinishReason::ContentFiltered,
			'TOOL_CALLS' => FinishReason::ToolCall,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['candidates'][0]['finishReason'] ?? null;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usageMetadata'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: $usage['promptTokenCount'] ?? null,
				outputTokens: $usage['candidatesTokenCount'] ?? null,
				reasoningTokens: null,
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
		if (isset($data['promptFeedback']['blockReason'])) {
			return;
		}

		$textParts = [];
		// Check standard candidates structure
		if (is_array($data['candidates'][0]['content']['parts'] ?? null)) {
			foreach ($data['candidates'][0]['content']['parts'] as $part) {
				if (is_string($part['text'] ?? null)) {
					$textParts[] = $part['text'];
				}
			}
		// Fallback for simpler structures (less common)
		} elseif (is_string($data['candidates'][0]['text'] ?? null)) {
			$textParts[] = $data['candidates'][0]['text'];
		}

		$this->text = $textParts ? implode("\n", $textParts) : null;
	}
}
