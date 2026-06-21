<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\VanillaSample;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real path: rows go through an actual database driver and Eloquent's query
 * builder / hydration, not hand-crafted arrays. Reads, writes, updates, fresh(),
 * and eager-loaded relations must all be byte-identical to vanilla.
 */
class SqlRoundtripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('samples');
        Schema::create('samples', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('int_val')->nullable();
            $t->float('real_val')->nullable();
            $t->float('float_val')->nullable();
            $t->decimal('dec_val', 12, 2)->nullable();
            $t->string('str_val')->nullable();
            $t->boolean('bool_val')->nullable();
            $t->text('obj_val')->nullable();
            $t->text('arr_val')->nullable();
            $t->text('json_val')->nullable();
            $t->text('coll_val')->nullable();
            $t->date('date_val')->nullable();
            $t->dateTime('dt_val')->nullable();
            $t->dateTime('cdt_val')->nullable();
            $t->date('imm_date_val')->nullable();
            $t->dateTime('imm_dt_val')->nullable();
            $t->dateTime('icdt_val')->nullable();
            $t->dateTime('ts_val')->nullable();
            $t->string('hashed_val')->nullable();
            $t->string('status_val')->nullable();
            $t->string('upper_val')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('rt_authors');
        Schema::create('rt_authors', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->integer('age')->nullable();
            $t->timestamps();
        });

        Schema::dropIfExists('rt_posts');
        Schema::create('rt_posts', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('author_id');
            $t->string('title');
            $t->boolean('published')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->text('meta')->nullable();
            $t->timestamps();
        });

        foreach ([1, 2, 3] as $i) {
            DB::table('samples')->insert([
                'int_val' => $i * 10, 'real_val' => 2.5, 'float_val' => 3.14159,
                'dec_val' => '12.34', 'str_val' => "row $i", 'bool_val' => $i % 2,
                'obj_val' => '{"x":1,"y":2}', 'arr_val' => '{"a":[1,2],"b":3}',
                'json_val' => '[1,2,3]', 'coll_val' => '[4,5,6]',
                'date_val' => '2026-03-04', 'dt_val' => '2026-03-04 09:10:11',
                'cdt_val' => '2026-03-04 09:10:11', 'imm_date_val' => '2026-03-04',
                'imm_dt_val' => '2026-03-04 09:10:11', 'icdt_val' => '2026-03-04 09:10:11',
                'ts_val' => '2026-03-04 09:10:11', 'hashed_val' => 'stored',
                'status_val' => 'active', 'upper_val' => 'hello',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    public function test_select_all_is_identical(): void
    {
        $v = VanillaSample::orderBy('id')->get();
        $g = GreasedSample::orderBy('id')->get();

        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
    }

    public function test_find_is_identical(): void
    {
        $this->assertSame(
            json_encode(VanillaSample::find(2)->toArray()),
            json_encode(GreasedSample::find(2)->toArray()),
        );
    }

    public function test_where_filtering_is_identical(): void
    {
        $v = VanillaSample::where('bool_val', true)->orderBy('id')->get();
        $g = GreasedSample::where('bool_val', true)->orderBy('id')->get();

        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
        $this->assertCount(2, $g);
    }

    public function test_insert_then_reread_is_identical(): void
    {
        $g = new GreasedSample;
        $g->int_val = 5;
        $g->bool_val = true;
        $g->dt_val = '2026-05-05 01:02:03';
        $g->arr_val = ['k' => 'v'];
        $g->str_val = 'written by grease';
        $g->save();

        // What grease wrote must read back identically through a vanilla model.
        $this->assertSame(
            json_encode(VanillaSample::find($g->id)->toArray()),
            json_encode(GreasedSample::find($g->id)->toArray()),
        );
    }

    public function test_update_roundtrip_is_identical(): void
    {
        $g = GreasedSample::find(1);
        $g->int_val = 777;
        $g->arr_val = ['changed' => true];

        $this->assertTrue($g->isDirty(['int_val', 'arr_val']));
        $g->save();

        $reloaded = VanillaSample::find(1);
        $this->assertSame(777, $reloaded->int_val);
        $this->assertSame(json_encode($reloaded->toArray()), json_encode(GreasedSample::find(1)->toArray()));
    }

    public function test_fresh_is_identical(): void
    {
        $g = GreasedSample::find(3);

        $this->assertSame(
            json_encode(VanillaSample::find(3)->fresh()->toArray()),
            json_encode($g->fresh()->toArray()),
        );
    }

    public function test_eager_loaded_relations_are_identical(): void
    {
        $authorId = DB::table('rt_authors')->insertGetId([
            'name' => 'Ada', 'age' => 36, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([1, 2] as $p) {
            DB::table('rt_posts')->insert([
                'author_id' => $authorId, 'title' => "Post $p", 'published' => $p % 2,
                'published_at' => '2026-02-02 02:02:02', 'meta' => '{"tags":["x"]}',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]);
        }

        $v = VanillaAuthor::with('posts')->orderBy('id')->get();
        $g = GreasedAuthor::with('posts')->orderBy('id')->get();

        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
        $this->assertCount(2, $g->first()->posts);
    }
}

class VanillaAuthor extends Model
{
    protected $table = 'rt_authors';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(VanillaPost::class, 'author_id');
    }
}

class VanillaPost extends Model
{
    protected $table = 'rt_posts';

    protected $casts = ['published' => 'boolean', 'published_at' => 'datetime', 'meta' => 'array'];

    public function author()
    {
        return $this->belongsTo(VanillaAuthor::class, 'author_id');
    }
}

class GreasedAuthor extends VanillaAuthor
{
    use HasGrease;

    public function posts()
    {
        return $this->hasMany(GreasedPost::class, 'author_id');
    }
}

class GreasedPost extends VanillaPost
{
    use HasGrease;

    public function author()
    {
        return $this->belongsTo(GreasedAuthor::class, 'author_id');
    }
}
