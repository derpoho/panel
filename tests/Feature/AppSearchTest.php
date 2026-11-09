<?php

use App\Support\ServiceTemplates\ServiceTemplateCatalog;

test('a search ranks names that start with it first, featured apps leading', function () {
    $names = array_column(app(ServiceTemplateCatalog::class)->search('post', 3), 'name');

    expect($names)->toBe(['PostgreSQL', 'PostGIS', 'PostgREST']);
});

test('an exact name comes before names that only contain it', function () {
    $first = app(ServiceTemplateCatalog::class)->search('redis')[0];

    expect($first['slug'])->toBe('redis');
});

test('a search falls back to descriptions', function () {
    $slugs = array_column(app(ServiceTemplateCatalog::class)->search('embeddings', 10), 'slug');

    expect($slugs)->toContain('pgvector');
});

test('an empty search finds nothing', function () {
    expect(app(ServiceTemplateCatalog::class)->search('   '))->toBe([]);
});

test('every featured app is in the catalogue', function () {
    $catalogue = app(ServiceTemplateCatalog::class)->all();

    foreach (ServiceTemplateCatalog::FEATURED as $slug) {
        expect($catalogue)->toHaveKey($slug);
    }
});
