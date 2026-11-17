<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * A tool couldn't do what the model asked. The message goes back to the model so it can try again.
 */
class ToolError extends RuntimeException {}
