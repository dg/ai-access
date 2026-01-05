<?php declare(strict_types=1);

use AIAccess\Embedding\Vector;
use AIAccess\LogicException;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('Vector construction with valid float array', function () {
	$values = [0.1, 0.2, 0.3, 0.4, 0.5];
	$vector = new Vector($values);

	Assert::same($values, $vector->toArray());
});


test('Vector construction fails with non-float values', function () {
	Assert::exception(
		fn() => new Vector(['not a float', 0.2, 0.3]),
		TypeError::class,
	);
});


test('toArray returns the original values', function () {
	$values = [0.1, -0.5, 0.75, 1.0, -0.33];
	$vector = new Vector($values);

	$returnedValues = $vector->toArray();
	Assert::same($values, $returnedValues);

	// Ensure returned value is a copy and can't modify the original
	$returnedValues[0] = 999.0;
	Assert::same($values, $vector->toArray());
});


test('Cosine similarity between identical vectors is 1.0', function () {
	$values = [0.5, 0.5, 0.5, 0.5];
	$vector1 = new Vector($values);
	$vector2 = new Vector($values);

	Assert::same(1.0, $vector1->cosineSimilarity($vector2));
});


test('Cosine similarity between perpendicular vectors is 0.0', function () {
	$vector1 = new Vector([1.0, 0.0, 0.0]);
	$vector2 = new Vector([0.0, 1.0, 0.0]);

	// Allow for floating point imprecision
	Assert::equal(0.0, $vector1->cosineSimilarity($vector2));
});


test('Cosine similarity between opposite vectors is -1.0', function () {
	$vector1 = new Vector([1.0, 2.0, 3.0]);
	$vector2 = new Vector([-1.0, -2.0, -3.0]);

	// Allow for floating point imprecision
	Assert::equal(-1.0, $vector1->cosineSimilarity($vector2));
});


test('Cosine similarity throws exception for different dimensions', function () {
	$vector1 = new Vector([1.0, 2.0, 3.0]);
	$vector2 = new Vector([1.0, 2.0]);

	Assert::exception(
		fn() => $vector1->cosineSimilarity($vector2),
		LogicException::class,
		'Cannot calculate similarity between vectors of different dimensions.',
	);
});


test('Cosine similarity with zero vector returns 0.0', function () {
	$vector1 = new Vector([1.0, 2.0, 3.0]);
	$vector2 = new Vector([0.0, 0.0, 0.0]);

	Assert::same(0.0, $vector1->cosineSimilarity($vector2));
	Assert::same(0.0, $vector2->cosineSimilarity($vector1));
});


test('Serialization and deserialization preserves values', function () {
	$originalValues = [0.1, -0.5, 0.75, 1.0, -0.33];
	$vector = new Vector($originalValues);

	$serialized = $vector->serialize();
	$deserialized = Vector::deserialize($serialized);

	// Floating point values might have tiny precision differences after serialization
	for ($i = 0; $i < count($originalValues); $i++) {
		Assert::equal($originalValues[$i], round($deserialized->toArray()[$i], 6));
	}
});


test('Vector constructor validates array with variadic helper', function () {
	// This should pass - all floats
	new Vector([0.1, 0.2, 0.3]);

	// This should throw a TypeError - mixed types
	Assert::exception(
		fn() => new Vector([0.1, 'string', 0.3]),
		TypeError::class,
	);
});
