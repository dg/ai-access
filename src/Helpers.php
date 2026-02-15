<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;

use function is_int, is_string;
use const JSON_THROW_ON_ERROR;


/**
 * @internal
 */
final class Helpers
{
	public static function decodeJson(string $data): mixed
	{
		try {
			return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new CommunicationException('Invalid JSON response from API: ' . $e->getMessage());
		}
	}


	public static function encodeJson(mixed $data): string
	{
		try {
			return json_encode($data, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new LogicException('Failed to encode request body as JSON: ' . $e->getMessage(), 0, $e);
		}
	}


	public static function expectString(mixed $value, string $what): string
	{
		return is_string($value)
			? $value
			: throw new UnexpectedResponseException("Missing or invalid $what in API response.");
	}


	public static function expectInt(mixed $value, string $what): int
	{
		return is_int($value)
			? $value
			: throw new UnexpectedResponseException("Missing or invalid $what in API response.");
	}
}
