<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess\ApiException;
use AIAccess\Chat\Delta;
use AIAccess\Chat\DeltaType;
use AIAccess\Helpers;
use function is_array, is_string;


/**
 * Collects a streamed response into the shape the plain endpoint returns.
 *
 * The terminal event carries the entire response, so normally the pieces only have to be
 * reported as they pass, and the usage and the finish reason are exactly what a non-streamed
 * call would give. Finished items and text deltas are still collected along the way: they
 * are all there is when the caller cancels before the terminal event.
 *
 * @internal
 */
final class StreamAccumulator
{
	/** @var mixed[] */
	private array $response = [];

	/** @var list<mixed[]> */
	private array $items = [];

	/** @var array<int, array<int, string>> */
	private array $partialTexts = [];

	private bool $terminated = false;
	private bool $sawText = false;


	/**
	 * @param  \Closure(Delta): (bool|null)  $onDelta
	 * @return bool  false when the caller asked to stop
	 */
	public function event(?string $name, string $json, \Closure $onDelta): bool
	{
		$data = Helpers::decodeJson($json);
		if (!is_array($data)) {
			return true;
		}

		switch ($name ?? $data['type'] ?? null) {
			case 'response.content_part.added':
				// getText() joins text blocks with \n, so the streamed text must read the same
				if ((($data['part'] ?? [])['type'] ?? null) === 'output_text'
					&& $this->sawText
					&& $onDelta(new Delta(DeltaType::Text, "\n")) === false) {
					return false;
				}
				break;

			case 'response.output_text.delta':
				$this->sawText = true;
				$this->partialTexts[(int) ($data['output_index'] ?? 0)][(int) ($data['content_index'] ?? 0)]
					= ($this->partialTexts[(int) ($data['output_index'] ?? 0)][(int) ($data['content_index'] ?? 0)] ?? '')
					. (string) ($data['delta'] ?? '');
				return $onDelta(new Delta(DeltaType::Text, (string) ($data['delta'] ?? ''))) !== false;

			case 'response.reasoning_summary_text.delta':
				return $onDelta(new Delta(DeltaType::Reasoning, (string) ($data['delta'] ?? ''))) !== false;

			case 'response.function_call_arguments.delta':
				return $onDelta(new Delta(DeltaType::ToolCall, (string) ($data['delta'] ?? ''))) !== false;

			case 'response.output_item.done':
				if (is_array($data['item'] ?? null)) {
					$this->items[] = $data['item'];
					unset($this->partialTexts[(int) ($data['output_index'] ?? 0)]);
				}
				break;

			case 'response.completed':
			case 'response.incomplete':
			case 'response.failed':
				$this->terminated = true;
				if (is_array($data['response'] ?? null)) {
					$this->response = $data['response'];
				}
				break;

			case 'error':
				// the stream is HTTP 200, so this event is the only word of the failure
				throw new ApiException(
					is_string($data['message'] ?? null) ? $data['message'] : 'The stream reported an error.',
				);
		}
		return true;
	}


	/**
	 * Whether a terminal event arrived. Without one, a stream that simply went quiet must
	 * not be mistaken for a successfully completed answer.
	 */
	public function isTerminated(): bool
	{
		return $this->terminated;
	}


	/**
	 * @return mixed[]
	 */
	public function getResponse(): array
	{
		if ($this->response) {
			return $this->response;
		}

		// no terminal event: the answer is whatever finished items and text had arrived
		$output = $this->items;
		foreach ($this->partialTexts as $contents) {
			ksort($contents);
			$output[] = [
				'type' => 'message',
				'role' => 'assistant',
				'content' => array_map(
					fn(string $text) => ['type' => 'output_text', 'text' => $text],
					array_values($contents),
				),
			];
		}
		return $output ? ['output' => $output] : [];
	}
}
