<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;


/**
 * Service responsible for creating and managing Gemini batch jobs.
 */
final class Batch implements AIAccess\Batch\Batch
{
	/** @var array<string, Chat|ImageRequest> */
	private array $requests = [];

	private ?string $model = null;


	public function __construct(
		private readonly Client $client,
	) {
	}


	public function addChat(string $model, string $customId): Chat
	{
		$this->checkNew($model, $customId);
		return $this->requests[$customId] = new Chat($this->client, $model);
	}


	/**
	 * Adds a request for an image to be generated - not an image, which is what the batch
	 * gets back. Gemini draws through the ordinary chat endpoint, so pictures and chats can
	 * share a job as long as they share a model.
	 */
	public function addImageRequest(string $model, string $customId, string $prompt): ImageRequest
	{
		$this->checkNew($model, $customId);
		return $this->requests[$customId] = new ImageRequest($this->client, $model, $prompt);
	}


	public function submit(): BatchResponse
	{
		if ($this->model === null) {
			throw new AIAccess\LogicException('Cannot submit batch job: No requests added.');
		}

		$payloads = [];
		foreach ($this->requests as $customId => $request) {
			$payloads[$customId] = $request->buildPayload();
		}

		return $this->client->submitBatch($this->model, $payloads);
	}


	private function checkNew(string $model, string $customId): void
	{
		if (isset($this->requests[$customId])) {
			throw new AIAccess\LogicException("Request with custom ID '$customId' already exists in this batch.");

		} elseif ($this->model !== null && $this->model !== $model) {
			// the model is part of the endpoint here, not of the individual request
			throw new AIAccess\LogicException("A batch runs on a single model, got '{$this->model}' and '$model'.");
		}
		$this->model = $model;
	}
}
