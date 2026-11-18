<?php

namespace App\Services\LegacyImport;

use RuntimeException;

/** Something in the old data can't be carried over exactly; nothing from the run is kept. */
class LegacyImportException extends RuntimeException {}
