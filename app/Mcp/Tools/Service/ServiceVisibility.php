<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Support\Deploys\ServiceLabels;

/**
 * What hide_service and unhide_service share: one service, or several on
 * the same machine, hidden from the panel's lists or shown again, as the
 * services list's Hide and Unhide. Nothing on the machine changes.
 */
final class ServiceVisibility
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'services' => Schema::array(Schema::service('A service, as service is given.'), 'Several services on the same machine at once, instead of service.', 100),
        ], ['machine']);
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'machine' => ['required', ServiceWork::reference()],
            'service' => ['required_without:services', ServiceWork::reference()],
            'services' => ['required_without:service', 'array', 'max:100'],
            'services.*' => [ServiceWork::reference()],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated by rules()
     * @return array<string, mixed>
     */
    public static function apply(McpContext $context, array $data, bool $hidden): array
    {
        $machine = $context->machine($data['machine']);
        $references = array_values(array_unique(isset($data['services']) ? $data['services'] : [$data['service']]));
        $services = array_map(fn ($reference) => $context->service($machine, (string) $reference), $references);
        $changed = array_values(array_filter($services, fn (array $service) => ServiceLabels::isHidden($machine, $service) !== $hidden));

        if ($changed !== []) {
            ServiceLabels::setHidden($machine, $changed, $hidden);
            $subject = count($changed) === 1 ? ServiceLabels::display($machine, $changed[0]) : count($changed).' services';
            $context->record($hidden ? 'service.hide' : 'service.unhide', $subject, $machine);
        }

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'services' => array_map(fn (array $service) => ['key' => $service['key'], 'name' => ServiceLabels::display($machine, $service), 'hidden' => $hidden], $services),
            'changed' => count($changed),
            'hiddenOnMachine' => count($machine->hidden_services ?? []),
        ];
    }
}
