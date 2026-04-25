<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAICompatible;

use AIAccess;
use AIAccess\Chat\Role;


/**
 * Shared request side of the chat/completions dialect, which several providers speak
 * verbatim: message serialization lives here once. What genuinely differs - option
 * names, effort mapping, the response subclass - stays in the subclasses.
 *
 * @internal
 */
abstract class BaseChat extends AIAccess\Chat\Chat
{
	/** @var mixed[] */
	protected array $options = [];


	public function __construct(
		protected readonly string $model,
	) {
	}


	protected function generateResponse(): AIAccess\Chat\Response
	{
		return $this->createResponse($this->callApi($this->buildPayload()));
	}


	/**
	 * @param  mixed[]  $payload
	 * @return mixed[]
	 */
	abstract protected function callApi(array $payload): array;


	/** @param mixed[] $raw */
	abstract protected function createResponse(array $raw): AIAccess\Chat\Response;


	/**
	 * Finishes the payload with what the dialects spell differently: the response schema
	 * and the reasoning effort.
	 * @param  mixed[]  $payload
	 */
	abstract protected function amendPayload(array &$payload): void;


	protected function buildContent(AIAccess\Chat\Message $message): string
	{
		foreach ($message->getParts() as $part) {
			// reasoning is deliberately not sent back yet: outside a tool loop it is undocumented
			// whether the endpoint accepts reasoning_content on input, and a 400 would break plain chat
			if (!$part instanceof AIAccess\Chat\TextPart && !$part instanceof AIAccess\Chat\ReasoningPart) {
				throw new AIAccess\LogicException('This chat supports text content only, ' . get_debug_type($part) . ' given.');
			}
		}
		return $message->getText();
	}


	/** @return mixed[] */
	protected function buildPayload(): array
	{
		if (!$this->messages) {
			throw new AIAccess\LogicException('Cannot send request with empty message history.');
		}

		$messages = [];
		if ($this->systemInstruction !== null) {
			$messages[] = ['role' => 'system', 'content' => $this->systemInstruction];
		}

		foreach ($this->messages as $message) {
			// a turn left with nothing to send, such as one carrying reasoning alone,
			// is dropped rather than sent as an empty message
			if (($content = $this->buildContent($message)) === '') {
				continue;
			}
			$messages[] = [
				'role' => match ($message->getRole()) {
					Role::User => 'user',
					Role::Model => 'assistant',
				},
				'content' => $content,
			];
		}

		$payload = [
			'model' => $this->model,
			'messages' => $messages,
		] + $this->options;

		$this->amendPayload($payload);
		return $payload;
	}
}
