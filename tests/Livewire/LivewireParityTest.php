<?php

namespace Grease\Tests\Livewire;

use Grease\Tests\Livewire\Fixtures\GreasedShowUser;
use Grease\Tests\Livewire\Fixtures\GreasedUserCard;
use Grease\Tests\Livewire\Fixtures\VanillaShowUser;
use Grease\Tests\Livewire\Fixtures\VanillaUserCard;
use Grease\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Livewire\Mechanisms\HandleComponents\Checksum;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Livewire byte-identity contract.
 *
 * Livewire serializes component state into a {@see https://livewire.laravel.com snapshot}
 * between requests and seals it with an HMAC checksum. The promise Grease has to keep is the
 * same one it keeps for an HTTP response: a greased model must serialize byte-for-byte like a
 * vanilla one, so the snapshot — and thus the checksum — is identical. If it weren't, a mixed
 * greased/vanilla rolling deploy would reject every payload with a corruption exception.
 *
 * The fixtures hold the model two ways: {@see Fixtures\ShowUser} as a live model property (which
 * Livewire dehydrates to `{class,key}` and re-queries — the serialized casts land in the HTML),
 * and {@see Fixtures\UserCard} as a `toArray()` array (the full serialized output — ISO dates,
 * the `decimal:2` string, the loaded `posts` relation — lands inside the snapshot `data` and is
 * sealed by the checksum). Each side has a greased and a vanilla twin differing only in the
 * source model class, so any divergence is attributable solely to the model axis.
 *
 * Note on the checksum: Livewire's HMAC covers the *whole* snapshot, including a per-request
 * random `memo.id`/`path`, so two `Livewire::test()` calls never share a checksum — not even
 * vanilla-vs-vanilla. The meaningful claim is therefore (a) the serialized `data` is byte-
 * identical, and (b) with component identity held constant, the checksum matches. We prove both.
 */
#[Group('livewire')]
class LivewireParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Livewire::class)) {
            $this->markTestSkipped('livewire/livewire is not installed');
        }

        parent::setUp();

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email');
            $t->integer('age');
            $t->boolean('is_active');
            $t->decimal('score', 8, 2);
            $t->text('settings');
            $t->dateTime('email_verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('posts', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->string('title');
            $t->text('body');
            $t->integer('view_count');
            $t->boolean('is_published');
            $t->dateTime('published_at')->nullable();
            $t->text('meta');
            $t->timestamps();
        });

        $now = '2026-01-01 00:00:00';
        $this->app['db']->connection()->table('users')->insert([
            'id' => 1, 'name' => 'User 1', 'email' => 'user1@example.test', 'age' => 30,
            'is_active' => 1, 'score' => '42.50', 'settings' => '{"theme":"dark"}',
            'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->app['db']->connection()->table('posts')->insert([
            'id' => 1, 'user_id' => 1, 'title' => 'Post 1', 'body' => 'lorem', 'view_count' => 5,
            'is_published' => 1, 'published_at' => $now, 'meta' => '{"tags":["a"]}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class];
    }

    /**
     * The headline: a model serialized whole into the snapshot `data` — ISO-8601 dates (the
     * date tier), the `decimal:2` string, the cast `settings`, and the loaded `posts` relation
     * with its own dates — is byte-identical greased vs vanilla. This is the wire format Livewire
     * persists between requests; if Grease moved one byte here, the checksum would break.
     */
    public function test_serialized_snapshot_data_is_byte_identical(): void
    {
        $greased = Livewire::test(GreasedUserCard::class, ['id' => 1])->snapshot;
        $vanilla = Livewire::test(VanillaUserCard::class, ['id' => 1])->snapshot;

        $this->assertSame(
            json_encode($vanilla['data']),
            json_encode($greased['data']),
            'the dehydrated snapshot data diverged from vanilla'
        );
    }

    /**
     * With component identity held constant (the random per-request memo fields normalized to
     * fixed values), Livewire's own checksum generator produces the identical HMAC for the
     * greased and vanilla snapshots — the mixed-deploy safety guarantee, proved through the real
     * {@see Checksum::generate()} rather than asserted by hand.
     */
    public function test_checksum_matches_with_component_identity_held_constant(): void
    {
        $greased = Livewire::test(GreasedUserCard::class, ['id' => 1])->snapshot;
        $vanilla = Livewire::test(VanillaUserCard::class, ['id' => 1])->snapshot;

        $this->assertSame(
            $this->normalizedChecksum($vanilla),
            $this->normalizedChecksum($greased),
            'the snapshot checksum diverged once component identity was held constant'
        );
    }

    /**
     * The rendered HTML — where a model-as-property component's serialized casts/dates actually
     * surface ({@see Fixtures\ShowUser} renders them with `{{ }}`) — is byte-identical across the
     * full lifecycle: mount, an action that mutates a `decimal:2` column, and a property set.
     */
    public function test_model_backed_component_html_is_byte_identical_across_lifecycle(): void
    {
        $greased = Livewire::test(GreasedShowUser::class, ['id' => 1]);
        $vanilla = Livewire::test(VanillaShowUser::class, ['id' => 1]);

        $this->assertSame($this->body($vanilla->html()), $this->body($greased->html()), 'mount HTML diverged');

        $greased->call('bump');
        $vanilla->call('bump');
        $this->assertSame($this->body($vanilla->html()), $this->body($greased->html()), 'post-action HTML diverged');

        $greased->set('bumps', 5);
        $vanilla->set('bumps', 5);
        $this->assertSame($this->body($vanilla->html()), $this->body($greased->html()), 'post-set HTML diverged');
    }

    /**
     * The snapshot `data` for the model-as-property shape is Livewire's `[null,{class,key,s}]`
     * reference envelope. In production the same `User` class is greased or not, so the class
     * string is held constant here; what's proved is that adding `HasGrease` leaves the
     * dehydration envelope — the null placeholder, the key, the `s:"mdl"` marker — untouched.
     */
    public function test_model_reference_dehydration_envelope_is_byte_identical(): void
    {
        $greased = Livewire::test(GreasedShowUser::class, ['id' => 1])->snapshot;
        $vanilla = Livewire::test(VanillaShowUser::class, ['id' => 1])->snapshot;

        $normalize = function (array $data) {
            $data['user'][1]['class'] = 'App\\Models\\User';

            return json_encode($data);
        };

        $this->assertSame($normalize($vanilla['data']), $normalize($greased['data']));
    }

    /** Recompute Livewire's checksum after pinning the per-request-random memo fields. */
    private function normalizedChecksum(array $snapshot): string
    {
        $snapshot['memo']['id'] = 'fixed-id';
        $snapshot['memo']['path'] = 'fixed-path';
        $snapshot['memo']['name'] = 'fixed-name';
        unset($snapshot['checksum']);

        return Checksum::generate($snapshot);
    }

    /** Strip the per-request-random wire:* attributes, leaving the rendered body to compare. */
    private function body(string $html): string
    {
        return preg_replace('/\s*wire:(id|snapshot|effects)="[^"]*"/', '', $html);
    }
}
