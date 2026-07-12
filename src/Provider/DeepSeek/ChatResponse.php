<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\DeepSeek;

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\OpenAICompatible;


/**
 * Represents a response from the DeepSeek API.
 */
final class ChatResponse extends OpenAICompatible\BaseChatResponse
{
	public const Provider = 'deepseek';


	protected function resolveFinishReason(): FinishReason
	{
		return match ($this->getRawFinishReason()) {
			'stop' => FinishReason::Complete,
			'length' => FinishReason::TokenLimit,
			'content_filter' => FinishReason::ContentFiltered,
			'tool_calls' => FinishReason::ToolCall,
			default => FinishReason::Unknown,
		};
	}


	/**
	 * DeepSeek counts cache hits at the top level of usage, not under prompt_tokens_details.
	 * @param  mixed[]  $usage
	 */
	protected function readCachedTokens(array $usage): mixed
	{
		return $usage['prompt_cache_hit_tokens'] ?? null;
	}
}
