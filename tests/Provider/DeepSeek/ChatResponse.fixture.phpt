<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\DeepSeek\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parses a real API response', function () {
	$response = new ChatResponse(fixture('deepseek/chat'));

	Assert::contains('OK', $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::null($response->getReasoning());

	$usage = $response->getUsage();
	Assert::same(7, $usage->inputTokens);
	Assert::same(2, $usage->outputTokens);
	Assert::same(0, $usage->cacheReadTokens);
});


test('thinking response exposes reasoning content', function () {
	$response = new ChatResponse(fixture('deepseek/thinking'));

	Assert::type('string', $response->getReasoning());
	Assert::same(FinishReason::TokenLimit, $response->getFinishReason());
	Assert::type('int', $response->getUsage()->reasoningTokens);
});
