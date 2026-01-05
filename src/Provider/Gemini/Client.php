<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use AIAccess\Embedding\Vector;
use AIAccess\Http;
use function count, is_array, rtrim;


/**
 * Client implementation for accessing Google Gemini API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Embedding\Service
{
	private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';


	public function __construct(
		private string $apiKey,
		private Http\Client $httpClient = new Http\CurlClient,
	) {
	}


	public function createChat(string $model): Chat
	{
		return new Chat($this, $model);
	}


	/**
	 * Calculates embeddings using Gemini models via the batch endpoint.
	 * @param  list<string>  $input
	 * @param  ?string  $taskType Optional task type hint (e.g., RETRIEVAL_QUERY, RETRIEVAL_DOCUMENT)
	 * @param  ?string  $title Optional title if taskType is RETRIEVAL_DOCUMENT
	 * @param  ?int  $outputDimensionality Optional request for specific embedding dimensions
	 * @return list<Vector>
	 */
	public function calculateEmbeddings(
		string $model,
		array $input,
		?string $taskType = null,
		?string $title = null,
		?int $outputDimensionality = null,
	): array
	{
		if (empty($input)) {
			return [];
		}

		$requests = [];
		foreach ($input as $text) {
			if ($text === '') {
				throw new AIAccess\LogicException('All input elements must be non-empty strings.');
			}
			$content = ['parts' => [['text' => $text]]];
			$request = ['model' => "models/$model", 'content' => $content];

			if ($taskType !== null) {
				$request['taskType'] = $taskType;
			}
			if ($title !== null && $taskType === 'RETRIEVAL_DOCUMENT') {
				$request['title'] = $title;
			}
			if ($outputDimensionality !== null) {
				$request['outputDimensionality'] = $outputDimensionality;
			}

			$requests[] = $request;
		}

		$response = $this->callApi("models/{$model}:batchEmbedContents", ['requests' => $requests]);
		$results = [];
		if (is_array($response['embeddings'] ?? null)) {
			foreach ($response['embeddings'] as $index => $data) {
				if (is_array($values = $data['values'] ?? null)) {
					/** @var list<float> $values */
					$results[$index] = new Vector($values);
				}
			}
		}

		if (count($results) !== count($input)) {
			trigger_error('Number of returned embeddings does not match the number of inputs.', E_USER_WARNING);
		}
		return array_values($results);
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
	 * @param  mixed[]  $payload
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, array $payload): array
	{
		$url = $this->baseUrl . $endpoint . '?key=' . $this->apiKey;
		$response = $this->httpClient->fetch($url, $payload);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "Gemini API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\ApiException('Invalid JSON response from Gemini API');
	}
}
