<?php declare(strict_types=1);

use AIAccess\Http\JsonlStream;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * A transport that pushes the given chunks and stops when the callback says so,
 * exactly as CurlClient does. $delivered records what it actually got to send.
 * @param  list<string>  $chunks
 * @param  ?list<string>  $delivered
 */
function transport(array $chunks, ?array &$delivered = null): Closure
{
	return function (Closure $onChunk) use ($chunks, &$delivered): void {
		$delivered = [];
		foreach ($chunks as $chunk) {
			$delivered[] = $chunk;
			if ($onChunk($chunk) === false) {
				return;
			}
		}
	};
}


test('a line torn between chunks is put back together', function () {
	$lines = iterator_to_array(JsonlStream::read(transport(['{"a":1}' . "\n" . '{"b', '":2}' . "\n"])));

	Assert::same(['{"a":1}', '{"b":2}'], $lines);
});


test('a last line whose newline never came is still a line', function () {
	Assert::same(['{"a":1}'], iterator_to_array(JsonlStream::read(transport(['{"a":1}']))));
});


test('blank lines are skipped and CRLF is not part of the line', function () {
	Assert::same(['x', 'y'], iterator_to_array(JsonlStream::read(transport(["x\r\n\r\ny\r\n"]))));
});


test('nothing is transferred until the reading starts', function () {
	$delivered = null;
	JsonlStream::read(transport(["a\nb\n"], $delivered));

	// building the generator sends nothing, which is what makes an early exit cheap
	Assert::null($delivered);
});


test('stopping the iteration aborts the transfer', function () {
	$delivered = null;
	foreach (JsonlStream::read(transport(["a\n", "b\n", "c\n"], $delivered)) as $line) {
		Assert::same('a', $line);
		break;
	}

	// unlike a chat stream, an abandoned result file is an end rather than a pause,
	// so the rest of it is never asked for
	Assert::same(["a\n"], $delivered);
});


test('an exception from the transport surfaces to the reader', function () {
	$stream = JsonlStream::read(function (Closure $onChunk): void {
		$onChunk("a\n");
		throw new AIAccess\CommunicationException('connection lost');
	});

	Assert::exception(
		fn() => iterator_to_array($stream),
		AIAccess\CommunicationException::class,
		'connection lost',
	);
});
