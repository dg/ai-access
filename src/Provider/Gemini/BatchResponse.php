<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use AIAccess\Batch\Status;
use AIAccess\Chat\Message;
use function is_array, is_string;


/**
 * Represents the state and eventual result of a Gemini batch job.
 *
 * A batch is a long-running operation here, so the state and the output live inside it
 * rather than at the top level.
 */
final class BatchResponse implements AIAccess\Batch\Response
{
	/** @var ?array<string, Message> */
	private ?array $messages = null;

	/** @var array<string, string> */
	private array $errors = [];


	public function __construct(
		private readonly Client $client,
		/** @var mixed[] */
		private array $batchData,
	) {
	}


	public function getId(): string
	{
		return AIAccess\Helpers::expectString($this->batchData['name'] ?? null, 'batch name');
	}


	public function getStatus(): Status
	{
		return match ($this->getState()) {
			'BATCH_STATE_PENDING', 'BATCH_STATE_RUNNING' => Status::InProgress,
			'BATCH_STATE_SUCCEEDED' => Status::Completed,
			'BATCH_STATE_FAILED', 'BATCH_STATE_CANCELLED', 'BATCH_STATE_EXPIRED' => Status::Failed,
			default => Status::Other,
		};
	}


	public function getMessages(): ?array
	{
		$this->loadResults();
		return $this->messages;
	}


	public function getErrors(): array
	{
		$this->loadResults();
		return $this->errors;
	}


	private function loadResults(): void
	{
		if ($this->messages !== null || $this->getStatus() !== Status::Completed) {
			return;
		}

		// a batch fetched on its own carries the results, one listed among others does not,
		// so the listed one has to be fetched again before it can be read
		if (!isset($this->batchData['response']['inlinedResponses'])) {
			$this->batchData = $this->client->callApi($this->getId());
		}

		$this->messages = [];
		$responses = $this->batchData['response']['inlinedResponses']['inlinedResponses'] ?? [];

		foreach ($responses as $index => $item) {
			$customId = $item['metadata']['key'] ?? (string) $index;
			if (isset($item['error'])) {
				$this->errors[$customId] = $item['error']['message'] ?? 'Request failed';
			} elseif (is_array($item['response'] ?? null)) {
				$this->messages[$customId] = (new ChatResponse($item['response']))->getMessage();
			}
		}
	}


	public function getError(): ?string
	{
		if (isset($this->batchData['error']['message'])) {
			return $this->batchData['error']['message'];
		}
		$failed = $this->batchData['metadata']['batchStats']['failedRequestCount'] ?? 0;
		return $failed > 0 ? "$failed requests failed" : null;
	}


	public function getCreatedAt(): ?\DateTimeImmutable
	{
		return $this->parseTime($this->batchData['metadata']['createTime'] ?? null);
	}


	public function getCompletedAt(): ?\DateTimeImmutable
	{
		return $this->parseTime($this->batchData['metadata']['endTime'] ?? null);
	}


	public function getRawResponse(): array
	{
		return $this->batchData;
	}


	private function getState(): ?string
	{
		return $this->batchData['metadata']['state'] ?? $this->batchData['state'] ?? null;
	}


	private function parseTime(mixed $value): ?\DateTimeImmutable
	{
		try {
			return is_string($value) ? new \DateTimeImmutable($value) : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
