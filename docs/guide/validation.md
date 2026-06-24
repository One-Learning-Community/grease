# Validation

Another axis on the request lifecycle — validating input. A `FormRequest` or
`$request->validate()` builds a fresh `Validator` per request, and inside one `passes()`
the same rule strings get parsed over and over.

## What it does

Vanilla `ValidationRuleParser::parse('max:255')` → `['Max', ['255']]` is a pure,
context-free function of the rule string — but the framework recomputes it constantly.
`Validator::getRule()` loops **every** rule of an attribute and parses each, and it's
reached from many probes per validation (`hasRule`, `isValidatable`,
`requireParameterCount`, the dependent-field checks…). So for a field with three rules,
the same exploded strings are re-parsed on every probe — roughly **O(rules²) parses per
attribute** within a single `passes()`.

`Grease\Validation\Validator` memoizes that parse. The result is a pure function of the
rule string with no invalidation trigger — no instance or config state feeds it — so the
memo is **static**: shared across every validator the app builds, and warm for the
worker's life under Octane. Non-string rules (arrays, `Rule` objects, closures,
`CompilableRules`) have no stable key and parse live, exactly as vanilla.

Only the looped, multiply-called parse site (`getRule`) is overridden. `validateAttribute`'s
single parse-per-rule is left to the framework — overriding that long method to save one
parse per rule isn't worth the version-fragility, and it isn't where the cost is.

## Behaviour-identical, by test

Parity is the verdict, the error bag, and the message order, asserted against vanilla
`Illuminate\Validation\Validator` across string rules, array-form rules, `Rule` objects and
closures (the bypass path), dependent-field rules (`confirmed`, `required_if`), wildcards
(`items.*.id`), repeated rule strings across attributes, and nullable/typed rules — plus the
`validated()` payload on a passing case. See `ValidatorParityTest`.

## What it's worth

Measured on Linux (`benchmarks/validation_ab.php`, opcache + JIT): **−45.6%** on a real
six-field validation, end to end — the honest full-`passes()` delta, not an isolated parse
loop. The win is large because `getRule`'s re-parse is genuinely most of a simple-rule
validation; for rules that do heavy work (regex, a database `exists`/`unique` lookup) the
*relative* share is smaller while the absolute parse time removed is the same. This tier
only does work on endpoints that actually validate.

## Opt in

A provider rebind, like the event and config tiers — register it (deliberately not
auto-discovered) and every validator the framework builds goes through the greased one:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Validation\GreaseValidationServiceProvider::class,
```

It points the validation Factory's resolver at the greased validator, so `FormRequest`
validation, `$request->validate()`, and `Validator::make()` all flow through it —
behaviour-identical, no other change required.
