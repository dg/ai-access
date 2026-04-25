<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * The model's chain of thought. Never part of Message::getText().
 *
 * Providers require their own reasoning payload back verbatim in multi-turn tool loops,
 * so $raw is opaque and is only ever sent to the provider named in $provider.
 */
final class ReasoningPart implements Part
{
	public function __construct(
		public readonly ?string $text,
		public readonly ?string $provider = null,
		public readonly mixed $raw = null,
	) {
	}
}
