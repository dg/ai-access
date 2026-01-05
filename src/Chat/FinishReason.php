<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;


/**
 * Enum representing the standardized reason why the AI model stopped generating output.
 */
enum FinishReason
{
	/** Model completed its response normally */
	case Complete;

	/** Model hit token limit before finishing naturally */
	case TokenLimit;

	/** Model stopped due to content safety filters */
	case ContentFiltered;

	/** Model stopped to request tool/function execution */
	case ToolCall;

	/** Client requested cancellation or streaming was interrupted */
	case Cancelled;

	/** The provider's stop reason is unknown, null, or not standardized */
	case Unknown;
}
