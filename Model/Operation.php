<?php

namespace Leantime\Plugins\Databridge\Model;

/**
 * Operations that can be granted to a Databridge API user.
 */
enum Operation: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';
}
