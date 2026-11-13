<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Exception;

use LuroWeb\KsefFoundry\Support\Redactor;

/** Base of every exception thrown by the SDK. Messages never contain secrets. */
class KsefException extends \RuntimeException
{
    /** Machine-friendly category, identical to the TypeScript SDK (network, timeout, session, ...). */
    public function category(): string
    {
        return 'ksef';
    }

    /**
     * Secret-free structure, safe for logs and error reporters.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'class' => static::class,
            'category' => $this->category(),
            'message' => (new Redactor())->redactString($this->getMessage()),
        ];
    }
}
