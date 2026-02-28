<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\Grok\ChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parses a real API response', function () {
	$response = new ChatResponse(fixture('grok/chat'));

	Assert::contains('OK', $response->getText());
	Assert::same(FinishReason::Complete, $response->getFinishReason());

	$usage = $response->getUsage();
	Assert::same(195, $usage->inputTokens);
	Assert::same(2, $usage->outputTokens);
	Assert::same(128, $usage->cacheReadTokens);
});


test('end_turn counts as normal completion', function () {
	$response = new ChatResponse(['choices' => [['finish_reason' => 'end_turn', 'message' => ['content' => 'x']]]]);

	Assert::same(FinishReason::Complete, $response->getFinishReason());
});
