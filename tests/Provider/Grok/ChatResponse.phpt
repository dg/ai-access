<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Usage;
use AIAccess\Provider\Grok\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('ChatResponse parses standard text response', function () {
	$responseText = 'This is a Grok response';
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
			'prompt_tokens' => 10,
			'completion_tokens' => 5,
			'total_tokens' => 15,
		],
	];

	$response = new ChatResponse($rawResponse);

	Assert::same($responseText, $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::same('stop', $response->getRawFinishReason());
	Assert::same($rawResponse, $response->getRawResponse());
});


test('a missing finish_reason is not a finished answer', function () {
	$rawResponse = [
		'choices' => [
			[
				'message' => [
					'content' => 'Response text',
				],
				// No finish_reason
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	// the enum says so itself: Unknown covers a reason that is null or not standardised,
	// and in a stream a missing one is the mark of an answer cut off mid-flight
	Assert::same(FinishReason::Unknown, $response->getFinishReason());
	Assert::null($response->getRawFinishReason());
});


test('ChatResponse handles different finish reasons correctly', function () {
	$testCases = [
		['finish_reason' => 'stop', 'expected' => FinishReason::Complete],
		['finish_reason' => 'length', 'expected' => FinishReason::TokenLimit],
		['finish_reason' => 'content_filter', 'expected' => FinishReason::ContentFiltered],
		['finish_reason' => 'tool_calls', 'expected' => FinishReason::ToolCall],
		['finish_reason' => 'unknown_reason', 'expected' => FinishReason::Unknown],
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


test('ChatResponse handles content refusal', function () {
	$rawResponse = [
		'choices' => [
			[
				'message' => [
					'content' => null,
					'refusal' => [
						'reason' => 'harmful',
						'message' => 'I cannot provide that information',
					],
				],
				'finish_reason' => null,
			],
		],
	];

	$response = new ChatResponse($rawResponse);

	// no text, and the finish reason is what says why
	Assert::same('', $response->getText());
	Assert::same(FinishReason::ContentFiltered, $response->getFinishReason());
});


test('ChatResponse correctly extracts usage information', function () {
	$promptTokens = 15;
	$completionTokens = 25;
	$reasoningTokens = 10;

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
			'prompt_tokens' => $promptTokens,
			'completion_tokens' => $completionTokens,
			'total_tokens' => $promptTokens + $completionTokens,
			'completion_tokens_details' => [
				'reasoning_tokens' => $reasoningTokens,
			],
		],
	];

	$response = new ChatResponse($rawResponse);
	$usage = $response->getUsage();

	Assert::type(Usage::class, $usage);
	Assert::same($promptTokens, $usage->inputTokens);
	Assert::same($completionTokens, $usage->outputTokens);
	Assert::same($reasoningTokens, $usage->reasoningTokens);
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


test('ChatResponse handles empty or null content', function () {
	$testCases = [
		// Empty string
		[
			'raw' => [
				'choices' => [
					[
						'message' => [
							'content' => '',
						],
					],
				],
			],
			'expected' => '',
		],
		// Missing content field
		[
			'raw' => [
				'choices' => [
					[
						'message' => [
							'role' => 'assistant',
						],
					],
				],
			],
			'expected' => '',
		],
		// Explicitly null content
		[
			'raw' => [
				'choices' => [
					[
						'message' => [
							'content' => null,
						],
					],
				],
			],
			'expected' => '',
		],
	];

	foreach ($testCases as $testCase) {
		$response = new ChatResponse($testCase['raw']);
		Assert::same($testCase['expected'], $response->getText());
	}
});
