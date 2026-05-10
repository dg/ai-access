<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * A function the model may call.
 */
final class Tool
{
	/**
	 * @param  mixed[]  $parameters  JSON Schema of the arguments
	 * @param  ?\Closure(mixed[], ToolCallPart): (string|mixed[])  $handler  omit to drive the loop yourself
	 * @param  bool  $strict  ask the provider to enforce the schema; Claude and Gemini have
	 *                       no such switch and ignore it
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description = '',
		public readonly array $parameters = [],
		public readonly ?\Closure $handler = null,
		public readonly bool $strict = false,
	) {
	}
}
