<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Provider\OpenAI;

use AIAccess;
use AIAccess\Batch\Result;
use AIAccess\Batch\Status;
use function implode, is_array, is_string, str_starts_with;


/**
 * Represents the state and eventual result of an OpenAI Batch API job.
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
		return match ($this->batchData['status'] ?? null) {
			// cancelling is still running, and its results are not there yet
			'validating', 'in_progress', 'finalizing', 'cancelling' => Status::InProgress,
			'completed' => Status::Completed,
			'failed', 'expired', 'cancelled' => Status::Failed,
			default => Status::Other,
		};
	}


	/**
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

		// which parser a line needs follows from the endpoint the whole batch declared
		$images = str_starts_with((string) ($this->batchData['endpoint'] ?? ''), '/v1/images/');

		// failed requests live in a separate error file; when everything failed, the output
		// file does not even exist, so both files are results
		foreach (['output_file_id', 'error_file_id'] as $field) {
			if (!is_string($this->batchData[$field] ?? null)) {
				continue;
			}
			foreach ($this->client->streamLines('files/' . $this->batchData[$field] . '/content') as $line) {
				if ($result = self::parseLine($line, $images)) {
					yield $result->customId => $result;
				}
			}
		}
	}


	public function getError(): ?string
	{
		$errors = [];
		foreach ($this->batchData['errors']['data'] ?? [] as $error) {
			if (is_string($error['message'] ?? null)) {
				$errors[] = $error['message'];
			}
		}
		return $errors ? 'Batch errors: ' . implode(', ', $errors) : null;
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


	private static function parseLine(string $line, bool $images): ?Result
	{
		$data = AIAccess\Helpers::decodeJson($line);
		$customId = $data['custom_id'] ?? null;
		if (!is_string($customId)) {
			return null;
		}

		$body = $data['response']['body'] ?? null;
		// a 200 is not proof of success: generation fails inside the body here just as it does live
		$succeeded = ($data['response']['status_code'] ?? null) === 200
			&& is_array($body)
			&& ($body['status'] ?? null) !== 'failed';

		if ($succeeded) {
			// the very same parser as a live call, so a batch turn carries whatever a live one would
			$message = $images
				? (new ImageResponse($body))->getMessage()
				: (new ChatResponse($body))->getMessage();

			return $images && !$message->getMedia()
				// a 200 that carries no picture is a failure, not an empty answer
				? Result::failed($customId, 'No image data in the response.')
				: Result::answered($customId, $message);
		}

		return Result::failed($customId, $data['error']['message']
			?? $body['error']['message']
			?? 'Request failed with status ' . ($data['response']['status_code'] ?? '?'));
	}
}
