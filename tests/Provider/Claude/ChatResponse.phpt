<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Usage;
use AIAccess\Provider\Claude\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('ChatResponse parses simple text response', function () {
	$responseText = 'This is a simple response';
	$rawResponse = [
		'content' => [
			['type' => 'text', 'text' => $responseText],
		],
		'stop_reason' => 'end_turn',
	];

	$response = new ChatResponse($rawResponse);

	Assert::same($responseText, $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::same('end_turn', $response->getRawFinishReason());
	Assert::same($rawResponse, $response->getRawResponse());
});


test('ChatResponse parses multipart text response', function () {
	$rawResponse = [
		'content' => [
			['type' => 'text', 'text' => 'Part 1'],
			['type' => 'text', 'text' => 'Part 2'],
		],
		'stop_reason' => 'end_turn',
	];

	$response = new ChatResponse($rawResponse);

	Assert::same("Part 1\nPart 2", $response->getText());
});


test('ChatResponse handles finish reasons correctly', function () {
	$testCases = [
		['stop_reason' => 'end_turn', 'expected' => FinishReason::Complete],
		['stop_reason' => 'stop_sequence', 'expected' => FinishReason::Complete],
		['stop_reason' => 'max_tokens', 'expected' => FinishReason::TokenLimit],
		['stop_reason' => 'content_filtered', 'expected' => FinishReason::ContentFiltered],
		['stop_reason' => 'tool_use', 'expected' => FinishReason::ToolCall],
		['stop_reason' => 'unknown_reason', 'expected' => FinishReason::Unknown],
		// Test missing stop_reason
		['no_stop_reason' => true, 'expected' => FinishReason::Unknown],
	];

	foreach ($testCases as $testCase) {
		$rawResponse = [
			'content' => [['type' => 'text', 'text' => 'Response text']],
		];

		if (!($testCase['no_stop_reason'] ?? false)) {
			$rawResponse['stop_reason'] = $testCase['stop_reason'];
		}

		$response = new ChatResponse($rawResponse);
		Assert::same($testCase['expected'], $response->getFinishReason());
	}
});


test('ChatResponse correctly extracts usage information', function () {
	$inputTokens = 10;
	$outputTokens = 20;
	$reasoningTokens = 30;

	$rawResponse = [
		'content' => [['type' => 'text', 'text' => 'Response']],
		'stop_reason' => 'end_turn',
		'usage' => [
			'input_tokens' => $inputTokens,
			'output_tokens' => $outputTokens,
			'reasoning_tokens' => $reasoningTokens,
			'total_tokens' => $inputTokens + $outputTokens,
		],
	];

	$response = new ChatResponse($rawResponse);
	$usage = $response->getUsage();

	Assert::type(Usage::class, $usage);
	Assert::same($inputTokens, $usage->inputTokens);
	Assert::same($outputTokens, $usage->outputTokens);
	Assert::same($reasoningTokens, $usage->reasoningTokens);
	Assert::same($rawResponse['usage'], $usage->raw);
});


test('ChatResponse returns null usage when not provided', function () {
	$rawResponse = [
		'content' => [['type' => 'text', 'text' => 'Response']],
		'stop_reason' => 'end_turn',
		// No usage field
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getUsage());
});


test('ChatResponse provides content blocks access', function () {
	$contentBlocks = [
		['type' => 'text', 'text' => 'First block'],
		['type' => 'text', 'text' => 'Second block'],
	];

	$rawResponse = [
		'content' => $contentBlocks,
		'stop_reason' => 'end_turn',
	];

	$response = new ChatResponse($rawResponse);
	Assert::same($contentBlocks, $response->getContentBlocks());
});


test('ChatResponse returns null contentBlocks when not provided', function () {
	// Test with a response format that doesn't use content blocks
	$rawResponse = [
		'content' => 'Plain text response without blocks',
		'stop_reason' => 'end_turn',
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getContentBlocks());
});


test('ChatResponse handles filtered content', function () {
	$rawResponse = [
		'content' => [['type' => 'text', 'text' => '']],
		'stop_reason' => 'content_filtered',
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getText());
	Assert::same(FinishReason::ContentFiltered, $response->getFinishReason());
});
