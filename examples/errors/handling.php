<?php declare(strict_types=1);

/**
 * The exception hierarchy, sorted by what you can do about each case.
 *
 * Demonstrates: ApiException, CommunicationException, UnexpectedResponseException, LogicException
 * Providers:    all
 * Usage:        php examples/errors/handling.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();

// a model name that does not exist: the API answers with an error
try {
	$client->createChat('no-such-model-2026')->sendMessage('Hello');

} catch (AIAccess\ApiException $e) {
	// the provider said no; getCode() carries the HTTP status
	echo 'ApiException (HTTP ', $e->getCode(), '): ', $e->getMessage(), "\n";

} catch (AIAccess\CommunicationException $e) {
	// network trouble or an unparseable body; retrying may help
	echo 'CommunicationException: ', $e->getMessage(), "\n";

} catch (AIAccess\UnexpectedResponseException $e) {
	// the response did not look the way it should; log it and investigate
	echo 'UnexpectedResponseException: ', $e->getMessage(), "\n";

} catch (AIAccess\ServiceException $e) {
	// anything else coming from the service
	echo 'ServiceException: ', $e->getMessage(), "\n";
}

// LogicException is not a service error, it is a bug in the calling code,
// so it deliberately sits outside the ServiceException tree
try {
	$client->createChat()->sendMessage();

} catch (AIAccess\LogicException $e) {
	echo 'LogicException: ', $e->getMessage(), "\n";
}
