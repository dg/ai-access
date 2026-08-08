<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Batch;

use AIAccess\Chat\Message;


/**
 * One finished item of a batch job. Either the model answered, or that single request failed;
 * both travel together, because telling them apart afterwards would mean reading the results
 * a second time.
 */
final class Result
{
	private function __construct(
		public readonly string $customId,
		public readonly ?Message $message,
		public readonly ?string $error,
	) {
	}


	public static function answered(string $customId, Message $message): self
	{
		return new self($customId, $message, null);
	}


	/**
	 * A request that failed on its own, and the reason in the words of whoever refused it.
	 */
	public static function failed(string $customId, string $error): self
	{
		return new self($customId, null, $error);
	}
}
