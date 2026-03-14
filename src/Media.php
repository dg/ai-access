<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;


/**
 * Binary media content (an image, a PDF, ...) together with its mime type.
 */
final class Media
{
	private ?string $base64 = null;


	public function __construct(
		private readonly string $data,
		private readonly string $mimeType,
		private readonly mixed $rawResponse = null,
	) {
	}


	public static function fromFile(string $path): static
	{
		$data = @file_get_contents($path);
		if ($data === false) {
			throw new IOException("Cannot read file '$path'.");
		}
		$mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($data);
		return new static($data, $mime === false ? 'application/octet-stream' : $mime);
	}


	public static function fromBinary(string $data, string $mimeType): static
	{
		return new static($data, $mimeType);
	}


	public function getData(): string
	{
		return $this->data;
	}


	public function getMimeType(): string
	{
		return $this->mimeType;
	}


	public function getBase64(): string
	{
		return $this->base64 ??= base64_encode($this->data);
	}


	public function save(string $path): void
	{
		if (@file_put_contents($path, $this->data) === false) {
			throw new IOException("Cannot write file '$path'.");
		}
	}


	public function getRawResponse(): mixed
	{
		return $this->rawResponse;
	}
}
