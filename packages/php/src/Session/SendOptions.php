<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Session;

use LuroWeb\KsefFoundry\Invoice\XmlValidator;

final class SendOptions
{
    /**
     * @param bool $validate validate the XML before sending (default true) with the FA(3) XmlValidator
     *                       (UTF-8/BOM/DOCTYPE/PI/forbidden characters, size, XSD) unless $validator is given
     * @param XmlValidator|null $validator custom validator (e.g. other size limit or schema path)
     */
    public function __construct(
        public readonly bool $validate = true,
        public readonly ?XmlValidator $validator = null,
        public readonly bool $offlineMode = false,
        public readonly ?string $hashOfCorrectedInvoice = null,
    ) {
    }
}
