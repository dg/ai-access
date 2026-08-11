<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * Provides access to the conversational capabilities.
 */
interface Service
{
	/**
	 * Creates a new chat session. Without a model the client's own default is used,
	 * and a client configured with none raises a LogicException.
	 */
	function createChat(?string $model = null): Chat;
}
