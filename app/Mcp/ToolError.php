<?php

namespace App\Mcp;

use RuntimeException;

/**
 * Something a tool couldn't do, in words for the agent (and the person
 * reading over its shoulder). Becomes a tool result with isError set,
 * not a protocol error, so the model can read it and try something else.
 */
class ToolError extends RuntimeException {}
