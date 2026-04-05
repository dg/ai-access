<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;


/**
 * A model offered by a provider. Metadata differ wildly, so only the id is unified.
 */
final class Model
{
	public function __construct(
		public readonly string $id,
		/** @var mixed[] */
		public readonly array $raw = [],
	) {
	}
}
