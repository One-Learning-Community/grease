<?php

namespace Grease\Tests\Validation;

use Grease\Validation\Validator as GreasedValidator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as VanillaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parse-memo contract: a greased validator must reach the same verdict (pass/fail), the same
 * error bag, and the same message order as vanilla — across string rules, array-form rules, `Rule`
 * objects and closures (which bypass the memo), dependent-field rules, and wildcards. Oracle =
 * vanilla {@see VanillaValidator}. Shares its rule shapes with `benchmarks/validation_ab.php`.
 */
class ValidatorParityTest extends TestCase
{
    private static function translator(): Translator
    {
        return new Translator(new ArrayLoader, 'en');
    }

    public static function cases(): array
    {
        return [
            'simple valid' => [
                ['name' => 'required|string|max:255', 'email' => 'required|email', 'age' => 'required|integer|min:18'],
                ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ],
            'simple invalid' => [
                ['name' => 'required|string|max:3', 'email' => 'required|email', 'age' => 'required|integer|min:18'],
                ['name' => 'Alice', 'email' => 'nope', 'age' => 5],
            ],
            'array-form rules' => [
                ['name' => ['required', 'string', 'max:255'], 'role' => ['required', 'in:admin,user']],
                ['name' => 'Bob', 'role' => 'editor'],
            ],
            'Rule object (bypasses memo)' => [
                ['role' => ['required', Rule::in(['admin', 'user'])]],
                ['role' => 'guest'],
            ],
            'closure rule (bypasses memo)' => [
                ['n' => ['required', function ($attr, $value, $fail) {
                    if ($value < 10) {
                        $fail('too small');
                    }
                }]],
                ['n' => 3],
            ],
            'dependent fields' => [
                ['password' => 'required|confirmed', 'kind' => 'required', 'detail' => 'required_if:kind,full'],
                ['password' => 'secret', 'password_confirmation' => 'nope', 'kind' => 'full'],
            ],
            'wildcards / nested' => [
                ['items' => 'required|array', 'items.*.id' => 'required|integer', 'items.*.tag' => 'required|string|max:3'],
                ['items' => [['id' => 1, 'tag' => 'ok'], ['id' => 'x', 'tag' => 'toolong']]],
            ],
            'repeated rule string across attributes' => [
                ['a' => 'required|max:255', 'b' => 'required|max:255', 'c' => 'required|max:255'],
                ['a' => 'x', 'b' => 'y', 'c' => 'z'],
            ],
            'nullable + types' => [
                ['bio' => 'nullable|string|max:1000', 'active' => 'required|boolean', 'score' => 'nullable|numeric'],
                ['bio' => null, 'active' => '1', 'score' => '9.5'],
            ],
        ];
    }

    #[DataProvider('cases')]
    public function test_validation_is_behaviour_identical(array $rules, array $data): void
    {
        $vanilla = new VanillaValidator(self::translator(), $data, $rules);
        $greased = new GreasedValidator(self::translator(), $data, $rules);

        $this->assertSame($vanilla->passes(), $greased->passes(), 'pass/fail verdict diverged');
        $this->assertSame(
            $vanilla->errors()->toArray(),
            $greased->errors()->toArray(),
            'error bag diverged',
        );
    }

    /** validated() data must match too (not just the verdict) on a passing case. */
    public function test_validated_payload_matches(): void
    {
        $rules = ['name' => 'required|string', 'age' => 'required|integer'];
        $data = ['name' => 'Alice', 'age' => 30, 'extra' => 'ignored'];

        $this->assertSame(
            (new VanillaValidator(self::translator(), $data, $rules))->validate(),
            (new GreasedValidator(self::translator(), $data, $rules))->validate(),
        );
    }
}
