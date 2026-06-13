<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;


/**
 * Reports every request and how it ended, for logging or a debug bar.
 */
final class ObservableClient implements Client
{
	public function __construct(
		private readonly Client $inner,
		/** @var ?\Closure(string, mixed): void */
		private readonly ?\Closure $onRequest = null,
		/** @var ?\Closure(Response, float): void */
		private readonly ?\Closure $onResponse = null,
		/** @var ?\Closure(\Throwable, float): void */
		private readonly ?\Closure $onError = null,
	) {
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?\Closure $onChunk = null,
	): Response
	{
		// headers are not passed on: they carry the API key
		if ($this->onRequest !== null) {
			($this->onRequest)($url, $payload);
		}

		// for a stream the elapsed time covers the whole transfer, so it says how long the
		// answer took to finish rather than how long it took to start
		$start = microtime(true);
		try {
			$response = $this->inner->fetch($url, $payload, $headers, $method, $onChunk);
		} catch (\Throwable $e) {
			// anything that went wrong during the transfer, which for a stream includes the
			// consumer's own callback
			if ($this->onError !== null) {
				($this->onError)($e, microtime(true) - $start);
			}
			throw $e;
		}

		if ($this->onResponse !== null) {
			($this->onResponse)($response, microtime(true) - $start);
		}
		return $response;
	}
}
