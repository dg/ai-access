<?php declare(strict_types=1);

use AIAccess\Batch\Status;
use AIAccess\Chat\Role;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../../bootstrap.php';


function geminiBatch(FakeHttpClient $http): AIAccess\Provider\Gemini\Batch
{
	$batch = (new Client('key', $http))->createBatch();
	$batch->addChat('gemini-3.5-flash-lite', 'first')->addMessage('Question one', Role::User);
	$batch->addChat('gemini-3.5-flash-lite', 'second')->addMessage('Question two', Role::User);
	return $batch;
}


test('submit nests the requests the way the API wants', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'batches/abc']);
	$response = geminiBatch($http)->submit();

	Assert::same(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:batchGenerateContent',
		$http->lastRequest()['url'],
	);

	// requests.requests is the double nesting the API insists on
	$requests = $http->lastPayload()['batch']['inputConfig']['requests']['requests'];
	Assert::count(2, $requests);
	Assert::same('first', $requests[0]['metadata']['key']);
	Assert::same('Question one', $requests[0]['request']['contents'][0]['parts'][0]['text']);
	Assert::same('second', $requests[1]['metadata']['key']);

	// the nested request repeats the model, as countTokens has to as well
	Assert::same('models/gemini-3.5-flash-lite', $requests[0]['request']['model']);

	Assert::same('batches/abc', $response->getId());
});


test('listing survives an empty response, which is what the API sends', function () {
	// a project with no batches answers {} - no operations key at all
	$http = (new FakeHttpClient)->queue([]);
	Assert::same([], (new Client('key', $http))->listBatches());

	$http = (new FakeHttpClient)->queue(['operations' => [
		['name' => 'batches/one', 'metadata' => ['state' => 'BATCH_STATE_RUNNING']],
		['name' => 'batches/two', 'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED']],
	]]);
	$batches = (new Client('key', $http))->listBatches(limit: 10);

	Assert::count(2, $batches);
	Assert::same('batches/one', $batches[0]->getId());
	Assert::same(Status::Completed, $batches[1]->getStatus());
	Assert::contains('pageSize=10', $http->lastRequest()['url']);
});


test('cancel posts to the batch and reports success', function () {
	$http = (new FakeHttpClient)->queue([]);
	Assert::true((new Client('key', $http))->cancelBatch('abc'));

	Assert::contains('/batches/abc:cancel', $http->lastRequest()['url']);
	// an empty array still makes it a POST rather than a GET
	Assert::same([], $http->lastRequest()['payload']);
});


test('one batch runs one model', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addChat('gemini-3.5-flash-lite', 'a')->addMessage('x', Role::User);
	$batch->addChat('gemini-3.6-flash', 'b')->addMessage('y', Role::User);

	Assert::exception(
		fn() => $batch->submit(),
		AIAccess\LogicException::class,
		'%a%single model%a%',
	);
});


test('a duplicate custom id is refused', function () {
	$batch = (new Client('key', new FakeHttpClient))->createBatch();
	$batch->addChat('gemini-3.5-flash-lite', 'same');

	Assert::exception(
		fn() => $batch->addChat('gemini-3.5-flash-lite', 'same'),
		AIAccess\LogicException::class,
		"Chat with custom ID 'same' already exists in this batch.",
	);
});


test('states map onto the shared status enum', function () {
	$cases = [
		'BATCH_STATE_PENDING' => Status::InProgress,
		'BATCH_STATE_RUNNING' => Status::InProgress,
		'BATCH_STATE_SUCCEEDED' => Status::Completed,
		'BATCH_STATE_FAILED' => Status::Failed,
		'BATCH_STATE_CANCELLED' => Status::Failed,
		'BATCH_STATE_EXPIRED' => Status::Failed,
		'BATCH_STATE_UNSPECIFIED' => Status::Other,
	];

	foreach ($cases as $state => $expected) {
		$http = (new FakeHttpClient)->queue(['name' => 'batches/abc', 'metadata' => ['state' => $state]]);
		$response = (new Client('key', $http))->retrieveBatch('abc');
		Assert::same($expected, $response->getStatus(), $state);
	}
});


test('results and per-item errors come out of the inline output', function () {
	$http = (new FakeHttpClient)->queue([
		'name' => 'batches/abc',
		'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED', 'createTime' => '2026-08-04T10:00:00Z'],
		'response' => ['inlinedResponses' => ['inlinedResponses' => [
			[
				'metadata' => ['key' => 'first'],
				'response' => ['candidates' => [['content' => ['parts' => [['text' => 'Answer one']]], 'finishReason' => 'STOP']]],
			],
			[
				'metadata' => ['key' => 'second'],
				'error' => ['code' => 400, 'message' => 'Prompt was blocked'],
			],
		]]],
	]);

	$response = (new Client('key', $http))->retrieveBatch('batches/abc');

	Assert::same(Status::Completed, $response->getStatus());
	$results = iterator_to_array($response->getResults());
	Assert::count(2, $results);
	Assert::same('Answer one', $results['first']->message->getText());
	Assert::null($results['second']->message);
	Assert::same('Prompt was blocked', $results['second']->error);
	Assert::same('2026-08-04', $response->getCreatedAt()->format('Y-m-d'));
});


test('an item carrying neither a result nor an error does not vanish', function () {
	$http = (new FakeHttpClient)->queue([
		'name' => 'batches/abc',
		'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED'],
		'response' => ['inlinedResponses' => ['inlinedResponses' => [
			['metadata' => ['key' => 'nothing']],
		]]],
	]);

	$results = iterator_to_array((new Client('key', $http))->retrieveBatch('batches/abc')->getResults());

	Assert::null($results['nothing']->message);
	Assert::same('The response carries neither a result nor an error.', $results['nothing']->error);
});


test('a cancelled batch still hands over the requests it finished', function () {
	$http = (new FakeHttpClient)->queue([
		'name' => 'batches/abc',
		'metadata' => ['state' => 'BATCH_STATE_CANCELLED'],
		'response' => ['inlinedResponses' => ['inlinedResponses' => [
			['metadata' => ['key' => 'first'], 'response' => ['candidates' => [['content' => ['parts' => [['text' => 'done']]]]]]],
		]]],
	]);

	$response = (new Client('key', $http))->retrieveBatch('batches/abc');

	// the work was done and billed before the cancellation landed
	Assert::same(Status::Failed, $response->getStatus());
	Assert::same('done', iterator_to_array($response->getResults())['first']->message->getText());
});


test('a job that failed outright has no output to read', function () {
	$job = ['name' => 'batches/abc', 'metadata' => ['state' => 'BATCH_STATE_FAILED']];
	$http = (new FakeHttpClient)->queue($job)->queue($job);

	$response = (new Client('key', $http))->retrieveBatch('batches/abc');

	Assert::same([], iterator_to_array($response->getResults()));
});


test('a batch keeping its results in a file says so instead of yielding nothing', function () {
	// the refetch answers with a file reference this client cannot read
	$job = ['name' => 'batches/abc', 'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED']];
	$http = (new FakeHttpClient)->queue($job)->queue($job + ['response' => ['responsesFile' => 'files/out']]);

	$response = (new Client('key', $http))->retrieveBatch('batches/abc');

	Assert::exception(
		fn() => iterator_to_array($response->getResults()),
		AIAccess\UnexpectedResponseException::class,
		'The batch keeps its results in a file, which is not supported yet.',
	);
});


test('a listed batch fetches its results, because listing leaves them out', function () {
	$listed = ['name' => 'batches/abc', 'metadata' => ['state' => 'BATCH_STATE_SUCCEEDED'], 'done' => true];
	$full = $listed + ['response' => ['inlinedResponses' => ['inlinedResponses' => [
		['metadata' => ['key' => 'first'], 'response' => ['candidates' => [['content' => ['parts' => [['text' => 'Paris']]]]]]],
	]]]];

	$http = (new FakeHttpClient)->queue(['operations' => [$listed]])->queue($full);
	$batches = (new Client('key', $http))->listBatches();

	Assert::same(Status::Completed, $batches[0]->getStatus());
	Assert::same('Paris', iterator_to_array($batches[0]->getResults())['first']->message->getText());

	// the second request is the one that went for the results
	Assert::same(2, $http->count());
	Assert::contains('/batches/abc', $http->lastRequest()['url']);
});


test('an unfinished batch yields nothing', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'batches/abc', 'metadata' => ['state' => 'BATCH_STATE_RUNNING']]);
	$response = (new Client('key', $http))->retrieveBatch('abc');

	Assert::same([], iterator_to_array($response->getResults()));
});


test('an id works with or without the batches/ prefix', function () {
	$http = (new FakeHttpClient)->queue(['name' => 'batches/abc'])->queue(['name' => 'batches/abc']);
	$client = new Client('key', $http);

	$client->retrieveBatch('abc');
	$bare = $http->lastRequest()['url'];
	$client->retrieveBatch('batches/abc');

	Assert::same($bare, $http->lastRequest()['url']);
	Assert::contains('/batches/abc', $bare);
});
