<?php
declare(strict_types=1);

/**
 * Canonical formatting for structured names, labels, and identifiers.
 * Free-text fields such as notes, descriptions, and comments are intentionally
 * excluded so the user's wording is preserved.
 */
function normalize_structured_text(?string $value, string $case = 'upper'): ?string
{
    if ($value === null) { return null; }
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    if ($value === '') { return null; }
    return match ($case) {
        'lower' => mb_strtolower($value, 'UTF-8'),
        'title' => mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
        default => mb_strtoupper($value, 'UTF-8'),
    };
}

function normalize_upper(?string $value): ?string
{
    return normalize_structured_text($value, 'upper');
}

function normalize_lower(?string $value): ?string
{
    return normalize_structured_text($value, 'lower');
}
