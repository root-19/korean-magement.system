<?php

/*
 * Money formatting as plain functions, not only as Blade directives.
 *
 * `@money(...)` works in markup but NOT inside a component attribute:
 * `<x-stat-card value="@money($x)">` compiles the tag into a PHP call first, so
 * the attribute stays a literal string and the directive is printed verbatim.
 * Attributes take `:value="money($x)"` instead, and the directives below
 * delegate here so both spellings can never drift apart.
 */

/**
 * The one place the currency symbol is written. Everything user-facing goes
 * through money() or money2() so it can never disagree with itself.
 */
const MONEY_SYMBOL = '₱';

if (! function_exists('money')) {
    /**
     * "₱47.50" — every peso figure is shown to the centavo.
     *
     * Totals used to be printed whole while the lines above them kept their
     * decimals, so a payslip holding one 15-minute audio class listed the class
     * at ₱47.50 and the net payable at ₱48. Nothing was ever rounded in the
     * database — gross/deductions/net are stored to two places — but a teacher
     * reading a total 50 centavos above the only line on the page has no way to
     * know that, and asked for the rounding to stop.
     *
     * Rates read "₱190.00/hr" for the same reason: one format for money means
     * no column can be read against another and come out short.
     */
    function money(float|int|string|null $amount): string
    {
        return MONEY_SYMBOL.number_format((float) $amount, 2);
    }
}

if (! function_exists('money2')) {
    /**
     * Kept for the call sites that spell out that the fractional part matters —
     * a per-session rate, an earnings line. Identical to money(), and delegating
     * rather than repeating the format is what stops the two drifting apart
     * again.
     */
    function money2(float|int|string|null $amount): string
    {
        return money($amount);
    }
}
