<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Gemini;

use AIAccess;
use AIAccess\Batch\Result;
use AIAccess\Batch\Status;
use function is_array, is_string;


/**
 * Represents the state and eventual result of a Gemini batch job.
 *
 * A batch is a long-running operation here, so the state and the output live inside it
 * rather than at the top level.
 */
final class BatchResponse implements AIAccess\Batch\Response
{
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


	/**
	 * The results ride inside the job itself, so unlike the other providers they are in
	 * memory whether you read them one by one or not; only a file-based job could stream.
	 * @return \Generator<string, Result>
	 * @throws AIAccess\ServiceException
	 */
	public function getResults(): \Generator
	{
		// a cancelled or expired job keeps the requests it managed to finish, and they are
		// paid for, so only a job still running has nothing to hand over
		if ($this->getStatus() === Status::InProgress) {
			return;
		}

		// a batch fetched on its own carries the results, one listed among others does not,
		// so the listed one has to be fetched again before it can be read
		if (!isset($this->batchData['response'])) {
			$this->batchData = $this->client->callApi($this->getId());
		}

		if (!isset($this->batchData['response'])) {
			// a job that failed outright never produced an output to read
			return;

		} elseif (!isset($this->batchData['response']['inlinedResponses'])) {
			// a job that wrote its output to a file, which this client does not read yet;
			// yielding nothing would be indistinguishable from a job that answered nothing
			throw new AIAccess\UnexpectedResponseException('The batch keeps its results in a file, which is not supported yet.');
		}

		foreach ($this->batchData['response']['inlinedResponses']['inlinedResponses'] ?? [] as $index => $item) {
			$key = $item['metadata']['key'] ?? null;
			$customId = is_string($key) ? $key : (string) $index;
			yield $customId => match (true) {
				isset($item['error']) => Result::failed($customId, $item['error']['message'] ?? 'Request failed'),
				is_array($item['response'] ?? null) => Result::answered($customId, (new ChatResponse($item['response']))->getMessage()),
				// dropping it would lose the request without a trace
				default => Result::failed($customId, 'The response carries neither a result nor an error.'),
			};
		}
	}


	public function getError(): ?string
	{
		$message = $this->batchData['error']['message'] ?? null;
		return is_string($message) ? $message : null;
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
