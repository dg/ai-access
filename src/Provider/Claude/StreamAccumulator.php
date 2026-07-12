<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess\ApiException;
use AIAccess\Chat\Delta;
use AIAccess\Chat\DeltaType;
use AIAccess\Helpers;
use function in_array, is_array, is_string;


/**
 * Rebuilds a whole message out of streamed events, so that the finished stream can be read
 * by the very same ChatResponse a non-streamed answer goes through.
 *
 * Claude names its events and builds content block by block; the closing message_delta
 * carries the stop reason and a usage that is **cumulative**, not incremental.
 *
 * @internal
 */
final class StreamAccumulator
{
	/** @var mixed[] */
	private array $message = ['content' => []];

	/** @var array<int, mixed[]> */
	private array $blocks = [];

	/** @var array<int, string> */
	private array $partialJson = [];

	/** @var array<int, true> */
	private array $closed = [];


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
			case 'message_start':
				if (is_array($data['message'] ?? null)) {
					$this->message = $data['message'] + ['content' => []];
				}
				break;

			case 'content_block_start':
				$index = (int) ($data['index'] ?? 0);
				$block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
				// getText() joins text blocks with \n, so the streamed text must read the same
				if (($block['type'] ?? null) === 'text' && $this->hasTextBlock()
					&& $onDelta(new Delta(DeltaType::Text, "\n")) === false) {
					return false;
				}
				$this->blocks[$index] = $block;
				break;

			case 'content_block_delta':
				$index = (int) ($data['index'] ?? 0);
				$delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
				return $this->applyDelta($index, $delta, $onDelta);

			case 'content_block_stop':
				$this->closed[(int) ($data['index'] ?? 0)] = true;
				break;

			case 'message_delta':
				$this->message = array_merge($this->message, is_array($data['delta'] ?? null) ? $data['delta'] : []);
				if (is_array($data['usage'] ?? null)) {
					// cumulative, so the last one wins rather than being added up
					$this->message['usage'] = array_merge($this->message['usage'] ?? [], $data['usage']);
				}
				break;

			case 'error':
				// the stream is HTTP 200, so this event is the only word of the failure; staying
				// silent would pass a truncated answer off as a finished one
				$error = is_array($data['error'] ?? null) ? $data['error'] : [];
				throw new ApiException(
					is_string($error['message'] ?? null) ? $error['message'] : 'The stream reported an error.',
				);
		}
		return true;
	}


	private function hasTextBlock(): bool
	{
		foreach ($this->blocks as $block) {
			if (($block['type'] ?? null) === 'text') {
				return true;
			}
		}
		return false;
	}


	/**
	 * @param  mixed[]  $delta
	 * @param  \Closure(Delta): (bool|null)  $onDelta
	 */
	private function applyDelta(int $index, array $delta, \Closure $onDelta): bool
	{
		switch ($delta['type'] ?? null) {
			case 'text_delta':
				$text = (string) ($delta['text'] ?? '');
				$this->blocks[$index]['text'] = ($this->blocks[$index]['text'] ?? '') . $text;
				return $onDelta(new Delta(DeltaType::Text, $text)) !== false;

			case 'thinking_delta':
				$text = (string) ($delta['thinking'] ?? '');
				$this->blocks[$index]['thinking'] = ($this->blocks[$index]['thinking'] ?? '') . $text;
				return $onDelta(new Delta(DeltaType::Reasoning, $text)) !== false;

			case 'signature_delta':
				$this->blocks[$index]['signature'] = ($this->blocks[$index]['signature'] ?? '') . (string) ($delta['signature'] ?? '');
				break;

			case 'input_json_delta':
				// tool arguments arrive as fragments of JSON text and are only valid once whole
				$text = (string) ($delta['partial_json'] ?? '');
				$this->partialJson[$index] = ($this->partialJson[$index] ?? '') . $text;
				return $onDelta(new Delta(DeltaType::ToolCall, $text)) !== false;
		}
		return true;
	}


	/**
	 * The response as the non-streaming endpoint would have returned it.
	 * @return mixed[]
	 */
	public function getResponse(): array
	{
		foreach ($this->partialJson as $index => $json) {
			$decoded = json_decode($json, true);
			$this->blocks[$index]['input'] = is_array($decoded) ? $decoded : [];
		}

		// a thinking block cut off before content_block_stop has a truncated signature,
		// and replaying that in the history means a 400 for every following request
		foreach ($this->blocks as $index => $block) {
			if (!isset($this->closed[$index])
				&& in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
				unset($this->blocks[$index]);
			}
		}

		ksort($this->blocks);
		$response = $this->message;
		$response['content'] = array_values($this->blocks);
		return $response;
	}
}
