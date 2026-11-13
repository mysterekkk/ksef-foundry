<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

final class SubmitOptions
{
    /**
     * @param float $awaitTimeoutSeconds how long to wait for the final invoice status
     * @param bool $resendIfAbsent after an UnknownOutcome and a reconcile that finds NO such invoice, resend once.
     *                             Default false: the exception is rethrown and the caller decides.
     * @param float $reconcileDelaySeconds pause before reconciling (lets KSeF register a just-queued invoice)
     */
    public function __construct(
        public readonly ?FormCode $formCode = null,
        public readonly SendOptions $send = new SendOptions(),
        public readonly float $awaitTimeoutSeconds = 120.0,
        public readonly bool $resendIfAbsent = false,
        public readonly float $reconcileDelaySeconds = 3.0,
    ) {
    }
}
