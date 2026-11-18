<?php

namespace App\Support;

use App\Enums\Currency;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of money as whole centavos (ARCHITECTURE §6, D-025).
 *
 * Immutable: add() and subtract() return a new Money. Floats are never used.
 * In JSON it becomes its centavos integer: {"amount_cents": 12345}.
 */
final readonly class Money implements JsonSerializable
{
    /** Whole-unit digits accepted by parse(), so centavos always fit in an int. */
    private const MAX_UNIT_DIGITS = 15;

    private function __construct(private int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse user input such as "1,234.5", "₱ 1,000", "$20" or "-500" using integer maths only.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input): self
    {
        // Spaces are allowed only around the amount and after the symbol, so a typo like "12 34" is rejected.
        $clean = str_replace(',', '', trim(str_replace(self::symbols(), '', trim($input))));

        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,2}))?$/', $clean, $matches) !== 1) {
            throw new InvalidArgumentException("Not a valid amount: \"{$input}\".");
        }

        $sign = $matches[1];
        $units = ltrim($matches[2], '0');
        $hundredths = $matches[3] ?? '';

        if ($matches[2] === '' && $hundredths === '') {
            throw new InvalidArgumentException("Not a valid amount: \"{$input}\".");
        }

        if (strlen($units) > self::MAX_UNIT_DIGITS) {
            throw new InvalidArgumentException("Amount is too large: \"{$input}\".");
        }

        $cents = (int) $units * 100 + (int) str_pad($hundredths, 2, '0');

        return new self($sign === '-' ? -$cents : $cents);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * "₱1,234.50" (or "$1,234.50"…), and "−₱500.00" (U+2212 minus sign) when negative.
     */
    public function format(Currency $currency = Currency::PHP): string
    {
        $grouped = strrev(implode(',', str_split(strrev($this->units()), 3)));
        $formatted = $currency->symbol().$grouped.'.'.$this->hundredths();

        return $this->isNegative() ? "\u{2212}".$formatted : $formatted;
    }

    /**
     * Plain amount for a form field, with no symbol or commas: "1234.50".
     */
    public function toDecimalString(): string
    {
        return ($this->isNegative() ? '-' : '').$this->units().'.'.$this->hundredths();
    }

    /** Whole units (pesos, dollars…) of the absolute amount, as digits. */
    private function units(): string
    {
        return (string) intdiv(abs($this->cents), 100);
    }

    /** The two-digit hundredths of the absolute amount: "05", "50". */
    private function hundredths(): string
    {
        return str_pad((string) (abs($this->cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Every currency symbol, longest first so "HK$" is removed before "$".
     *
     * @return list<string>
     */
    private static function symbols(): array
    {
        $symbols = array_map(fn (Currency $currency): string => $currency->symbol(), Currency::cases());
        usort($symbols, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $symbols;
    }
}
