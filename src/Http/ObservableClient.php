<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;


/**
 * Reports every request and response, for logging or a debug bar.
 */
final class ObservableClient implements Client
{
	public function __construct(
		private readonly Client $inner,
		/** @var ?\Closure(string, mixed): void */
		private readonly ?\Closure $onRequest = null,
		/** @var ?\Closure(Response, float): void */
		private readonly ?\Closure $onResponse = null,
	) {
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
	): Response
	{
		// headers are not passed on: they carry the API key
		if ($this->onRequest !== null) {
			($this->onRequest)($url, $payload);
		}

		// a failed request reports nothing: there is no response to describe
		$start = microtime(true);
		$response = $this->inner->fetch($url, $payload, $headers, $method);

		if ($this->onResponse !== null) {
			($this->onResponse)($response, microtime(true) - $start);
		}
		return $response;
	}
}
