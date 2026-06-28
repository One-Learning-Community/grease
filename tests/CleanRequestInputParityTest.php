<?php

namespace Grease\Tests;

use Grease\Http\Middleware\CleanRequestInput;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * {@see CleanRequestInput} must be byte-identical to running the stock `TrimStrings` then
 * `ConvertEmptyStringsToNull` (the default global-stack order) — across flat/nested input,
 * the trim-except list (literal and wildcard), empty-string → null, and untouched non-string
 * values. The oracle is the REAL framework middleware run in sequence; if the fused pass
 * diverged by a single byte, rehydration/validation downstream would see different input.
 */
class CleanRequestInputParityTest extends TestCase
{
    protected function tearDown(): void
    {
        // Static except/skip state leaks across tests otherwise.
        TrimStrings::flushState();
        ConvertEmptyStringsToNull::flushState();
        CleanRequestInput::flushState();

        parent::tearDown();
    }

    private function jsonRequest(array $payload): Request
    {
        $r = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $r->json(); // prime the memoized InputBag

        return $r;
    }

    /** Run the stock pair in stack order; return the cleaned JSON bag. */
    private function cleanVanilla(Request $r): array
    {
        (new TrimStrings)->handle($r, fn ($r2) => (new ConvertEmptyStringsToNull)->handle($r2, fn ($r3) => $r3));

        return $r->json()->all();
    }

    private function cleanFused(Request $r): array
    {
        (new CleanRequestInput)->handle($r, fn ($r2) => $r2);

        return $r->json()->all();
    }

    private function assertParity(array $payload, string $message = ''): void
    {
        $vanilla = $this->cleanVanilla($this->jsonRequest($payload));
        $fused = $this->cleanFused($this->jsonRequest($payload));

        $this->assertSame(
            json_encode($vanilla),
            json_encode($fused),
            $message ?: 'payload: '.json_encode($payload),
        );
    }

    public function test_flat_input_is_byte_identical(): void
    {
        $this->assertParity([
            'name' => '  Jane Doe  ',
            'empty' => '',
            'clean' => 'value',
            'count' => 5,
            'flag' => true,
            'nil' => null,
            'whitespace_only' => "  \t\n  ",
        ]);
    }

    public function test_nested_input_is_byte_identical(): void
    {
        $this->assertParity([
            'user' => ['name' => '  Jane  ', 'bio' => '', 'age' => 34],
            'tags' => ['  a  ', '', 'b'],
            'meta' => ['nested' => ['deep' => '  x  ', 'empty' => '']],
        ]);
    }

    public function test_default_except_password_not_trimmed_but_empty_nulled(): void
    {
        // password (excepted) keeps its surrounding whitespace; current_password (excepted) is
        // still '' → null because ConvertEmptyStringsToNull has no except — the subtle order
        // the fused pass must preserve.
        $this->assertParity([
            'password' => '  secret  ',
            'current_password' => '',
            'password_confirmation' => '  secret  ',
            'email' => '  e@x.com  ',
        ]);
    }

    public function test_honors_literal_and_wildcard_excepts(): void
    {
        TrimStrings::except(['api_key', '*_secret']);
        CleanRequestInput::except(['api_key', '*_secret']);

        $this->assertParity([
            'api_key' => '  KEEP  ',        // literal except → not trimmed
            'billing_secret' => '  KEEP  ', // wildcard except → not trimmed
            'empty_secret' => '',           // excepted but '' → null
            'name' => '  trim me  ',        // not excepted → trimmed
        ], 'literal + wildcard excepts');
    }

    public function test_query_bag_is_byte_identical(): void
    {
        $r1 = Request::create('/x?a='.urlencode('  spaced  ').'&b=&c=keep', 'GET');
        (new TrimStrings)->handle($r1, fn ($r) => (new ConvertEmptyStringsToNull)->handle($r, fn ($r2) => $r2));

        $r2 = Request::create('/x?a='.urlencode('  spaced  ').'&b=&c=keep', 'GET');
        (new CleanRequestInput)->handle($r2, fn ($r) => $r);

        $this->assertSame(json_encode($r1->query->all()), json_encode($r2->query->all()));
    }

    public function test_skip_when_skips_the_whole_clean(): void
    {
        CleanRequestInput::skipWhen(fn ($request) => true);

        $r = $this->jsonRequest(['name' => '  untouched  ', 'empty' => '']);
        (new CleanRequestInput)->handle($r, fn ($r2) => $r2);

        $this->assertSame(['name' => '  untouched  ', 'empty' => ''], $r->json()->all());
    }
}
