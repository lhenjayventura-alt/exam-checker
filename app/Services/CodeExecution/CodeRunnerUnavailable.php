<?php

namespace App\Services\CodeExecution;

use RuntimeException;

/**
 * The sandbox could not run the code (daemon down, image missing, host error). This is an
 * infrastructure problem and must never be scored as an incorrect answer.
 */
class CodeRunnerUnavailable extends RuntimeException {}
