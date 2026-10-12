<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library (https://ai-access.deegee.dev)
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * The model asking for a function to be called.
 */
final readonly class ToolCallPart implements Part
{
	/**
	 * @param  string  $callId  the value the provider wants back with the result
	 * @param  mixed[]  $arguments  empty when the model sent arguments that could not be decoded
	 * @param  ?string  $argumentsError  why the arguments could not be read
	 */
	public function __construct(
		public string $callId,
		public string $name,
		public array $arguments = [],
		public ?string $argumentsError = null,
		public ?string $provider = null,
		public mixed $raw = null,
	) {
	}
}
