<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess\Chat\Delta;
use AIAccess\Chat\DeltaType;
use AIAccess\Helpers;
use function is_array, is_string;


/**
 * Rebuilds a chat completion out of streamed chunks.
 *
 * The pieces live in choices[0].delta and have to be concatenated by hand, tool call
 * arguments included. Two traps: the stream ends with a literal [DONE] line that is not
 * JSON, and the chunk carrying the usage has an **empty** choices array, so anything
 * reaching into choices[0] blindly breaks on the last chunk.
 *
 * @internal
 */
final class StreamAccumulator
{
	private string $content = '';
	private string $reasoning = '';
	private string $refusal = '';
	private ?string $finishReason = null;

	/** @var mixed[] */
	private array $meta = [];

	/** @var mixed[]|null */
	private ?array $usage = null;

	/** @var array<int, mixed[]> */
	private array $toolCalls = [];


	/**
	 * @param  \Closure(Delta): (bool|null)  $onDelta
	 * @return bool  false when the caller asked to stop
	 */
	public function event(?string $name, string $json, \Closure $onDelta): bool
	{
		if ($json === '[DONE]') {
			return true;
		}

		$data = Helpers::decodeJson($json);
		if (!is_array($data)) {
			return true;
		}

		$this->meta = array_diff_key($data, ['choices' => 1, 'usage' => 1]) + $this->meta;
		if (is_array($data['usage'] ?? null)) {
			$this->usage = $data['usage'];
		}

		$choice = $data['choices'][0] ?? null;
		if (!is_array($choice)) {
			return true; // the usage-only chunk
		}
		if (is_string($choice['finish_reason'] ?? null)) {
			$this->finishReason = $choice['finish_reason'];
		}

		$delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
		$reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
		if (is_string($reasoning) && $reasoning !== '') {
			$this->reasoning .= $reasoning;
			if ($onDelta(new Delta(DeltaType::Reasoning, $reasoning)) === false) {
				return false;
			}
		}
		if (is_string($delta['refusal'] ?? null)) {
			// a streamed refusal must end up where the plain response carries it,
			// or it would read as an ordinary complete answer
			$this->refusal .= $delta['refusal'];
		}
		if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
			$this->content .= $delta['content'];
			if ($onDelta(new Delta(DeltaType::Text, $delta['content'])) === false) {
				return false;
			}
		}

		foreach ($delta['tool_calls'] ?? [] as $call) {
			if (!is_array($call)) {
				continue;
			}
			$index = (int) ($call['index'] ?? 0);
			$this->toolCalls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
			$this->toolCalls[$index]['id'] = $call['id'] ?? $this->toolCalls[$index]['id'];
			$this->toolCalls[$index]['function']['name'] = $call['function']['name'] ?? $this->toolCalls[$index]['function']['name'];

			$arguments = (string) ($call['function']['arguments'] ?? '');
			$this->toolCalls[$index]['function']['arguments'] .= $arguments;
			if ($arguments !== '' && $onDelta(new Delta(DeltaType::ToolCall, $arguments)) === false) {
				return false;
			}
		}
		return true;
	}


	/**
	 * The response as the non-streaming endpoint would have returned it.
	 * @return mixed[]
	 */
	public function getResponse(): array
	{
		$message = ['role' => 'assistant', 'content' => $this->content === '' ? null : $this->content];
		if ($this->reasoning !== '') {
			$message['reasoning_content'] = $this->reasoning;
		}
		if ($this->refusal !== '') {
			$message['refusal'] = $this->refusal;
		}
		if ($this->toolCalls) {
			ksort($this->toolCalls);
			$message['tool_calls'] = array_values($this->toolCalls);
		}

		return $this->meta + [
			'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $this->finishReason]],
			'usage' => $this->usage,
		];
	}
}
