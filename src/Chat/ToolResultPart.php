<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * The answer to a tool call. Carries the name as well, because Gemini pairs results by name.
 */
final class ToolResultPart implements Part
{
	/** @param string|mixed[] $content */
	public function __construct(
		public readonly string $callId,
		public readonly string $name,
		public readonly string|array $content,
		public readonly bool $isError = false,
	) {
	}
}
