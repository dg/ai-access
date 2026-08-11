<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;
use AIAccess\Http;
use function is_array;


/**
 * Client implementation for accessing Anthropic Claude API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Batch\Service
{
	private string $baseUrl = 'https://api.anthropic.com/';
	private string $apiVersion = '2023-06-01';


	/**
	 * @param  ?string  $chatModel  used by createChat() and the batch when none is given there
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly Http\Client $httpClient = new Http\CurlClient,
		private readonly ?string $chatModel = null,
	) {
	}


	public function createChat(?string $model = null): Chat
	{
		$model ??= $this->chatModel ?? throw new AIAccess\LogicException('No chat model given and the client has no default one.');
		return new Chat($this, $model);
	}


	/**
	 * Lists models offered by the provider, following pagination.
	 * @return list<AIAccess\Model>
	 */
	public function listModels(): array
	{
		$res = [];
		$after = null;
		do {
			$response = $this->callApi('v1/models?limit=100' . ($after === null ? '' : '&after_id=' . $after));
			foreach ($response['data'] ?? [] as $model) {
				if (isset($model['id'])) {
					$res[] = new AIAccess\Model($model['id'], $model);
				}
			}
			$after = ($response['has_more'] ?? false) ? ($response['last_id'] ?? null) : null;
		} while ($after !== null);
		return $res;
	}


	public function createBatch(): Batch
	{
		return new Batch($this, $this->chatModel);
	}


	/**
	 * Lists existing batch jobs, newest first.
	 * @return \Generator<BatchResponse>
	 * @throws AIAccess\ServiceException
	 */
	public function listBatches(): \Generator
	{
		$after = null;
		do {
			$params = $after === null ? [] : ['after_id' => $after];
			$response = $this->callApi('v1/messages/batches' . ($params ? '?' . http_build_query($params) : ''));
			foreach ($response['data'] ?? [] as $batchData) {
				yield new BatchResponse($this, $batchData);
			}
			$after = ($response['has_more'] ?? false) ? $response['last_id'] ?? null : null;
		} while ($after !== null);
	}


	public function retrieveBatch(string $id): BatchResponse
	{
		return new BatchResponse($this, $this->callApi("v1/messages/batches/{$id}"));
	}


	public function cancelBatch(string $id): bool
	{
		$response = $this->callApi("v1/messages/batches/{$id}/cancel", []);
		return isset($response['cancel_initiated_at']);
	}


	/**
	 * Sets or updates client-wide options.
	 * @param  ?string  $customBaseUrl Override the base API URL. Null leaves current setting unchanged.
	 * @param  ?string  $apiVersion Override the Anthropic API version. Null leaves current setting unchanged.
	 */
	public function setOptions(
		?string $customBaseUrl = null,
		?string $apiVersion = null,
	): static
	{
		if ($customBaseUrl !== null) {
			$this->baseUrl = rtrim($customBaseUrl, '/') . '/';
		}

		if ($apiVersion !== null) {
			$this->apiVersion = $apiVersion;
		}

		return $this;
	}


	/**
	 * @param  mixed[]  $payload
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, ?array $payload = null): array
	{
		$url = str_contains($endpoint, '://') ? $endpoint : $this->baseUrl . $endpoint;
		$headers = [
			'Anthropic-Version' => $this->apiVersion,
			'x-api-key' => $this->apiKey,
		];

		$response = $this->httpClient->fetch($url, $payload, $headers);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "Claude API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from Claude API');
	}


	/**
	 * Streams a request, handing raw SSE bytes to $onChunk.
	 * @param  mixed[]  $payload
	 * @param  \Closure(string): (bool|null)  $onChunk
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApiStream(string $endpoint, array $payload, \Closure $onChunk): void
	{
		$headers = [
			'Anthropic-Version' => $this->apiVersion,
			'x-api-key' => $this->apiKey,
		];

		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload + ['stream' => true], $headers, onChunk: $onChunk);
		if ($response->getStatusCode() >= 400) {
			$data = $response->getData();
			throw new AIAccess\ApiException(
				$data['error']['message'] ?? "Claude API error (HTTP {$response->getStatusCode()})",
				$response->getStatusCode(),
			);
		}
	}


	/**
	 * Downloads a body and hands it over line by line, so that a result file of any size
	 * never has to fit in memory.
	 * @return iterable<int, string>
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function streamLines(string $endpoint): iterable
	{
		return Http\JsonlStream::read(function (\Closure $onChunk) use ($endpoint): void {
			$url = str_contains($endpoint, '://') ? $endpoint : $this->baseUrl . $endpoint;
			$headers = [
				'Anthropic-Version' => $this->apiVersion,
				'x-api-key' => $this->apiKey,
			];

			$response = $this->httpClient->fetch($url, headers: $headers, onChunk: $onChunk);
			if ($response->getStatusCode() >= 400) {
				$data = $response->getData();
				throw new AIAccess\ApiException(
					$data['error']['message'] ?? "Claude API error (HTTP {$response->getStatusCode()})",
					$response->getStatusCode(),
				);
			}
		});
	}
}
