<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;


/**
 * Service responsible for creating and managing OpenAI Batch API jobs.
 */
final class Batch implements AIAccess\Batch\Batch
{
	/** @var Chat[] */
	private array $chats = [];

	private string $endpoint = '/v1/responses';
	private string $completionWindow = '24h';

	/** @var mixed[]|null */
	private ?array $metadata = null;


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


	/**
	 * Sets metadata for the batch job.
	 * @param  mixed[]  $metadata
	 */
	public function setMetadata(array $metadata): static
	{
		$this->metadata = $metadata;
		return $this;
	}


	public function submit(): BatchResponse
	{
		if (!$this->chats) {
			throw new AIAccess\LogicException('Cannot submit batch job: No chat requests added.');
		}

		$jsonlContent = '';
		foreach ($this->chats as $customId => $chat) {
			$payload = $chat->buildPayload();
			$request = [
				'custom_id' => $customId,
				'method' => 'POST',
				'url' => $this->endpoint,
				'body' => $payload,
			];
			$jsonlContent .= AIAccess\Helpers::encodeJson($request) . "\n";
		}

		$fileId = $this->client->uploadContent(
			$jsonlContent,
			'batch_requests.jsonl',
			'batch',
			'text/jsonl',
		);

		$payload = [
			'input_file_id' => $fileId,
			'endpoint' => $this->endpoint,
			'completion_window' => $this->completionWindow,
		];

		if ($this->metadata !== null) {
			$payload['metadata'] = $this->metadata;
		}

		$response = $this->client->callApi('batches', $payload);
		return new BatchResponse($this->client, $response);
	}
}
