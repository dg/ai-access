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


test('an argument-less tool call replays as an object, never as []', function () {
	// json_decode turns {} into [], and sending [] back is a 400 on Claude
	$http = (new FakeHttpClient)->queue([
		'id' => 'msg', 'role' => 'assistant', 'model' => 'm',
		'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'get_time', 'input' => []]],
		'stop_reason' => 'tool_use',
		'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
	])->queue(fixture('claude/chat'));

	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('get_time', 'Time. No parameters.', handler: fn() => '12:00'));
	$chat->sendMessage('Time?');

	$block = $http->lastPayload()['messages'][1]['content'][0];
	Assert::same('tool_use', $block['type']);
	Assert::type(stdClass::class, $block['input']);
});


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


test('openai replays assistant text parts as output_text, not input_text', function () {
	// two texts side by side are the turn that forces the block form
	$call = new ToolCallPart('call_1', 'get_time', [], provider: 'someone-else');
	$http = (new FakeHttpClient)->queue(fixture('openai/chat'));
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('m');
	$chat->addMessage('Q', Role::User);
	$chat->addMessage([
		new AIAccess\Chat\TextPart('Let me check.'),
		new AIAccess\Chat\TextPart('Checking now.'),
		$call,
	], Role::Model);
	$chat->addToolResult($call, 'noon');
	$chat->sendMessage('Next');

	$assistant = null;
	foreach ($http->lastPayload()['input'] as $item) {
		if (($item['role'] ?? null) === 'assistant') {
			$assistant = $item;
		}
	}
	Assert::same(['output_text', 'output_text'], array_column($assistant['content'], 'type'));
});


test('openai keeps the parts of a turn in the order the model produced them', function () {
	// the flat input list has to read as the answer did: a text that came before a call
	// must not end up behind it, or a reasoning item loses the item required to follow it
	$call = new ToolCallPart('call_1', 'get_time', [], provider: 'someone-else');
	$http = (new FakeHttpClient)->queue(fixture('openai/chat'));
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('m');
	$chat->addMessage('Q', Role::User);
	$chat->addMessage([
		new AIAccess\Chat\TextPart('Let me check.'),
		$call,
		new AIAccess\Chat\TextPart('Checking now.'),
	], Role::Model);
	$chat->addToolResult($call, 'noon');
	$chat->sendMessage('Next');

	$shape = array_map(
		fn($item) => [$item['type'] ?? $item['role'], $item['content'] ?? $item['call_id']],
		$http->lastPayload()['input'],
	);
	Assert::same([
		['user', 'Q'],
		['assistant', 'Let me check.'],
		['function_call', 'call_1'],
		['assistant', 'Checking now.'],
		['function_call_output', 'call_1'],
		['user', 'Next'],
	], $shape);
});
