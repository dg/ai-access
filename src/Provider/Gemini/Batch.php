<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use function count;


/**
 * Service responsible for creating and managing Gemini batch jobs.
 */
final class Batch implements AIAccess\Batch\Batch
{
	/** @var array<string, Chat> */
	private array $chats = [];

	/** @var array<string, string> */
	private array $models = [];


	public function __construct(
		private readonly Client $client,
	) {
	}


	public function addChat(string $model, string $customId): Chat
	{
		if (isset($this->chats[$customId])) {
			throw new AIAccess\LogicException("Chat with custom ID '$customId' already exists in this batch.");
		}
		$this->models[$customId] = $model;
		return $this->chats[$customId] = new Chat($this->client, $model);
	}


	public function submit(): BatchResponse
	{
		if (!$this->chats) {
			throw new AIAccess\LogicException('Cannot submit batch job: No chat requests added.');
		} elseif (count(array_unique($this->models)) > 1) {
			// the model is part of the endpoint here, not of the individual request
			throw new AIAccess\LogicException('A Gemini batch runs on a single model, got: ' . implode(', ', array_unique($this->models)) . '.');
		}

		$model = reset($this->models);
		$requests = [];
		foreach ($this->chats as $customId => $chat) {
			$requests[] = [
				// a nested request repeats the model even though the endpoint carries it,
				// the same way countTokens has to
				'request' => ['model' => "models/$model"] + $chat->buildPayload(),
				'metadata' => ['key' => $customId],
			];
		}

		$response = $this->client->callApi("models/$model:batchGenerateContent", [
			// the double nesting under inputConfig.requests.requests is not a typo
			'batch' => ['inputConfig' => ['requests' => ['requests' => $requests]]],
		]);
		return new BatchResponse($this->client, $response);
	}
}
