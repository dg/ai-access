<?php declare(strict_types=1);

// The library does not persist conversations, but its message format must not stand in
// the way of anyone who wants to. This test guards that; it is not a public API promise.

use AIAccess\Chat\ReasoningPart;
use AIAccess\Chat\Role;
use AIAccess\Chat\TextPart;
use AIAccess\Media;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


function claudeChat(): AIAccess\Provider\Claude\Chat
{
	return (new AIAccess\Provider\Claude\Client('key', new FakeHttpClient))->createChat('claude-sonnet-5');
}


test('a history survives serialize() and builds an identical payload', function () {
	$chat = claudeChat();
	$chat->setSystemInstruction('Be brief.');
	$chat->addMessage('Question', Role::User);
	$chat->addMessage([
		new ReasoningPart('thought', 'claude', ['type' => 'thinking', 'thinking' => 'thought', 'signature' => 'SIG']),
		new TextPart('Answer', 'claude', ['type' => 'text', 'text' => 'Answer']),
	], Role::Model);
	$chat->addMessage('Follow-up', Role::User);
	$before = $chat->buildPayload();

	$restored = unserialize(serialize($chat->getMessages()));

	$replay = claudeChat();
	$replay->setSystemInstruction('Be brief.');
	foreach ($restored as $message) {
		$replay->addMessage($message->getParts(), $message->getRole());
	}

	Assert::equal($before, $replay->buildPayload());
	Assert::same('SIG', $replay->buildPayload()['messages'][1]['content'][0]['signature']);
});


test('media survives serialization', function () {
	$media = Media::fromBinary("\x00binary\xff", 'image/png');
	$restored = unserialize(serialize(new AIAccess\Chat\Message($media, Role::User)));

	$part = $restored->getParts()[0];
	Assert::type(Media::class, $part);
	Assert::same("\x00binary\xff", $part->getData());
	Assert::same('image/png', $part->getMimeType());
	Assert::same(base64_encode("\x00binary\xff"), $part->getBase64());
});


test('roles and finish reasons round-trip through their backing values', function () {
	Assert::same(Role::Model, Role::from(Role::Model->value));
	Assert::same(
		AIAccess\Chat\FinishReason::ToolCall,
		AIAccess\Chat\FinishReason::from(AIAccess\Chat\FinishReason::ToolCall->value),
	);
});
