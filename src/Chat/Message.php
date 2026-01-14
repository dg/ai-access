<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * Represents a chat message.
 */
class Message
{
	public function __construct(
		private readonly string $text,
		private readonly Role $role,
	) {
	}


	public function getRole(): Role
	{
		return $this->role;
	}


	public function getText(): string
	{
		return $this->text;
	}
}
