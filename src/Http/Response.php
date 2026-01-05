<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;


/**
 * HTTP Response.
 * @internal
 */
final class Response
{
	public function __construct(
		private int $statusCode,
		/** @var array<string, string[]> $headers HTTP headers with lowercased keys */
		private array $headers,
		private mixed $data,
	) {
	}


	public function getStatusCode(): int
	{
		return $this->statusCode;
	}


	/**
	 * Returns the first value of the specified HTTP header.
	 */
	public function getHeader(string $name): ?string
	{
		return $this->headers[strtolower($name)][0] ?? null;
	}


	/**
	 * Returns all values of the specified HTTP header.
	 * @return string[]
	 */
	public function getHeaders(string $name): array
	{
		return $this->headers[strtolower($name)] ?? [];
	}


	/**
	 * Gets processed data from the response. If the response contains valid JSON,
	 * returns the decoded PHP value (array/object), otherwise returns the raw body as string.
	 */
	public function getData(): mixed
	{
		return $this->data;
	}
}
