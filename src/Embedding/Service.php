<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Embedding;
use AIAccess\ServiceException;


/**
 * Provides access to the embedding calculations.
 */
interface Service
{
	/**
	 * Calculates embeddings for the given input text(s) using a specified model.
	 * @param  list<string>  $input
	 * @return list<Vector>
	 * @throws ServiceException
	 */
	function calculateEmbeddings(string $model, array $input): array;
}
