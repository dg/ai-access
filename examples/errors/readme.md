# Error handling

This is the only place in the examples with a `try`/`catch`. Everywhere else
exceptions are left to bubble, because in a CLI script the stack trace is more
useful than a message you invented.

```shell
php examples/errors/handling.php claude
```

## handling.php

The hierarchy is organised around one question: what can the caller do about it?

```
ServiceException                  base for everything the service can throw
├── ApiException                  the API returned an error, getCode() has the HTTP status
├── CommunicationException        network failure or unparseable response, retrying may help
└── UnexpectedResponseException   the response structure changed, log and investigate
LogicException                    a bug in your code, fix it during development
```

`LogicException` sits outside the tree on purpose. Sending a chat with no
messages, or asking for embeddings with an empty array, is not something the
service did to you; catching it in production would only hide the mistake.

The example triggers two of these on purpose: a made-up model name, which the
provider rejects, and a `sendMessage()` on an empty conversation.

```
Output (will vary):
ApiException (HTTP 404): model: no-such-model-2026
LogicException: Cannot send request with empty message history.
```
