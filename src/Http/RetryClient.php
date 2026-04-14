<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\CommunicationException;


/**
 * Retries rate limits, overloads and network failures with exponential backoff.
 *
 * Requests are replayed as they are, so a 5xx that in fact created something
 * server-side (a batch job, an uploaded file) can be created twice.
 */
final class RetryClient implements Client
{
	/** @var \Closure(float): void */
	private \Closure $sleep;


	/**
	 * @param  \Closure(float): void  $sleep
	 */
	public function __construct(
		private readonly Client $inner,
		private readonly int $maxAttempts = 3,
		private readonly float $initialDelay = 1.0,
		private readonly float $maxDelay = 30.0,
		?callable $sleep = null,
	) {
		$this->sleep = $sleep ?? static fn(float $seconds) => usleep((int) ($seconds * 1_000_000));
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
	): Response
	{
		$attempt = 1;
		while (true) {
			try {
				$response = $this->inner->fetch($url, $payload, $headers, $method);
				if ($attempt >= $this->maxAttempts || !$this->isRetriable($response->getStatusCode())) {
					return $response;
				}
				$delay = $this->parseRetryAfter($response->getHeader('retry-after')) ?? $this->backoff($attempt);

			} catch (CommunicationException $e) {
				if ($attempt >= $this->maxAttempts) {
					throw $e;
				}
				$delay = $this->backoff($attempt);
			}

			($this->sleep)($delay);
			$attempt++;
		}
	}


	private function isRetriable(int $statusCode): bool
	{
		// 501 and 505 are the server saying "never", not "not now"
		return $statusCode === 408
			|| $statusCode === 429
			|| ($statusCode >= 500 && $statusCode !== 501 && $statusCode !== 505);
	}


	private function backoff(int $attempt): float
	{
		$delay = min($this->initialDelay * 2 ** ($attempt - 1), $this->maxDelay);
		return $delay * (0.5 + mt_rand() / mt_getrandmax() / 2);
	}


	private function parseRetryAfter(?string $value): ?float
	{
		if ($value === null) {
			return null;
		} elseif (is_numeric($value)) {
			// a negative value from a confused proxy must not turn into a negative sleep
			return min(max((float) $value, 0), $this->maxDelay);
		}
		$time = strtotime($value);
		return $time === false ? null : min(max($time - time(), 0), $this->maxDelay);
	}
}
