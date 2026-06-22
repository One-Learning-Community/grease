<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A model with the lot — a hidden column, an appended accessor, a datetime cast,
 * and the built-in timestamps — so the subset helper is exercised against every
 * arrayable source attributesToArray() draws from.
 */
class VanillaOnly extends Model
{
    protected $table = 'ts';

    protected $hidden = ['secret'];

    protected $appends = ['shout'];

    protected $casts = ['published_at' => 'datetime'];

    public function getShoutAttribute(): string
    {
        return strtoupper((string) ($this->attributes['name'] ?? ''));
    }
}

class GreasedOnly extends Model
{
    use HasGrease;

    protected $table = 'ts';

    protected $hidden = ['secret'];

    protected $appends = ['shout'];

    protected $casts = ['published_at' => 'datetime'];

    public function getShoutAttribute(): string
    {
        return strtoupper((string) ($this->attributes['name'] ?? ''));
    }
}

/**
 * `greaseSerializeOnly($keys)` must be byte-identical to
 * `Arr::only($model->attributesToArray(), $keys)` in every visibility config — it
 * only skips serializing the keys that filter would have discarded, and it must
 * never mutate the model's own visible list.
 */
class SerializeOnlyParityTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        GreasedOnly::flushGreaseBlueprint();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        GreasedOnly::flushGreaseBlueprint();
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'widget',
            'secret' => 'hunter2',
            'published_at' => '2026-03-04 09:10:11',
            'created_at' => '2026-03-04 09:10:11',
            'updated_at' => '2024-12-31 23:59:59',
        ], $overrides);
    }

    /**
     * The contract: the helper equals filtering the full vanilla serialization, and
     * equally the full greased serialization — keys, values, and order.
     */
    private function assertOnlyMatches(array $keys, array $row = []): void
    {
        $row = $this->row($row);

        $v = (new VanillaOnly)->newFromBuilder($row);
        $g = (new GreasedOnly)->newFromBuilder($row);

        $expected = Arr::only($v->attributesToArray(), $keys);

        $this->assertSame($expected, $g->greaseSerializeOnly($keys));
        // Also self-consistent against the greased full serialization.
        $this->assertSame(Arr::only($g->attributesToArray(), $keys), $g->greaseSerializeOnly($keys));
    }

    public function test_plain_subset_matches_arr_only(): void
    {
        $this->assertOnlyMatches(['name', 'created_at']);
    }

    public function test_subset_including_append_matches(): void
    {
        $this->assertOnlyMatches(['name', 'shout', 'published_at']);
    }

    public function test_requested_hidden_key_stays_hidden(): void
    {
        // 'secret' is hidden; asking for it must not resurrect it (matches Arr::only,
        // which filters a serialization that already dropped it).
        $g = (new GreasedOnly)->newFromBuilder($this->row());
        $out = $g->greaseSerializeOnly(['name', 'secret']);

        $this->assertSame(['name' => 'widget'], $out);
        $this->assertArrayNotHasKey('secret', $out);
    }

    public function test_preexisting_visible_list_is_respected(): void
    {
        $keys = ['name', 'secret', 'created_at'];

        $v = (new VanillaOnly)->newFromBuilder($this->row())->setVisible(['name', 'created_at']);
        $g = (new GreasedOnly)->newFromBuilder($this->row())->setVisible(['name', 'created_at']);

        // The model only exposes name + created_at; asking also for 'secret' can't
        // widen that — the helper composes the request with the standing visible list.
        $expected = Arr::only($v->attributesToArray(), $keys);

        $this->assertSame($expected, $g->greaseSerializeOnly($keys));
        $this->assertArrayNotHasKey('secret', $g->greaseSerializeOnly($keys));
    }

    public function test_no_overlap_with_visible_list_is_empty(): void
    {
        $g = (new GreasedOnly)->newFromBuilder($this->row())->setVisible(['name']);

        $this->assertSame([], $g->greaseSerializeOnly(['created_at']));
    }

    public function test_empty_keys_returns_empty(): void
    {
        $g = (new GreasedOnly)->newFromBuilder($this->row());

        // Deliberately NOT setVisible([]) semantics (which would mean "no restriction"
        // and serialize everything) — an empty request serializes nothing.
        $this->assertSame([], $g->greaseSerializeOnly([]));
        $this->assertSame(Arr::only($g->attributesToArray(), []), $g->greaseSerializeOnly([]));
    }

    public function test_nonexistent_key_is_ignored(): void
    {
        $this->assertOnlyMatches(['name', 'does_not_exist']);
    }

    public function test_output_order_follows_attributes_not_request(): void
    {
        // Request reversed vs storage order; output must follow attributesToArray()'s
        // order (storage order), exactly as Arr::only would.
        $g = (new GreasedOnly)->newFromBuilder($this->row());

        $out = $g->greaseSerializeOnly(['updated_at', 'name', 'created_at']);

        $this->assertSame(['name', 'created_at', 'updated_at'], array_keys($out));
    }

    public function test_subset_composes_with_date_fastpath(): void
    {
        // The date tier still fires over the narrowed set: created_at comes out in the
        // certified utc_iso form, byte-identical to vanilla.
        $g = (new GreasedOnly)->newFromBuilder($this->row());

        $out = $g->greaseSerializeOnly(['created_at']);

        $this->assertSame('2026-03-04T09:10:11.000000Z', $out['created_at']);
        $this->assertSame(
            Arr::only((new VanillaOnly)->newFromBuilder($this->row())->attributesToArray(), ['created_at']),
            $out,
        );
    }

    public function test_is_non_mutating(): void
    {
        $g = (new GreasedOnly)->newFromBuilder($this->row());

        $before = $g->getVisible();
        $fullBefore = $g->attributesToArray();

        $g->greaseSerializeOnly(['name']);

        // visible list untouched, and a subsequent full serialization is unchanged.
        $this->assertSame($before, $g->getVisible());
        $this->assertSame($fullBefore, $g->attributesToArray());
    }

    public function test_non_mutating_even_with_preexisting_visible(): void
    {
        $g = (new GreasedOnly)->newFromBuilder($this->row())->setVisible(['name', 'created_at']);

        $g->greaseSerializeOnly(['name']);

        $this->assertSame(['name', 'created_at'], $g->getVisible());
    }
}
