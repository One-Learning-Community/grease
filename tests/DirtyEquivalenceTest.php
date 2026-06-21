<?php

namespace Grease\Tests;

use Carbon\Carbon;

/**
 * Dirty-checking must agree with vanilla in the subtle cases — equivalent values
 * re-assigned (type-mismatched scalars, reordered JSON, equal dates) and genuine
 * changes — because a wrong "is this dirty?" silently corrupts saves.
 */
class DirtyEquivalenceTest extends TestCase
{
    public function test_untouched_model_is_clean_identically(): void
    {
        [$v, $g] = $this->pair($this->sampleRow());

        $this->assertSame($v->isDirty(), $g->isDirty());
        $this->assertSame([], $g->getDirty());
    }

    public function test_equivalent_reassignments_agree(): void
    {
        $equivalents = [
            'int_val' => 42,                          // int vs stored '42'
            'real_val' => 2.5,
            'float_val' => 3.14159,
            'dec_val' => '12.34',
            'bool_val' => true,                       // bool vs stored '1'
            'str_val' => '100',
            'arr_val' => ['b' => 3, 'a' => [1, 2]],   // reordered, re-encodes differently
            'json_val' => [1, 2, 3],
            'dt_val' => Carbon::parse('2026-03-04 09:10:11'),
        ];

        foreach ($equivalents as $col => $value) {
            [$v, $g] = $this->pair($this->sampleRow());
            $v->{$col} = $value;
            $g->{$col} = $value;

            $this->assertSame($v->isDirty($col), $g->isDirty($col), "isDirty divergence on [$col]");
            $this->assertEquals($v->getDirty(), $g->getDirty(), "getDirty divergence on [$col]");
        }
    }

    public function test_real_changes_agree(): void
    {
        $changes = [
            'int_val' => 999,
            'dec_val' => '99.99',
            'bool_val' => false,
            'arr_val' => ['totally' => 'different'],
            'str_val' => 'changed',
            'dt_val' => Carbon::parse('2030-12-31 23:59:59'),
        ];

        foreach ($changes as $col => $value) {
            [$v, $g] = $this->pair($this->sampleRow());
            $v->{$col} = $value;
            $g->{$col} = $value;

            $this->assertTrue($g->isDirty($col), "expected [$col] to be dirty");
            $this->assertSame($v->isDirty($col), $g->isDirty($col), "isDirty divergence on [$col]");
            $this->assertEquals($v->getDirty(), $g->getDirty(), "getDirty divergence on [$col]");
        }
    }
}
