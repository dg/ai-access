<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * A piece of an answer as it is being generated.
 *
 * The public API yields text only, but accumulators report every kind they see, so that
 * exposing reasoning or partial tool arguments later is a matter of letting them through
 * rather than rebuilding the providers.
 */
final class Delta
{
	public function __construct(
		public readonly DeltaType $type,
		public readonly string $text,
	) {
	}
}
