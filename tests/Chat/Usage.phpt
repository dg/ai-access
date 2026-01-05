<?php declare(strict_types=1);

use AIAccess\Chat\Usage;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('Usage with all values', function () {
	$inputTokens = 100;
	$outputTokens = 50;
	$reasoningTokens = 200;
	$raw = [
		'prompt_tokens' => $inputTokens,
		'completion_tokens' => $outputTokens,
		'total_tokens' => $inputTokens + $outputTokens,
		'reasoning_tokens' => $reasoningTokens,
		'extra_field' => 'value',
	];

	$usage = new Usage($inputTokens, $outputTokens, $reasoningTokens, $raw);

	Assert::same($inputTokens, $usage->inputTokens);
	Assert::same($outputTokens, $usage->outputTokens);
	Assert::same($reasoningTokens, $usage->reasoningTokens);
	Assert::same($raw, $usage->raw);
});


test('Usage with null values', function () {
	$usage = new Usage(null, null, null, []);

	Assert::null($usage->inputTokens);
	Assert::null($usage->outputTokens);
	Assert::null($usage->reasoningTokens);
	Assert::same([], $usage->raw);
});


test('Usage with partial values', function () {
	$inputTokens = 100;
	$raw = ['prompt_tokens' => $inputTokens];

	$usage = new Usage($inputTokens, null, null, $raw);

	Assert::same($inputTokens, $usage->inputTokens);
	Assert::null($usage->outputTokens);
	Assert::null($usage->reasoningTokens);
	Assert::same($raw, $usage->raw);
});


test('Usage with default raw array', function () {
	$inputTokens = 50;
	$outputTokens = 75;

	$usage = new Usage($inputTokens, $outputTokens);

	Assert::same($inputTokens, $usage->inputTokens);
	Assert::same($outputTokens, $usage->outputTokens);
	Assert::null($usage->reasoningTokens);
	Assert::same([], $usage->raw);
});


test('Usage with zero values', function () {
	$usage = new Usage(0, 0, 0, ['prompt_tokens' => 0, 'completion_tokens' => 0]);

	Assert::same(0, $usage->inputTokens);
	Assert::same(0, $usage->outputTokens);
	Assert::same(0, $usage->reasoningTokens);
	Assert::count(2, $usage->raw);
});
