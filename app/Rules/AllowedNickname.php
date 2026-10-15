<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedNickname implements ValidationRule
{
    // Matched anywhere inside the name. Only long, unambiguous words go here.
    private const BLOCKED_SUBSTRINGS = [
        'fuck', 'shit', 'bitch', 'bastard', 'cunt', 'whore', 'slut',
        'nigger', 'nigga', 'faggot', 'retard', 'rape', 'nazi', 'porn',
        'idiot', 'moron', 'dumbass', 'asshole', 'loser', 'wanker', 'twat', 'prick', 'scumbag',
        'bewakoof', 'bewaqoof', 'bevaqoof', 'jahil', 'kamina', 'kameena', 'harami',
        'zaleel', 'badtameez', 'ghatiya', 'kutta', 'kutte', 'kutti', 'chutiya',
        'madarchod', 'bhenchod', 'behenchod',
        'dhakkan', 'nikamma', 'gadha', 'gadhe', 'besharam', 'ulludapattha', 'ulludapatha',
    ];

    // Matched only when the whole name equals the word, so "class" is still allowed.
    private const BLOCKED_EXACT = [
        'ass', 'arse', 'dick', 'cock', 'pussy', 'tit', 'tits', 'piss', 'fag',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalised = strtolower((string) $value);
        $normalised = strtr($normalised, [
            '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
            '5' => 's', '@' => 'a', '$' => 's',
        ]);
        $normalised = preg_replace('/[^a-z]/', '', $normalised);

        foreach (self::BLOCKED_SUBSTRINGS as $word) {
            if (str_contains($normalised, $word)) {
                $fail('Please use another name. This nickname is not allowed.');
                return;
            }
        }

        if (in_array($normalised, self::BLOCKED_EXACT, true)) {
            $fail('Please use another name. This nickname is not allowed.');
        }
    }
}
