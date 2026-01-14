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


	public function __construct(
		private readonly Client $client,
		/** @var mixed[] */
		private readonly array $batchData,
	) {
	}


	public function getStatus(): Status
	{
		return match ($this->batchData['processing_status'] ?? null) {
			'in_progress' => Status::InProgress,
			'ended' => Status::Completed,
			'canceling' => Status::Failed,
			default => Status::Other,
		};
	}


	/**
	 * @throws AIAccess\ServiceException
	 */
	public function getMessages(): ?array
	{
		if ($this->messages === null
			&& $this->getStatus() === Status::Completed
			&& isset($this->batchData['results_url'])
		) {
			$response = $this->client->callApi($this->batchData['results_url'], isJson: false);
			$this->messages = $this->parseMessages($response);
		}

		return $this->messages;
	}


	/** @return array<string, Message> */
	private function parseMessages(string $jsonl): array
	{
		$res = [];
		$lines = explode("\n", trim($jsonl));

		foreach ($lines as $line) {
			if (empty(trim($line))) {
				continue;
			}

			$lineData = AIAccess\Helpers::decodeJson($line);
			$customId = $lineData['custom_id'] ?? null;
			if ($customId === null) {
				continue;
			}

			$resultType = $lineData['result']['type'] ?? null;
			if ($resultType === 'succeeded' && is_array($lineData['result']['message']['content'] ?? null)) {
				$content = '';
				foreach ($lineData['result']['message']['content'] as $contentBlock) {
					if ($contentBlock['type'] === 'text') {
						$content .= $contentBlock['text'];
					}
				}
				$res[$customId] = new Message(trim($content), AIAccess\Chat\Role::Model);

			} elseif ($resultType === 'errored') {
				$errorMsg = "Error in request '{$customId}'";
				if (isset($lineData['result']['error'])) {
					$error = $lineData['result']['error'];
					$errorMsg .= ': ' . ($error['message'] ?? 'Unknown error');
					if (isset($error['type'])) {
						$errorMsg .= " (type: {$error['type']})";
					}
				}
				trigger_error($errorMsg, E_USER_WARNING);
			}
		}

		return $res;
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


	public function getRawResult(): mixed
	{
		return $this->batchData;
	}


	public function getId(): string
	{
		return $this->batchData['id'];
	}
}
