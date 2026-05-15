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

	/** @var list<Http\Response> */
	private array $queue = [];


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
	): Http\Response
	{
		$this->requests[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers, 'method' => $method];
		return array_shift($this->queue) ?? throw new \LogicException('No response queued in FakeHttpClient.');
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
