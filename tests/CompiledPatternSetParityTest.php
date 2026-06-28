<?php

namespace Grease\Tests;

use Grease\Support\CompiledPatternSet;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * {@see CompiledPatternSet} must be byte-for-byte equivalent to `Str::is($patterns, $value)`
 * (the framework comparison it replaces in hot loops) across every pattern kind: literal,
 * exact, prefix/suffix/middle wildcard, the match-all `*`, dotted keys, regex metacharacters
 * (which Str::is treats as literals), and the empty set. The oracle is the real Str::is — if a
 * future Laravel changes its semantics, this fails loudly rather than diverging silently.
 */
class CompiledPatternSetParityTest extends TestCase
{
    /**
     * Every (pattern-set × value) pair is asserted against Str::is. The values deliberately
     * include hits, near-misses, and unrelated keys for each set.
     */
    public function test_matches_are_identical_to_str_is(): void
    {
        $patternSets = [
            [],                                                   // empty → matches nothing
            ['password'],                                         // single literal
            ['current_password', 'password', 'password_confirmation'], // the TrimStrings default
            ['*'],                                                // match everything
            ['api_*'],                                            // prefix wildcard
            ['*_token'],                                          // suffix wildcard
            ['a*z'],                                              // middle wildcard
            ['*.secret'],                                         // dotted + wildcard
            ['user.name'],                                        // regex metachar (dot) as literal
            ['password', '*_token', '*.secret'],                  // mixed literal + wildcards
            ['secret', '*', 'whatever'],                          // a bare * among others
        ];

        $values = [
            'password', 'current_password', 'password_confirmation', 'pass', 'passwordx',
            'api_token', 'api_', 'apitoken', 'x_token', 'token', '_token',
            'abz', 'az', 'ab', 'a*z',
            'billing.secret', 'secret', 'user.name', 'userXname', 'user_name',
            'addresses.0.line1', 'items.3.note', '', 'random_field',
        ];

        foreach ($patternSets as $patterns) {
            $set = new CompiledPatternSet($patterns);

            foreach ($values as $value) {
                $this->assertSame(
                    Str::is($patterns, $value),
                    $set->matches($value),
                    'patterns=['.implode(',', $patterns)."] value='$value'",
                );
            }
        }
    }

    public function test_match_everything_short_circuits(): void
    {
        $set = new CompiledPatternSet(['*']);

        $this->assertTrue($set->matches(''));
        $this->assertTrue($set->matches('anything.at.all'));
    }

    public function test_empty_set_matches_nothing(): void
    {
        $set = new CompiledPatternSet([]);

        $this->assertFalse($set->matches('password'));
        $this->assertFalse($set->matches(''));
    }
}
