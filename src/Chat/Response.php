<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * Represents the response received from the AI model.
 */
interface Response
{
	/**
	 * Gets the main textual content of the AI's response.
	 * Returns null if the model refused to generate content (e.g., due to safety filters).
	 */
	function getText(): ?string;

	/**
	 * Gets the reason the model stopped generating output (provider-specific).
	 */
	function getFinishReason(): FinishReason;

	/**
	 * Gets provider-specific token usage information, if available.
	 */
	function getUsage(): ?Usage;

	/**
	 * Gets the raw, unprocessed response data from the API provider.
	 * @return mixed[]
	 */
	function getRawResponse(): mixed;

	function getRawFinishReason(): mixed;
}
