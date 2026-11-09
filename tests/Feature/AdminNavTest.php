<?php

use App\Models\AdminAction;
use App\Models\User;
use App\Support\Admin\AdminPages;
use App\Support\Extensions\AdminNavItem;
use App\Support\Extensions\Extensions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/** @return list<array{0: string, 1: list<string>}> each group's key and its items' keys */
function adminNavKeys(Extensions $extensions, User $user): array
{
    return array_map(fn (array $group) => [$group['key'], array_column($group['items'], 'key')], $extensions->adminNavFor($user));
}

test('without the packages, the admin sidebar has only the pages the panel serves itself', function () {
    // As an install without the packages has it.
    app()->instance(Extensions::class, $extensions = new Extensions);
    AdminPages::register($extensions);
    $admin = User::factory()->admin()->create();

    expect(adminNavKeys($extensions, $admin))->toBe([
        ['platform', ['pulse']],
    ]);

    // Shared with admin pages only, for the staff, with nothing a package
    // shares. A page of the admin's own, since every Inertia one under
    // /admin is a package's.
    Route::middleware('web')->get('admin/nav-test', fn () => Inertia::render('errors/Error'))->name('admin.nav-test');
    $this->actingAs($admin)->get('/oauth/authorize?client_id=none')->assertInertia(fn (Assert $page) => $page->missing('adminNav'));
    $this->actingAs($admin)->get('https://serveros.test/admin/nav-test')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('adminNav.0.key', 'platform')
        ->where('adminNav.0.items.0', [
            'key' => 'pulse', 'label' => 'Pulse', 'icon' => 'heart-pulse', 'href' => '/admin/pulse',
            'components' => [], 'badge' => null, 'external' => true,
        ])
        ->missing('anvilUnread')
        ->missing('supportWaiting'));

    // Someone who isn't staff sees none of it.
    expect($extensions->adminNavFor(User::factory()->create()))->toBe([])
        ->and($extensions->adminNavFor(null))->toBe([]);
});

test('admin sidebar items go where they say, whichever order they were added in', function () {
    $item = fn (string $key, ?string $after = null, ?string $before = null) => new AdminNavItem(
        key: $key, label: ucfirst($key), icon: 'rocket', path: "/admin/{$key}", group: 'growth', after: $after, before: $before,
    );
    $keys = fn (array $items) => array_map(fn (AdminNavItem $i) => $i->key, AdminNavItem::arrange($items));

    // The panel's add after the package's, the package's after the panel's: the same order.
    expect($keys([$item('reports'), $item('exports'), $item('imports'), $item('jobs'), $item('queues', before: 'reports'), $item('logs', after: 'imports')]))
        ->toBe(['queues', 'reports', 'exports', 'imports', 'logs', 'jobs'])
        ->and($keys([$item('queues', before: 'reports'), $item('logs', after: 'imports'), $item('reports'), $item('exports'), $item('imports'), $item('jobs')]))
        ->toBe(['queues', 'reports', 'exports', 'imports', 'logs', 'jobs'])
        // Before wins over after when both are there; one whose item isn't there goes at the end.
        ->and($keys([$item('a'), $item('b'), $item('c', after: 'a', before: 'b'), $item('d', after: 'missing')]))
        ->toBe(['a', 'c', 'b', 'd'])
        ->and($keys([$item('x', after: 'y'), $item('y', after: 'x')]))->toBe(['x', 'y']);

    expect(fn () => new AdminNavItem(key: 'k', label: 'K', icon: 'rocket', path: '/k', group: 'nowhere'))
        ->toThrow(InvalidArgumentException::class);
});

test('a package’s models can be what a staff action is about, under the name it gives', function () {
    $extensions = app(Extensions::class);
    $extensions->addAuditTarget('test_admin_user', AdminNavTestModel::class, fn (AdminNavTestModel $m) => "Model {$m->getKey()}");
    $admin = User::factory()->admin()->create();
    $model = (new AdminNavTestModel)->forceFill(['id' => 7]);

    AdminAction::record($admin, 'test_admin_user.poked', $model);

    $action = AdminAction::query()->sole();
    expect($action->target_type)->toBe('test_admin_user')
        ->and($action->target_id)->toBe(7)
        ->and($action->target_label)->toBe('Model 7')
        ->and(AdminAction::targets())->toMatchArray(['user' => User::class, 'test_admin_user' => AdminNavTestModel::class]);
});

/** A package's model, for the audit target test. */
class AdminNavTestModel extends Model
{
    protected $table = 'users';
}
