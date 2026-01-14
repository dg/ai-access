<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;
use AIAccess\Http;
use function array_filter, http_build_query, is_array, rtrim, str_contains;


/**
 * Client implementation for accessing Anthropic Claude API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Batch\Service
{
	private string $baseUrl = 'https://api.anthropic.com/';
	private string $apiVersion = '2023-06-01';


	public function __construct(
		private readonly string $apiKey,
		private readonly Http\Client $httpClient = new Http\CurlClient,
	) {
	}


	public function createChat(string $model): Chat
	{
		return new Chat($this, $model);
	}


	public function createBatch(): Batch
	{
		return new Batch($this);
	}


	/**
	 * Lists existing batch jobs.
	 * @param  ?int  $limit  Maximum number of jobs to return
	 * @param  ?string  $after  Cursor for pagination (retrieve the page after this batch ID)
	 * @param  ?string  $before  Cursor for pagination (retrieve the page before this batch ID)
	 * @return list<BatchResponse>
	 */
	public function listBatches(?int $limit = null, ?string $after = null, ?string $before = null): array
	{
		$params = array_filter([
			'limit' => $limit,
			'after_id' => $after,
			'before_id' => $before,
		], fn($v) => $v !== null);
		$response = $this->callApi('v1/messages/batches?' . http_build_query($params));

		$res = [];
		foreach ($response['data'] ?? [] as $batchData) {
			$res[] = new BatchResponse($this, $batchData);
		}
		return $res;
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
	 * @return ($isJson is true ? mixed[] : string)
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, ?array $payload = null, bool $isJson = true): array|string
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

		return !$isJson || is_array($data)
			? $data
			: throw new AIAccess\ApiException('Invalid JSON response from Claude API');
	}
}
