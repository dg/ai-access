<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\DeepSeek;

use AIAccess;
use AIAccess\Http;
use function is_array, rtrim;


/**
 * Client implementation for accessing DeepSeek API models.
 */
final class Client implements AIAccess\Chat\Service
{
	private string $baseUrl = 'https://api.deepseek.com/';


	public function __construct(
		private readonly string $apiKey,
		private readonly Http\Client $httpClient = new Http\CurlClient,
	) {
	}


	public function createChat(string $model): Chat
	{
		return new Chat($this, $model);
	}


	/** @return list<AIAccess\Model> */
	public function listModels(): array
	{
		$res = [];
		foreach ($this->callApi('models')['data'] ?? [] as $model) {
			if (isset($model['id'])) {
				$res[] = new AIAccess\Model($model['id'], $model);
			}
		}
		return $res;
	}


	/**
	 * Sets or updates client-wide options.
	 * @param  ?string  $customBaseUrl Override the base API URL. Null leaves current setting unchanged.
	 */
	public function setOptions(
		?string $customBaseUrl = null,
	): static
	{
		if ($customBaseUrl !== null) {
			$this->baseUrl = rtrim($customBaseUrl, '/') . '/';
		}
		return $this;
	}


	/**
	 * @param  ?mixed[]  $payload
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, ?array $payload = null): array
	{
		$headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
		];

		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload, $headers);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "DeepSeek API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from DeepSeek API');
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
		$headers = ['Authorization' => 'Bearer ' . $this->apiKey];

		$response = $this->httpClient->fetch(
			$this->baseUrl . $endpoint,
			$payload + ['stream' => true, 'stream_options' => ['include_usage' => true]],
			$headers,
			onChunk: $onChunk,
		);
		if ($response->getStatusCode() >= 400) {
			$data = $response->getData();
			throw new AIAccess\ApiException(
				$data['error']['message'] ?? 'DeepSeek API error (HTTP ' . $response->getStatusCode() . ')',
				$response->getStatusCode(),
			);
		}
	}
}
