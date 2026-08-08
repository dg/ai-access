<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;
use AIAccess\Batch\Result;
use AIAccess\Batch\Status;
use function implode, is_array, is_string;


/**
 * Represents the state and eventual result of a Claude Batch API job.
 */
final class BatchResponse implements AIAccess\Batch\Response
{
	public function __construct(
		private readonly Client $client,
		/** @var mixed[] */
		private readonly array $batchData,
	) {
	}


	public function getStatus(): Status
	{
		return match ($this->batchData['processing_status'] ?? null) {
			'in_progress', 'canceling' => Status::InProgress,
			'ended' => Status::Completed,
			default => Status::Other,
		};
	}


	/**
	 * @return \Generator<string, Result>
	 * @throws AIAccess\ServiceException
	 */
	public function getResults(): \Generator
	{
		if ($this->getStatus() !== Status::Completed || !is_string($this->batchData['results_url'] ?? null)) {
			return;
		}

		foreach ($this->client->streamLines($this->batchData['results_url']) as $line) {
			if ($result = self::parseLine($line)) {
				yield $result->customId => $result;
			}
		}
	}


	public function getError(): ?string
	{
		$counts = $this->batchData['request_counts'] ?? null;
		$errorInfo = [];
		if (isset($counts['errored']) && $counts['errored'] > 0) {
			$errorInfo[] = "{$counts['errored']} requests encountered errors";
		}
		if (isset($counts['expired']) && $counts['expired'] > 0) {
			$errorInfo[] = "{$counts['expired']} requests expired";
		}
		if (isset($counts['canceled']) && $counts['canceled'] > 0) {
			$errorInfo[] = "{$counts['canceled']} requests were canceled";
		}
		return $errorInfo ? 'Batch encountered issues: ' . implode(', ', $errorInfo) : null;
	}


	public function getCreatedAt(): ?\DateTimeImmutable
	{
		if (isset($this->batchData['created_at'])) {
			try {
				return new \DateTimeImmutable($this->batchData['created_at']);
			} catch (\Throwable) {
			}
		}
		return null;
	}


	public function getCompletedAt(): ?\DateTimeImmutable
	{
		if (isset($this->batchData['ended_at'])) {
			try {
				return new \DateTimeImmutable($this->batchData['ended_at']);
			} catch (\Throwable) {
			}
		}
		return null;
	}


	public function getRawResponse(): array
	{
		return $this->batchData;
	}


	public function getId(): string
	{
		return AIAccess\Helpers::expectString($this->batchData['id'] ?? null, 'batch id');
	}


	private static function parseLine(string $line): ?Result
	{
		$data = AIAccess\Helpers::decodeJson($line);
		$customId = $data['custom_id'] ?? null;
		if (!is_string($customId)) {
			return null;
		}

		$result = $data['result'] ?? [];
		if (($result['type'] ?? null) === 'succeeded' && is_array($result['message'] ?? null)) {
			// the very same parser as live chat, so a batch turn carries whatever a live one would
			return Result::answered($customId, (new ChatResponse($result['message']))->getMessage());
		}

		// the wire nests it: result.error = {type: "error", error: {type, message}}
		$error = $result['error'] ?? [];
		$error = is_array($error['error'] ?? null) ? $error['error'] : $error;
		return Result::failed($customId, ($error['message'] ?? 'Request ' . ($result['type'] ?? 'failed'))
			. (isset($error['type']) ? " (type: {$error['type']})" : ''));
	}
}
