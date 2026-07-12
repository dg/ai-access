<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess\Chat\FinishReason;


/**
 * Response from an OpenAI-compatible endpoint.
 */
final class ChatResponse extends BaseChatResponse
{
	public const Provider = 'openai-compatible';


	protected function resolveFinishReason(): FinishReason
	{
		return match ($this->getRawFinishReason()) {
			'stop', 'end_turn' => FinishReason::Complete,
			'length' => FinishReason::TokenLimit,
			'tool_calls' => FinishReason::ToolCall,
			'content_filter' => FinishReason::ContentFiltered,
			default => FinishReason::Unknown,
		};
	}
}
