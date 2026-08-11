<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Image;

use AIAccess\Media;
use AIAccess\ServiceException;


/**
 * Provides access to image generation.
 */
interface Service
{
	/**
	 * Generates a single image. Working from reference images is not something every
	 * provider does, so it lives on the concrete clients that do.
	 * @throws ServiceException
	 */
	function generateImage(string $prompt, string $model): Media;
}
