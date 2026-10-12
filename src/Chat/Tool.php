<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library (https://ai-access.deegee.dev)
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * A function the model may call.
 */
final readonly class Tool
{
	/**
	 * @param  mixed[]  $parameters  JSON Schema of the arguments
	 * @param  ?\Closure(mixed[], ToolCallPart): mixed  $handler  omit to drive the loop yourself;
	 *                       a string or an array reaches the model as it is, a scalar becomes text
	 * @param  bool  $strict  ask the provider to enforce the schema; Claude and Gemini have
	 *                       no such switch and ignore it
	 */
	public function __construct(
		public string $name,
		public string $description = '',
		public array $parameters = [],
		public ?\Closure $handler = null,
		public bool $strict = false,
	) {
	}
}
