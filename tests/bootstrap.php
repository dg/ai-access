<?php declare(strict_types=1);

// The Nette Tester command-line runner can be
// invoked through the command: ../vendor/bin/tester .

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}

// configure environment
Tester\Environment::setup();
Tester\Environment::setupFunctions();
DG\BypassFinals::enable();


register_shutdown_function(function () {
	Mockery::close();
});


require __DIR__ . '/Support/FakeHttpClient.php';


/**
 * Decoded API response captured from a real provider.
 * @return mixed[]
 */
function fixture(string $name): array
{
	$data = json_decode(fixtureRaw($name . '.json'), true, flags: JSON_THROW_ON_ERROR);
	return is_array($data) ? $data : throw new LogicException("Fixture $name is not an array.");
}


function fixtureRaw(string $name): string
{
	$file = __DIR__ . '/fixtures/' . $name;
	return @file_get_contents($file) ?: throw new LogicException("Missing fixture $file.");
}


function getTempDir(): string
{
	$dir = __DIR__ . '/tmp/' . getmypid();

	if (empty($GLOBALS['\lock'])) {
		// garbage collector
		$GLOBALS['\lock'] = $lock = fopen(__DIR__ . '/lock', 'w');
		if (rand(0, 100)) {
			flock($lock, LOCK_SH);
			@mkdir(dirname($dir));
		} elseif (flock($lock, LOCK_EX)) {
			Tester\Helpers::purge(dirname($dir));
		}

		@mkdir($dir);
	}

	return $dir;
}
