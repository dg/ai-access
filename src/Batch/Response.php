<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Batch;

use AIAccess\ServiceException;


/**
 * Represents the state and eventual result of a batch processing job.
 */
interface Response
{
	function getId(): string;

	/**
	 * Gets the current status of the batch job.
	 */
	function getStatus(): Status;

	/**
	 * Reads the results of a completed job, one item at a time, so that a job of pictures
	 * does not have to fit in memory. An unfinished job yields nothing.
	 *
	 * The results are fetched while you iterate; stopping early stops the transfer, and
	 * iterating a second time fetches them again, because nothing is kept - unless the
	 * provider ships them inside the job itself, where there is nothing left to fetch.
	 * @return iterable<string, Result>  keyed by custom_id
	 * @throws ServiceException
	 */
	function getResults(): iterable;

	/**
	 * Gets the error information if the batch job as a whole had any issues, otherwise null.
	 * Failures of individual requests belong to their Result.
	 */
	function getError(): ?string;

	/**
	 * Gets the timestamp when the batch job was created.
	 */
	function getCreatedAt(): ?\DateTimeImmutable;

	/**
	 * Gets the timestamp when the batch job finished processing (completed, failed, or cancelled).
	 */
	function getCompletedAt(): ?\DateTimeImmutable;

	/**
	 * Gets the raw, unprocessed response data for the batch job from the API provider.
	 * @return mixed[]
	 */
	function getRawResponse(): array;
}
