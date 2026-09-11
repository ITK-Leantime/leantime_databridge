<?php

namespace Leantime\Plugins\Databridge\Exceptions;

use Leantime\Plugins\Databridge\Model\Operation;

/**
 * Thrown when an authenticated API user lacks the operation required by an endpoint.
 */
class OperationNotGrantedException extends \Exception
{
    /**
     * @param  string    $userName  Display name of the API user, for logging.
     * @param  Operation $operation The operation the endpoint requires.
     */
    public function __construct(
        public readonly string $userName,
        public readonly Operation $operation,
    ) {
        parent::__construct(sprintf('API user "%s" is not granted the "%s" operation.', $userName, $operation->value));
    }
}
