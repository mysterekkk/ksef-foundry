<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Testing;

/**
 * !!! TEST ENVIRONMENT ONLY !!!  These are random numbers that merely satisfy the checksums; they identify nobody.
 * Never use them (or this class) for real taxpayers or outside tests.
 *
 * TEST-ONLY generator of random, checksum-valid, SYNTHETIC NIP and PESEL numbers for the KSeF TEST
 * environment. Never feed real identifiers into tests.
 */
final class SyntheticIdentity
{
    public static function nip(): string
    {
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        while (true) {
            $d = [random_int(1, 9), random_int(0, 9), random_int(0, 9)];
            if ($d[1] === 0 && $d[2] === 0) {
                continue;
            }
            for ($i = 3; $i < 9; ++$i) {
                $d[] = random_int(0, 9);
            }
            $sum = 0;
            foreach ($weights as $i => $w) {
                $sum += $w * $d[$i];
            }
            $check = $sum % 11;
            if ($check === 10) {
                continue;
            }
            $d[] = $check;

            return implode('', $d);
        }
    }

    public static function isValidNip(string $nip): bool
    {
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/D', $nip) !== 1) {
            return false;
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $nip[$i];
        }

        return $sum % 11 === (int) $nip[9];
    }

    /** Random PESEL of someone born 1980-1999. */
    public static function pesel(): string
    {
        $year = random_int(1980, 1999);
        $month = random_int(1, 12);
        $day = random_int(1, 28);
        $digits = sprintf('%02d%02d%02d%04d', $year % 100, $month, $day, random_int(0, 9999));
        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $digits[$i];
        }

        return $digits . ((10 - $sum % 10) % 10);
    }

    public static function isValidPesel(string $pesel): bool
    {
        if (preg_match('/^\d{11}$/D', $pesel) !== 1) {
            return false;
        }
        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $pesel[$i];
        }

        return (10 - $sum % 10) % 10 === (int) $pesel[10];
    }
}
