<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Provider\OpenAICompatible\BaseChatResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a subclass that forgets the Provider constant is told, not tolerated', function () {
	// without it the parts carry an empty tag, never match on replay and vanish quietly
	Assert::exception(
		fn() => new class ([]) extends BaseChatResponse {
			protected function resolveFinishReason(): FinishReason
			{
				return FinishReason::Unknown;
			}
		},
		AIAccess\LogicException::class,
		'%a% must override the Provider constant.',
	);
});
