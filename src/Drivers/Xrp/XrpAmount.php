<?php

namespace BlockchainSdk\Drivers\Xrp;

use InvalidArgumentException;

class XrpAmount
{
    /** Exact decimal expansion, including scientific notation, without floating-point conversion. */
    public static function plain(string $value): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d{1,3}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid XRP token amount.');
        }
        $fraction = $parts[3] ?? '';
        $digits = $parts[2].$fraction;
        $point = strlen($parts[2]) + (int) ($parts[4] ?? 0);
        $plain = $point <= 0 ? '0.'.str_repeat('0', -$point).$digits
            : ($point >= strlen($digits) ? $digits.str_repeat('0', $point - strlen($digits)) : substr($digits, 0, $point).'.'.substr($digits, $point));
        [$whole, $decimal] = array_pad(explode('.', $plain), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $decimal = rtrim($decimal, '0');
        $plain = $whole.($decimal === '' ? '' : '.'.$decimal);

        return ($parts[1] === '-' && $plain !== '0' ? '-' : '').$plain;
    }

    /** Fixed SDK accounting scale; reject excess precision instead of silently truncating deposits. */
    public static function raw(string $value, int $decimals): string
    {
        $plain = self::plain($value);
        if ($decimals < 0 || $decimals > 8 || strlen(explode('.', $plain)[1] ?? '') > $decimals) {
            throw new InvalidArgumentException('XRP issued-token amount exceeds its configured accounting precision (0–8 decimals).');
        }
        $raw = bcmul($plain, bcpow('10', (string) $decimals), 0);
        if (strlen(ltrim($raw, '-')) > 78 || strlen(explode('.', ltrim($plain, '-'))[0]) > 16) {
            throw new InvalidArgumentException('XRP token amount exceeds SDK storage capacity.');
        }

        return $raw;
    }

    public static function currencyHex(string $currency): string
    {
        if (preg_match('/^[A-Za-z0-9?!@#$%^&*<>(){}|]{3}$/D', $currency) && strtoupper($currency) !== 'XRP') {
            return str_repeat('00', 12).bin2hex($currency).str_repeat('00', 5);
        }
        if (preg_match('/^[0-9a-f]{40}$/iD', $currency) && substr($currency, 0, 2) !== '00' && strtoupper($currency) !== str_repeat('0', 40)) {
            return strtoupper($currency);
        }
        throw new InvalidArgumentException('Invalid XRP issued-currency code.');
    }

    public static function issued(array $amount): string
    {
        $plain = self::plain((string) ($amount['value'] ?? ''));
        if (str_starts_with($plain, '-')) {
            throw new InvalidArgumentException('Negative XRP token payments are not supported.');
        }
        $fraction = explode('.', $plain)[1] ?? '';
        $mantissa = ltrim(str_replace('.', '', $plain), '0');
        $exponent = -strlen($fraction);
        if ($mantissa === '') {
            $bits = gmp_init('8000000000000000', 16);
        } else {
            while (strlen($mantissa) > 16 && str_ends_with($mantissa, '0')) {
                $mantissa = substr($mantissa, 0, -1);
                $exponent++;
            }
            if (strlen($mantissa) > 16) {
                throw new InvalidArgumentException('XRP token amount exceeds significant-digit precision.');
            }
            while (strlen($mantissa) < 16) {
                $mantissa .= '0';
                $exponent--;
            }
            if ($exponent < -96 || $exponent > 80) {
                throw new InvalidArgumentException('XRP token amount exponent is outside the ledger range.');
            }
            $bits = gmp_or(gmp_init('C000000000000000', 16), gmp_or(gmp_mul($exponent + 97, gmp_pow(2, 54)), gmp_init($mantissa)));
        }

        return str_pad(gmp_strval($bits, 16), 16, '0', STR_PAD_LEFT)
            .self::currencyHex($amount['currency'] ?? '').bin2hex(XrpWalletGenerator::accountId($amount['issuer'] ?? ''));
    }
}
