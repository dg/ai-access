<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\OpenAI\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parses a real API response', function () {
	$response = new ChatResponse(fixture('openai/chat'));

	Assert::contains('OK', $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::null($response->getRefusal());

	$usage = $response->getUsage();
	Assert::same(9, $usage->inputTokens);
	Assert::same(5, $usage->outputTokens);
	Assert::same(0, $usage->reasoningTokens);
	Assert::same(0, $usage->cacheReadTokens);
	Assert::same(0, $usage->cacheWriteTokens);
});


test('refusal is reported instead of text', function () {
	$response = new ChatResponse([
		'status' => 'completed',
		'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]],
	]);

	// the text is empty; getRefusal() and the finish reason carry what happened
	Assert::same('', $response->getText());
	Assert::same('I cannot help with that.', $response->getRefusal());
	Assert::same(FinishReason::ContentFiltered, $response->getFinishReason());
});


test('finish reason follows status', function () {
	$incomplete = new ChatResponse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]);
	Assert::same(FinishReason::TokenLimit, $incomplete->getFinishReason());

	$cancelled = new ChatResponse(['status' => 'cancelled']);
	Assert::same(FinishReason::Cancelled, $cancelled->getFinishReason());

	$toolCall = new ChatResponse(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'f']]]);
	Assert::same(FinishReason::ToolCall, $toolCall->getFinishReason());
});
