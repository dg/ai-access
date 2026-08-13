<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\ReasoningPart;
use AIAccess\Chat\TextPart;
use AIAccess\Chat\ToolCallPart;
use AIAccess\Provider\OpenAICompatible\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parses text, usage and the raw finish reason', function () {
	$raw = [
		'choices' => [['message' => ['content' => 'Hello there'], 'finish_reason' => 'stop']],
		'usage' => [
			'prompt_tokens' => 11,
			'completion_tokens' => 3,
			'completion_tokens_details' => ['reasoning_tokens' => 2],
			'prompt_tokens_details' => ['cached_tokens' => 8],
		],
	];

	$response = new ChatResponse($raw);

	Assert::same('Hello there', $response->getText());
	Assert::same('stop', $response->getRawFinishReason());
	Assert::same($raw, $response->getRawResponse());

	$usage = $response->getUsage();
	Assert::same(11, $usage->inputTokens);
	Assert::same(3, $usage->outputTokens);
	Assert::same(2, $usage->reasoningTokens);
	// unlike DeepSeek, cache hits are read from the nested key
	Assert::same(8, $usage->cacheReadTokens);
});


test('finish reasons map, and a missing one marks an answer cut short', function () {
	$cases = [
		'stop' => FinishReason::Complete,
		'end_turn' => FinishReason::Complete,
		'length' => FinishReason::TokenLimit,
		'tool_calls' => FinishReason::ToolCall,
		'content_filter' => FinishReason::ContentFiltered,
		'something_else' => FinishReason::Unknown,
	];

	foreach ($cases as $reason => $expected) {
		$response = new ChatResponse([
			'choices' => [['message' => ['content' => 'x'], 'finish_reason' => $reason]],
		]);
		Assert::same($expected, $response->getFinishReason(), $reason);
	}

	// the dialect has no closing event, so a reason that never arrived is the mark of a
	// stream that died mid-answer; calling that complete would hide the truncation
	$missing = new ChatResponse(['choices' => [['message' => ['content' => 'x']]]]);
	Assert::same(FinishReason::Unknown, $missing->getFinishReason());
	Assert::null($missing->getRawFinishReason());
});


test('a refusal is read here too, although only Grok sends one today', function () {
	// it belongs to the dialect, so an endpoint reached through this client can send it
	$response = new ChatResponse([
		'choices' => [['message' => ['content' => null, 'refusal' => 'I cannot help with that.'], 'finish_reason' => 'stop']],
	]);

	Assert::same(FinishReason::ContentFiltered, $response->getFinishReason());
	Assert::same('', $response->getText());
});


test('a tool call that is not an object is a broken response, not an empty call', function () {
	Assert::exception(
		fn() => new ChatResponse([
			'choices' => [['message' => ['content' => 'x', 'tool_calls' => ['nonsense']], 'finish_reason' => 'tool_calls']],
		]),
		AIAccess\UnexpectedResponseException::class,
		'Expected an object in choices[0].message.tool_calls, got string.',
	);
});


test('reasoning and tool calls become parts tagged for this provider only', function () {
	$response = new ChatResponse([
		'choices' => [[
			'message' => [
				'content' => 'Looking it up.',
				'reasoning_content' => 'The user wants the weather.',
				'tool_calls' => [[
					'id' => 'call_1',
					'type' => 'function',
					'function' => ['name' => 'get_weather', 'arguments' => '{"city": "Brno"}'],
				]],
			],
			'finish_reason' => 'tool_calls',
		]],
	]);

	Assert::same('The user wants the weather.', $response->getReasoning());
	Assert::same(FinishReason::ToolCall, $response->getFinishReason());

	$parts = $response->getMessage()->getParts();
	Assert::type(ReasoningPart::class, $parts[0]);
	Assert::type(TextPart::class, $parts[1]);
	Assert::type(ToolCallPart::class, $parts[2]);
	foreach ($parts as $part) {
		Assert::same(ChatResponse::Provider, $part->provider);
	}

	$calls = $response->getToolCalls();
	Assert::count(1, $calls);
	Assert::same('call_1', $calls[0]->callId);
	Assert::same('get_weather', $calls[0]->name);
	Assert::same(['city' => 'Brno'], $calls[0]->arguments);
	Assert::null($calls[0]->argumentsError);
});


test('arguments the model mangled are reported back, not thrown', function () {
	$response = new ChatResponse([
		'choices' => [['message' => ['tool_calls' => [[
			'id' => 'call_2',
			'function' => ['name' => 'get_weather', 'arguments' => '{"city": '],
		]]], 'finish_reason' => 'tool_calls']],
	]);

	$call = $response->getToolCalls()[0];
	Assert::same([], $call->arguments);
	Assert::type('string', $call->argumentsError);
});


test('a stopped stream keeps what arrived and says it was cancelled', function () {
	$stopped = new ChatResponse(['choices' => [['message' => ['content' => 'half'], 'finish_reason' => 'stop']]], cancelled: true);

	// cancelled wins over the raw reason, which still says the answer finished
	Assert::same(FinishReason::Cancelled, $stopped->getFinishReason());
	Assert::same('half', $stopped->getText());
});


test('no text at all is an empty string, and getJson() still answers null', function () {
	$empty = new ChatResponse(['choices' => [['message' => ['content' => null], 'finish_reason' => 'stop']]]);

	Assert::same('', $empty->getText());
	Assert::null($empty->getJson());
});


test('a message shape this dialect does not speak is reported, not fatal', function () {
	Assert::exception(
		fn() => new ChatResponse(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'hi']]]]]]),
		AIAccess\UnexpectedResponseException::class,
		'Expected a string in choices[0].message.content, got array.',
	);

	Assert::exception(
		fn() => new ChatResponse(['choices' => [['message' => ['content' => 'hi', 'tool_calls' => 'nonsense']]]]),
		AIAccess\UnexpectedResponseException::class,
		'Expected a list in choices[0].message.tool_calls, got string.',
	);
});
