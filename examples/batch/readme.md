# Batch processing

When you do not need the answer now, you can hand the provider a pile of
requests and collect them later for roughly half the price. Typical uses:
classifying a backlog, translating a catalogue, generating summaries overnight.

Supported by OpenAI, Claude and Gemini. Under the hood all three work very
differently: OpenAI wants a JSONL file uploaded first and then a job pointing at
it, Claude takes the requests inline, and Gemini runs the whole thing as a
long-running operation on a single model. You write the same code either way.

```shell
php examples/batch/submit.php openai
php examples/batch/status.php openai batch_abc123
php examples/batch/results.php openai batch_abc123
```

## submit.php

Builds three independent chats, each with its own custom id, and queues them.
`submit()` returns immediately with a batch id. Store that id; it is the only
thing connecting you to the results.

Each chat is configured exactly like an interactive one, system instruction
and options included.

## status.php

Batches finish in minutes or in hours, so checking is a separate step, usually
run from cron. `Status::Completed` means the whole job went through, but a
cancelled or expired one is worth reading too: the requests it managed to
finish are done and paid for.

## results.php

Answers come back keyed by the custom id you assigned, not in the order you sent
them, so always look them up by id. They are read one at a time as they arrive,
so the size of the job does not decide whether it fits in memory.

Individual requests can fail without failing the batch. Those do not throw
either: such an item simply arrives with `$result->error` filled in instead of
`$result->message`, so you can retry just the ones that need it.

A batch of pictures reads through the very same script: a drawn answer is still
a message, its images sit in `getMedia()` instead of `getText()`, and this
example saves them to the temp directory. Queue one with
[examples/images/batch.php](../images/batch.php).
