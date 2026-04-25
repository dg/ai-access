<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use function implode, is_array, is_string;


/**
 * Represents a response from the Gemini API.
 */
final class ChatResponse implements Chat\Response
{
	public const Provider = 'gemini';

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
		// a blocked prompt has no candidates at all; the reason lives in promptFeedback
		if (isset($this->rawResponse['promptFeedback']['blockReason'])) {
			return FinishReason::ContentFiltered;
		}

		return match ($this->getRawFinishReason()) {
			'STOP' => FinishReason::Complete,
			'MAX_TOKENS' => FinishReason::TokenLimit,
			'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII',
			'IMAGE_SAFETY', 'IMAGE_PROHIBITED_CONTENT', 'IMAGE_RECITATION' => FinishReason::ContentFiltered,
			default => FinishReason::Unknown,
		};
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['candidates'][0]['finishReason']
			?? $this->rawResponse['promptFeedback']['blockReason']
			?? null;
	}


	/**
	 * Summary of the model's thinking, if it was requested. Not part of getText().
	 */
	public function getReasoning(): ?string
	{
		return $this->reasoning;
	}


	public function getMessage(): Chat\Message
	{
		return new Chat\Message($this->parts, Chat\Role::Model);
	}


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usageMetadata'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: Helpers::intOrNull($usage['promptTokenCount'] ?? null),
				outputTokens: Helpers::intOrNull($usage['candidatesTokenCount'] ?? null),
				reasoningTokens: Helpers::intOrNull($usage['thoughtsTokenCount'] ?? null),
				cacheReadTokens: Helpers::intOrNull($usage['cachedContentTokenCount'] ?? null),
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
		if (isset($data['promptFeedback']['blockReason'])) {
			return;
		}

		$textParts = $thoughtParts = [];
		if (is_array($data['candidates'][0]['content']['parts'] ?? null)) {
			foreach ($data['candidates'][0]['content']['parts'] as $part) {
				if (!is_string($part['text'] ?? null)) {
					continue;
				} elseif ($part['thought'] ?? false) {
					$thoughtParts[] = $part['text'];
					$this->parts[] = new Chat\ReasoningPart($part['text'], self::Provider, $part);
				} else {
					$textParts[] = $part['text'];
					// the raw part may carry a thoughtSignature, which Gemini wants back verbatim
					$this->parts[] = new Chat\TextPart($part['text'], self::Provider, $part);
				}
			}
		} elseif (is_string($data['candidates'][0]['text'] ?? null)) {
			$textParts[] = $data['candidates'][0]['text'];
		}

		// joined with nothing: Gemini text parts are slices of one continuous answer
		// (a signature carrier, say), not separate blocks
		$this->text = ($text = implode('', $textParts)) === '' ? null : $text;
		$this->reasoning = ($thoughts = implode("\n", $thoughtParts)) === '' ? null : $thoughts;
	}
}
