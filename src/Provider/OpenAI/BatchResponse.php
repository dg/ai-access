<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use AIAccess\Batch\Status;
use AIAccess\Chat\Message;
use function explode, implode, is_array, trim;


/**
 * Represents the state and eventual result of an OpenAI Batch API job.
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
		return match ($this->batchData['status'] ?? null) {
			'validating', 'in_progress', 'finalizing' => Status::InProgress,
			'completed' => Status::Completed,
			'cancelling', 'failed', 'expired', 'cancelled' => Status::Failed,
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
		if ($this->messages !== null || $this->getStatus() !== Status::Completed) {
			return;
		}

		$this->messages = [];
		// failed requests live in a separate error file; when everything failed, the output
		// file does not even exist, so both files are results
		foreach (['output_file_id', 'error_file_id'] as $field) {
			if (isset($this->batchData[$field])) {
				$this->parseFile($this->client->callApi('files/' . $this->batchData[$field] . '/content', isJson: false));
			}
		}
	}


	private function parseFile(string $jsonl): void
	{
		foreach (explode("\n", trim($jsonl)) as $line) {
			if (trim($line) === '') {
				continue;
			}

			$lineData = AIAccess\Helpers::decodeJson($line);
			$customId = $lineData['custom_id'] ?? null;
			if ($customId === null) {
				continue;
			}

			if (($lineData['response']['status_code'] ?? null) === 200) {
				$content = '';
				foreach ($lineData['response']['body']['output'] ?? [] as $item) {
					if (($item['type'] ?? null) === 'message' && is_array($item['content'] ?? null)) {
						foreach ($item['content'] as $contentBlock) {
							if (($contentBlock['type'] ?? null) === 'output_text') {
								$content .= $contentBlock['text'] ?? '';
							}
						}
					}
				}
				$this->messages[$customId] = new Message(trim($content), AIAccess\Chat\Role::Model);

			} else {
				$this->errors[$customId] = $lineData['error']['message']
					?? $lineData['response']['body']['error']['message']
					?? 'Request failed with status ' . ($lineData['response']['status_code'] ?? '?');
			}
		}
	}


	public function getError(): ?string
	{
		// Check for batch-level errors
		if (isset($this->batchData['errors']) && !empty($this->batchData['errors']['data'])) {
			$errorMessages = [];
			foreach ($this->batchData['errors']['data'] as $error) {
				if (isset($error['message'])) {
					$errorMessages[] = $error['message'];
				}
			}
			if (!empty($errorMessages)) {
				return 'Batch errors: ' . implode(', ', $errorMessages);
			}
		}

		// Check for batch-level errors based on request counts
		if (isset($this->batchData['request_counts'])) {
			$counts = $this->batchData['request_counts'];
			$errorInfo = [];

			if (isset($counts['failed']) && $counts['failed'] > 0) {
				$errorInfo[] = "{$counts['failed']} requests failed";
			}

			if (!empty($errorInfo)) {
				return 'Batch encountered issues: ' . implode(', ', $errorInfo);
			}
		}

		return null;
	}


	public function getCreatedAt(): ?\DateTimeImmutable
	{
		if (isset($this->batchData['created_at'])) {
			try {
				return new \DateTimeImmutable('@' . $this->batchData['created_at']);
			} catch (\Throwable) {
			}
		}
		return null;
	}


	public function getCompletedAt(): ?\DateTimeImmutable
	{
		foreach (['completed_at', 'failed_at', 'expired_at', 'cancelled_at'] as $field) {
			if (isset($this->batchData[$field])) {
				try {
					return new \DateTimeImmutable('@' . $this->batchData[$field]);
				} catch (\Throwable) {
				}
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
