<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Batch;

use AIAccess\Chat\Chat;
use AIAccess\ServiceException;


/**
 * Represents a batch job containing multiple chat requests.
 */
interface Batch
{
	/**
	 * Creates a new chat request to be included in the batch.
	 */
	function addChat(string $model, string $customId): Chat;

	/**
	 * Submits all added chat requests as a new batch job.
	 * @throws ServiceException
	 */
	function submit(): Response;
}
