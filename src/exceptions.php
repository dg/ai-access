<?php declare(strict_types=1);

/**
 * This file is part of the AI Access library.
 * Copyright (c) 2024 David Grudl (https://davidgrudl.com)
 */

namespace AIAccess;


/**
 * Invalid method parameters or library state.
 */
class LogicException extends \Exception
{
}

/**
 * Error occurred during AI service communication.
 */
class ServiceException extends \Exception
{
}

/**
 * AI provider returned an error response.
 */
class ApiException extends ServiceException
{
}

/**
 * Failed to communicate with the API or parse the response.
 */
class CommunicationException extends ServiceException
{
}

/**
 * API response has an unexpected structure.
 */
class UnexpectedResponseException extends ServiceException
{
}
