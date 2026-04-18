<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Unit');
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something(): void
{
    /* reserved for global helpers */
}
