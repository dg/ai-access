<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess\Chat;
use AIAccess\Chat\FinishReason;
use AIAccess\Helpers;
use AIAccess\LogicException;
use AIAccess\UnexpectedResponseException;
use function is_array, is_string;


/**
 * Reads the chat/completions response shape, which several providers speak verbatim.
 *
 * Only the parts that genuinely differ between them are left open: how a finish reason is
 * spelled and where cached input tokens are reported. Everything else - the message, the
 * reasoning, the tool calls, the usage - is the same wire format and is parsed here once.
 *
 * @internal
 */
abstract class BaseChatResponse implements Chat\Response
{
	/** tags the parts this response owns, so they are replayed only to their own provider */
	public const Provider = '';

	protected ?string $text = null;

	/** @var list<Chat\Part> */
	protected array $parts = [];


	public function __construct(
		/** @var mixed[] */
		protected readonly array $rawResponse,
		/** the caller stopped a stream, so the answer is whatever had arrived */
		private readonly bool $cancelled = false,
	) {
		if (static::Provider === '') {
			// parts would be tagged with '' and silently dropped when replayed
			throw new LogicException(static::class . ' must override the Provider constant.');
		}

		$text = $this->rawResponse['choices'][0]['message']['content'] ?? null;
		if ($text !== null && !is_string($text)) {
			// content as a list of blocks is another dialect; without this it is an uncatchable TypeError
			throw new UnexpectedResponseException('Expected a string in choices[0].message.content, got ' . get_debug_type($text) . '.');
		}
		$this->text = $text === '' ? null : $text;

		if (($reasoning = $this->getReasoning()) !== null) {
			$this->parts[] = new Chat\ReasoningPart($reasoning, static::Provider, $reasoning);
		}
		if (is_string($this->text)) {
			$this->parts[] = new Chat\TextPart($this->text, static::Provider);
		}

		$calls = $this->rawResponse['choices'][0]['message']['tool_calls'] ?? [];
		if (!is_array($calls)) {
			throw new UnexpectedResponseException('Expected a list in choices[0].message.tool_calls, got ' . get_debug_type($calls) . '.');
		}

		foreach ($calls as $call) {
			[$arguments, $error] = Helpers::decodeArguments($call['function']['arguments'] ?? null);
			$this->parts[] = new Chat\ToolCallPart(
				(string) ($call['id'] ?? ''),
				(string) ($call['function']['name'] ?? ''),
				$arguments,
				$error,
				static::Provider,
				$call,
			);
		}
	}


	public function getText(): ?string
	{
		return $this->text;
	}


	public function getMessage(): Chat\Message
	{
		return new Chat\Message($this->parts, Chat\Role::Model);
	}


	/**
	 * Chain of thought, if the model produced one and the endpoint reports it.
	 * Must be passed back in tool call loops.
	 */
	public function getReasoning(): ?string
	{
		// DeepSeek and xAI say reasoning_content; OpenRouter and Groq say reasoning
		$message = $this->rawResponse['choices'][0]['message'] ?? [];
		$content = $message['reasoning_content'] ?? $message['reasoning'] ?? null;
		return is_string($content) && $content !== '' ? $content : null;
	}


	/** @return list<Chat\ToolCallPart> */
	public function getToolCalls(): array
	{
		return array_values(array_filter($this->parts, fn($part) => $part instanceof Chat\ToolCallPart));
	}


	public function getFinishReason(): FinishReason
	{
		if ($this->cancelled) {
			return FinishReason::Cancelled;
		} elseif ($this->text === null && isset($this->rawResponse['choices'][0]['message']['refusal'])) {
			// a refusal arrives as its own field rather than as a finish reason
			return FinishReason::ContentFiltered;
		}
		return $this->resolveFinishReason();
	}


	/**
	 * The one place the dialects disagree: each spells its finish reasons its own way, so
	 * deciding this here would silently change what a provider reports.
	 */
	abstract protected function resolveFinishReason(): FinishReason;


	public function getUsage(): ?Chat\Usage
	{
		$usage = $this->rawResponse['usage'] ?? null;
		return is_array($usage)
			? new Chat\Usage(
				inputTokens: Helpers::intOrNull($usage['prompt_tokens'] ?? null),
				outputTokens: Helpers::intOrNull($usage['completion_tokens'] ?? null),
				reasoningTokens: Helpers::intOrNull($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
				cacheReadTokens: Helpers::intOrNull($this->readCachedTokens($usage)),
				raw: $usage,
			)
			: null;
	}


	/** @param mixed[] $usage */
	protected function readCachedTokens(array $usage): mixed
	{
		return $usage['prompt_tokens_details']['cached_tokens'] ?? null;
	}


	public function getJson(): mixed
	{
		return Helpers::decodeResponseJson($this->getText());
	}


	public function getRawResponse(): mixed
	{
		return $this->rawResponse;
	}


	public function getRawFinishReason(): mixed
	{
		return $this->rawResponse['choices'][0]['finish_reason'] ?? null;
	}
}
