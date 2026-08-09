<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use function base64_decode, is_array, is_string;


/**
 * Represents a response from the OpenAI images endpoints, live or from a batch line.
 */
final class ImageResponse
{
	/** @var list<AIAccess\Media> */
	private array $media = [];


	public function __construct(
		/** @var mixed[] */
		private readonly array $rawResponse,
	) {
		// answered rather than guessed: the model may not have produced what was asked for
		$mime = is_string($this->rawResponse['output_format'] ?? null)
			? 'image/' . $this->rawResponse['output_format']
			: 'image/png';

		foreach (is_array($this->rawResponse['data'] ?? null) ? $this->rawResponse['data'] : [] as $item) {
			if (!is_string($item['b64_json'] ?? null)) {
				continue;
			} elseif (($data = base64_decode($item['b64_json'], strict: true)) === false) {
				throw new AIAccess\UnexpectedResponseException('Image data is not valid base64.');
			}
			$this->media[] = new AIAccess\Media($data, $mime, $this->rawResponse);
		}
	}


	/** @return list<AIAccess\Media> */
	public function getMedia(): array
	{
		return $this->media;
	}


	/**
	 * The images as a model turn, so a batch of pictures reads exactly like a batch of chats.
	 */
	public function getMessage(): AIAccess\Chat\Message
	{
		return new AIAccess\Chat\Message($this->media, AIAccess\Chat\Role::Model);
	}


	/** @return mixed[] */
	public function getRawResponse(): array
	{
		return $this->rawResponse;
	}
}
