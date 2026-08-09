<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Grok;

use AIAccess;
use function array_filter, array_merge, base64_decode;


/**
 * One image request for the xAI images endpoint.
 */
final class ImageRequest
{
	/** @var mixed[] */
	private array $options = [];


	public function __construct(
		private readonly Client $client,
		private readonly string $model,
		private readonly string $prompt,
	) {
	}


	/**
	 * @param  ?string  $aspectRatio  e.g. '1:1', '16:9' or 'auto'
	 * @param  ?string  $resolution  '1k' or '2k'
	 * @param  ?int  $n  How many images to generate. Only the first one is returned by generate().
	 */
	public function setOptions(
		?string $aspectRatio = null,
		?string $resolution = null,
		?int $n = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'aspect_ratio' => $aspectRatio,
				'resolution' => $resolution,
				'n' => $n,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	public function generate(): AIAccess\Media
	{
		$response = $this->client->callApi('images/generations', $this->buildPayload());

		$encoded = AIAccess\Helpers::expectString($response['data'][0]['b64_json'] ?? null, 'image data');
		$data = base64_decode($encoded, strict: true);
		if ($data === false) {
			throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
		}

		// the response carries no mime type; xAI generates JPEG, but the bytes know best
		$mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($data);
		return new AIAccess\Media($data, $mime === false ? 'image/jpeg' : $mime, $response);
	}


	/** @return mixed[] */
	private function buildPayload(): array
	{
		return array_merge([
			'model' => $this->model,
			'prompt' => $this->prompt,
			'n' => 1,
			'response_format' => 'b64_json',
		], $this->options);
	}
}
