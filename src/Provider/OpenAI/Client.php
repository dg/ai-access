<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use AIAccess\Embedding\Vector;
use AIAccess\Http;
use AIAccess\Http\FormData;
use function array_filter, count, http_build_query, is_array, rtrim, usort;


/**
 * Client implementation for accessing OpenAI API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Embedding\Service, AIAccess\Batch\Service
{
	private string $baseUrl = 'https://api.openai.com/v1/';
	private ?string $organizationId = null;


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
	 * @return list<BatchResponse>
	 */
	public function listBatches(?int $limit = null, ?string $after = null): array
	{
		$params = array_filter([
			'limit' => $limit,
			'after' => $after,
		], fn($v) => $v !== null);
		$response = $this->callApi('batches?' . http_build_query($params));

		$res = [];
		foreach ($response['data'] ?? [] as $batchData) {
			$res[] = new BatchResponse($this, $batchData);
		}
		return $res;
	}


	public function retrieveBatch(string $id): BatchResponse
	{
		return new BatchResponse($this, $this->callApi("batches/{$id}"));
	}


	public function cancelBatch(string $id): bool
	{
		$response = $this->callApi("batches/{$id}/cancel", '');
		return AIAccess\Helpers::expectString($response['status'] ?? null, 'batch status') === 'cancelling';
	}


	/**
	 * Calculates embeddings for the given input text(s) using a specified OpenAI model.
	 * @param  list<string>  $input
	 * @param  ?int  $dimensions  Size of the resulting vectors. Only supported by text-embedding-3 models. Maximum 2048 inputs per request.
	 * @return list<Vector>
	 */
	public function calculateEmbeddings(string $model, array $input, ?int $dimensions = null): array
	{
		if (!$input) {
			throw new AIAccess\LogicException('Input cannot be empty.');
		}
		foreach ($input as $text) {
			if ($text === '') {
				throw new AIAccess\LogicException('All input elements must be non-empty strings.');
			}
		}

		$payload = [
			'model' => $model,
			'input' => $input,
		];
		if ($dimensions !== null) {
			$payload['dimensions'] = $dimensions;
		}

		$response = $this->callApi('embeddings', $payload);

		$results = [];
		if (is_array($response['data'] ?? null)) {
			usort($response['data'], fn($a, $b) => $a['index'] <=> $b['index']);

			foreach ($response['data'] as $data) {
				if (is_array($values = $data['embedding'] ?? null)) {
					/** @var list<float> $values */
					$results[] = new Vector($values);
				}
			}
		}

		if (count($results) !== count($input)) {
			throw new AIAccess\UnexpectedResponseException('Number of returned embeddings (' . count($results) . ') does not match the number of inputs (' . count($input) . ').');
		}

		return $results;
	}


	/**
	 * Uploads a file to the OpenAI API.
	 * @throws AIAccess\ServiceException
	 */
	public function uploadContent(string $content, string $fileName, string $purpose, ?string $mimeType = null): string
	{
		$formData = (new FormData)
			->addField('purpose', $purpose)
			->addFileContent('file', $content, $fileName, $mimeType);
		$response = $this->callApi('files', $formData);
		return AIAccess\Helpers::expectString($response['id'] ?? null, 'file id');
	}


	/**
	 * Uploads a file from a local path to the OpenAI API.
	 * @throws AIAccess\ServiceException
	 */
	public function uploadFile(string $filePath, string $purpose, ?string $mimeType = null): string
	{
		$formData = (new FormData)
			->addField('purpose', $purpose)
			->addFile('file', $filePath, null, $mimeType);
		$response = $this->callApi('files', $formData);
		return AIAccess\Helpers::expectString($response['id'] ?? null, 'file id');
	}


	/**
	 * Sets or updates client-wide options.
	 * @param  ?string  $customBaseUrl  Override the base API URL. Null leaves current setting unchanged.
	 * @param  ?string  $organizationId  Set the OpenAI Organization ID. Null leaves it unchanged, empty string removes it.
	 */
	public function setOptions(
		?string $customBaseUrl = null,
		?string $organizationId = null,
	): static
	{
		if ($customBaseUrl !== null) {
			$this->baseUrl = rtrim($customBaseUrl, '/') . '/';
		}
		if ($organizationId !== null) {
			$this->organizationId = $organizationId === '' ? null : $organizationId;
		}
		return $this;
	}


	/**
	 * @param  mixed[]  $payload
	 * @param  string[]  $headers
	 * @return ($isJson is true ? mixed[] : string)
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(
		string $endpoint,
		array|string|FormData|null $payload = null,
		array $headers = [],
		bool $isJson = true,
	): array|string
	{
		$headers = array_filter($headers + [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'OpenAI-Organization' => $this->organizationId,
		]);

		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload, $headers);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "OpenAI API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return !$isJson || is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from OpenAI API');
	}
}
