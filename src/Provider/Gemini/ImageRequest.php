<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use function array_filter, array_merge, is_array, is_string;


/**
 * One image request for Gemini. There is no dedicated image endpoint here: an image model
 * is asked through the ordinary chat one and references are part of the prompt.
 */
final class ImageRequest
{
	/** @var list<AIAccess\Media> */
	private array $references = [];

	/** @var mixed[] */
	private array $imageConfig = [];


	public function __construct(
		private readonly Client $client,
		private readonly string $model,
		private readonly string $prompt,
	) {
	}


	public function addReference(AIAccess\Media $media): static
	{
		$this->references[] = $media;
		return $this;
	}


	/**
	 * @param  ?string  $aspectRatio  e.g. '1:1', '16:9' or '9:16'
	 * @param  ?string  $imageSize  '1K', '2K' or '4K'; the flash-lite model draws 1K only
	 */
	public function setOptions(
		?string $aspectRatio = null,
		?string $imageSize = null,
	): static
	{
		$this->imageConfig = array_merge($this->imageConfig, array_filter(
			[
				'aspectRatio' => $aspectRatio,
				'imageSize' => $imageSize,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	public function generate(): AIAccess\Media
	{
		$response = new ChatResponse($this->client->callApi("models/{$this->model}:generateContent", $this->buildPayload()));
		$media = $response->getMessage()->getMedia();
		if ($media) {
			return $media[0];
		}

		// the parser skips undecodable bytes, so a broken picture and no picture look alike here
		$parts = $response->getRawResponse()['candidates'][0]['content']['parts'] ?? null;
		foreach (is_array($parts) ? $parts : [] as $part) {
			if (is_array($part) && isset($part['inlineData'])) {
				throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
			}
		}

		// a model that refuses answers with text instead of a picture
		$reason = $response->getRawFinishReason();
		throw new AIAccess\UnexpectedResponseException('No image in the response'
			. (is_string($reason) ? ", finish reason $reason" : '') . '.');
	}


	/**
	 * @return mixed[]
	 * @internal  public for ImageBatch, which reuses the very same request shape
	 */
	public function buildPayload(): array
	{
		$parts = [['text' => $this->prompt]];
		foreach ($this->references as $reference) {
			$parts[] = ['inlineData' => ['mimeType' => $reference->getMimeType(), 'data' => $reference->getBase64()]];
		}

		$config = ['responseModalities' => ['IMAGE']];
		if ($this->imageConfig) {
			$config['imageConfig'] = $this->imageConfig;
		}

		return [
			'contents' => [['role' => 'user', 'parts' => $parts]],
			'generationConfig' => $config,
		];
	}
}
