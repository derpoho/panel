<?php

namespace App\Support\Workflows;

use Illuminate\Support\Str;

/**
 * Lays nodes out for templates. Positions are the node's centre-top; the
 * editor's nodes are 400 wide.
 */
final class GraphBuilder
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    /**
     * @param  array<string, mixed>  $rules
     */
    public function node(string $kind, int $x, int $y, string $title, string $description, array $rules, ?string $from = null, string $port = 'out'): string
    {
        $id = Str::lower(Str::random(12));
        $this->nodes[] = ['id' => $id, 'kind' => $kind, 'x' => $x - 200, 'y' => $y, 'title' => $title, 'description' => $description, 'rules' => array_filter($rules, fn ($v) => $v !== null)];
        if ($from !== null) {
            $this->edges[] = ['id' => Str::lower(Str::random(12)), 'source' => $from, 'port' => $port, 'target' => $id];
        }

        return $id;
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function graph(): array
    {
        return WorkflowSteps::clean(['nodes' => $this->nodes, 'edges' => $this->edges]);
    }
}
