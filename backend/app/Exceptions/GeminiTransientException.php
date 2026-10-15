<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown internally by GeminiService when the Google API answers with a
 * transient status (429 quota, 500/502/503/504 overload) that may clear in a
 * few seconds. The service catches it to retry the same key with a backoff
 * before falling through to the next key.
 */
class GeminiTransientException extends RuntimeException
{
}