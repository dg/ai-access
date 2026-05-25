<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Media;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


$png = Media::fromBinary('PNGDATA', 'image/png');
$pdf = Media::fromBinary('PDFDATA', 'application/pdf', 'report.pdf');

$clients = [
	'claude' => fn($http) => new AIAccess\Provider\Claude\Client('key', $http),
	'openai' => fn($http) => new AIAccess\Provider\OpenAI\Client('key', $http),
	'gemini' => fn($http) => new AIAccess\Provider\Gemini\Client('key', $http),
	'grok' => fn($http) => new AIAccess\Provider\Grok\Client('key', $http),
];


test('an image reaches every vision provider, base64 and all', function () use ($clients, $png) {
	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)->queue(fixture($name === 'gemini' ? 'gemini/tool-call' : $name . '/chat'));
		$chat = $factory($http)->createChat('model');

		$chat->sendMessage(['What is this?', $png]);

		$sent = json_encode($http->lastPayload(), JSON_UNESCAPED_SLASHES);
		Assert::contains(base64_encode('PNGDATA'), $sent, $name);
		Assert::contains('What is this?', $sent, $name);
		Assert::contains('image/png', $sent, $name);
	}
});


test('each provider uses its own shape', function () use ($clients, $png) {
	$expected = [
		'claude' => ['image', 'base64'],
		'openai' => ['input_image', 'data:image/png;base64,'],
		'gemini' => ['inlineData', 'mimeType'],
		'grok' => ['image_url', 'data:image/png;base64,'],
	];

	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)->queue(fixture($name === 'gemini' ? 'gemini/tool-call' : $name . '/chat'));
		$chat = $factory($http)->createChat('model');
		$chat->sendMessage([$png]);

		$sent = json_encode($http->lastPayload(), JSON_UNESCAPED_SLASHES);
		foreach ($expected[$name] as $needle) {
			Assert::contains($needle, $sent, $name);
		}
	}
});


test('a document goes as a document where documents exist', function () use ($clients, $pdf) {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$claude = $clients['claude']($http)->createChat('model');
	$claude->sendMessage(['Summarize', $pdf]);
	$block = $http->lastPayload()['messages'][0]['content'][1];
	Assert::same('document', $block['type']);
	Assert::same('application/pdf', $block['source']['media_type']);

	$http = (new FakeHttpClient)->queue(fixture('openai/chat'));
	$openai = $clients['openai']($http)->createChat('model');
	$openai->sendMessage(['Summarize', $pdf]);
	$block = $http->lastPayload()['input'][0]['content'][1];
	Assert::same('input_file', $block['type']);
	Assert::same('report.pdf', $block['filename']);
});


test('providers that cannot take media say so before the request', function () use ($png, $pdf) {
	$deepseek = (new AIAccess\Provider\DeepSeek\Client('key', new FakeHttpClient))->createChat('model');
	Assert::exception(
		fn() => $deepseek->sendMessage(['What is this?', $png]),
		AIAccess\LogicException::class,
		'DeepSeek cannot send image/png content: it has no vision model.',
	);

	$grok = (new AIAccess\Provider\Grok\Client('key', new FakeHttpClient))->createChat('model');
	Assert::exception(
		fn() => $grok->sendMessage(['Summarize', $pdf]),
		AIAccess\LogicException::class,
		'Grok cannot send application/pdf content, only images.',
	);
});


test('parts keep the order they were written in', function () use ($clients, $png) {
	$expected = [
		'claude' => ['image', 'text'],
		'openai' => ['input_image', 'input_text'],
		'grok' => ['image_url', 'text'],
	];

	foreach ($expected as $name => $types) {
		$http = (new FakeHttpClient)->queue(fixture($name . '/chat'));
		$chat = $clients[$name]($http)->createChat('model');
		$chat->sendMessage([$png, 'and this text comes after it']);

		$content = $name === 'openai'
			? $http->lastPayload()['input'][0]['content']
			: $http->lastPayload()['messages'][0]['content'];
		Assert::same($types, array_column($content, 'type'), $name);
	}

	// gemini has no type key, so the shape of the parts is what tells them apart
	$http = (new FakeHttpClient)->queue(fixture('gemini/tool-call'));
	$gemini = $clients['gemini']($http)->createChat('model');
	$gemini->sendMessage([$png, 'and this text comes after it']);
	$parts = $http->lastPayload()['contents'][0]['parts'];
	Assert::same(['inlineData', 'text'], [array_key_first($parts[0]), array_key_first($parts[1])]);
});


test('the generic OpenAI-compatible client carries images too', function () use ($png, $pdf) {
	$http = (new FakeHttpClient)->queue(fixture('grok/chat'));
	$chat = (new AIAccess\Provider\OpenAICompatible\Client('key', 'https://host/v1/', $http))->createChat('llava');

	$chat->sendMessage(['What is this?', $png]);

	$content = $http->lastPayload()['messages'][0]['content'];
	Assert::same(['text', 'image_url'], array_column($content, 'type'));
	Assert::contains(base64_encode('PNGDATA'), $content[1]['image_url']['url']);

	$chat = (new AIAccess\Provider\OpenAICompatible\Client('key', 'https://host/v1/', new FakeHttpClient))->createChat('llava');
	Assert::exception(
		fn() => $chat->sendMessage(['Summarize', $pdf]),
		AIAccess\LogicException::class,
		'This endpoint cannot send application/pdf content, only images.',
	);
});


test('a lone text part still travels as a plain string', function () use ($clients) {
	$http = (new FakeHttpClient)->queue(fixture('openai/chat'));
	$chat = $clients['openai']($http)->createChat('model');

	$chat->addMessage([new AIAccess\Chat\TextPart('one'), new AIAccess\Chat\TextPart('two')], Role::User);
	$chat->sendMessage();

	Assert::same("one\ntwo", $http->lastPayload()['input'][0]['content']);
});


test('media survive a serialized history', function () use ($clients, $png) {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$chat = $clients['claude']($http)->createChat('model');
	$chat->addMessage(['Look', $png], Role::User);

	$restored = unserialize(serialize($chat->getMessages()));
	$replay = $clients['claude'](new FakeHttpClient)->createChat('model');
	foreach ($restored as $message) {
		$replay->addMessage($message->getParts(), $message->getRole());
	}

	$chat->sendMessage();
	Assert::equal($http->lastPayload(), $replay->buildPayload());
});
