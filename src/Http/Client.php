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
	 *
	 * With $onChunk given the request streams: the body is handed to the callback in pieces
	 * as they arrive and the returned Response carries only the status and headers, because
	 * the body has already been delivered - except on a non-2xx status, where the body is
	 * kept whole so the caller can build an exception from it. A stream is also bounded by silence
	 * rather than by total time: it is given up on when nothing arrives for the request
	 * timeout, however long the whole answer takes.
	 * @param  string  $url  URL for the request
	 * @param  string|mixed[]|FormData|null  $payload  Request body (array is encoded as JSON)
	 * @param  string[]  $headers  HTTP headers
	 * @param  non-empty-string|null  $method  HTTP method (GET, POST, ...), defaults to GET for null payload or POST for non-null payload
	 * @param  ?\Closure(string): (bool|null)  $onChunk  streams the response body; returning false stops the transfer
	 * @throws CommunicationException  On connection errors, timeouts, stalled transfers, etc.
	 */
	function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?\Closure $onChunk = null,
	): Response;
}
