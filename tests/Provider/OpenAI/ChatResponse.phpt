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
	$message = [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Test text']]]];

	$cases = [
		[['status' => 'completed', 'output' => $message], FinishReason::Complete],
		[['output' => $message], FinishReason::Complete],
		[['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']], FinishReason::TokenLimit],
		[['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']], FinishReason::ContentFiltered],
		[['status' => 'incomplete', 'incomplete_details' => ['reason' => 'whatever']], FinishReason::Unknown],
		[['status' => 'cancelled'], FinishReason::Cancelled],
		[['status' => 'queued'], FinishReason::Unknown],
	];

	foreach ($cases as [$raw, $expected]) {
		Assert::same($expected, (new ChatResponse($raw))->getFinishReason());
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
			'output_tokens_details' => ['reasoning_tokens' => $reasoningTokens],
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
	Assert::same('', $response->getText());
});


test('ChatResponse handles empty or missing content', function () {
	$testCases = [
		// Empty output array
		[
			'raw' => [
				'output' => [],
			],
			'expected' => '',
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
			'expected' => '',
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
			'expected' => '',
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
			'expected' => '',
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
			'expected' => '',
		],
		// Missing output array
		[
			'raw' => ['other' => 'data'],
			'expected' => '',
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
	Assert::same('', $response->getText());
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
