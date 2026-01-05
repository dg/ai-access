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
use function array_filter, count, http_build_query, is_array, rtrim, str_contains, usort;


/**
 * Client implementation for accessing OpenAI API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Embedding\Service, AIAccess\Batch\Service
{
	private string $baseUrl = 'https://api.openai.com/v1/';
	private ?string $organizationId = null;


	public function __construct(
		private string $apiKey,
		private Http\Client $httpClient = new Http\CurlClient,
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
	 * @return BatchResponse[]
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
		return $this->callApi("batches/{$id}/cancel", '')['status'] === 'cancelling';
	}


	/**
	 * Calculates embeddings for the given input text(s) using a specified OpenAI model.
	 * @param  ?int  $dimensions  The number of dimensions the resulting output embeddings should have. Only supported for 'text-embedding-3' models
	 */
	public function calculateEmbeddings(string $model, array $input, ?int $dimensions = null): array
	{
		if (empty($input)) {
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
			if (!str_contains($model, 'text-embedding-3')) {
				trigger_error("The 'dimensions' parameter is only supported for text-embedding-3 models.", E_USER_WARNING);
			}
			$payload['dimensions'] = $dimensions;
		}

		$response = $this->callApi('embeddings', $payload);

		$results = [];
		if (isset($response['data']) && is_array($response['data'])) {
			usort($response['data'], fn($a, $b) => $a['index'] <=> $b['index']);

			foreach ($response['data'] as $data) {
				if (is_array($values = $data['embedding'] ?? null)) {
					/** @var list<float> $values */
					$results[] = new Vector($values);
				} elseif (isset($data['error'])) {
					trigger_error("Error processing input at index {$data['index']}: " . ($data['error']['message'] ?? 'Unknown error'), E_USER_WARNING);
				}
			}
		}

		if (count($results) !== count($input)) {
			trigger_error('Number of returned embeddings (' . count($results) . ') does not match the number of inputs (' . count($input) . '). Check for errors in the raw response.', E_USER_WARNING);
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
		return $response['id'];
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
		return $response['id'];
	}


	/**
	 * Sets or updates client-wide options.
	 * @param  ?string  $customBaseUrl  Override the base API URL. Null leaves current setting unchanged.
	 * @param  ?string  $organizationId  Set the OpenAI Organization ID. Null leaves current setting unchanged or removes it.
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
			$this->organizationId = $organizationId;
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
			: throw new AIAccess\ApiException('Invalid JSON response from OpenAI API');
	}
}
