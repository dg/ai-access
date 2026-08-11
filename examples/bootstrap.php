<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo "Install dependencies using `composer install`\n";
	exit(1);
}

loadEnv(__DIR__ . '/.env');


/** Values already present in the environment win, so CI can override the file. */
function loadEnv(string $file): void
{
	foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
			continue;
		}
		[$key, $value] = explode('=', $line, 2);
		if (getenv(trim($key)) === false) {
			putenv(trim($key) . '=' . trim($value));
		}
	}
}


/**
 * Creates a client for the provider named in the first CLI argument.
 * @param  ?string  $capability  interface the provider must implement
 * @param  ?AIAccess\Http\Client  $http  transport, e.g. wrapped in a decorator
 */
function createClient(?string $capability = null, ?AIAccess\Http\Client $http = null): AIAccess\Chat\Service
{
	// each provider with the models the examples run on; the client carries them from here on
	$providers = [
		'openai' => [
			AIAccess\Provider\OpenAI\Client::class,
			'OPENAI_API_KEY',
			['chatModel' => 'gpt-5.6-luna', 'imageModel' => 'gpt-image-2', 'embeddingModel' => 'text-embedding-3-small'],
			fn(string $key, AIAccess\Http\Client $http, array $m) => new AIAccess\Provider\OpenAI\Client($key, $http, $m['chatModel'], $m['imageModel'], $m['embeddingModel']),
		],
		'claude' => [
			AIAccess\Provider\Claude\Client::class,
			'ANTHROPIC_API_KEY',
			['chatModel' => 'claude-sonnet-5'],
			fn(string $key, AIAccess\Http\Client $http, array $m) => new AIAccess\Provider\Claude\Client($key, $http, $m['chatModel']),
		],
		'gemini' => [
			AIAccess\Provider\Gemini\Client::class,
			'GEMINI_API_KEY',
			['chatModel' => 'gemini-3.5-flash-lite', 'imageModel' => 'gemini-3.1-flash-image', 'embeddingModel' => 'gemini-embedding-2'],
			fn(string $key, AIAccess\Http\Client $http, array $m) => new AIAccess\Provider\Gemini\Client($key, $http, $m['chatModel'], $m['imageModel'], $m['embeddingModel']),
		],
		'deepseek' => [
			AIAccess\Provider\DeepSeek\Client::class,
			'DEEPSEEK_API_KEY',
			['chatModel' => 'deepseek-v4-flash'],
			fn(string $key, AIAccess\Http\Client $http, array $m) => new AIAccess\Provider\DeepSeek\Client($key, $http, $m['chatModel']),
		],
		'grok' => [
			AIAccess\Provider\Grok\Client::class,
			'XAI_API_KEY',
			['chatModel' => 'grok-4.3', 'imageModel' => 'grok-imagine-image'],
			fn(string $key, AIAccess\Http\Client $http, array $m) => new AIAccess\Provider\Grok\Client($key, $http, $m['chatModel'], $m['imageModel']),
		],
	];

	$name = $GLOBALS['argv'][1] ?? getenv('AI_PROVIDER') ?: 'openai';
	[$class, $envName, $models, $factory] = $providers[$name]
		?? fail("Unknown provider '$name'. Use one of: " . implode(', ', array_keys($providers)));

	if ($capability !== null && !is_subclass_of($class, $capability)) {
		$supported = array_keys(array_filter($providers, fn($p) => is_subclass_of($p[0], $capability)));
		fail("Provider '$name' does not support this feature. Supported: " . implode(', ', $supported));
	}

	$key = getenv($envName);
	if (!$key) {
		fail("Missing $envName. Copy examples/.env.example to examples/.env and fill in your keys.");
	}

	if ($override = getenv('AI_MODEL')) {
		$models['chatModel'] = $override;
	}

	$GLOBALS['aiModels'] = $models;
	echo "[$name]\n";
	return $factory($key, $http ?? new AIAccess\Http\CurlClient, $models);
}


/**
 * The model the current provider was configured with, for the rare example that needs the
 * name rather than the answer. Everything else lets the client fill it in.
 */
function configuredModel(string $which = 'chatModel'): string
{
	return $GLOBALS['aiModels'][$which] ?? fail("No $which configured for this provider.");
}


/** Positional argument following the provider name. */
function arg(int $i): ?string
{
	return $GLOBALS['argv'][$i + 1] ?? null;
}


function fail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}
