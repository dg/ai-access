<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Grok;

use AIAccess;
use AIAccess\Http;
use function is_array, rtrim;


/**
 * Client implementation for accessing Grok (xAI) API models.
 */
final class Client implements AIAccess\Chat\Service, AIAccess\Image\Service
{
	private string $baseUrl = 'https://api.x.ai/v1/';


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
	 * Generates an image.
	 * @param  list<AIAccess\Media>  $references  not supported by xAI, must be empty
	 */
	public function generateImage(string $model, string $prompt, array $references = []): AIAccess\Media
	{
		if ($references) {
			throw new AIAccess\LogicException('Grok image generation does not accept reference images.');
		}

		$response = $this->callApi('images/generations', [
			'model' => $model,
			'prompt' => $prompt,
			'n' => 1,
			'response_format' => 'b64_json',
		]);

		$encoded = AIAccess\Helpers::expectString($response['data'][0]['b64_json'] ?? null, 'image data');
		$data = base64_decode($encoded, strict: true);
		if ($data === false) {
			throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
		}

		// the response carries no mime type; xAI generates JPEG, but the bytes know best
		$mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($data);
		return new AIAccess\Media($data, $mime === false ? 'image/jpeg' : $mime, $response);
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
		$headers = [
			'Authorization' => 'Bearer ' . $this->apiKey,
		];

		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload, $headers);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$errorMessage = $data['error']['message'] ?? "Grok API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException($errorMessage, $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from Grok API');
	}
}
