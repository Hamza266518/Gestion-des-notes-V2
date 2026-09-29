<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Gemini API cannot be reached at all (TLS, DNS, timeout).
 * Callers must surface this as a connectivity error instead of pretending
 * the uploaded document was unreadable.
 */
class GeminiTransportException extends RuntimeException
{
}
