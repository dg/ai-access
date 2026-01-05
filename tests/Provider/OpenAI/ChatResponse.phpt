<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Usage;
use AIAccess\Provider\OpenAI\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('ChatResponse parses standard text response', function () {
	$responseText = 'This is an OpenAI response';
	$rawResponse = [
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => $responseText],
				],
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
	Assert::null($response->getRawFinishReason());
	Assert::same($rawResponse, $response->getRawResponse());
});


test('ChatResponse parses multiple text blocks', function () {
	$rawResponse = [
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'Part 1'],
					['type' => 'output_text', 'text' => 'Part 2'],
				],
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same("Part 1\nPart 2", $response->getText());
});


test('ChatResponse handles multiple message blocks', function () {
	$rawResponse = [
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'Message 1'],
				],
			],
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'Message 2'],
				],
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same("Message 1\nMessage 2", $response->getText());
});


test('ChatResponse handles finish reasons correctly', function () {
	$testCases = [
		['reason' => 'stop', 'expected' => FinishReason::Complete],
		['reason' => 'length', 'expected' => FinishReason::TokenLimit],
		['reason' => 'max_output_tokens', 'expected' => FinishReason::TokenLimit],
		['reason' => 'content_filter', 'expected' => FinishReason::ContentFiltered],
		['reason' => 'tool_calls', 'expected' => FinishReason::ToolCall],
		['reason' => 'unknown_reason', 'expected' => FinishReason::Unknown],
		['no_reason' => true, 'expected' => FinishReason::Complete],
	];

	foreach ($testCases as $testCase) {
		$rawResponse = [
			'output' => [
				[
					'type' => 'message',
					'content' => [
						['type' => 'output_text', 'text' => 'Test text'],
					],
				],
			],
		];

		if (!($testCase['no_reason'] ?? false)) {
			$rawResponse['incomplete_details'] = ['reason' => $testCase['reason']];
		}

		$response = new ChatResponse($rawResponse);
		Assert::same($testCase['expected'], $response->getFinishReason());
	}
});


test('ChatResponse correctly extracts usage information', function () {
	$inputTokens = 15;
	$outputTokens = 25;
	$reasoningTokens = 10;

	$rawResponse = [
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'Response'],
				],
			],
		],
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
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'Response'],
				],
			],
		],
		// No usage field
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getUsage());
});


test('ChatResponse handles blocked content', function () {
	$rawResponse = [
		'blocked' => true,
		'block_reason' => 'safety',
		'output' => [], // Empty output
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getText());
});


test('ChatResponse handles empty or missing content', function () {
	$testCases = [
		// Empty output array
		[
			'raw' => [
				'output' => [],
			],
			'expected' => null,
		],
		// Missing type field
		[
			'raw' => [
				'output' => [
					[
						'content' => [
							['type' => 'output_text', 'text' => 'Text'],
						],
					],
				],
			],
			'expected' => null,
		],
		// Empty content array
		[
			'raw' => [
				'output' => [
					[
						'type' => 'message',
						'content' => [],
					],
				],
			],
			'expected' => null,
		],
		// Missing text field
		[
			'raw' => [
				'output' => [
					[
						'type' => 'message',
						'content' => [
							['type' => 'output_text'],
						],
					],
				],
			],
			'expected' => null,
		],
		// Wrong content type
		[
			'raw' => [
				'output' => [
					[
						'type' => 'message',
						'content' => [
							['type' => 'other_type', 'text' => 'Text'],
						],
					],
				],
			],
			'expected' => null,
		],
		// Missing output array
		[
			'raw' => ['other' => 'data'],
			'expected' => null,
		],
	];

	foreach ($testCases as $testCase) {
		$response = new ChatResponse($testCase['raw']);
		Assert::same($testCase['expected'], $response->getText());
	}
});


test('ChatResponse handles non-array content structure', function () {
	// Try with a response that doesn't match the expected structure
	$rawResponse = [
		'content' => 'Plain string content without proper structure',
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getText());
});


test('ChatResponse joins multiple text blocks with newlines', function () {
	$rawResponse = [
		'output' => [
			[
				'type' => 'message',
				'content' => [
					['type' => 'output_text', 'text' => 'First paragraph.'],
					['type' => 'output_text', 'text' => 'Second paragraph.'],
					['type' => 'output_text', 'text' => 'Third paragraph.'],
				],
			],
		],
	];

	$response = new ChatResponse($rawResponse);
	Assert::same("First paragraph.\nSecond paragraph.\nThird paragraph.", $response->getText());
});
