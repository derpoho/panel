<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\ToolError;
use App\Support\Workflows\WorkflowSteps;

/**
 * A graph an agent sends, checked before it's saved. The dashboard's
 * editor can only draw valid graphs, and WorkflowSteps::clean() quietly
 * drops anything malformed; an agent gets told instead, so a step it
 * meant to add is never silently lost. Steps without a title or
 * position get what the editor would give them.
 */
final class GraphInput
{
    /** Errors listed at most. */
    private const SHOWN = 12;

    /** Rules whose text may be longer than the usual 300 characters. */
    private const LONG_RULES = ['body', 'command', 'message', 'steps'];

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} ready for WorkflowSteps::clean()
     */
    public static function prepare(mixed $graph): array
    {
        if (! is_array($graph) || array_is_list($graph) && $graph !== []) {
            throw new ToolError('graph must be an object: {"nodes": [...], "edges": [...]}. get_workflow_reference describes it.');
        }
        $nodes = $graph['nodes'] ?? null;
        $edges = $graph['edges'] ?? [];
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new ToolError('graph.nodes must be a list of steps, the trigger included.');
        }
        if (! is_array($edges) || ! array_is_list($edges)) {
            throw new ToolError('graph.edges must be a list of {source, port, target}.');
        }
        if (count($nodes) > WorkflowSteps::MAX_NODES) {
            throw new ToolError('A workflow can have up to '.WorkflowSteps::MAX_NODES.' steps; this graph has '.count($nodes).'.');
        }
        if (count($edges) > WorkflowSteps::MAX_EDGES) {
            throw new ToolError('A workflow can have up to '.WorkflowSteps::MAX_EDGES.' edges; this graph has '.count($edges).'.');
        }

        $errors = [];
        $kinds = [];
        $triggers = 0;
        foreach ($nodes as $i => $node) {
            $at = "nodes[{$i}]";
            if (! is_array($node) || array_is_list($node) && $node !== []) {
                $errors[] = "{$at} must be an object.";

                continue;
            }
            $id = $node['id'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
                $errors[] = "{$at}.id must be 1–64 letters, digits, “-” or “_”.";

                continue;
            }
            $at = "step “{$id}”";
            if (isset($kinds[$id])) {
                $errors[] = "Two steps have the id “{$id}”.";

                continue;
            }
            $kind = $node['kind'] ?? null;
            if (! is_string($kind) || ! isset(WorkflowSteps::PORTS[$kind])) {
                $errors[] = "{$at}: kind must be one of ".implode(', ', array_keys(WorkflowSteps::PORTS)).'.';

                continue;
            }
            $kinds[$id] = $kind;
            if ($kind === 'trigger' && ++$triggers === 2) {
                $errors[] = 'A workflow has one trigger; this graph has more.';
            }
            foreach (['title' => 80, 'description' => 280] as $field => $max) {
                if (isset($node[$field]) && (! is_string($node[$field]) || mb_strlen(trim($node[$field])) > $max)) {
                    $errors[] = "{$at}: {$field} must be text of up to {$max} characters.";
                }
            }
            foreach (['x', 'y'] as $field) {
                if (isset($node[$field]) && ! is_int($node[$field]) && ! is_float($node[$field])) {
                    $errors[] = "{$at}: {$field} must be a number.";
                }
            }
            $rules = $node['rules'] ?? [];
            if (! is_array($rules) || array_is_list($rules) && $rules !== []) {
                $errors[] = "{$at}: rules must be an object of settings.";

                continue;
            }
            foreach ($rules as $key => $value) {
                if (! is_string($key) || preg_match('/^[a-z_]{1,32}$/', $key) !== 1) {
                    $errors[] = "{$at}: “{$key}” isn’t a rule name (lowercase letters and “_”).";
                } elseif (! is_scalar($value) && $value !== null) {
                    $errors[] = "{$at}: rules.{$key} must be a string, number, boolean or null.";
                } elseif (is_string($value) && mb_strlen($value) > ($max = in_array($key, self::LONG_RULES, true) ? ($key === 'steps' ? 6000 : 2000) : 300)) {
                    $errors[] = "{$at}: rules.{$key} is longer than {$max} characters.";
                }
            }
        }

        foreach ($edges as $i => $edge) {
            $at = "edges[{$i}]";
            if (! is_array($edge) || array_is_list($edge) && $edge !== []) {
                $errors[] = "{$at} must be an object.";

                continue;
            }
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            $port = $edge['port'] ?? 'out';
            if (! is_string($source) || ! isset($kinds[$source])) {
                $errors[] = "{$at}: source must be the id of a step in nodes.";

                continue;
            }
            if (! is_string($target) || ! isset($kinds[$target])) {
                $errors[] = "{$at}: target must be the id of a step in nodes.";

                continue;
            }
            if ($source === $target) {
                $errors[] = "{$at}: a step can’t lead to itself.";
            } elseif ($kinds[$target] === 'trigger') {
                $errors[] = "{$at}: nothing can lead into the trigger.";
            } elseif (! is_string($port) || ! in_array($port, WorkflowSteps::PORTS[$kinds[$source]], true)) {
                $errors[] = "{$at}: a {$kinds[$source]} step leaves by ".implode(' or ', WorkflowSteps::PORTS[$kinds[$source]]).', not “'.(is_scalar($port) ? $port : '?').'”.';
            }
            if (isset($edge['id']) && (! is_string($edge['id']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $edge['id']) !== 1)) {
                $errors[] = "{$at}: id must be 1–64 letters, digits, “-” or “_” (or left out).";
            }
        }

        if ($errors !== []) {
            $shown = array_slice($errors, 0, self::SHOWN);
            $more = count($errors) - count($shown);

            throw new ToolError('The graph wasn’t saved: '.implode(' ', $shown).($more > 0 ? " (and {$more} more)" : '').' get_workflow_reference describes steps, ports and rules.');
        }

        return self::layout([
            'nodes' => array_map(fn (array $node) => [
                ...$node,
                'title' => array_key_exists('title', $node) && $node['title'] !== null ? $node['title'] : (WorkflowReference::STEP_WORDS[$node['kind']][1] ?? ''),
                'description' => array_key_exists('description', $node) && $node['description'] !== null ? $node['description'] : (WorkflowReference::STEP_WORDS[$node['kind']][2] ?? ''),
                'rules' => $node['rules'] ?? [],
            ], $nodes),
            'edges' => array_map(fn (array $edge) => [...$edge, 'port' => $edge['port'] ?? 'out'], $edges),
        ]);
    }

    /**
     * Place steps that came without a position: in rows under the
     * trigger by how many steps lead to them, side by side within a row,
     * as the templates are drawn. Steps nothing leads to go in a row of
     * their own at the bottom.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private static function layout(array $graph): array
    {
        $placed = fn (array $node) => isset($node['x'], $node['y']);
        if (collect($graph['nodes'])->every($placed)) {
            return $graph;
        }

        $depth = [];
        $queue = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['kind'] === 'trigger') {
                $depth[$node['id']] = 0;
                $queue[] = $node['id'];
            }
        }
        while (($id = array_shift($queue)) !== null) {
            foreach ($graph['edges'] as $edge) {
                if ($edge['source'] === $id && ! isset($depth[$edge['target']])) {
                    $depth[$edge['target']] = $depth[$id] + 1;
                    $queue[] = $edge['target'];
                }
            }
        }
        $bottom = ($depth === [] ? -1 : max($depth)) + 1;

        $rows = [];
        foreach ($graph['nodes'] as $i => $node) {
            if (! $placed($node)) {
                $rows[$depth[$node['id']] ?? $bottom][] = $i;
            }
        }
        foreach ($rows as $row => $indexes) {
            $count = count($indexes);
            foreach ($indexes as $n => $i) {
                // Nodes are 400 wide; x is the left edge, centred on 0.
                $graph['nodes'][$i]['x'] = (int) round(($n - ($count - 1) / 2) * 440) - 200;
                $graph['nodes'][$i]['y'] = $row * 200;
            }
        }

        return $graph;
    }
}
