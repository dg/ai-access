<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library (https://ai-access.deegee.dev)
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\{CommunicationException, RequestNotSentException};
use function in_array;


/**
 * Retries rate limits, overloads and network failures with exponential backoff.
 *
 * A failure after the request left is repeated only for a GET: the model may have answered
 * by then, and a second attempt would be paid for again. For the same reason any other request
 * is repeated only after a status saying the server did not work on it (408, 429, 500, 503);
 * behind a 502 or 504 a gateway gave up while the server may still be answering.
 *
 * Requests are replayed as they are, so a 500 that in fact created something
 * server-side (a batch job, an uploaded file) can be created twice.
 */
final readonly class RetryClient implements Client
{
	/** @var \Closure(float): void */
	private \Closure $sleep;


	/**
	 * @param  \Closure(float): void  $sleep
	 */
	public function __construct(
		private Client $inner,
		private int $maxAttempts = 3,
		private float $initialDelay = 1.0,
		private float $maxDelay = 30.0,
		?\Closure $sleep = null,
	) {
		$this->sleep = $sleep ?? static fn(float $seconds) => usleep((int) ($seconds * 1_000_000));
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?\Closure $onChunk = null,
	): Response
	{
		$idempotent = strtoupper($method ?? ($payload === null ? 'GET' : 'POST')) === 'GET';
		$attempt = 1;
		while (true) {
			$started = false;
			$watch = $onChunk === null ? null : function (string $chunk) use ($onChunk, &$started) {
				$started = true;
				return $onChunk($chunk);
			};

			try {
				$response = $this->inner->fetch($url, $payload, $headers, $method, $watch);
				// once the model has started talking, replaying the request would duplicate
				// the answer and pay for it twice
				if ($started || $attempt >= $this->maxAttempts || !self::isRetriable($response->getStatusCode(), $idempotent)) {
					return $response;
				}
				$delay = $this->parseRetryAfter($response->getHeader('retry-after')) ?? $this->backoff($attempt);

			} catch (CommunicationException $e) {
				if ($started || $attempt >= $this->maxAttempts || !($idempotent || $e instanceof RequestNotSentException)) {
					throw $e;
				}
				$delay = $this->backoff($attempt);
			}

			($this->sleep)($delay);
			$attempt++;
		}
	}


	private static function isRetriable(int $statusCode, bool $idempotent): bool
	{
		// 501 and 505 are the server saying "never", not "not now"
		return in_array($statusCode, [408, 429, 500, 503], true)
			|| ($idempotent && $statusCode >= 500 && $statusCode !== 501 && $statusCode !== 505);
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
