<?php

namespace Leantime\Plugins\Databridge\Exceptions;

/**
 * Thrown when a request body field fails validation.
 *
 * The message is written for the API consumer and is safe to return verbatim
 * in a 400 response.
 */
class InvalidInputException extends \Exception {}
