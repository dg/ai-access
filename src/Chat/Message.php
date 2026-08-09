<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;

use AIAccess\LogicException;
use AIAccess\Media;
use function array_filter, array_values, implode, is_string;


/**
 * Represents a chat message. Content is a list of parts; a plain string becomes a single TextPart.
 */
class Message
{
	/** @var list<Part> */
	private readonly array $parts;


	/** @param string|Part|mixed[] $content  elements are strings or Parts, validated at runtime */
	public function __construct(
		string|Part|array $content,
		private readonly Role $role,
	) {
		$parts = [];
		foreach (is_string($content) || $content instanceof Part ? [$content] : $content as $part) {
			if (is_string($part)) {
				$part = new TextPart($part);
			} elseif (!$part instanceof Part) {
				throw new LogicException('Message content must be a string or a Part, ' . get_debug_type($part) . ' given.');
			}
			$parts[] = $part;
		}
		$this->parts = $parts;
	}


	public function getRole(): Role
	{
		return $this->role;
	}


	/** @return list<Part> */
	public function getParts(): array
	{
		return $this->parts;
	}


	/**
	 * Text of the message; parts that are not text are skipped.
	 */
	public function getText(): string
	{
		$texts = [];
		foreach ($this->parts as $part) {
			if ($part instanceof TextPart) {
				$texts[] = $part->text;
			}
		}
		return implode("\n", $texts);
	}


	/**
	 * Media carried by the message, e.g. images a model drew. Other parts are skipped.
	 * @return list<Media>
	 */
	public function getMedia(): array
	{
		return array_values(array_filter($this->parts, fn($part) => $part instanceof Media));
	}


	/**
	 * True when the message carries nothing but text, i.e. every provider can send it as a plain string.
	 */
	public function isTextOnly(): bool
	{
		foreach ($this->parts as $part) {
			if (!$part instanceof TextPart) {
				return false;
			}
		}
		return true;
	}
}
