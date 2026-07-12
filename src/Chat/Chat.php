<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess\Chat;
use AIAccess\LogicException;
use AIAccess\ServiceException;
use AIAccess\TooManyRoundsException;
use function array_key_exists, array_reverse, array_slice, count, is_array, is_bool, is_float, is_int, is_scalar, is_string;


/**
 * Conversation.
 */
abstract class Chat
{
	/** @var list<Message> */
	protected array $messages = [];
	protected ?string $systemInstruction = null;
	protected ?Effort $effort = null;

	/** @var array<string, Tool> */
	protected array $tools = [];
	protected ?string $toolChoice = null;
	private int $maxRounds = 8;
	private bool $catchToolErrors = false;
	private ?Usage $totalUsage = null;


	/**
	 * Sends the next message to the model or continues generation based on history.
	 * Runs the tool loop on its own when every requested tool has a handler.
	 * @param  string|Part|list<string|Part>|null  $message
	 * @param  ?\Closure(Response): ?string  $validate  returning a string sends it back as
	 *         another user message, so the model can correct itself; null accepts the answer.
	 *         Not consulted while the answer still waits for tool results the caller handles itself.
	 * @param  ?\Closure(string): (bool|null)  $onStream  receives the answer in pieces as it is
	 *         written; returning false stops the generation
	 * @throws ServiceException
	 */
	public function sendMessage(
		string|Part|array|null $message = null,
		?\Closure $validate = null,
		?\Closure $onStream = null,
	): Response
	{
		$save = $this->messages;
		if ($message !== null) {
			$this->addMessage($message, Role::User);
		}

		$round = 0;
		$response = null;
		$toolChoice = $this->toolChoice;
		try {
			while (true) {
				if ($round++ >= $this->maxRounds) {
					throw new TooManyRoundsException("The conversation did not settle within $this->maxRounds rounds.", $response);
				}

				try {
					// tool rounds stream too; the loop simply runs one stream per round
					$response = $onStream === null
						? $this->generateResponse()
						: $this->generateStreamResponse(fn(Delta $delta) => $delta->type === DeltaType::Text
							? $onStream($delta->text)
							: null);
				} catch (\Throwable $e) {
					// rolling back rounds that already happened would throw away paid-for work
					// and tool side effects, so only an unanswered first round is undone
					if ($round === 1) {
						$this->messages = $save;
					}
					throw $e;
				}

				// a turn with no text but with reasoning or tool calls still belongs in the history
				$turn = $response->getMessage();
				if ($turn->getParts()) {
					$this->messages[] = $turn;
				}
				if ($usage = $response->getUsage()) {
					$this->totalUsage = $this->totalUsage?->add($usage) ?? $usage;
				}

				// the caller stopped the stream, so running tools from a half-read answer and
				// asking again is the opposite of what they asked for; when the loop owns the
				// exchange, every call still needs an answer, or the history could never be
				// continued - a caller running tools by hand keeps that responsibility
				if ($response->getFinishReason() === FinishReason::Cancelled) {
					$calls = $response->getToolCalls();
					if ($calls && $this->canRunTools($calls)) {
						foreach ($calls as $call) {
							$this->addToolResult($call, 'The tool was not run because the generation was cancelled.', isError: true);
						}
					}
					return $response;
				}

				if ($calls = $response->getToolCalls()) {
					if (!$this->canRunTools($calls)) {
						// the caller answers these itself; validating a turn that still waits
						// for tool results would only corrupt the exchange
						return $response;
					}
					$this->runTools($calls);
					// a forced tool has been called now; keeping the force up would demand it
					// again in every round and the loop could never settle
					$this->toolChoice = null;
					continue;
				}

				if ($validate !== null && ($feedback = $validate($response)) !== null) {
					$this->addMessage($feedback, Role::User);
					continue;
				}

				return $response;
			}
		} finally {
			$this->toolChoice = $toolChoice;
		}
	}


	/**
	 * Sends a message and returns the answer as a stream you can read while it is written.
	 * Nothing is sent until you start reading it.
	 * @param  string|Part|list<string|Part>|null  $message
	 * @param  ?\Closure(Response): ?string  $validate
	 */
	public function sendMessageStream(string|Part|array|null $message = null, ?\Closure $validate = null): TextStream
	{
		return new TextStream(fn(\Closure $emit) => $this->sendMessage($message, $validate, $emit));
	}


	/**
	 * Configures the automatic tool loop. The round limit covers tool rounds and
	 * validate() iterations together.
	 */
	public function setToolLoop(int $maxRounds = 8, bool $catchErrors = false): static
	{
		if ($maxRounds < 1) {
			throw new LogicException('At least one round is needed.');
		}
		$this->maxRounds = $maxRounds;
		$this->catchToolErrors = $catchErrors;
		return $this;
	}


	/**
	 * Tokens spent by this conversation so far, summed over every round.
	 */
	public function getTotalUsage(): ?Usage
	{
		return $this->totalUsage;
	}


	/**
	 * The loop only runs while the caller has nothing to do: some tool must have a handler,
	 * and no requested tool may be one the caller wants to answer itself.
	 * @param  list<ToolCallPart>  $calls
	 */
	private function canRunTools(array $calls): bool
	{
		$handlers = false;
		foreach ($this->tools as $tool) {
			$handlers = $handlers || $tool->handler !== null;
		}
		if (!$handlers) {
			return false;
		}

		foreach ($calls as $call) {
			$tool = $this->tools[$call->name] ?? null;
			if ($tool !== null && $tool->handler === null) {
				return false;
			}
		}
		return true;
	}


	/**
	 * Answers every call of the round. When a handler blows up, the remaining calls still get
	 * an error result before the exception continues - a round answered halfway would make
	 * every following request invalid, with no public API to mend it.
	 * @param  list<ToolCallPart>  $calls
	 */
	private function runTools(array $calls): void
	{
		foreach ($calls as $i => $call) {
			try {
				[$result, $isError] = $this->executeTool($call);
			} catch (\Throwable $e) {
				foreach (array_slice($calls, $i) as $unanswered) {
					$this->addToolResult($unanswered, 'The tool run was interrupted by an error.', isError: true);
				}
				throw $e;
			}
			$this->addToolResult($call, $result, $isError);
		}
	}


	/**
	 * A mistake the model can fix - an invented tool, unreadable arguments, arguments that
	 * do not match the schema - goes back to it as an error result rather than an exception.
	 * @return array{string|mixed[], bool}
	 */
	private function executeTool(ToolCallPart $call): array
	{
		$tool = $this->tools[$call->name] ?? null;
		if ($tool === null) {
			return ["Unknown tool '$call->name'.", true];
		} elseif ($tool->handler === null) {
			return ["Tool '$call->name' has no handler.", true];
		} elseif ($call->argumentsError !== null) {
			return [$call->argumentsError, true];
		} elseif ($error = $this->checkArguments($tool, $call->arguments)) {
			return [$error, true];
		}

		try {
			$result = ($tool->handler)($call->arguments, $call);
		} catch (\Throwable $e) {
			// a failed tool is the model's business, but an \Error is a bug in the handler;
			// feeding that to the model would hide it behind a plausible-looking conversation
			if (!$this->catchToolErrors || $e instanceof \Error) {
				throw $e;
			}
			return [$e->getMessage(), true];
		}

		return match (true) {
			is_array($result), is_string($result) => [$result, false],
			is_scalar($result), $result === null => [(string) $result, false],
			default => throw new LogicException("Tool '$call->name' returned " . get_debug_type($result) . ', expected a string or an array.'),
		};
	}


	/**
	 * Required keys and scalar types only; a full JSON Schema validator is not worth
	 * a dependency when the model gets the error message anyway.
	 * @param  mixed[]  $arguments
	 */
	private function checkArguments(Tool $tool, array $arguments): ?string
	{
		foreach ($tool->parameters['required'] ?? [] as $name) {
			if (!array_key_exists($name, $arguments)) {
				return "Missing required argument '$name'.";
			}
		}

		foreach ($tool->parameters['properties'] ?? [] as $name => $spec) {
			if (!array_key_exists($name, $arguments) || !isset($spec['type'])) {
				continue;
			}
			$valid = match ($spec['type']) {
				'string' => is_string($arguments[$name]),
				'integer' => is_int($arguments[$name]),
				'number' => is_int($arguments[$name]) || is_float($arguments[$name]),
				'boolean' => is_bool($arguments[$name]),
				'array', 'object' => is_array($arguments[$name]),
				default => true,
			};
			if (!$valid) {
				return "Argument '$name' must be of type {$spec['type']}, " . get_debug_type($arguments[$name]) . ' given.';
			}
		}
		return null;
	}


	/**
	 * Adds a message to the chat history without sending it to the API.
	 * @param  string|Part|list<string|Part>  $message
	 */
	public function addMessage(string|Part|array $message, Role $role): Message
	{
		return $this->messages[] = new Message($message, $role);
	}


	/**
	 * Retrieves the current message history (user and model messages).
	 * @return list<Message>
	 */
	public function getMessages(): array
	{
		return $this->messages;
	}


	/**
	 * Sets a system-level instruction for the model.
	 */
	public function setSystemInstruction(string $instruction): static
	{
		$this->systemInstruction = $instruction;
		return $this;
	}


	/**
	 * Sets how much the model should reason before answering. Null keeps the provider's default.
	 */
	public function setEffort(?Effort $effort): static
	{
		$this->effort = $effort;
		return $this;
	}


	/**
	 * Registers a function the model may call.
	 */
	public function addTool(Tool $tool): static
	{
		$this->tools[$tool->name] = $tool;
		return $this;
	}


	/**
	 * Forces the model to call the named tool. Null leaves the choice to the model;
	 * to forbid tools entirely, register none.
	 */
	public function setToolChoice(?string $name): static
	{
		if ($name !== null && !isset($this->tools[$name])) {
			throw new LogicException("Unknown tool '$name'.");
		}
		$this->toolChoice = $name;
		return $this;
	}


	/**
	 * Answers a tool call. Results are gathered into a single message, because Claude
	 * rejects parallel results spread over several turns; merging replaces the previous
	 * Tool message, so do not hold on to an earlier return value.
	 *
	 * Pass the ToolCallPart itself where a provider sends no call ids, as Gemini usually does.
	 * @param  string|mixed[]  $result
	 */
	public function addToolResult(ToolCallPart|string $call, string|array $result, bool $isError = false): Message
	{
		$part = new ToolResultPart(
			$call instanceof ToolCallPart ? $call->callId : $call,
			$call instanceof ToolCallPart ? $call->name : $this->findToolCall($call)->name,
			$result,
			$isError,
		);

		$last = $this->messages[count($this->messages) - 1] ?? null;
		return $last?->getRole() === Role::Tool
			? $this->messages[count($this->messages) - 1] = new Message([...$last->getParts(), $part], Role::Tool)
			: $this->messages[] = new Message($part, Role::Tool);
	}


	private function findToolCall(string $callId): ToolCallPart
	{
		// backwards, because an id is unique only within a turn: Gemini often sends none at
		// all, so a search from the front would answer a call settled several rounds ago
		foreach (array_reverse($this->messages) as $message) {
			foreach (array_reverse($message->getParts()) as $part) {
				if ($part instanceof ToolCallPart && $part->callId === $callId) {
					return $part;
				}
			}
		}
		throw new LogicException("No tool call '$callId' in the history.");
	}


	/**
	 * Removes all messages from the history. Tokens already spent stay in getTotalUsage().
	 */
	public function clearMessages(): static
	{
		$this->messages = [];
		return $this;
	}


	/**
	 * Generates the next response based on the current chat history and settings.
	 */
	abstract protected function generateResponse(): Response;


	/**
	 * The same, but reporting pieces of the answer through $onDelta as they arrive.
	 * The callback returning false stops the generation.
	 * @param  \Closure(Delta): (bool|null)  $onDelta
	 */
	abstract protected function generateStreamResponse(\Closure $onDelta): Response;
}
