<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;

use function is_array, is_int, is_string;
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
			throw new CommunicationException('Invalid JSON response from API: ' . $e->getMessage(), 0, $e);
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


	/**
	 * Decodes text the model produced under a response schema.
	 */
	public static function decodeResponseJson(string $text): mixed
	{
		if ($text === '') { // the model produced no text at all; that is not a malformed answer
			return null;
		}
		try {
			return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new UnexpectedResponseException('Response is not valid JSON: ' . $e->getMessage(), 0, $e);
		}
	}


	/**
	 * Reads tool call arguments, which most providers send as a JSON string. A model can emit
	 * malformed JSON, and that is its mistake to correct, not a transport failure - hence the
	 * message instead of an exception.
	 * @return array{mixed[], ?string}  arguments and the reason they could not be read
	 */
	public static function decodeArguments(mixed $value): array
	{
		if (is_array($value)) {
			return [$value, null];
		} elseif ($value === null || $value === '') {
			return [[], null];
		} elseif (!is_string($value)) {
			return [[], 'Arguments are ' . get_debug_type($value) . ', expected a JSON object.'];
		}

		try {
			$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return [[], 'Arguments are not valid JSON: ' . $e->getMessage()];
		}
		return is_array($decoded)
			? [$decoded, null]
			: [[], 'Arguments decoded to ' . get_debug_type($decoded) . ', expected a JSON object.'];
	}


	/**
	 * Reads an optional integer from a response; anything else counts as absent. Token counts
	 * feed ?int properties, and a foreign endpoint sending a float or a string must degrade
	 * to null, not to an uncatchable TypeError.
	 */
	public static function intOrNull(mixed $value): ?int
	{
		return is_int($value) ? $value : null;
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
