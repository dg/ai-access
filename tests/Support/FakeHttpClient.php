<?php declare(strict_types=1);

namespace Tests\Support;

use AIAccess\Http;


/**
 * Returns queued responses and records what was sent.
 */
class FakeHttpClient implements Http\Client, \Countable
{
	/** @var list<array{url: string, payload: mixed, headers: string[], method: ?string}> */
	public array $requests = [];

	/** @var list<array<string, string>> uploaded form fields and file contents, one entry per request */
	public array $uploads = [];

	/** @var list<Http\Response> */
	private array $queue = [];

	/** @var list<array{chunks: list<string>, status: int, headers: array<string, string[]>, error: mixed[]|string|null}> */
	private array $streams = [];


	/** @param mixed[]|string $data */
	public function queue(array|string $data, int $statusCode = 200, array $headers = []): static
	{
		$this->queue[] = new Http\Response($statusCode, $headers, $data);
		return $this;
	}


	public function fetch(
		string $url,
		string|array|Http\FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
		?\Closure $onChunk = null,
	): Http\Response
	{
		$this->requests[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers, 'method' => $method];

		if ($payload instanceof Http\FormData) {
			// read here and not in the assertion: an uploaded file is often a temporary one
			// the caller deletes the moment the request is over
			$upload = [];
			foreach ($payload->getItems() as $field => $info) {
				$upload[$field] = $info['value']
					?? $info['content']
					?? (string) @file_get_contents($info['path']);
			}
			$this->uploads[] = $upload;
		}
		if ($onChunk === null) {
			return array_shift($this->queue) ?? throw new \LogicException('No response queued in FakeHttpClient.');
		}

		$stream = array_shift($this->streams) ?? throw new \LogicException('No stream queued in FakeHttpClient.');
		foreach ($stream['chunks'] as $chunk) {
			if ($onChunk($chunk) === false) {
				break;
			}
		}
		return new Http\Response($stream['status'], $stream['headers'], $stream['error']);
	}


	/**
	 * Queues a stream: the pieces are handed to the callback one by one, exactly as split.
	 * @param  list<string>  $chunks
	 */
	public function queueStream(array $chunks, int $statusCode = 200, array $headers = []): static
	{
		$this->streams[] = ['chunks' => $chunks, 'status' => $statusCode, 'headers' => $headers, 'error' => null];
		return $this;
	}


	/**
	 * Queues a failed stream. As with a real one, the callback is never called and the body
	 * arrives whole in the response instead.
	 * @param  mixed[]|string  $body
	 */
	public function queueStreamError(array|string $body, int $statusCode = 400): static
	{
		$this->streams[] = ['chunks' => [], 'status' => $statusCode, 'headers' => [], 'error' => $body];
		return $this;
	}


	/** How many requests were made, i.e. how many rounds the loop took. */
	public function count(): int
	{
		return count($this->requests);
	}


	/** @return array{url: string, payload: mixed, headers: string[], method: ?string} */
	public function lastRequest(): array
	{
		return $this->requests[count($this->requests) - 1] ?? throw new \LogicException('No request was made.');
	}


	/** @return mixed[] */
	public function lastPayload(): array
	{
		$payload = $this->lastRequest()['payload'];
		return is_array($payload) ? $payload : throw new \LogicException('Last payload is not an array.');
	}
}
