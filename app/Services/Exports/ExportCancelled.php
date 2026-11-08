<?php

namespace App\Services\Exports;

use RuntimeException;

/** Thrown from inside the writing of a file when the person cancelled it, to stop at the next row. */
final class ExportCancelled extends RuntimeException {}
