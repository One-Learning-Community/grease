<?php

namespace Grease\Validation;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;

/**
 * Opt into the greased validator app-wide. Register this provider (deliberately NOT auto-discovered
 * — opt-in is the point) and every validator the framework builds memoizes rule parsing:
 *
 *   // bootstrap/providers.php, or the providers array in config/app.php
 *   Grease\Validation\GreaseValidationServiceProvider::class,
 *
 * It points the validation Factory's resolver at {@see Validator} via `Factory::resolver()` — the
 * documented seam the Factory consults in `resolve()`. `Factory::make()`'s post-construction setup
 * (presence verifier, container, extensions) applies to the greased instance unchanged, so
 * FormRequests, `$request->validate()`, and `Validator::make()` all flow through it, behaviour-identical.
 */
class GreaseValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // afterResolving fires when the (singleton) Factory is first built, before any validator is
        // made, so the resolver is always in place.
        $this->app->afterResolving('validator', function (Factory $factory) {
            $factory->resolver(function ($translator, $data, $rules, $messages = [], $attributes = []) {
                return new Validator($translator, $data, $rules, $messages, $attributes);
            });
        });
    }
}
