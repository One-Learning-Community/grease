<?php

namespace Grease\Validation;

use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator as BaseValidator;

/**
 * A validator that memoizes rule parsing. Behaviour-identical to {@see BaseValidator}.
 *
 * `ValidationRuleParser::parse('max:255')` → `['Max', ['255']]` is a pure, context-free function of
 * the rule string, but vanilla recomputes it constantly: `getRule()` loops EVERY rule of an attribute
 * and parses each, and it's reached from many probes per validation (`hasRule`, `isValidatable`,
 * `requireParameterCount`, dependent-field checks…), so the same exploded string is re-parsed
 * repeatedly within one `passes()` — roughly O(rules²) per attribute. Memoizing it collapses the
 * re-parses to a single hash hit.
 *
 * The memo is **static**: parse is a pure function of the rule string with no invalidation trigger
 * (no instance/config state), so it's globally correct, shared across every validator, and stays
 * warm for the worker's life under Octane. `??=` is safe — `parse()` never returns null. Non-string
 * rules (arrays, `Rule` objects, `CompilableRules`) bypass the memo and parse live, exactly as vanilla.
 *
 * Only `getRule()` is overridden — the looped, multiply-called parse site. `validateAttribute()`'s
 * single parse-per-rule is left to vanilla (overriding that long method to save one parse per rule
 * isn't worth the fragility). Output — pass/fail, messages, order — is byte-identical.
 */
class Validator extends BaseValidator
{
    /**
     * Memoized rule parses: rule string => [normalized name, parameters].
     *
     * @var array<string, array{0: string, 1: array}>
     */
    protected static array $greaseParsed = [];

    /** {@inheritDoc} */
    protected function getRule($attribute, $rules)
    {
        if (! array_key_exists($attribute, $this->rules)) {
            return;
        }

        $rules = (array) $rules;

        foreach ($this->rules[$attribute] as $rule) {
            [$name, $parameters] = is_string($rule)
                ? (self::$greaseParsed[$rule] ??= ValidationRuleParser::parse($rule))
                : ValidationRuleParser::parse($rule);

            if (in_array($name, $rules)) {
                return [$name, $parameters];
            }
        }
    }
}
