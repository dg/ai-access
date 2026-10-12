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
	public function __construct(
		public string $name,
		public string $description = '',
		/** @var mixed[] JSON Schema of the arguments */
		public array $parameters = [],
		/**
		 * @var ?\Closure(mixed[], ToolCallPart): mixed omit to drive the loop yourself;
		 * a string or an array reaches the model as it is, a scalar becomes text
		 */
		public ?\Closure $handler = null,
		/**
		 * ask the provider to enforce the schema; Claude and Gemini have
		 * no such switch and ignore it
		 */
		public bool $strict = false,
	) {
	}
}
