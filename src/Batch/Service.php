<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Batch;
use AIAccess\ServiceException;


/**
 * Provides access to the conversational capabilities.
 */
interface Service
{
	/**
	 * Creates a new batch job.
	 */
	function createBatch(): Batch;

	/**
	 * Lists existing batch jobs.
	 * @return Response[]
	 * @throws ServiceException
	 */
	function listBatches(/* Implementation defines named arguments */): array;

	/**
	 * Retrieves the current status and details of a specific batch job by its ID.
	 * @throws ServiceException
	 */
	function retrieveBatch(string $id): Response;

	/**
	 * Attempts to cancel a batch job that is currently in progress.
	 * @return bool True if cancellation was initiated successfully, false otherwise
	 * @throws ServiceException
	 */
	function cancelBatch(string $id): bool;
}
