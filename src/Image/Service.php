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
	 * Generates a single image. With references the model edits or continues from them.
	 * @param  list<Media>  $references  reference images
	 * @throws ServiceException
	 */
	function generateImage(string $model, string $prompt, array $references = []): Media;
}
