<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


final class Usage
{
	public function __construct(
		public readonly ?int $inputTokens = null,
		public readonly ?int $outputTokens = null,
		public readonly ?int $reasoningTokens = null,
		public readonly ?int $cacheReadTokens = null,
		public readonly ?int $cacheWriteTokens = null,
		/** @var mixed[] */
		public readonly array $raw = [],
	) {
	}


	public function getTotalTokens(): ?int
	{
		return $this->inputTokens === null && $this->outputTokens === null
			? null
			: ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
	}
}
