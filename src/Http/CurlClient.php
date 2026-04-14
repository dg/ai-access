<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\CommunicationException;
use AIAccess\Helpers;
use function defined, is_array, is_string;


/**
 * cURL-based implementation of the HTTP Client interface.
 */
final class CurlClient implements Client
{
	private string $userAgent = 'ai-access-php';

	/** Default timeouts in seconds */
	private int $connectTimeout = 10;
	private int $requestTimeout = 180;
	private ?string $proxy = null;


	public function setOptions(
		?int $connectTimeout = null,
		?int $requestTimeout = null,
		?string $proxy = null,
	): static
	{
		if ($connectTimeout !== null) {
			$this->connectTimeout = $connectTimeout;
		}
		if ($requestTimeout !== null) {
			$this->requestTimeout = $requestTimeout;
		}
		if ($proxy !== null) {
			$this->proxy = $proxy;
		}
		return $this;
	}


	public function fetch(
		string $url,
		string|array|FormData|null $payload = null,
		array $headers = [],
		?string $method = null,
	): Response
	{
		$ch = $this->create($payload, $headers, $url, $method);
		return $this->execute($ch);
	}


	/**
	 * @param string|mixed[]|FormData|null $payload
	 * @param string[] $headers
	 * @param non-empty-string|null $method
	 */
	private function create(
		array|string|FormData|null $payload,
		array $headers,
		string $url,
		?string $method,
	): \CurlHandle
	{
		$ch = curl_init();
		assert($url !== '');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method ?? ($payload === null ? 'GET' : 'POST'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, max($this->connectTimeout, $this->requestTimeout));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_ENCODING, '');
		if (defined('CURLOPT_PROTOCOLS_STR')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
		}

		if ($payload instanceof FormData) {
			$tmp = [];
			foreach ($payload->getItems() as $field => $info) {
				if (isset($info['value'])) {
					$tmp[$field] = $info['value'];
				} elseif (isset($info['content'])) {
					$tmp[$field] = new \CURLStringFile($info['content'], $info['name'], $info['mime'] ?? 'application/octet-stream');
				} else {
					$tmp[$field] = new \CURLFile($info['path'], $info['mime'] ?? 'application/octet-stream', $info['name']);
				}
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $tmp);

		} elseif (is_array($payload)) {
			$headers['content-type'] = 'application/json';
			$headers['accept'] = 'application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, Helpers::encodeJson($payload));

		} elseif (is_string($payload)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		$headers += ['User-Agent' => $this->userAgent];
		$tmp = array_map(fn($k, $v) => "$k: $v", array_keys($headers), array_values($headers));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $tmp);

		$proxy = $this->proxy;
		if ($proxy !== null && $proxy !== '') {
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
		}

		return $ch;
	}


	private function execute(\CurlHandle $ch): Response
	{
		$response = curl_exec($ch);
		if ($response === false) {
			$errorNo = curl_errno($ch);
			throw new CommunicationException('cURL request failed: [' . $errorNo . '] ' . curl_error($ch), $errorNo);
		}

		assert(is_string($response));
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$headers = substr($response, 0, $headerSize);
		$data = substr($response, $headerSize);
		$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
		if ($data !== '' && preg_match('~^application/json|\+json~', $contentType)) {
			$data = Helpers::decodeJson($data);
		}

		return new Response($httpCode, $this->parseHeaders($headers), $data);
	}


	/**
	 * Parses raw HTTP headers into an associative array.
	 * Normalizes header names to lowercase.
	 * @return array<string, string[]>
	 */
	private function parseHeaders(string $rawHeaders): array
	{
		$headers = [];
		$lines = explode("\r\n", trim($rawHeaders));
		array_shift($lines); // Skip the first line (HTTP status line)

		foreach ($lines as $line) {
			if (!str_contains($line, ':')) {
				continue;
			}

			[$name, $value] = explode(':', $line, 2);
			$name = strtolower(trim($name));
			$headers[$name][] = trim($value);
		}

		return $headers;
	}
}
