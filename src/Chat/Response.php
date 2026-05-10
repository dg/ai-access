<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;
use AIAccess\UnexpectedResponseException;


/**
 * Represents the response received from the AI model.
 */
interface Response
{
	/**
	 * Gets the main textual content of the AI's response.
	 * Returns null if the model refused to generate content (e.g., due to safety filters).
	 */
	function getText(): ?string;

	/**
	 * The whole model turn as parts, including reasoning. This is what goes into the chat history.
	 */
	function getMessage(): Message;

	/**
	 * The model's chain of thought, if it produced a readable one. Never part of getText().
	 */
	function getReasoning(): ?string;

	/**
	 * Tool calls the model is asking for. Answer them with Chat::addToolResult().
	 * @return list<ToolCallPart>
	 */
	function getToolCalls(): array;

	/**
	 * Gets the reason the model stopped generating output (provider-specific).
	 */
	function getFinishReason(): FinishReason;

	/**
	 * Gets provider-specific token usage information, if available.
	 */
	function getUsage(): ?Usage;

	/**
	 * Decodes the response text as JSON. Use together with setResponseSchema().
	 * @throws UnexpectedResponseException  when the text is not valid JSON
	 */
	function getJson(): mixed;

	/**
	 * Gets the raw, unprocessed response data from the API provider.
	 * @return mixed[]
	 */
	function getRawResponse(): mixed;

	function getRawFinishReason(): mixed;
}
