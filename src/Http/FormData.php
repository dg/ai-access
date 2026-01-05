<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Http;

use AIAccess\LogicException;


/**
 * Class for multipart/form-data HTTP requests.
 */
final class FormData
{
	/** @var array<string, array{value?: string, path?: string, content?: string, name?: string, mime?: ?string}> */
	private array $items = [];


	/**
	 * Adds a form field.
	 */
	public function addField(string $field, string $value): static
	{
		$this->items[$field] = [
			'value' => $value,
		];
		return $this;
	}


	/**
	 * Adds a file to be uploaded.
	 */
	public function addFile(
		string $field,
		string $filePath,
		?string $fileName = null,
		?string $mimeType = null,
	): static
	{
		if (!is_file($filePath) || !is_readable($filePath)) {
			throw new LogicException("File not found or not readable: $filePath");
		}

		$this->items[$field] = [
			'path' => $filePath,
			'name' => $fileName ?? basename($filePath),
			'mime' => $mimeType,
		];
		return $this;
	}


	/**
	 * Adds file contents to be uploaded.
	 */
	public function addFileContent(
		string $field,
		string $content,
		string $fileName,
		?string $mimeType = null,
	): static
	{
		$this->items[$field] = [
			'content' => $content,
			'name' => $fileName,
			'mime' => $mimeType,
		];
		return $this;
	}


	/**
	 * Gets all form items.
	 * @return array<string, array{value?: string, path?: string, content?: string, name?: string, mime?: ?string}>
	 */
	public function getItems(): array
	{
		return $this->items;
	}
}
