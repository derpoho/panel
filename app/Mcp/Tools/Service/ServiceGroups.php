<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Models\ServiceGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Service groups as the machine page keeps them: named sets of one
 * machine's services (a service sits in one at most), which are also the
 * sections of the machine's status page. A group remembers a deployed app
 * by its name and anything else by its inventory key.
 */
final class ServiceGroups
{
    /** @return Collection<int, ServiceGroup> by name, as the machine page lists them */
    public static function of(Machine $machine): Collection
    {
        return $machine->serviceGroups()->orderBy('name')->get();
    }

    /** A group on the machine, by id or name (any case). */
    public static function find(Machine $machine, mixed $reference): ServiceGroup
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which group: its id or name. list_service_groups shows them.');
        }

        $groups = self::of($machine);
        if (ctype_digit($reference) && ($group = $groups->firstWhere('id', (int) $reference)) !== null) {
            return $group;
        }

        $matches = $groups->filter(fn (ServiceGroup $g) => mb_strtolower($g->name) === mb_strtolower($reference));
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several groups on {$machine->name} are called “{$reference}”: ".$matches->map(fn (ServiceGroup $g) => "#{$g->id}")->implode(', ').'. Use the id.');
        }

        throw new ToolError("No service group “{$reference}” on {$machine->name}.".($groups->isEmpty() ? ' It has none yet.' : ' It has: '.$groups->pluck('name')->implode(', ').'.'));
    }

    /**
     * Whether the group holds this service: by its key, or a deployed app
     * by its name.
     *
     * @param  array<string, mixed>  $service  from McpContext::describeService()
     */
    public static function holds(ServiceGroup $group, array $service): bool
    {
        $members = $group->services ?? [];

        return in_array($service['key'], $members, true) || in_array(ServiceWork::memberKey($service), $members, true);
    }

    /**
     * The group a service is in, if any.
     *
     * @param  Collection<int, ServiceGroup>  $groups
     * @param  array<string, mixed>  $service
     */
    public static function containing(Collection $groups, array $service): ?ServiceGroup
    {
        return $groups->first(fn (ServiceGroup $group) => self::holds($group, $service));
    }

    /**
     * The services named, as the keys a group keeps.
     *
     * @param  list<mixed>  $references
     * @return list<string>
     */
    public static function members(McpContext $context, Machine $machine, array $references): array
    {
        return array_values(array_unique(array_map(
            fn ($reference) => ServiceWork::memberKey($context->service($machine, $reference)),
            $references,
        )));
    }

    /**
     * The group with its services as they are on the machine now. Keys
     * whose service is gone (removed, or not seen since the last scan) are
     * listed as missing.
     *
     * @return array<string, mixed>
     */
    public static function present(McpContext $context, Machine $machine, ServiceGroup $group): array
    {
        $services = $context->services($machine)->map(fn (array $s) => $context->describeService($machine, $s));
        $inside = $services->filter(fn (array $s) => self::holds($group, $s))->values();
        $found = $inside->flatMap(fn (array $s) => [$s['key'], ServiceWork::memberKey($s)])->all();

        return [
            'id' => $group->id,
            'name' => $group->name,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'icon' => $group->icon_path === null ? null : Storage::disk('public')->url($group->icon_path),
            'services' => $inside->map(fn (array $s) => [
                'key' => $s['key'],
                'name' => $s['display'],
                'status' => $s['status'] ?? 'unknown',
            ])->all(),
            'missing' => array_values(array_diff($group->services ?? [], $found)),
            'url' => $context->url("/machines/{$machine->id}"),
        ];
    }

    /**
     * A group's fields, checked as ServiceGroupController does; services
     * are references, resolved to keys after.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $creating): array
    {
        return [
            'machine' => ['required', ServiceWork::reference()],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60', 'regex:/\S/'],
            'services' => ['sometimes', 'array', 'list', 'max:500'],
            'services.*' => ['string', 'max:300'],
        ];
    }

    /**
     * The machine's other groups that will lose services to this one.
     *
     * @param  list<string>  $members
     * @return list<array{id: int, name: string, services: list<string>}>
     */
    public static function losing(Machine $machine, ?ServiceGroup $group, array $members): array
    {
        return array_values(self::of($machine)
            ->reject(fn (ServiceGroup $other) => $other->id === $group?->id)
            ->map(fn (ServiceGroup $other) => ['id' => $other->id, 'name' => $other->name, 'services' => array_values(array_intersect($other->services ?? [], $members))])
            ->filter(fn (array $other) => $other['services'] !== [])
            ->all());
    }
}
