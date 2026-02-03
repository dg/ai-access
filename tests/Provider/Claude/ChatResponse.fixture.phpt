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

	$usage = $response->getUsage();
	Assert::same(10, $usage->inputTokens);
	Assert::same(5, $usage->outputTokens);
});
