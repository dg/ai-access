<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess\Chat\Delta;
use AIAccess\Chat\DeltaType;
use AIAccess\Helpers;
use function count, is_array, is_string;


/**
 * Rebuilds a whole response out of streamed ones.
 *
 * Every event here is a complete GenerateContentResponse carrying the next slice of the
 * answer, and there is no event that says "this was the last one" - the stream simply
 * stops. The final shape is therefore the last event with the collected parts put back in.
 *
 * @internal
 */
final class StreamAccumulator
{
	/** @var mixed[] */
	private array $last = [];

	/** @var list<mixed[]> */
	private array $parts = [];


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
		// replace, not assign: a usage-only closing chunk must not wipe out the candidate
		// that carried finishReason
		$this->last = array_replace($this->last, $data);

		foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
			if (!is_array($part)) {
				continue;
			}
			$this->collect($part);

			if (is_string($part['text'] ?? null)) {
				// the closing part often carries only a signature, and an empty delta is noise
				$type = ($part['thought'] ?? false) ? DeltaType::Reasoning : DeltaType::Text;
				if ($part['text'] !== '' && $onDelta(new Delta($type, $part['text'])) === false) {
					return false;
				}
			} elseif (isset($part['functionCall'])) {
				$toolName = (string) ($part['functionCall']['name'] ?? '');
				if ($onDelta(new Delta(DeltaType::ToolCall, $toolName)) === false) {
					return false;
				}
			}
		}
		return true;
	}


	/**
	 * A slice of text is not a part of its own: the finished answer has one text part, and
	 * keeping the slices apart would make getText() insert newlines that were never sent.
	 * Only plain text slices merge; anything carrying a signature or a call stays whole.
	 * @param  mixed[]  $part
	 */
	private function collect(array $part): void
	{
		$plain = fn(array $p) => is_string($p['text'] ?? null) && !array_diff_key($p, ['text' => 1, 'thought' => 1]);
		$previous = $this->parts ? $this->parts[count($this->parts) - 1] : null;

		if ($previous !== null
			&& $plain($part) && $plain($previous)
			&& ($part['thought'] ?? false) === ($previous['thought'] ?? false)
		) {
			$this->parts[count($this->parts) - 1]['text'] .= $part['text'];
		} else {
			$this->parts[] = $part;
		}
	}


	/**
	 * @return mixed[]
	 */
	public function getResponse(): array
	{
		$response = $this->last;
		if ($this->parts) {
			$response['candidates'][0]['content']['parts'] = $this->parts;
		}
		return $response;
	}
}
