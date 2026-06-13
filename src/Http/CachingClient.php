<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\LogicException;


/**
 * Replays identical requests from disk. Meant for development and tests,
 * where re-running a script should not cost money; in production a model
 * answering the same thing every time is a bug, not a feature.
 */
final class CachingClient implements Client
{
	public function __construct(
		private readonly Client $inner,
		private readonly string $directory,
		private readonly int $ttl = 86400,
	) {
		if (!is_dir($directory) && !@mkdir($directory, recursive: true) && !is_dir($directory)) {
			throw new LogicException("Cannot create cache directory '$directory'.");
		}
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?\Closure $onChunk = null,
	): Response
	{
		// streams pass straight through: replaying one from disk would have to fake the timing
		// as well as the content, and a cache that changes how the code behaves is worse than
		// no cache
		if ($onChunk !== null) {
			return $this->inner->fetch($url, $payload, $headers, $method, $onChunk);
		}

		// an upload is not a question with an answer to remember, and its files are not part
		// of a serializable key anyway
		if ($payload instanceof FormData) {
			return $this->inner->fetch($url, $payload, $headers, $method);
		}

		// headers belong to the key, minus their secrets: the API key does not change the
		// answer, but a version or beta-feature header does
		$identity = array_diff_key(
			array_change_key_case($headers),
			['authorization' => 0, 'x-api-key' => 0, 'api-key' => 0, 'x-goog-api-key' => 0],
		);
		ksort($identity);
		$file = $this->directory . '/' . hash('xxh128', serialize([$method, $url, $payload, $identity])) . '.cache';
		if (is_file($file) && filemtime($file) > time() - $this->ttl) {
			$cached = @unserialize((string) file_get_contents($file), ['allowed_classes' => [Response::class]]);
			if ($cached instanceof Response) {
				return $cached;
			}
		}

		$response = $this->inner->fetch($url, $payload, $headers, $method);
		if ($response->getStatusCode() < 300) {
			file_put_contents($file, serialize($response), LOCK_EX);
		}
		return $response;
	}
}
