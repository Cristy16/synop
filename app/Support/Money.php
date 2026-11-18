<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An amount of money as whole centavos (ARCHITECTURE §6, D-025).
 *
 * Immutable: add() and subtract() return a new Money. Floats are never used.
 */
final readonly class Money
{
    /** Pesos digits accepted by parse(), so centavos always fit in an int. */
    private const MAX_PESO_DIGITS = 15;

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
     * Parse user input such as "1,234.5", "₱ 1,000" or "-500" using integer maths only.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input): self
    {
        $clean = str_replace(['₱', ',', ' '], '', trim($input));

        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,2}))?$/', $clean, $matches) !== 1) {
            throw new InvalidArgumentException("Not a valid amount: \"{$input}\".");
        }

        $sign = $matches[1];
        $pesos = ltrim($matches[2], '0');
        $centavos = $matches[3] ?? '';

        if ($matches[2] === '' && $centavos === '') {
            throw new InvalidArgumentException("Not a valid amount: \"{$input}\".");
        }

        if (strlen($pesos) > self::MAX_PESO_DIGITS) {
            throw new InvalidArgumentException("Amount is too large: \"{$input}\".");
        }

        $cents = (int) $pesos * 100 + (int) str_pad($centavos, 2, '0');

        return new self($sign === '-' ? -$cents : $cents);
    }

    public function cents(): int
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
     * "₱1,234.50", or "−₱500.00" (U+2212 minus sign) when negative.
     */
    public function format(): string
    {
        $abs = abs($this->cents);
        $pesos = (string) intdiv($abs, 100);
        $grouped = strrev(implode(',', str_split(strrev($pesos), 3)));
        $formatted = '₱'.$grouped.'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $this->isNegative() ? "\u{2212}".$formatted : $formatted;
    }
}
