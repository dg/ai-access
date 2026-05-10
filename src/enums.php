<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat {

	/**
	 * Message sender.
	 */
	enum Role: string
	{
		case User = 'user';
		case Model = 'model';

		/** Results of tool calls; providers map it to whatever their wire format calls for. */
		case Tool = 'tool';
	}

	/**
	 * How much effort the model should spend on reasoning before answering.
	 */
	enum Effort: string
	{
		case None = 'none';
		case Low = 'low';
		case Medium = 'medium';
		case High = 'high';
		case XHigh = 'xhigh';
		case Max = 'max';
	}

	/**
	 * Enum representing the standardized reason why the AI model stopped generating output.
	 */
	enum FinishReason: string
	{
		/** Model completed its response normally */
		case Complete = 'complete';

		/** Model hit token limit before finishing naturally */
		case TokenLimit = 'token_limit';

		/** Model stopped due to content safety filters */
		case ContentFiltered = 'content_filtered';

		/** Model stopped to request tool/function execution */
		case ToolCall = 'tool_call';

		/** Client requested cancellation or streaming was interrupted */
		case Cancelled = 'cancelled';

		/** The provider's stop reason is unknown, null, or not standardized */
		case Unknown = 'unknown';
	}
}

namespace AIAccess\Batch {

	/**
	 * Status of a batch job.
	 */
	enum Status: string
	{
		case InProgress = 'in_progress';
		case Completed = 'completed';
		case Failed = 'failed';
		case Other = 'other';
	}
}
