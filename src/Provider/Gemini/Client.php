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
final class Client implements AIAccess\Chat\Service, AIAccess\Embedding\Service, AIAccess\Batch\Service, AIAccess\Image\Service
{
	private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/';


	public function __construct(
		private readonly string $apiKey,
		private readonly Http\Client $httpClient = new Http\CurlClient,
	) {
	}


	public function createChat(string $model): Chat
	{
		return new Chat($this, $model);
	}


	/**
	 * Lists models offered by the provider, following pagination.
	 * @return list<AIAccess\Model>
	 */
	public function listModels(): array
	{
		$res = [];
		$token = null;
		do {
			$response = $this->callApi('models?pageSize=200' . ($token === null ? '' : '&pageToken=' . urlencode($token)));
			foreach ($response['models'] ?? [] as $model) {
				if (isset($model['name'])) {
					$res[] = new AIAccess\Model(preg_replace('~^models/~', '', $model['name']), $model);
				}
			}
			$token = $response['nextPageToken'] ?? null;
		} while ($token !== null);
		return $res;
	}


	public function createBatch(): Batch
	{
		return new Batch($this);
	}


	/**
	 * Lists existing batch jobs.
	 * @param  ?int  $limit  Maximum number of jobs to return
	 * @param  ?string  $pageToken  Cursor for pagination
	 * @return list<BatchResponse>
	 */
	public function listBatches(?int $limit = null, ?string $pageToken = null): array
	{
		$params = array_filter(['pageSize' => $limit, 'pageToken' => $pageToken], fn($v) => $v !== null);
		$response = $this->callApi('batches' . ($params ? '?' . http_build_query($params) : ''));

		$res = [];
		// a batch is a long-running operation, hence the key
		foreach ($response['operations'] ?? $response['batches'] ?? [] as $batchData) {
			$res[] = new BatchResponse($this, $batchData);
		}
		return $res;
	}


	public function retrieveBatch(string $id): BatchResponse
	{
		return new BatchResponse($this, $this->callApi($this->batchPath($id)));
	}


	public function cancelBatch(string $id): bool
	{
		// the endpoint answers with an empty body, so anything but an exception is a success;
		// an empty array still makes this a POST
		$this->callApi($this->batchPath($id) . ':cancel', []);
		return true;
	}


	/**
	 * Ids come back as "batches/xyz" and are used as such; a bare id is accepted too.
	 */
	private function batchPath(string $id): string
	{
		return str_starts_with($id, 'batches/') ? $id : "batches/$id";
	}


	/**
	 * Generates an image. Gemini has no separate image endpoint: an image model is asked
	 * through the ordinary chat one, and references are simply part of the prompt.
	 * @param  list<AIAccess\Media>  $references  images to work from
	 */
	public function generateImage(string $model, string $prompt, array $references = []): AIAccess\Media
	{
		$parts = [['text' => $prompt]];
		foreach ($references as $reference) {
			$parts[] = ['inlineData' => ['mimeType' => $reference->getMimeType(), 'data' => $reference->getBase64()]];
		}

		$response = $this->callApi("models/$model:generateContent", [
			'contents' => [['role' => 'user', 'parts' => $parts]],
			'generationConfig' => ['responseModalities' => ['IMAGE']],
		]);

		foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
			if (isset($part['inlineData']['data'])) {
				$data = base64_decode((string) $part['inlineData']['data'], strict: true);
				if ($data === false) {
					throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
				}
				return new AIAccess\Media($data, (string) ($part['inlineData']['mimeType'] ?? 'image/png'), $response);
			}
		}

		// a model that refuses answers with text instead of a picture
		throw new AIAccess\UnexpectedResponseException('No image in the response'
			. (isset($response['candidates'][0]['finishReason']) ? ', finish reason ' . $response['candidates'][0]['finishReason'] : '') . '.');
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
		if (!$input) {
			throw new AIAccess\LogicException('Input cannot be empty.');
		}
		if ($title !== null && $taskType !== 'RETRIEVAL_DOCUMENT') {
			throw new AIAccess\LogicException("The 'title' parameter requires taskType RETRIEVAL_DOCUMENT.");
		}

		$config = array_filter([
			'taskType' => $taskType,
			'title' => $title,
			'outputDimensionality' => $outputDimensionality,
		], fn($v) => $v !== null);

		$requests = [];
		foreach ($input as $text) {
			if ($text === '') {
				throw new AIAccess\LogicException('All input elements must be non-empty strings.');
			}
			$request = ['model' => "models/$model", 'content' => ['parts' => [['text' => $text]]]];
			if ($config) {
				$request['embedContentConfig'] = $config;
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
			throw new AIAccess\UnexpectedResponseException(
				'Number of returned embeddings (' . count($results) . ') does not match the number of inputs (' . count($input) . ').',
			);
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
	 * @param  ?mixed[]  $payload
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, ?array $payload = null): array
	{
		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload, ['x-goog-api-key' => $this->apiKey]);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "Gemini API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from Gemini API');
	}
}
