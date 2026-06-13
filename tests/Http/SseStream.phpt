<?php declare(strict_types=1);

use AIAccess\Http\SseStream;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('an event needs a blank line to be complete', function () {
	$sse = new SseStream;

	Assert::same([], $sse->feed("data: first\n"));
	Assert::same([['event' => null, 'data' => 'first']], $sse->feed("\n"));
});


test('an event torn across chunks is put back together', function () {
	$sse = new SseStream;

	Assert::same([], $sse->feed('eve'));
	Assert::same([], $sse->feed("nt: delta\ndata: {\"a\":"));
	Assert::same([['event' => 'delta', 'data' => '{"a":1}']], $sse->feed("1}\n\n"));
});


test('several events in one chunk all come out', function () {
	$sse = new SseStream;
	$events = $sse->feed("data: one\n\ndata: two\n\ndata: three\n\n");

	Assert::same(['one', 'two', 'three'], array_column($events, 'data'));
});


test('comment lines are dropped, which is what keeps DeepSeek connections alive', function () {
	$sse = new SseStream;
	$events = $sse->feed(": keep-alive\n\ndata: real\n\n");

	Assert::same([['event' => null, 'data' => 'real']], $events);
});


test('CRLF and a missing space after the colon are both fine', function () {
	$sse = new SseStream;
	$events = $sse->feed("event: ping\r\ndata:no-space\r\n\r\n");

	Assert::same([['event' => 'ping', 'data' => 'no-space']], $events);
});


test('multi-line data is joined the way the spec says', function () {
	$sse = new SseStream;
	$events = $sse->feed("data: line one\ndata: line two\n\n");

	Assert::same("line one\nline two", $events[0]['data']);
});


test('a sentinel is data like any other, for the provider layer to recognise', function () {
	$sse = new SseStream;
	Assert::same([['event' => null, 'data' => '[DONE]']], $sse->feed("data: [DONE]\n\n"));
});


test('every captured transcript parses into events', function () {
	$expected = [
		// provider => [events, all named?, ends with [DONE]?]
		'claude' => [true, false],
		'openai' => [true, false],
		'gemini' => [false, false],
		'deepseek' => [false, true],
		'grok' => [false, true],
	];

	foreach ($expected as $provider => [$named, $sentinel]) {
		$raw = file_get_contents(__DIR__ . "/../fixtures/$provider/stream.sse.txt");
		$sse = new SseStream;

		// fed in small pieces, so the boundaries fall in the middle of events
		$events = [];
		foreach (str_split($raw, 64) as $piece) {
			$events = array_merge($events, $sse->feed($piece));
		}

		Assert::true(count($events) > 1, $provider);
		Assert::same($named, $events[0]['event'] !== null, $provider);
		Assert::same($sentinel, end($events)['data'] === '[DONE]', $provider);

		foreach ($events as $event) {
			if ($event['data'] !== '[DONE]') {
				Assert::type('array', json_decode($event['data'], true), $provider);
			}
		}
	}
});


test('a leading BOM does not corrupt the first field name', function () {
	$sse = new SseStream;
	$events = $sse->feed("\u{FEFF}event: ping\ndata: x\n\n");

	Assert::same([['event' => 'ping', 'data' => 'x']], $events);
});


test('lone CR line endings are legal, even torn between chunks', function () {
	$sse = new SseStream;

	Assert::same([], $sse->feed("data: one\r"));
	Assert::same([['event' => null, 'data' => 'one']], $sse->feed("\rdata: two\r"));
	// the trailing \r may still be half of a \r\n pair, so it waits
	Assert::same([['event' => null, 'data' => 'two']], $sse->feed("\n\n"));
});


test('close() flushes an event whose final blank line never came', function () {
	$sse = new SseStream;

	Assert::same([], $sse->feed("event: done\ndata: last\n"));
	Assert::same(['event' => 'done', 'data' => 'last'], $sse->close());
	Assert::null($sse->close());
});


test('close() with nothing buffered is quiet', function () {
	$sse = new SseStream;
	$sse->feed("data: whole\n\n");

	Assert::null($sse->close());
});
