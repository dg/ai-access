<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use function array_map, array_unique, count, implode, reset;


/**
 * Service responsible for creating and managing OpenAI Batch API jobs.
 */
final class Batch implements AIAccess\Batch\Batch
{
	/** @var array<string, Chat|ImageRequest> */
	private array $requests = [];

	private ?string $model = null;

	/** @var mixed[]|null */
	private ?array $metadata = null;


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
	 * gets back. Pictures and chats cannot travel in one job, see submit().
	 */
	public function addImageRequest(string $model, string $customId, string $prompt): ImageRequest
	{
		$this->checkNew($model, $customId);
		return $this->requests[$customId] = new ImageRequest($this->client, $model, $prompt);
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
		if (!$this->requests) {
			throw new AIAccess\LogicException('Cannot submit batch job: No requests added.');
		}

		// one job declares one endpoint, which is what keeps chats apart from pictures and
		// generating apart from editing; measured, the API kills the whole job otherwise
		$urls = array_unique(array_map($this->urlOf(...), $this->requests));
		if (count($urls) > 1) {
			throw new AIAccess\LogicException('A batch runs on a single endpoint, got ' . implode(' and ', $urls) . '.');
		}

		return $this->client->submitBatch(reset($urls), $this->buildLines(), $this->metadata);
	}


	private function checkNew(string $model, string $customId): void
	{
		if (isset($this->requests[$customId])) {
			throw new AIAccess\LogicException("Request with custom ID '{$customId}' already exists in this batch.");

		} elseif ($this->model !== null && $this->model !== $model) {
			// one input file is one model: the API validates it and fails the whole job
			throw new AIAccess\LogicException("A batch runs on a single model, got '{$this->model}' and '$model'.");
		}
		$this->model = $model;
	}


	private function urlOf(Chat|ImageRequest $request): string
	{
		return $request instanceof Chat ? '/v1/responses' : $request->getBatchUrl();
	}


	/** @return \Generator<string> */
	private function buildLines(): \Generator
	{
		foreach ($this->requests as $customId => $request) {
			yield AIAccess\Helpers::encodeJson([
				'custom_id' => $customId,
				'method' => 'POST',
				'url' => $this->urlOf($request),
				'body' => $request->buildPayload(),
			]);
		}
	}
}
