<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Chat\Tool;
use AIAccess\Chat\ToolCallPart;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


function geminiAnswer(): array
{
	return [
		'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'OK.']]], 'finishReason' => 'STOP']],
		'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
	];
}


test('a foreign call with no arguments replays as an object on every wire', function () {
	$foreign = new ToolCallPart('call_x', 'get_time', [], provider: 'someone-else');
	$openai = fn($http) => (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('m');
	$deepseek = fn($http) => (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('m');
	$gemini = fn($http) => (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m');

	// openai: arguments is a JSON string and must read {}
	$http = (new FakeHttpClient)->queue(fixture('openai/chat'));
	$chat = $openai($http);
	$chat->addMessage('Q', Role::User);
	$chat->addMessage($foreign, Role::Model);
	$chat->addToolResult($foreign, 'noon');
	$chat->sendMessage('Next');
	[, $call] = $http->lastPayload()['input'];
	Assert::same('{}', $call['arguments']);

	// chat/completions dialect: the same JSON string shape
	$http = (new FakeHttpClient)->queue(fixture('deepseek/chat'));
	$chat = $deepseek($http);
	$chat->addMessage('Q', Role::User);
	$chat->addMessage($foreign, Role::Model);
	$chat->addToolResult($foreign, 'noon');
	$chat->sendMessage('Next');
	$calls = $http->lastPayload()['messages'][1]['tool_calls'];
	Assert::same('{}', $calls[0]['function']['arguments']);

	// gemini: args is an inline object, and the foreign id must ride along on both sides
	$http = (new FakeHttpClient)->queue(geminiAnswer());
	$chat = $gemini($http);
	$chat->addMessage('Q', Role::User);
	$chat->addMessage($foreign, Role::Model);
	$chat->addToolResult($foreign, 'noon');
	$chat->sendMessage();
	$contents = $http->lastPayload()['contents'];
	$call = $contents[1]['parts'][0]['functionCall'];
	Assert::same('call_x', $call['id']);
	Assert::type(stdClass::class, $call['args']);
	Assert::same('call_x', $contents[2]['parts'][0]['functionResponse']['id']);
});


test('gemini wraps a list result, because a JSON array is not a Struct', function () {
	$call = new ToolCallPart('get_items#0', 'get_items', [], provider: 'gemini', raw: ['functionCall' => ['name' => 'get_items', 'args' => new stdClass]]);

	$http = (new FakeHttpClient)->queue(geminiAnswer());
	$chat = (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m');
	$chat->addMessage('Q', Role::User);
	$chat->addMessage($call, Role::Model);
	$chat->addToolResult($call, [1, 2, 3]);
	$chat->sendMessage();

	$response = $http->lastPayload()['contents'][2]['parts'][0]['functionResponse'];
	Assert::same(['result' => [1, 2, 3]], $response['response']);

	// and an empty result is still an object
	$http = (new FakeHttpClient)->queue(geminiAnswer());
	$chat = (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m');
	$chat->addMessage('Q', Role::User);
	$chat->addMessage($call, Role::Model);
	$chat->addToolResult($call, []);
	$chat->sendMessage();

	$response = $http->lastPayload()['contents'][2]['parts'][0]['functionResponse'];
	Assert::type(stdClass::class, $response['response']);
});


