<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library (https://ai-access.deegee.dev)
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * The answer to a tool call. Carries the name as well, because Gemini pairs results by name.
 */
final readonly class ToolResultPart implements Part
{
	/** @param string|mixed[] $content */
	public function __construct(
		public string $callId,
		public string $name,
		public string|array $content,
		public bool $isError = false,
	) {
	}
}
