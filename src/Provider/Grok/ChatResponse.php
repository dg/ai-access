<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Grok;

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\OpenAICompatible;


/**
 * Represents a response from the Grok (xAI) API.
 */
final class ChatResponse extends OpenAICompatible\BaseChatResponse
{
	public const Provider = 'grok';


	protected function resolveFinishReason(): FinishReason
	{
		return match ($this->getRawFinishReason()) {
			'stop', 'end_turn', null => FinishReason::Complete,
			'length' => FinishReason::TokenLimit,
			'tool_calls' => FinishReason::ToolCall,
			'content_filter' => FinishReason::ContentFiltered,
			default => FinishReason::Unknown,
		};
	}
}
