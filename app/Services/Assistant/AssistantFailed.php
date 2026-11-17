<?php

namespace App\Services\Assistant;

use RuntimeException;

/**
 * The assistant couldn't answer. The message is safe to show to the person.
 */
class AssistantFailed extends RuntimeException {}
