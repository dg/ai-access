<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess;
use AIAccess\Http;
use function is_array, is_string, rtrim;


/**
 * Client for any service that speaks the OpenAI chat completions dialect,
 * such as Ollama, Mistral, OpenRouter, Together or vLLM.
 */
final class Client implements AIAccess\Chat\Service
{
	private string $baseUrl;
	private string $authHeader = 'Authorization';
	private string $authPrefix = 'Bearer ';

	/** @var string[] */
	private array $extraHeaders = [];


	public function __construct(
		private readonly string $apiKey,
		string $baseUrl,
		private readonly Http\Client $httpClient = new Http\CurlClient,
	) {
		$this->baseUrl = rtrim($baseUrl, '/') . '/';
	}


	public function createChat(string $model): Chat
	{
		return new Chat($this, $model);
	}


	/**
	 * @param  ?string  $authHeader  header carrying the key, e.g. 'api-key' for Azure
	 * @param  ?string  $authPrefix  value prefix, empty string for services that want the bare key
	 * @param  ?string[]  $extraHeaders  sent with every request, e.g. HTTP-Referer for OpenRouter
	 */
	public function setOptions(
		?string $authHeader = null,
		?string $authPrefix = null,
		?array $extraHeaders = null,
	): static
	{
		if ($authHeader !== null) {
			$this->authHeader = $authHeader;
		}
		if ($authPrefix !== null) {
			$this->authPrefix = $authPrefix;
		}
		if ($extraHeaders !== null) {
			$this->extraHeaders = $extraHeaders;
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
		$headers = $this->extraHeaders;
		if ($this->apiKey !== '') {
			$headers[$this->authHeader] = $this->authPrefix . $this->apiKey;
		}

		$response = $this->httpClient->fetch($this->baseUrl . $endpoint, $payload, $headers);
		$data = $response->getData();

		if ($response->getStatusCode() >= 400) {
			$message = $data['error']['message'] ?? $data['error'] ?? "API error (HTTP {$response->getStatusCode()})";
			throw new AIAccess\ApiException(is_string($message) ? $message : 'API error', $response->getStatusCode());
		}

		return is_array($data)
			? $data
			: throw new AIAccess\CommunicationException('Invalid JSON response from API');
	}
}
