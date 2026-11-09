<?php

use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;

/** A small Next.js project zipped the way people do: one folder inside. */
function nextZip(array $extra = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('my-app/package.json', json_encode(['dependencies' => ['next' => '15.0.0', 'react' => '19.0.0']]));
    $zip->addFromString('my-app/app/page.tsx', 'export default function Page() { return null }');
    $zip->addFromString('my-app/node_modules/react/index.js', 'junk');
    $zip->addFromString('my-app/.env', 'SECRET=1');
    $zip->addFromString('my-app/.env.example', 'SECRET=');
    foreach ($extra as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $path;
}

/** Runs the upload through start, chunks and finish. */
function upload($test, Workspace $workspace, Machine $machine, string $zip)
{
    $base = 'https://'.$workspace->host()."/machines/{$machine->id}/sources";
    $bytes = file_get_contents($zip);

    $start = $test->postJson($base, ['filename' => 'my-app.zip', 'size' => strlen($bytes)])->assertCreated();
    $id = $start->json('id');

    foreach (str_split($bytes, 1000) as $i => $piece) {
        $test->call('POST', "{$base}/{$id}/chunk?offset=".($i * 1000), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $piece)->assertOk();
    }

    return $test->postJson("{$base}/{$id}/finish");
}

test('an uploaded zip becomes a Git repository, detected as Next.js', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();

    $response = upload($this->actingAs($workspace->owner), $workspace, $machine, nextZip())->assertOk();

    expect($response->json('template'))->toBe('nextjs')
        ->and($response->json('commit'))->toMatch('/^[0-9a-f]{40}$/')
        ->and($response->json('repo'))->toEndWith('.git');

    $source = DeploySource::query()->sole();
    $files = trim(shell_exec('git --git-dir='.escapeshellarg($source->repoPath()).' ls-tree -r --name-only HEAD'));
    expect(explode("\n", $files))->toEqualCanonicalizing(['package.json', 'app/page.tsx', '.env.example']);
    expect(file_exists($source->zipPath()))->toBeFalse();

    File::deleteDirectory($source->directory());
});

test('zips that climb out of the folder are refused', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();

    upload($this->actingAs($workspace->owner), $workspace, $machine, nextZip(['../escape.txt' => 'x']))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('the repository is served for fetching only, and only while it lasts', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    upload($this->actingAs($workspace->owner), $workspace, $machine, nextZip())->assertOk();
    $source = DeploySource::query()->sole();
    $url = "/api/deploy-sources/{$source->token}.git";

    $refs = $this->get("{$url}/info/refs?service=git-upload-pack")->assertOk();
    expect($refs->streamedContent())->toContain('# service=git-upload-pack')->toContain($source->commit);

    $this->get("{$url}/info/refs?service=git-receive-pack")->assertForbidden();

    $source->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->get("{$url}/info/refs?service=git-upload-pack")->assertNotFound();

    File::deleteDirectory($source->directory());
});

test('viewers cannot upload', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)
        ->postJson('https://'.$workspace->host()."/machines/{$machine->id}/sources", ['filename' => 'a.zip', 'size' => 10])
        ->assertForbidden();
});
