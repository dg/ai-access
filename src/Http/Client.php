<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\CommunicationException;


/**
 * Interface for sending HTTP requests required by the AI Access library.
 */
interface Client
{
	/**
	 * Sends an HTTP request.
	 * @param  string  $url  URL for the request
	 * @param  string|mixed[]|FormData|null  $payload  Request body (array is encoded as JSON)
	 * @param  string[]  $headers  HTTP headers
	 * @param  non-empty-string|null  $method  HTTP method (GET, POST, ...), defaults to GET for null payload or POST for non-null payload
	 * @throws CommunicationException  On connection errors, timeouts, etc.
	 */
	function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
	): Response;
}
