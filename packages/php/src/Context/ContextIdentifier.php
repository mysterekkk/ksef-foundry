<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Context;

use LuroWeb\KsefFoundry\Exception\ConfigurationException;

final class ContextIdentifier
{
    // phpcs:ignore Generic.Files.LineLength -- verbatim copy of the official XSD TNipVatUE alternatives
    private const NIP_VAT_UE_PATTERN = '([1-9]((\\d[1-9])|([1-9]\\d))\\d{7}-((AT)(U\\d{8})|(BE)([01]{1}\\d{9})|(BG)(\\d{9,10})|(CY)(\\d{8}[A-Z])|(CZ)(\\d{8,10})|(DE)(\\d{9})|(DK)(\\d{8})|(EE)(\\d{9})|(EL)(\\d{9})|(ES)([A-Z]\\d{8}|\\d{8}[A-Z]|[A-Z]\\d{7}[A-Z])|(FI)(\\d{8})|(FR)[A-Z0-9]{2}\\d{9}|(HR)(\\d{11})|(HU)(\\d{8})|(IE)(\\d{7}[A-Z]{2}|\\d[A-Z0-9+*]\\d{5}[A-Z])|(IT)(\\d{11})|(LT)(\\d{9}|\\d{12})|(LU)(\\d{8})|(LV)(\\d{11})|(MT)(\\d{8})|(NL)([A-Z0-9+*]{12})|(PT)(\\d{9})|(RO)(\\d{2,10})|(SE)(\\d{12})|(SI)(\\d{8})|(SK)(\\d{10})|(XI)((\\d{9}|(\\d{12}))|(GD|HA)(\\d{3}))))';

    private function __construct(
        public readonly ContextIdentifierType $type,
        public readonly string $value,
    ) {
    }

    public static function nip(string $nip): self
    {
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/D', $nip) !== 1) {
            throw new ConfigurationException('NIP must be 10 digits in the form accepted by KSeF.');
        }

        return new self(ContextIdentifierType::Nip, $nip);
    }

    /** Internal identifier: NIP-5 digits, e.g. 1234567890-12345. */
    public static function internalId(string $id): self
    {
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-\d{5}$/D', $id) !== 1) {
            throw new ConfigurationException('InternalId must look like 1234567890-12345.');
        }

        return new self(ContextIdentifierType::InternalId, $id);
    }

    /** Composite context: NIP-VATUE, e.g. 1234567890-DE123456789. */
    /** Composite context: NIP-VATUE, e.g. 1234567890-DE123456789 (official XSD TNipVatUE alternatives). */
    public static function nipVatUe(string $value): self
    {
        if (preg_match('/^' . self::NIP_VAT_UE_PATTERN . '$/D', $value) !== 1) {
            throw new ConfigurationException('NipVatUe must look like 1234567890-DE123456789.');
        }

        return new self(ContextIdentifierType::NipVatUe, $value);
    }

    /** Validates by type with the patterns of the OpenAPI/XSD (Nip, InternalId, NipVatUe, PeppolId). */
    public static function of(ContextIdentifierType $type, string $value): self
    {
        return match ($type) {
            ContextIdentifierType::Nip => self::nip($value),
            ContextIdentifierType::InternalId => self::internalId($value),
            ContextIdentifierType::NipVatUe => self::nipVatUe($value),
            ContextIdentifierType::PeppolId => self::peppolId($value),
        };
    }

    /** Peppol service provider id, e.g. PPL123456. */
    public static function peppolId(string $value): self
    {
        if (preg_match('/^P[A-Z]{2}[0-9]{6}$/D', $value) !== 1) {
            throw new ConfigurationException('PeppolId must look like PPL123456.');
        }

        return new self(ContextIdentifierType::PeppolId, $value);
    }

    /** @return array{type: string, value: string} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'value' => $this->value];
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }
}
