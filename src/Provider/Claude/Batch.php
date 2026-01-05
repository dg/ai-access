<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;


/**
 * Service responsible for creating and managing Claude Batch API jobs.
 */
final class Batch implements AIAccess\Batch\Batch
{
	/** @var Chat[] */
	private array $chats = [];


	public function __construct(
		private Client $client,
	) {
	}


	public function addChat(string $model, string $customId): Chat
	{
		if (isset($this->chats[$customId])) {
			throw new AIAccess\LogicException("Chat with custom ID '{$customId}' already exists in this batch.");
		}
		return $this->chats[$customId] = new Chat($this->client, $model);
	}


	public function submit(): BatchResponse
	{
		if (!$this->chats) {
			throw new AIAccess\LogicException('Cannot submit batch job: No chat requests added.');
		}

		$requests = [];
		foreach ($this->chats as $customId => $chat) {
			$requests[] = [
				'custom_id' => $customId,
				'params' => $chat->buildPayload(),
			];
		}

		$payload = [
			'requests' => $requests,
		];
		$response = $this->client->callApi('v1/messages/batches', $payload);
		return new BatchResponse($this->client, $response);
	}
}
