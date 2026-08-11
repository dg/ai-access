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


	/**
	 * @param  ?string  $chatModel  used by createChat() when none is given there
	 */
	public function __construct(
		private readonly string $apiKey,
		string $baseUrl,
		private readonly Http\Client $httpClient = new Http\CurlClient,
		private readonly ?string $chatModel = null,
	) {
		$this->baseUrl = rtrim($baseUrl, '/') . '/';
	}


	public function createChat(?string $model = null): Chat
	{
		$model ??= $this->chatModel ?? throw new AIAccess\LogicException('No chat model given and the client has no default one.');
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
	 * @param  ?mixed[]  $payload
	 * @return mixed[]
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApi(string $endpoint, ?array $payload = null): array
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


	/**
	 * Streams a request, handing raw SSE bytes to $onChunk.
	 * @param  mixed[]  $payload
	 * @param  \Closure(string): (bool|null)  $onChunk
	 * @throws AIAccess\ServiceException
	 * @internal
	 */
	public function callApiStream(string $endpoint, array $payload, \Closure $onChunk): void
	{
		$headers = $this->extraHeaders;
		if ($this->apiKey !== '') {
			$headers[$this->authHeader] = $this->authPrefix . $this->apiKey;
		}

		$response = $this->httpClient->fetch(
			$this->baseUrl . $endpoint,
			// stream_options is an OpenAI invention and an unknown dialect may refuse the whole
			// request over it; ask for it with setOptions(custom: [...]) where it is supported
			$payload + ['stream' => true],
			$headers,
			onChunk: $onChunk,
		);
		if ($response->getStatusCode() >= 400) {
			$data = $response->getData();
			throw new AIAccess\ApiException(
				$data['error']['message'] ?? 'Endpoint API error (HTTP ' . $response->getStatusCode() . ')',
				$response->getStatusCode(),
			);
		}
	}
}
