<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\Claude\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parses a real API response', function () {
	$response = new ChatResponse(fixture('claude/chat'));

	Assert::contains('OK', $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());
	Assert::same('end_turn', $response->getRawFinishReason());
	Assert::null($response->getReasoning());

	$usage = $response->getUsage();
	Assert::same(10, $usage->inputTokens);
	Assert::same(5, $usage->outputTokens);
	Assert::same(0, $usage->cacheReadTokens);
	Assert::same(0, $usage->cacheWriteTokens);
});


test('thinking is kept out of the text', function () {
	$response = new ChatResponse(fixture('claude/thinking'));

	$thinking = $response->getReasoning();
	Assert::type('string', $thinking);
	Assert::notContains('[Thinking:', (string) $response->getText());
	Assert::notContains($thinking, (string) $response->getText());

	Assert::same(119, $response->getUsage()->reasoningTokens);
});


test('refusal maps to ContentFiltered', function () {
	$response = new ChatResponse(['content' => [], 'stop_reason' => 'refusal']);

	Assert::same(FinishReason::ContentFiltered, $response->getFinishReason());
});
