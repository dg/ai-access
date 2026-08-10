<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Batch;

use AIAccess\Chat\Chat;
use AIAccess\ServiceException;


/**
 * Represents a batch job containing multiple requests. Chats are what every provider takes;
 * those that also draw offer addImageRequest() on their own class.
 */
interface Batch
{
	/**
	 * Creates a new chat request to be included in the batch.
	 */
	function addChat(string $customId, string $model): Chat;

	/**
	 * Submits all added requests as a new batch job.
	 * @throws ServiceException
	 */
	function submit(): Response;
}
