<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use function array_filter, array_merge, in_array;


/**
 * One image request for the OpenAI images endpoints, sent right away or put into a batch.
 */
final class ImageRequest
{
	/** @var list<mixed[]> */
	private array $references = [];

	/** @var mixed[] */
	private array $options = [];


	public function __construct(
		private readonly Client $client,
		private readonly string $model,
		private readonly string $prompt,
	) {
	}


	/**
	 * Adds a reference image (PNG, JPEG or WebP), max 16. References move the request from
	 * generating to editing.
	 */
	public function addReference(AIAccess\Media $media): static
	{
		if (!in_array($mime = $media->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true)) {
			throw new AIAccess\LogicException("Unsupported reference image type: $mime");
		}
		// a data URL, because a batch line cannot carry a multipart upload
		$this->references[] = ['image_url' => 'data:' . $mime . ';base64,' . $media->getBase64()];
		return $this;
	}


	/**
	 * @param  ?string  $size  e.g. '1024x1024' or '1536x1024'
	 * @param  ?string  $quality  'low', 'medium' or 'high'
	 * @param  ?string  $background  'transparent', 'opaque' or 'auto'
	 * @param  ?string  $format  'png', 'jpeg' or 'webp'
	 * @param  ?int  $n  How many images to generate (1-10). Only the first one is returned by generate().
	 * @param  ?string  $inputFidelity  'low' or 'high', how closely the references are followed
	 * @param  ?string  $moderation  'auto' or 'low'
	 */
	public function setOptions(
		?string $size = null,
		?string $quality = null,
		?string $background = null,
		?string $format = null,
		?int $n = null,
		?string $inputFidelity = null,
		?string $moderation = null,
	): static
	{
		$this->options = array_merge($this->options, array_filter(
			[
				'size' => $size,
				'quality' => $quality,
				'background' => $background,
				'output_format' => $format,
				'n' => $n,
				'input_fidelity' => $inputFidelity,
				'moderation' => $moderation,
			],
			fn($value) => $value !== null,
		));
		return $this;
	}


	public function generate(): AIAccess\Media
	{
		$response = new ImageResponse($this->client->callApi($this->getEndpoint(), $this->buildPayload()));
		return $response->getMedia()[0]
			?? throw new AIAccess\UnexpectedResponseException('No image in the response.');
	}


	/**
	 * Editing is a different endpoint than generating, which is why a batch cannot mix the two.
	 * @internal
	 */
	public function getEndpoint(): string
	{
		return $this->references ? 'images/edits' : 'images/generations';
	}


	/**
	 * The same endpoint as the batch names it, both in a line and in the job.
	 * @internal
	 */
	public function getBatchUrl(): string
	{
		return '/v1/' . $this->getEndpoint();
	}


	/**
	 * @return mixed[]
	 * @internal  public for Batch, which reuses the very same request shape
	 */
	public function buildPayload(): array
	{
		$payload = ['model' => $this->model, 'prompt' => $this->prompt] + $this->options;
		if ($this->references) {
			// the JSON form of images/edits calls the field "images", unlike the multipart one
			$payload['images'] = $this->references;
		}
		return $payload;
	}
}
