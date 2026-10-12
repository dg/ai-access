<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library (https://ai-access.deegee.dev)
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * Plain text content.
 *
 * $raw holds the wire fragment this part came from and is sent back only to $provider;
 * Gemini attaches thought signatures to ordinary text parts, and those must survive a round trip.
 */
final readonly class TextPart implements Part
{
	public function __construct(
		public string $text,
		public ?string $provider = null,
		public mixed $raw = null,
	) {
	}
}
