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


	/**
	 * Adds up two usages component by component. The raw data of a sum would be
	 * meaningless, so it is dropped.
	 */
	public function add(self $other): self
	{
		$sum = fn(?int $a, ?int $b) => $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
		return new self(
			inputTokens: $sum($this->inputTokens, $other->inputTokens),
			outputTokens: $sum($this->outputTokens, $other->outputTokens),
			reasoningTokens: $sum($this->reasoningTokens, $other->reasoningTokens),
			cacheReadTokens: $sum($this->cacheReadTokens, $other->cacheReadTokens),
			cacheWriteTokens: $sum($this->cacheWriteTokens, $other->cacheWriteTokens),
		);
	}
}
