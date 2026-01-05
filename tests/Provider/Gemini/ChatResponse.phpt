<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Usage;
use AIAccess\Provider\Gemini\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('ChatResponse parses standard text response', function () {
	$responseText = 'This is a Gemini response';
	$rawResponse = [
		'candidates' => [
			[
				'content' => [
					'parts' => [
						['text' => $responseText],
					],
				],
				'finishReason' => 'STOP',
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same($responseText, $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::same('STOP', $response->getRawFinishReason());
	Assert::same($rawResponse, $response->getRawResponse());
});


test('ChatResponse parses multipart text response', function () {
	$rawResponse = [
		'candidates' => [
			[
				'content' => [
					'parts' => [
						['text' => 'Part 1'],
						['text' => 'Part 2'],
					],
				],
				'finishReason' => 'STOP',
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same("Part 1\nPart 2", $response->getText());
});


test('ChatResponse handles fallback response format', function () {
	// Some response formats might be simpler
	$responseText = 'Simpler response format';
	$rawResponse = [
		'candidates' => [
			[
				'text' => $responseText,
				'finishReason' => 'STOP',
			],
		],
	];

	$response = new ChatResponse($rawResponse);
	Assert::same($responseText, $response->getText());
});


test('ChatResponse handles finish reasons correctly', function () {
	$testCases = [
		['finishReason' => 'STOP', 'expected' => FinishReason::Complete],
		['finishReason' => 'MAX_TOKENS', 'expected' => FinishReason::TokenLimit],
		['finishReason' => 'SAFETY', 'expected' => FinishReason::ContentFiltered],
		['finishReason' => 'RECITATION', 'expected' => FinishReason::ContentFiltered],
		['finishReason' => 'TOOL_CALLS', 'expected' => FinishReason::ToolCall],
		['finishReason' => 'OTHER_REASON', 'expected' => FinishReason::Unknown],
		// Test missing finishReason
		['no_finish_reason' => true, 'expected' => FinishReason::Unknown],
	];

	foreach ($testCases as $testCase) {
		$rawResponse = [
			'candidates' => [
				[
					'content' => [
						'parts' => [
							['text' => 'Test text'],
						],
					],
				],
			],
		];

		if (!($testCase['no_finish_reason'] ?? false)) {
			$rawResponse['candidates'][0]['finishReason'] = $testCase['finishReason'];
		}

		$response = new ChatResponse($rawResponse);
		Assert::same($testCase['expected'], $response->getFinishReason());
	}
});


test('ChatResponse correctly extracts usage information', function () {
	$promptTokens = 15;
	$candidatesTokens = 25;

	$rawResponse = [
		'candidates' => [
			[
				'content' => [
					'parts' => [
						['text' => 'Response'],
					],
				],
				'finishReason' => 'STOP',
			],
		],
		'usageMetadata' => [
			'promptTokenCount' => $promptTokens,
			'candidatesTokenCount' => $candidatesTokens,
			'totalTokenCount' => $promptTokens + $candidatesTokens,
		],
	];

	$response = new ChatResponse($rawResponse);
	$usage = $response->getUsage();

	Assert::type(Usage::class, $usage);
	Assert::same($promptTokens, $usage->inputTokens);
	Assert::same($candidatesTokens, $usage->outputTokens);
	Assert::null($usage->reasoningTokens); // Gemini doesn't have reasoning tokens
	Assert::same($rawResponse['usageMetadata'], $usage->raw);
});


test('ChatResponse returns null usage when not provided', function () {
	$rawResponse = [
		'candidates' => [
			[
				'content' => [
					'parts' => [
						['text' => 'Response'],
					],
				],
				'finishReason' => 'STOP',
			],
		],
		// No usageMetadata field
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getUsage());
});


test('ChatResponse handles content filtering', function () {
	$rawResponse = [
		'promptFeedback' => [
			'blockReason' => 'SAFETY',
			'safetyRatings' => [
				[
					'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
					'probability' => 'HIGH',
				],
			],
		],
		'candidates' => [], // Empty candidates
	];

	$response = new ChatResponse($rawResponse);
	Assert::null($response->getText());
});


test('ChatResponse handles empty content parts', function () {
	$testCases = [
		// Empty parts array
		[
			'raw' => [
				'candidates' => [
					[
						'content' => [
							'parts' => [],
						],
						'finishReason' => 'STOP',
					],
				],
			],
			'expected' => null,
		],
		// Parts with no text field
		[
			'raw' => [
				'candidates' => [
					[
						'content' => [
							'parts' => [
								['otherField' => 'value'],
							],
						],
						'finishReason' => 'STOP',
					],
				],
			],
			'expected' => null,
		],
		// Empty candidates array
		[
			'raw' => [
				'candidates' => [],
			],
			'expected' => null,
		],
	];

	foreach ($testCases as $testCase) {
		$response = new ChatResponse($testCase['raw']);
		Assert::same($testCase['expected'], $response->getText());
	}
});


test('ChatResponse handles malformed response gracefully', function () {
	$testCases = [
		// Missing candidates
		[
			'raw' => ['other' => 'data'],
			'expected' => null,
		],
		// Missing content
		[
			'raw' => [
				'candidates' => [
					['finishReason' => 'STOP'],
				],
			],
			'expected' => null,
		],
	];

	foreach ($testCases as $testCase) {
		$response = new ChatResponse($testCase['raw']);
		Assert::same($testCase['expected'], $response->getText());
	}
});
