# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Documentation

Any distilled, agent-facing documentation for this package - how it works
internally and the rationale behind key design decisions - lives in `docs/`.
Consult it before non-trivial changes; it is the source of truth from which the
public manual is distilled.

## Project Overview

**AIAccess** is a unified PHP library providing a consistent interface for accessing multiple AI model providers (OpenAI, Anthropic Claude, Google Gemini, DeepSeek, and Grok/xAI). The library abstracts provider-specific differences, allowing developers to write AI integration code once and switch providers with minimal changes.

**Key Features:**
- Single unified API across multiple providers
- Support for Chat, Batch processing, and Embeddings
- Modern PHP 8.3+ with strict types throughout
- No vendor SDK dependencies (uses native HTTP client)

## Development Commands

### Testing
```bash
# Run all tests
composer run tester

# Run specific test file
vendor/bin/tester tests/Chat/Chat.phpt -s -C

# Run tests in specific directory
vendor/bin/tester tests/Chat/ -s -C
```

### Static Analysis
```bash
# Run PHPStan (level 8)
composer run phpstan
```

### Installation
```bash
# Install dependencies
composer install
```

## Architecture

### Provider Abstraction Pattern

The library uses **interface-based abstraction** to standardize behavior across different AI providers:

```
AIAccess\Chat\Service (interface)
  ├── OpenAI\Client
  ├── Claude\Client
  ├── Gemini\Client
  ├── DeepSeek\Client
  ├── Grok\Client
  └── OpenAICompatible\Client   # generic, for any endpoint speaking that dialect
```

Each provider implements core interfaces:
- `Chat\Service` - Creates chat sessions
- `Batch\Service` - Optional batch processing (OpenAI, Claude only)
- `Embedding\Service` - Optional embeddings (OpenAI, Gemini only)
- `Http\Client` - HTTP transport abstraction (default: CurlClient)

### Abstract Base Class Pattern

`AIAccess\Chat\Chat` is an abstract class that defines the conversation contract:
- Message history management via `addMessage()` and `getMessages()`
- System instructions via `setSystemInstruction()`
- Common `sendMessage()` flow with error recovery and the automatic tool loop
- Tool registration via `addTool()`, `setToolChoice()`, `setToolLoop()`
- Abstract `generateResponse()` for provider-specific implementation

A `Message` is a `Role` plus a list of `Part`s: `TextPart`, `ReasoningPart`,
`ToolCallPart`, `ToolResultPart` and `Media`. A plain string becomes a single
`TextPart`, so text-only code is unaffected. `Part`s that carry a provider tag
and a raw payload (reasoning, tool calls) are replayed **only** to the provider
that issued them.

Each provider extends this: `OpenAI\Chat`, `Claude\Chat`, `Gemini\Chat`, etc.

### Directory Structure

```
src/
├── Batch/              # Batch processing abstractions
├── Chat/               # Core chat abstractions (Chat, Response, Message, Role, etc.)
├── Embedding/          # Text embeddings support
├── Http/               # HTTP transport layer (Client, CurlClient, Response)
├── Provider/           # Provider-specific implementations
│   ├── OpenAI/
│   ├── Claude/
│   ├── Gemini/
│   ├── DeepSeek/
│   └── Grok/
├── Helpers.php         # Internal utilities (JSON encoding/decoding)
└── exceptions.php      # Exception hierarchy
```

### Exception Hierarchy

Exception design focuses on **recovery strategy**:

```
ServiceException                    (Base for all service errors)
├── ApiException                   (API returned error response - may be retriable)
├── CommunicationException         (Network or parse errors - potentially retriable)
├── UnexpectedResponseException    (Response structure mismatch - not retriable)
└── TooManyRoundsException         (Tool/validation loop hit its round limit)

LogicException                      (Programming errors - fix during development)
IOException                         (Local file cannot be read or written; extends \RuntimeException)
```

**Error Handling Pattern:**
- `ApiException`: API explicitly returned an error (rate limits, validation, etc.)
- `CommunicationException`: Network issues or invalid JSON - retry may help
- `UnexpectedResponseException`: Response doesn't match expected schema - log and investigate
- `TooManyRoundsException`: carries `$lastResponse`; the history keeps every finished round, so the conversation can continue
- `LogicException`: Invalid parameters or wrong method call order - fix in development
- `IOException`: local filesystem problem (`Media::fromFile()`/`save()`, batch upload), deliberately outside the `ServiceException` tree

## Code Organization Principles

### Flat Structure Within Categories
All entities in single namespace with clear suffixes (`ChatResponse`, `BatchResponse`, `Vector`). Avoids deep nesting; prefers descriptive class names.

### Provider Parallelism
Each provider directory (`OpenAI/`, `Claude/`, etc.) has identical structure:
- `Client.php` - API communication and service factory
- `Chat.php` - Conversation state management
- `ChatResponse.php` - Response parsing and transformation
- `Batch.php` - Batch request container (if supported)
- `BatchResponse.php` - Batch response parsing (if supported)

This makes adding new providers straightforward: copy and adapt.

### Single Responsibility
- `Client` - API communication, service factory, HTTP handling
- `Chat` - Conversation state, message history
- `ChatResponse` - Response parsing, data transformation
- Clean separation enables easier testing and maintenance

## Testing with Nette Tester

Tests use `.phpt` extension and follow this structure:

```php
<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

test('Description of what is being tested', function () {
	$object = new SomeClass();
	$result = $object->doSomething();

	Assert::same('expected value', $result);
});
```

**Key Points:**
- Use `test()` function for each test case
- First parameter is a clear description (no need for separate comments)
- Group related tests in same file
- Use `testException()` when entire test expects an exception
- Tests mirror `src/` structure in `tests/` directory

**Testing Exceptions:**
```php
Assert::exception(
    fn() => $mapper->getAsset('missing.txt'),
    AssetNotFoundException::class,
    "Expected error message pattern %a%",
);
```

**Available in tests:**
- Mockery for mocking (`mockery/mockery`)
- DG\BypassFinals for testing final classes
- Tracy for debugging

## Coding Standards

### PHP Standards
- **Strict types required:** Every file must have `declare(strict_types=1)`
- **PHP 8.3+ features:** Use enums, readonly properties, union types, match expressions
- **Full type hints:** All properties, parameters, and return types must be typed
- **Modern syntax:** Prefer concise expressions (e.g., `if (is_array($response['data'] ?? null))`)

### Documentation
- PHPDoc on all public methods
- Document non-obvious behavior or surprising edge cases
- No need to document obvious getters/setters
- Type hints are primary documentation source

## Feature Support Matrix

What AIAccess implements today:

| Feature | OpenAI | Claude | Gemini | DeepSeek | Grok |
|---------|--------|--------|--------|----------|------|
| Chat | ✓ | ✓ | ✓ | ✓ | ✓ |
| Reasoning effort | ✓ | ✓ | ✓ | ✓ | ✓ |
| Tool calling | ✓ | ✓ | ✓ | ✓ | ✓ |
| Image input | ✓ | ✓ | ✓ | - | ✓ |
| Document input | ✓ | ✓ | ✓ | - | - |
| Structured output | ✓ | ✓ | ✓ | - | ✓ |
| Image generation | ✓ | - | - | - | ✓ |
| Batch | ✓ | ✓ | ✓ | - | - |
| Embeddings | ✓ | - | ✓ | - | - |
| List of models | ✓ | ✓ | ✓ | ✓ | ✓ |

Gemini batch needs a **billing-enabled Google project**: on the free tier the
endpoint answers `FAILED_PRECONDITION`. Verified against a paid account.

Do not read a dash as "the provider cannot do this". xAI has batch and Files
APIs that are not wrapped yet. Anthropic genuinely has no embedding endpoint,
DeepSeek has neither batch nor embeddings and no vision model, and Grok takes
images but not documents.

`OpenAICompatible` has no column because it has no fixed answer: it is a chat client for
any endpoint speaking `chat/completions`, so what works is decided by that endpoint, not
by this library. Chat, streaming and tool calling are what it sends; everything else
depends on who answers.

## Provider-Specific Options

Each provider's `Chat` class implements `setOptions()` with provider-specific parameters. Common options:

- **temperature**: Controls randomness (ranges vary by provider)
- **maxOutputTokens**: Maximum tokens in response (same name on every provider,
  whatever the wire calls it)
- **topP**: Nucleus sampling threshold
- **stopSequences/stop**: Strings that halt generation

Refer to individual provider classes in `src/Provider/*/Chat.php` for complete option sets.

## Key Implementation Notes

### HTTP Client Abstraction
Default HTTP client is `CurlClient`, but can be swapped by implementing `Http\Client` interface. All providers use this abstraction for API communication.

### Batch Processing
Batch API completely abstracts provider differences:
- **OpenAI**: Library formats requests to JSONL, uploads file, creates batch job
- **Claude**: Library sends chat payloads directly in batch creation request
- **Unified workflow:** `createBatch()` → `addChat()` → `submit()` → `retrieveBatch()` → `getMessages()`

### Embeddings
- Returns array of `Embedding\Vector` objects
- Each `Vector` includes `serialize()`/`deserialize()` for efficient storage
- Built-in `cosineSimilarity()` method for comparing vectors

## Common Development Patterns

### Adding a New Provider
1. Create `src/Provider/NewProvider/` directory
2. Implement `Client.php` implementing the service interfaces it supports
3. Implement `Chat.php` extending `AIAccess\Chat\Chat`
4. Implement `ChatResponse.php` implementing `Chat\Response`
5. Add tests in `tests/Provider/NewProvider/`
6. Update README.md with initialization example

### Error Handling in Provider Code
```php
try {
    // API call
} catch (JsonException $e) {
    throw new CommunicationException('Failed to parse response', 0, $e);
} catch (/* network error */) {
    throw new CommunicationException('Network error', 0, $e);
}

// Check for API errors
if (isset($data['error'])) {
    throw new ApiException($data['error']['message'], $statusCode);
}

// Validate response structure
if (!isset($data['expected_field'])) {
    throw new UnexpectedResponseException('Missing expected field');
}
```

### Implementing Chat Methods
Provider-specific `Chat` classes must:
- Extend `AIAccess\Chat\Chat`
- Implement `generateResponse(): Response`
- Use `$this->getMessages()` for conversation history
- Use `$this->systemInstruction` if set
- Apply options from `$this->options` array
- Return provider-specific `ChatResponse` implementation

## Development Workflow

1. **Make changes** to `src/` files
2. **Run tests** with `composer run tester`
3. **Run static analysis** with `composer run phpstan`
4. **Update README.md** if adding new features or changing API
5. **Add tests** for new functionality in corresponding `tests/` directory
