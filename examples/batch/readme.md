# Batch processing

When you do not need the answer now, you can hand the provider a pile of
requests and collect them later for roughly half the price. Typical uses:
classifying a backlog, translating a catalogue, generating summaries overnight.

Supported by OpenAI and Claude. Under the hood the two work very differently:
OpenAI wants a JSONL file uploaded first and then a job pointing at it, Claude
takes the requests inline. You write the same code either way.

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
run from cron. Only `Status::Completed` means the results are ready.

## results.php

Answers come back keyed by the custom id you assigned, not in the order you sent
them, so always look them up by id.

Individual requests can fail without failing the batch. Those do not throw:
they are listed by `getErrors()`, again keyed by custom id, so you can retry
just the ones that need it.
