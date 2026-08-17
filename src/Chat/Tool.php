<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;

use AIAccess\Helpers;
use Nette\Schema\Elements\Structure;


/**
 * A function the model may call.
 */
final class Tool
{
	/**
	 * JSON Schema of the arguments, as sent to the provider
	 * @var mixed[]
	 */
	public readonly array $parameters;

	/** the Nette schema the arguments were given as, if they were; it validates and casts them before the handler */
	public readonly ?Structure $schema;


	/**
	 * @param  mixed[]|Structure  $parameters  JSON Schema of the arguments, or a Nette schema (Expect::structure(), Expect::from())
	 * @param  ?\Closure(mixed, ToolCallPart): mixed  $handler  omit to drive the loop yourself; gets the arguments
	 *                       as an array, or as whatever a Nette schema yields; a string or an array it returns
	 *                       reaches the model as it is, a scalar becomes text
	 * @param  bool  $strict  ask the provider to enforce the schema; Claude and Gemini have
	 *                       no such switch and ignore it
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description = '',
		array|Structure $parameters = [],
		public readonly ?\Closure $handler = null,
		public readonly bool $strict = false,
	) {
		$this->parameters = Helpers::exportSchema($parameters);
		$this->schema = $parameters instanceof Structure ? $parameters : null;
		if ($strict && $this->schema) {
			Helpers::assertStrictSchema($this->parameters);
		}
	}
}
