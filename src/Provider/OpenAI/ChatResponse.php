<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function implode, is_array, is_string;


/**
 * Represents a response from the OpenAI API.
 */
final class ChatResponse implements Chat\Response
{
	public const Provider = 'openai';

	private ?string $text = null;
	private ?string $refusal = null;
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
		if ($this->refusal !== null) {
			return FinishReason::ContentFiltered;
		}

		return match ($this->rawResponse['status'] ?? null) {
			'incomplete' => match ($this->getRawFinishReason()) {
				'max_output_tokens' => FinishReason::TokenLimit,
				'content_filter' => FinishReason::ContentFiltered,
				default => FinishReason::Unknown,
			},
			'cancelled' => FinishReason::Cancelled,
			'completed', null => $this->hasToolCall() ? FinishReason::ToolCall : FinishReason::Complete,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['incomplete_details']['reason'] ?? $this->rawResponse['status'] ?? null;
	}


	/**
	 * Explanation of why the model declined to answer, if it did.
	 */
	public function getRefusal(): ?string
	{
		return $this->refusal;
	}


	public function getReasoning(): ?string
	{
		return $this->reasoning;
	}


	public function getMessage(): Chat\Message
	{
		return new Chat\Message($this->parts, Chat\Role::Model);
	}


	private function hasToolCall(): bool
	{
		foreach ($this->rawResponse['output'] ?? [] as $item) {
			if (($item['type'] ?? null) === 'function_call') {
				return true;
			}
		}
		return false;
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: Helpers::intOrNull($usage['input_tokens'] ?? null),
				outputTokens: Helpers::intOrNull($usage['output_tokens'] ?? null),
				reasoningTokens: Helpers::intOrNull($usage['output_tokens_details']['reasoning_tokens'] ?? null),
				cacheReadTokens: Helpers::intOrNull($usage['input_tokens_details']['cached_tokens'] ?? null),
				cacheWriteTokens: Helpers::intOrNull($usage['input_tokens_details']['cache_write_tokens'] ?? null),
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
		$textParts = $refusals = $summaries = [];
		foreach ($data['output'] ?? [] as $item) {
			if (($item['type'] ?? null) === 'reasoning') {
				$own = [];
				foreach ($item['summary'] ?? [] as $block) {
					if (is_string($block['text'] ?? null)) {
						$own[] = $block['text'];
						$summaries[] = $block['text'];
					}
				}
				// the raw item is kept whole; whether it can be replayed (by id with store on,
				// or via encrypted_content) is decided when the payload is built
				$this->parts[] = new Chat\ReasoningPart(
					($text = implode("\n", $own)) === '' ? null : $text,
					self::Provider,
					$item,
				);
				continue;
			} elseif (($item['type'] ?? null) !== 'message' || !is_array($item['content'] ?? null)) {
				continue;
			}
			foreach ($item['content'] as $block) {
				if (($block['type'] ?? null) === 'output_text' && is_string($block['text'] ?? null)) {
					$textParts[] = $block['text'];
					$this->parts[] = new Chat\TextPart($block['text'], self::Provider, $block);
				} elseif (($block['type'] ?? null) === 'refusal' && is_string($block['refusal'] ?? null)) {
					$refusals[] = $block['refusal'];
					// the turn stays in the history, so a validate loop sees what was declined
					$this->parts[] = new Chat\TextPart($block['refusal'], self::Provider, $block);
				}
			}
		}

		$this->text = ($text = implode("\n", $textParts)) === '' ? null : $text;
		$this->refusal = ($refusal = implode("\n", $refusals)) === '' ? null : $refusal;
		$this->reasoning = ($reasoning = implode("\n", $summaries)) === '' ? null : $reasoning;
	}
}
