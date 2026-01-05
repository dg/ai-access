<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Embedding;

use AIAccess\LogicException;
use function array_reduce, array_values, count, pack, sqrt, unpack;


/**
 * Represents the result of an embedding calculation for a single input text.
 */
final class Vector
{
	private float $norm;


	/** @param list<float> $vector */
	public function __construct(
		private readonly array $vector,
	) {
		(function (float ...$num) {})(...$vector);
	}


	/** @return list<float> */
	public function toArray(): array
	{
		return $this->vector;
	}


	/**
	 * Calculates the cosine similarity between this and another embedding.
	 */
	public function cosineSimilarity(self $embedding): float
	{
		$count = count($this->vector);
		if ($count !== count($embedding->vector)) {
			throw new LogicException('Cannot calculate similarity between vectors of different dimensions.');
		}

		$this->norm ??= sqrt(array_reduce($this->vector, fn($carry, $value) => $carry + $value * $value, 0.0));
		$embedding->norm ??= sqrt(array_reduce($embedding->vector, fn($carry, $value) => $carry + $value * $value, 0.0));
		if ($this->norm == 0.0 || $embedding->norm == 0.0) {
			return 0.0;
		}

		$dot = 0;
		for ($i = 0; $i < $count; $i++) {
			$dot += $this->vector[$i] * $embedding->vector[$i];
		}
		return $dot / ($this->norm * $embedding->norm);
	}


	/**
	 * Serializes embedding into binary form.
	 */
	public function serialize(): string
	{
		return pack('f*', ...$this->vector);
	}


	/**
	 * Deserializes binary data back to embedding.
	 */
	public static function deserialize(string $data): self
	{
		$unpacked = unpack('f*', $data);
		if ($unpacked === false) {
			throw new LogicException('Failed to unpack binary data into floats.');
		}
		return new self(array_values($unpacked));
	}
}
