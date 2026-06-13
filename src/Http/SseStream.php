<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;



/**
 * Cuts a byte stream into server-sent events.
 *
 * Chunks arrive as the network happens to split them, so an event can be torn in half and
 * has to wait for the rest. Providers disagree on everything else: some name their events,
 * some do not, some end with a [DONE] sentinel and some just stop talking.
 *
 * @internal
 */
final class SseStream
{
	/** normalized to \n line endings */
	private string $buffer = '';
	private bool $crPending = false;
	private bool $fresh = true;


	/**
	 * Drives a whole transfer: feeds every chunk the transport delivers, hands finished
	 * events to $onEvent and aborts the transfer when it answers false. A final event left
	 * unterminated at the end of the transfer is flushed rather than lost.
	 * Every streaming provider runs this very loop, so it lives here once.
	 * @param  \Closure(\Closure(string): (bool|null)): void  $transport  runs the request, pushing body chunks into the given callback
	 * @param  \Closure(?string, string): bool  $onEvent  receives event name and data; false stops the transfer
	 * @return bool  true when $onEvent stopped the transfer early
	 */
	public static function consume(\Closure $transport, \Closure $onEvent): bool
	{
		$self = new self;
		$stopped = false;
		$transport(function (string $chunk) use ($self, $onEvent, &$stopped): bool {
			foreach ($self->feed($chunk) as $event) {
				if (!$onEvent($event['event'], $event['data'])) {
					$stopped = true;
					return false;
				}
			}
			return true;
		});

		if (!$stopped && ($event = $self->close()) !== null) {
			$onEvent($event['event'], $event['data']);
		}
		return $stopped;
	}


	/**
	 * Feeds raw bytes in and returns whatever complete events they finished.
	 * @return list<array{event: ?string, data: string}>
	 */
	public function feed(string $chunk): array
	{
		if ($this->fresh && $chunk !== '') {
			// the spec allows one leading BOM; it must not become part of the first field name
			$chunk = str_starts_with($chunk, "\u{FEFF}") ? substr($chunk, 3) : $chunk;
			$this->fresh = false;
		}

		// a lone \r is a legal line ending, but it may also be the first half of a \r\n pair
		// torn between chunks, so a trailing one waits for the next chunk
		if ($this->crPending) {
			$chunk = "\r" . $chunk;
			$this->crPending = false;
		}
		if (str_ends_with($chunk, "\r")) {
			$chunk = substr($chunk, 0, -1);
			$this->crPending = true;
		}
		$this->buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);

		$events = [];
		// an event ends with a blank line
		while (($pos = strpos($this->buffer, "\n\n")) !== false) {
			$block = substr($this->buffer, 0, $pos);
			$this->buffer = substr($this->buffer, $pos + 2);
			if ($event = $this->parse($block)) {
				$events[] = $event;
			}
		}
		return $events;
	}


	/**
	 * The transfer is over: whatever remains buffered is an event whose final blank line
	 * never came. The spec says to discard it, but a provider that ends the last event with
	 * a single newline should not have that event silently lost.
	 * @return ?array{event: ?string, data: string}
	 */
	public function close(): ?array
	{
		$block = $this->buffer;
		$this->buffer = '';
		$this->crPending = false;
		return $block === '' ? null : $this->parse($block);
	}


	/**
	 * @return ?array{event: ?string, data: string}
	 */
	private function parse(string $block): ?array
	{
		$name = null;
		$data = [];
		foreach (explode("\n", $block) as $line) {
			if ($line === '' || str_starts_with($line, ':')) {
				continue; // a comment, which is how DeepSeek keeps the connection alive
			}

			[$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
			$value = str_starts_with($value, ' ') ? substr($value, 1) : $value;
			if ($field === 'event') {
				$name = $value;
			} elseif ($field === 'data') {
				$data[] = $value;
			}
			// id and retry are of no use here
		}

		return $data === []
			? null
			: ['event' => $name, 'data' => implode("\n", $data)];
	}
}
