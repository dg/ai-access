<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;


/**
 * Binary media content (an image, a PDF, ...) together with its mime type.
 */
final class Media implements Chat\Part
{
	private ?string $base64 = null;


	/** @param  ?mixed[]  $rawResponse */
	public function __construct(
		private readonly string $data,
		private readonly string $mimeType,
		private readonly ?array $rawResponse = null,
		private readonly ?string $fileName = null,
	) {
	}


	public static function fromFile(string $path): static
	{
		$data = @file_get_contents($path);
		if ($data === false) {
			throw new IOException("Cannot read file '$path'.");
		}
		$mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($data);
		return new static($data, $mime === false ? 'application/octet-stream' : $mime, fileName: basename($path));
	}


	public static function fromBinary(string $data, string $mimeType, ?string $fileName = null): static
	{
		return new static($data, $mimeType, fileName: $fileName);
	}


	public function isImage(): bool
	{
		return str_starts_with($this->mimeType, 'image/');
	}


	/**
	 * Name to show the model; documents need one, so a generic one stands in when unknown.
	 */
	public function getFileName(): string
	{
		// a structured subtype like application/vnd.* hides no extension in its first word
		$subtype = explode('/', $this->mimeType)[1] ?? '';
		return $this->fileName ?? 'file.' . (preg_match('~^[a-z0-9]+(?=$|\+)~i', $subtype, $m) ? $m[0] : 'bin');
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


	/** @return ?mixed[] */
	public function getRawResponse(): ?array
	{
		return $this->rawResponse;
	}
}
