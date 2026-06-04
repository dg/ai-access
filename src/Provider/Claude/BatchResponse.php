<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\Claude;

use AIAccess;
use AIAccess\Batch\Status;
use AIAccess\Chat\Message;
use function explode, implode, is_array, trim;


/**
 * Represents the state and eventual result of a Claude Batch API job.
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
	 * @throws AIAccess\ServiceException
	 */
	public function getMessages(): ?array
	{
		$this->loadResults();
		return $this->messages;
	}


	/**
	 * @throws AIAccess\ServiceException
	 */
	public function getErrors(): array
	{
		$this->loadResults();
		return $this->errors;
	}


	private function loadResults(): void
	{
		if ($this->messages !== null
			|| $this->getStatus() !== Status::Completed
			|| !isset($this->batchData['results_url'])
		) {
			return;
		}

		$this->messages = [];
		$jsonl = $this->client->callApi($this->batchData['results_url'], isJson: false);

		foreach (explode("\n", trim($jsonl)) as $line) {
			if (trim($line) === '') {
				continue;
			}

			$lineData = AIAccess\Helpers::decodeJson($line);
			$customId = $lineData['custom_id'] ?? null;
			if ($customId === null) {
				continue;
			}

			$result = $lineData['result'] ?? [];
			if (($result['type'] ?? null) === 'succeeded' && is_array($result['message'] ?? null)) {
				// the very same parser as live chat, so a batch turn carries whatever a live one would
				$this->messages[$customId] = (new ChatResponse($result['message']))->getMessage();

			} else {
				// the wire nests it: result.error = {type: "error", error: {type, message}}
				$error = $result['error'] ?? [];
				$error = is_array($error['error'] ?? null) ? $error['error'] : $error;
				$this->errors[$customId] = ($error['message'] ?? 'Request ' . ($result['type'] ?? 'failed'))
					. (isset($error['type']) ? " (type: {$error['type']})" : '');
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
}
