<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;

use Nette\Schema\Elements\Structure;
use Nette\Schema\JsonSchema;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use function array_diff, array_keys, implode, is_array, is_int, is_string;
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
	 * Decodes text the model produced under a response schema; a Nette schema also validates
	 * and casts it, so the caller gets what the schema yields.
	 * @param  mixed[]|Structure|null  $schema
	 * @throws UnexpectedResponseException  when the text is not valid JSON or does not match the schema
	 */
	public static function decodeResponseJson(string $text, array|Structure|null $schema = null): mixed
	{
		if ($text === '') { // the model produced no text at all; that is not a malformed answer
			return null;
		}
		try {
			$data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new UnexpectedResponseException('Response is not valid JSON: ' . $e->getMessage(), 0, $e);
		}
		if (!$schema instanceof Structure) {
			return $data;
		}
		try {
			return (new Processor)->process($schema, $data);
		} catch (ValidationException $e) {
			throw new UnexpectedResponseException('Response does not match the schema: ' . implode(' ', $e->getMessages()), 0, $e);
		}
	}


	/**
	 * Turns a Nette schema into the JSON Schema sent to the provider; an array is already one.
	 * @param  mixed[]|Structure  $schema
	 * @return mixed[]
	 */
	public static function exportSchema(array|Structure $schema): array
	{
		return is_array($schema) ? $schema : (array) JsonSchema::export($schema);
	}


	/**
	 * A strict provider wants every property required and no additional ones; a Nette schema
	 * breaking that is a mistake to report now, not an ApiException a request later.
	 * @param  mixed[]  $schema  JSON Schema
	 */
	public static function assertStrictSchema(array $schema, string $path = ''): void
	{
		if (($schema['type'] ?? null) === 'object') {
			if (($schema['additionalProperties'] ?? true) !== false) {
				throw new LogicException("Object '" . ($path ?: '(root)') . "' allows additional properties (arrayOf(), otherItems()), which the provider's strict mode does not; use structure() or listOf().");
			}
			foreach (array_diff(array_keys($schema['properties'] ?? []), $schema['required'] ?? []) as $key) {
				throw new LogicException("Key '$path$key' is optional, but the provider's strict mode needs every key required; make it required() or nullable(), with Expect::from() through its second argument.");
			}
			foreach ($schema['properties'] ?? [] as $key => $property) {
				self::assertStrictSchema($property, "$path$key.");
			}
		}
		if (is_array($schema['items'] ?? null)) {
			self::assertStrictSchema($schema['items'], "{$path}items.");
		}
		foreach ($schema['anyOf'] ?? [] as $variant) {
			if (is_array($variant)) {
				self::assertStrictSchema($variant, $path);
			}
		}
	}


	/**
	 * Validates tool arguments against a Nette schema. A mismatch is the model's mistake to
	 * correct, hence the message instead of an exception.
	 * @param  mixed[]  $arguments
	 * @return array{mixed, ?string}  the arguments as the schema yields them, and the reason they were refused
	 */
	public static function processArguments(Structure $schema, array $arguments): array
	{
		try {
			return [(new Processor)->process($schema, $arguments), null];
		} catch (ValidationException $e) {
			return [null, implode(' ', $e->getMessages())];
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
