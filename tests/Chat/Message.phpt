<?php declare(strict_types=1);

use AIAccess\Chat\Message;
use AIAccess\Chat\Role;
use AIAccess\Chat\TextPart;
use AIAccess\Media;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('a plain string becomes a single text part', function () {
	$message = new Message('Hello', Role::User);

	Assert::same('Hello', $message->getText());
	Assert::same(Role::User, $message->getRole());
	Assert::count(1, $message->getParts());
	Assert::type(TextPart::class, $message->getParts()[0]);
	Assert::true($message->isTextOnly());
});


test('a list of strings and parts is normalized', function () {
	$media = Media::fromBinary('binary', 'image/png');
	$message = new Message(['First', new TextPart('Second'), $media], Role::User);

	Assert::count(3, $message->getParts());
	Assert::same("First\nSecond", $message->getText());
	Assert::same($media, $message->getParts()[2]);
	Assert::false($message->isTextOnly());
});


test('a message without text parts has empty text', function () {
	$message = new Message(Media::fromBinary('binary', 'image/png'), Role::User);

	Assert::same('', $message->getText());
	Assert::count(1, $message->getParts());
});


test('getMedia picks the media out and skips everything else', function () {
	$image = Media::fromBinary('binary', 'image/png');
	$message = new Message(['here it is', $image], Role::Model);

	Assert::same([$image], $message->getMedia());
	Assert::same('here it is', $message->getText());
	Assert::same([], (new Message('just text', Role::Model))->getMedia());
});


test('an unsupported content type is rejected', function () {
	Assert::exception(
		fn() => new Message([123], Role::User),
		AIAccess\LogicException::class,
		'Message content must be a string or a Part, int given.',
	);
});


test('roles are backed by the library own names', function () {
	Assert::same('user', Role::User->value);
	Assert::same('model', Role::Model->value);
	Assert::same(Role::User, Role::from('user'));
});
