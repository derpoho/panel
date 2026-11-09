<?php

use App\Enums\Plan;
use App\Models\Machine;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('on Essential the workflows page is locked, with a real workflow to show behind it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();
    Machine::factory()->for($workspace)->create(['name' => 'web-1']);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/workflows')
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Workflows')
            ->where('planAllows', false)
            ->has('preview.graph.nodes', 7)
            ->has('preview.options.machines', 1)
            ->etc());
});

test('on Pro there is nothing to preview', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/workflows')
        ->assertInertia(fn (Assert $page) => $page->where('planAllows', true)->where('preview', null)->etc());
});
