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
     * "₱79" — totals are shown whole while the stored decimals stay exact, so a
     * payslip reads as round figures without any rounding being persisted.
     */
    function money(float|int|string|null $amount): string
    {
        return MONEY_SYMBOL.number_format((float) $amount);
    }
}

if (! function_exists('money2')) {
    /**
     * "₱79.17" for the places a per-session rate is shown and the fractional
     * part is the point.
     */
    function money2(float|int|string|null $amount): string
    {
        return MONEY_SYMBOL.number_format((float) $amount, 2);
    }
}
