<?php

namespace App\Services\CorpoFel;

class FelNumberToWords
{
    private const UNITS = [
        '', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
    ];

    private const TENS = [
        '', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA',
    ];

    private const HUNDREDS = [
        '', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    public static function toQuetzales(float $amount): string
    {
        $integer = (int) floor($amount);
        $cents = (int) round(($amount - $integer) * 100);

        $words = self::convertInteger($integer) . ' QUETZALES';

        if ($cents > 0) {
            $words .= ' CON ' . self::convertInteger($cents) . ' CENTAVOS';
        } else {
            $words .= ' EXACTOS';
        }

        return trim($words) . ' ';
    }

    private static function convertInteger(int $number): string
    {
        if ($number === 0) {
            return 'CERO';
        }
        if ($number === 100) {
            return 'CIEN';
        }

        $parts = [];

        if ($number >= 1000000) {
            $millions = intdiv($number, 1000000);
            $parts[] = ($millions === 1 ? 'UN MILLON' : self::convertBelowThousand($millions) . ' MILLONES');
            $number %= 1000000;
        }

        if ($number >= 1000) {
            $thousands = intdiv($number, 1000);
            $parts[] = ($thousands === 1 ? 'MIL' : self::convertBelowThousand($thousands) . ' MIL');
            $number %= 1000;
        }

        if ($number > 0) {
            $parts[] = self::convertBelowThousand($number);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private static function convertBelowThousand(int $number): string
    {
        if ($number < 20) {
            return self::UNITS[$number];
        }

        if ($number < 100) {
            $ten = intdiv($number, 10);
            $unit = $number % 10;
            if ($number <= 29) {
                return $unit === 0 ? self::TENS[$ten] : str_replace('VEINTE', 'VEINTI', self::TENS[$ten]) . self::UNITS[$unit];
            }
            return $unit === 0 ? self::TENS[$ten] : self::TENS[$ten] . ' Y ' . self::UNITS[$unit];
        }

        $hundred = intdiv($number, 100);
        $rest = $number % 100;
        $text = self::HUNDREDS[$hundred];
        if ($rest > 0) {
            $text .= ' ' . self::convertBelowThousand($rest);
        }

        return trim($text);
    }
}
