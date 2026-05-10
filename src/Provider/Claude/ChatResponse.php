<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function implode, is_array, is_string;


/**
 * Represents a response from the Claude API.
 */
final class ChatResponse implements Chat\Response
{
	public const Provider = 'claude';

	private ?string $text = null;
	private ?string $reasoning = null;

	/** @var list<Chat\Part> */
	private array $parts = [];


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


	public function getMessage(): Chat\Message
	{
		return new Chat\Message($this->parts, Chat\Role::Model);
	}


	/** @return list<Chat\ToolCallPart> */
	public function getToolCalls(): array
	{
		return array_values(array_filter($this->parts, fn($part) => $part instanceof Chat\ToolCallPart));
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
		if (!is_array($data['content'] ?? null)) {
			return;
		}

		$textParts = $thinkingParts = [];
		foreach ($data['content'] as $block) {
			$type = $block['type'] ?? null;
			if ($type === 'text' && is_string($block['text'] ?? null)) {
				$textParts[] = $block['text'];
				$this->parts[] = new Chat\TextPart($block['text'], self::Provider, $block);
			} elseif ($type === 'thinking' && is_string($block['thinking'] ?? null)) {
				$thinkingParts[] = $block['thinking'];
				// signature must come back unchanged, and display:omitted leaves the text empty
				$this->parts[] = new Chat\ReasoningPart($block['thinking'] === '' ? null : $block['thinking'], self::Provider, $block);
			} elseif ($type === 'redacted_thinking') {
				$this->parts[] = new Chat\ReasoningPart(null, self::Provider, $block);
			} elseif ($type === 'tool_use' && isset($block['id'], $block['name'])) {
				$this->parts[] = new Chat\ToolCallPart(
					(string) $block['id'],
					(string) $block['name'],
					is_array($block['input'] ?? null) ? $block['input'] : [],
					provider: self::Provider,
					raw: $block,
				);
			}
		}

		$this->text = ($text = implode("\n", $textParts)) === '' ? null : $text;
		$this->reasoning = ($thinking = implode("\n", $thinkingParts)) === '' ? null : $thinking;
	}
}
