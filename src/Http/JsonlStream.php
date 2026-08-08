<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use function rtrim, strpos, substr;


/**
 * Cuts a byte stream into lines and turns the transport's push into a pull.
 *
 * Batch results arrive as JSONL that can be hundreds of megabytes, so they are never held
 * whole: the request runs inside a Fiber that suspends on every finished line. Unlike
 * Chat\TextStream, abandoning the iteration is an end rather than a pause - there is
 * nothing to resume a result file for - so the transfer is aborted as soon as the
 * generator is dropped.
 *
 * @internal
 */
final class JsonlStream
{
	private string $buffer = '';


	/**
	 * @param  \Closure(\Closure(string): (bool|null)): void  $transport  runs the request, pushing body chunks into the given callback
	 * @return \Generator<int, string>  complete lines, empty ones skipped
	 */
	public static function read(\Closure $transport): \Generator
	{
		$self = new self;
		/** @var \Fiber<void, ?bool, void, string> $fiber */
		$fiber = new \Fiber(fn() => $transport(static function (string $chunk) use ($self): bool {
			foreach ($self->feed($chunk) as $line) {
				// false comes back from the consumer and aborts the transfer
				if (\Fiber::suspend($line) === false) {
					return false;
				}
			}
			return true;
		}));

		try {
			$line = $fiber->start();
			while (!$fiber->isTerminated()) {
				if ($line !== null) {
					yield $line;
				}
				$line = $fiber->resume();
			}
			if (($line = $self->close()) !== null) {
				yield $line;
			}
		} finally {
			// the consumer stopped early, so the rest of the file is neither transferred nor paid for
			try {
				while (!$fiber->isTerminated()) {
					$fiber->resume(false);
				}
			} catch (\Throwable) {
				// a dying generator must not throw on top of whatever ended it
			}
		}
	}


	/**
	 * Feeds raw bytes in and returns whatever complete lines they finished. A chunk routinely
	 * ends mid-line, so the tail waits for the next one.
	 * @return list<string>
	 */
	private function feed(string $chunk): array
	{
		$this->buffer .= $chunk;
		$lines = [];
		while (($pos = strpos($this->buffer, "\n")) !== false) {
			$line = rtrim(substr($this->buffer, 0, $pos), "\r");
			$this->buffer = substr($this->buffer, $pos + 1);
			if ($line !== '') {
				$lines[] = $line;
			}
		}
		return $lines;
	}


	/**
	 * The transfer is over: a last line whose newline never came is still a line.
	 */
	private function close(): ?string
	{
		$line = rtrim($this->buffer, "\r");
		$this->buffer = '';
		return $line === '' ? null : $line;
	}
}
