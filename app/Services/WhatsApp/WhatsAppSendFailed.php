<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Thrown only to put a TRANSIENT failure back on the queue.
 *
 * Never for a refusal the provider will repeat — a bad template, an
 * unopted-in number — because retrying those three times only delays anybody
 * finding out. Those are recorded on the log and the job returns quietly.
 */
class WhatsAppSendFailed extends RuntimeException {}
