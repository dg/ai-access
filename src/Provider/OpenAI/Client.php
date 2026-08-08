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
use function array_filter, count, http_build_query, is_array, rtrim, strlen, usort;


/**
 * Client implementation for accessing OpenAI API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Embedding\Service, AIAccess\Batch\Service, AIAccess\Image\Service
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


	public function createBatch(): Batch
	{
		return new Batch($this);
	}


	/**
	 * Uploads the requests as a JSONL file and creates a batch job over it. The lines are
	 * written to disk rather than joined in memory, because a batch of images with inlined
	 * references runs to hundreds of megabytes.
	 * @param  iterable<string>  $lines
	 * @param  ?mixed[]  $metadata
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function submitBatch(string $endpoint, iterable $lines, ?array $metadata = null): BatchResponse
	{
		$path = sys_get_temp_dir() . '/aiaccess-batch-' . bin2hex(random_bytes(8)) . '.jsonl';
		$handle = @fopen($path, 'wb');
		if ($handle === false) {
			throw new AIAccess\IOException("Cannot write file '$path'.");
		}

		try {
			try {
				foreach ($lines as $line) {
					$line .= "\n";
					// a full disk writes a part of the line and reports no error; the job would
					// then fail remotely, on a request nobody wrote that way
					if (@fwrite($handle, $line) !== strlen($line)) {
						throw new AIAccess\IOException("Cannot write file '$path'.");
					}
				}
			} finally {
				fclose($handle);
			}
			$fileId = $this->uploadFile($path, 'batch', 'text/jsonl', 'batch_requests.jsonl');
		} finally {
			@unlink($path);
		}

		$payload = [
			'input_file_id' => $fileId,
			'endpoint' => $endpoint,
			// the only window the API offers
			'completion_window' => '24h',
		];
		if ($metadata !== null) {
			$payload['metadata'] = $metadata;
		}
		return new BatchResponse($this, $this->callApi('batches', $payload));
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
		// an empty array still makes this a POST, and unlike an empty string it goes out as
		// JSON; the API rejects the form-urlencoded body curl would default to
		$response = $this->callApi("batches/{$id}/cancel", []);
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
	 * Generates an image. With references the model edits them instead (max 10).
	 * @param  list<AIAccess\Media>  $references  reference images (PNG, JPEG or WebP)
	 * @param  ?string  $size  e.g. '1024x1024' or '2048x1024'
	 * @param  ?string  $quality  'low', 'medium' or 'high'
	 * @param  ?string  $background  'transparent', 'opaque' or 'auto'
	 * @param  ?string  $format  'png', 'jpeg' or 'webp'
	 */
	public function generateImage(
		string $model,
		string $prompt,
		array $references = [],
		?string $size = null,
		?string $quality = null,
		?string $background = null,
		?string $format = null,
	): AIAccess\Media
	{
		$options = array_filter([
			'size' => $size,
			'quality' => $quality,
			'background' => $background,
			'output_format' => $format,
		], fn($v) => $v !== null);

		if ($references) {
			$formData = new FormData;
			$formData->addField('model', $model)->addField('prompt', $prompt)->addField('n', '1');
			foreach ($options as $key => $value) {
				$formData->addField($key, $value);
			}
			foreach ($references as $i => $reference) {
				$extension = match ($reference->getMimeType()) {
					'image/png' => 'png',
					'image/jpeg' => 'jpg',
					'image/webp' => 'webp',
					default => throw new AIAccess\LogicException('Unsupported reference image type: ' . $reference->getMimeType()),
				};
				$formData->addFileContent("image[$i]", $reference->getData(), "image$i.$extension", $reference->getMimeType());
			}
			$response = $this->callApi('images/edits', $formData);
		} else {
			$response = $this->callApi('images/generations', ['model' => $model, 'prompt' => $prompt, 'n' => 1] + $options);
		}

		$encoded = AIAccess\Helpers::expectString($response['data'][0]['b64_json'] ?? null, 'image data');
		$data = base64_decode($encoded, strict: true);
		if ($data === false) {
			throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
		}

		return new AIAccess\Media($data, match ($format) {
			null, 'png' => 'image/png',
			'jpg', 'jpeg' => 'image/jpeg',
			default => 'image/' . $format,
		}, $response);
	}


	/**
	 * Uploads a file from a local path to the Files API. Not public API: files are how the
	 * batch endpoint takes its requests, everything a model reads travels as Media instead.
	 * @param  ?string  $fileName  name to send instead of the one on disk
	 * @throws AIAccess\ServiceException
	 */
	private function uploadFile(
		string $filePath,
		string $purpose,
		?string $mimeType = null,
		?string $fileName = null,
	): string
	{
		$formData = (new FormData)
			->addField('purpose', $purpose)
			->addFile('file', $filePath, $fileName, $mimeType);
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
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(
		string $endpoint,
		array|string|FormData|null $payload = null,
		array $headers = [],
	): array
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

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from OpenAI API');
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
		$headers = array_filter([
			'Authorization' => 'Bearer ' . $this->apiKey,
			'OpenAI-Organization' => $this->organizationId,
		]);

		$response = $this->httpClient->fetch(
			$this->baseUrl . $endpoint,
			$payload + ['stream' => true],
			$headers,
			onChunk: $onChunk,
		);
		if ($response->getStatusCode() >= 400) {
			$data = $response->getData();
			throw new AIAccess\ApiException(
				$data['error']['message'] ?? 'OpenAI API error (HTTP ' . $response->getStatusCode() . ')',
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
			$headers = array_filter([
				'Authorization' => 'Bearer ' . $this->apiKey,
				'OpenAI-Organization' => $this->organizationId,
			]);

			$response = $this->httpClient->fetch($this->baseUrl . $endpoint, headers: $headers, onChunk: $onChunk);
			if ($response->getStatusCode() >= 400) {
				$data = $response->getData();
				throw new AIAccess\ApiException(
					$data['error']['message'] ?? 'OpenAI API error (HTTP ' . $response->getStatusCode() . ')',
					$response->getStatusCode(),
				);
			}
		});
	}
}
