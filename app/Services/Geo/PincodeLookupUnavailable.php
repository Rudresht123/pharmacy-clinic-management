<?php

namespace App\Services\Geo;

use RuntimeException;

/**
 * India Post could not be asked, or answered with something unreadable.
 *
 * Distinct from "no such PIN code", which is an answer. This is the absence
 * of one, and the two must not be confused: a PIN code that fails to resolve
 * because the service is down is not a bad PIN code, and remembering it as
 * one would refuse a real address for a month.
 */
class PincodeLookupUnavailable extends RuntimeException {}
