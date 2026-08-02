<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;

use AIAccess\LogicException;


/**
 * An answer that can be read while it is still being written.
 *
 * The HTTP layer pushes chunks into a callback while foreach() wants to pull them, so the
 * request runs inside a Fiber that suspends on every piece of text. Nothing happens until
 * you start reading.
 *
 * Stopping half way is fine: getResponse() finishes reading the same stream rather than
 * asking the model again, and a second foreach() picks up where the first one stopped.
 * Breaking out is therefore a pause, not an end; to stop the generation for good, and stop
 * paying for it, call cancel().
 *
 * @implements \IteratorAggregate<int, string>
 */
final class TextStream implements \IteratorAggregate
{
	private ?Response $response = null;

	/** @var ?\Fiber<void, ?bool, Response, string> */
	private ?\Fiber $fiber = null;
	private ?string $pending = null;
	private ?\Throwable $failure = null;

	/** @var \Closure(\Closure(string): (bool|null)): Response */
	private \Closure $run;


	/**
	 * @param  \Closure(\Closure(string): (bool|null)): Response  $run  receives the emit callback,
	 *         returns the finished response
	 * @internal
	 */
	public function __construct(\Closure $run)
	{
		$this->run = $run;
	}


	/**
	 * @return \Generator<int, string>
	 */
	public function getIterator(): \Generator
	{
		if ($this->response !== null) {
			throw new LogicException('The stream has already been read.');
		}

		$fiber = $this->fiber();
		while (!$fiber->isTerminated()) {
			// pending is cleared before the yield, so a chunk is never delivered twice
			// when the consumer breaks out and a later foreach picks the stream back up
			if (($chunk = $this->pending) === null) {
				$this->pending = $this->guard($fiber->resume(...));
				continue;
			}
			$this->pending = null;
			yield $chunk;
		}
		$this->response = $fiber->getReturn();
	}


	/**
	 * The finished response: text, usage, finish reason, tool calls. Reads whatever is left
	 * of the stream first, without sending a second request.
	 */
	public function getResponse(): Response
	{
		if ($this->response === null) {
			$fiber = $this->fiber();
			while (!$fiber->isTerminated()) {
				$this->pending = $this->guard($fiber->resume(...));
			}
			$this->response = $fiber->getReturn();
		}
		return $this->response;
	}


	/**
	 * The whole answer as one string, for when streaming was about progress rather than
	 * about handling the pieces. Reads whatever is left of the stream first and works
	 * no matter how much of it was already consumed.
	 */
	public function getText(): string
	{
		return $this->getResponse()->getText();
	}


	/**
	 * Stops the generation for good. Breaking out of foreach() only pauses reading, because
	 * the request stays open and can be resumed; this closes it, so the rest of the answer is
	 * neither produced nor billed. What is already read stays readable through getResponse(),
	 * which then reports FinishReason::Cancelled.
	 */
	public function cancel(): void
	{
		if ($this->response !== null || $this->fiber === null) {
			return; // nothing sent yet, or already finished
		}

		// false travels back through the emit callback, and that is what aborts the transfer
		$fiber = $this->fiber;
		while (!$fiber->isTerminated()) {
			$this->guard(fn() => $fiber->resume(false));
		}
		$this->pending = null;
		$this->response = $fiber->getReturn();
	}


	/**
	 * @return \Fiber<void, ?bool, Response, string>
	 */
	private function fiber(): \Fiber
	{
		// whatever the request failed with is what every later call fails with too; without
		// this the second one would report that a fiber has no return value, which says
		// nothing about the rate limit or the dropped connection that actually happened
		if ($this->failure !== null) {
			throw $this->failure;
		}

		if ($this->fiber === null) {
			$this->fiber = new \Fiber(fn() => ($this->run)(static fn(string $text) => \Fiber::suspend($text)));
			$this->pending = $this->guard($this->fiber->start(...));
		}
		return $this->fiber;
	}


	/**
	 * @param  \Closure(): ?string  $step
	 */
	private function guard(\Closure $step): ?string
	{
		try {
			return $step();
		} catch (\Throwable $e) {
			$this->failure = $e;
			throw $e;
		}
	}
}
