<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Usage;
use AIAccess\Provider\DeepSeek\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('ChatResponse parses standard text response', function () {
	$responseText = 'This is a DeepSeek response';
	$rawResponse = [
		'choices' => [
			[
				'message' => [
					'content' => $responseText,
				],
				'finish_reason' => 'stop',
			],
		],
		'usage' => [
			'input_tokens' => 10,
			'output_tokens' => 5,
			'total_tokens' => 15,
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same($responseText, $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::same('stop', $response->getRawFinishReason());
	Assert::same($rawResponse, $response->getRawResponse());
});


test('ChatResponse handles different finish reasons correctly', function () {
	$testCases = [
		['finish_reason' => 'stop', 'expected' => FinishReason::Complete],
		['finish_reason' => 'length', 'expected' => FinishReason::TokenLimit],
		['finish_reason' => 'content_filter', 'expected' => FinishReason::ContentFiltered],
		['finish_reason' => 'tool_calls', 'expected' => FinishReason::ToolCall],
		['finish_reason' => 'unknown_reason', 'expected' => FinishReason::Unknown],
		// Test missing finish_reason
		['no_finish_reason' => true, 'expected' => FinishReason::Unknown],
	];

	foreach ($testCases as $testCase) {
		$rawResponse = [
			'choices' => [
				[
					'message' => [
						'content' => 'Response text',
					],
				],
			],
		];

		if (!($testCase['no_finish_reason'] ?? false)) {
			$rawResponse['choices'][0]['finish_reason'] = $testCase['finish_reason'];
		}

		$response = new ChatResponse($rawResponse);
		Assert::same($testCase['expected'], $response->getFinishReason());
	}
});


test('ChatResponse correctly extracts standard usage information', function () {
	$inputTokens = 15;
	$outputTokens = 25;

	$rawResponse = [
		'choices' => [
			[
				'message' => [
					'content' => 'Response',
				],
				'finish_reason' => 'stop',
			],
		],
		'usage' => [
			'input_tokens' => $inputTokens,
			'output_tokens' => $outputTokens,
			'total_tokens' => $inputTokens + $outputTokens,
		],
	];

	$response = new ChatResponse($rawResponse);
	$usage = $response->getUsage();

	Assert::type(Usage::class, $usage);
	Assert::same($inputTokens, $usage->inputTokens);
	Assert::same($outputTokens, $usage->outputTokens);
	Assert::null($usage->reasoningTokens); // Standard DeepSeek doesn't have reasoning tokens
	Assert::same($rawResponse['usage'], $usage->raw);
});


test('ChatResponse returns null usage when not provided', function () {
	$rawResponse = [
		'choices' => [
			[
				'message' => [
					'content' => 'Response',
				],
				'finish_reason' => 'stop',
			],
		],
		// No usage field
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getUsage());
});
