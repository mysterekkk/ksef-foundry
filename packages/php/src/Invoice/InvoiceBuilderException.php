<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Invoice;

/**
 * Thrown by the FA(3) builder BEFORE any XML is produced when the model is incomplete,
 * outside the supported variant, or violates an XSD constraint the builder checks up front.
 */
class InvoiceBuilderException extends \InvalidArgumentException
{
}
