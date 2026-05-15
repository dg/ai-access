<?php declare(strict_types=1);

use AIAccess\Chat\Usage;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('Usage with all values', function () {
	$raw = ['prompt_tokens' => 100, 'extra_field' => 'value'];

	$usage = new Usage(
		inputTokens: 100,
		outputTokens: 50,
		reasoningTokens: 200,
		cacheReadTokens: 30,
		cacheWriteTokens: 10,
		raw: $raw,
	);

	Assert::same(100, $usage->inputTokens);
	Assert::same(50, $usage->outputTokens);
	Assert::same(200, $usage->reasoningTokens);
	Assert::same(30, $usage->cacheReadTokens);
	Assert::same(10, $usage->cacheWriteTokens);
	Assert::same($raw, $usage->raw);
});


test('Usage with null values', function () {
	$usage = new Usage;

	Assert::null($usage->inputTokens);
	Assert::null($usage->outputTokens);
	Assert::null($usage->reasoningTokens);
	Assert::null($usage->cacheReadTokens);
	Assert::null($usage->cacheWriteTokens);
	Assert::same([], $usage->raw);
});


test('Usage with partial values', function () {
	$raw = ['prompt_tokens' => 100];

	$usage = new Usage(inputTokens: 100, raw: $raw);

	Assert::same(100, $usage->inputTokens);
	Assert::null($usage->outputTokens);
	Assert::same($raw, $usage->raw);
});


test('Usage with zero values', function () {
	$usage = new Usage(0, 0, 0);

	Assert::same(0, $usage->inputTokens);
	Assert::same(0, $usage->outputTokens);
	Assert::same(0, $usage->reasoningTokens);
});


test('getTotalTokens sums input and output', function () {
	Assert::same(150, (new Usage(100, 50))->getTotalTokens());
	Assert::same(100, (new Usage(inputTokens: 100))->getTotalTokens());
	Assert::null((new Usage)->getTotalTokens());
});


test('add() sums counts and keeps null only where neither side knows', function () {
	$a = new Usage(inputTokens: 10, outputTokens: 5, reasoningTokens: null, cacheReadTokens: 3, raw: ['x' => 1]);
	$b = new Usage(inputTokens: 20, outputTokens: null, reasoningTokens: 7, cacheReadTokens: null, raw: ['y' => 2]);

	$sum = $a->add($b);

	Assert::same(30, $sum->inputTokens);
	Assert::same(5, $sum->outputTokens);
	Assert::same(7, $sum->reasoningTokens);
	Assert::same(3, $sum->cacheReadTokens);
	Assert::null($sum->cacheWriteTokens);
	// a merged raw would lie about its origin, so the sum has none
	Assert::same([], $sum->raw);
});
