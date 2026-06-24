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
	$providers = [
		'openai' => [AIAccess\Provider\OpenAI\Client::class, 'OPENAI_API_KEY'],
		'claude' => [AIAccess\Provider\Claude\Client::class, 'ANTHROPIC_API_KEY'],
		'gemini' => [AIAccess\Provider\Gemini\Client::class, 'GEMINI_API_KEY'],
		'deepseek' => [AIAccess\Provider\DeepSeek\Client::class, 'DEEPSEEK_API_KEY'],
		'grok' => [AIAccess\Provider\Grok\Client::class, 'XAI_API_KEY'],
	];

	$name = $GLOBALS['argv'][1] ?? getenv('AI_PROVIDER') ?: 'openai';
	[$class, $envName] = $providers[$name]
		?? fail("Unknown provider '$name'. Use one of: " . implode(', ', array_keys($providers)));

	if ($capability !== null && !is_subclass_of($class, $capability)) {
		$supported = array_keys(array_filter($providers, fn($p) => is_subclass_of($p[0], $capability)));
		fail("Provider '$name' does not support this feature. Supported: " . implode(', ', $supported));
	}

	$key = getenv($envName);
	if (!$key) {
		fail("Missing $envName. Copy examples/.env.example to examples/.env and fill in your keys.");
	}

	echo "[$name]\n";
	return new $class($key, $http ?? new AIAccess\Http\CurlClient);
}


function chatModel(object $client): string
{
	$models = [
		AIAccess\Provider\OpenAI\Client::class => 'gpt-5.6-luna',
		AIAccess\Provider\Claude\Client::class => 'claude-sonnet-5',
		AIAccess\Provider\Gemini\Client::class => 'gemini-3.5-flash-lite',
		AIAccess\Provider\DeepSeek\Client::class => 'deepseek-v4-flash',
		AIAccess\Provider\Grok\Client::class => 'grok-4.3',
	];
	return getenv('AI_MODEL') ?: ($models[$client::class] ?? fail('No chat model configured for ' . $client::class));
}


function embeddingModel(object $client): string
{
	$models = [
		AIAccess\Provider\OpenAI\Client::class => 'text-embedding-3-small',
		AIAccess\Provider\Gemini\Client::class => 'gemini-embedding-2',
	];
	return $models[$client::class] ?? fail('No embedding model configured for ' . $client::class);
}


function imageModel(object $client): string
{
	$models = [
		AIAccess\Provider\OpenAI\Client::class => 'gpt-image-2',
		AIAccess\Provider\Gemini\Client::class => 'gemini-3.1-flash-image',
		AIAccess\Provider\Grok\Client::class => 'grok-imagine-image',
	];
	return $models[$client::class] ?? fail('No image model configured for ' . $client::class);
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
