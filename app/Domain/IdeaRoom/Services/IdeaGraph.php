<?php

declare(strict_types=1);

namespace Leantime\Domain\IdeaRoom\Services;

use InvalidArgumentException;
use Leantime\Core\Auth\Permissions\PermissionService;
use Leantime\Core\Exceptions\AuthorizationException;
use Leantime\Core\Exceptions\NotFoundException;
use Leantime\Domain\IdeaRoom\AI\PlanPatch;
use Leantime\Domain\IdeaRoom\Repositories\GraphRepository;
use Leantime\Domain\IdeaRoom\Repositories\RoomRepository;
use Leantime\Domain\IdeaRoom\Support\GraphConflictException;
use Leantime\Domain\IdeaRoom\Support\GraphInput;
use Leantime\Domain\IdeaRoom\Support\Plan;
use Leantime\Domain\Projects\Permissions\ProjectsPermissions;

final class IdeaGraph
{
    public function __construct(
        private readonly IdeaRoom $ideaRooms,
        private readonly GraphRepository $graphs,
        private readonly RoomRepository $rooms,
        private readonly PermissionService $permissions,
    ) {}

    /** @return array{graph: array<string, mixed>} */
    public function graph(int $roomId): array
    {
        $room = $this->ideaRooms->room($roomId);
        $nodes = array_values(array_filter($this->graphs->nodes($roomId), fn (array $node): bool => $this->canViewNode($node)));
        $visible = array_fill_keys(array_column($nodes, 'id'), true);
        $links = array_values(array_filter($this->graphs->links($roomId), static fn (array $link): bool => isset($visible[$link['sourceId']], $visible[$link['targetId']])));

        return ['graph' => [
            'version' => (int) ($room['graph_version'] ?? 0),
            'mode' => in_array(($room['mode'] ?? ''), ['explore', 'execute'], true) ? $room['mode'] : 'explore',
            'nodes' => $nodes,
            'links' => $links,
        ]];
    }

    /** @return list<array{id: int, title: string}> */
    public function linkableRooms(int $roomId): array
    {
        $this->ideaRooms->room($roomId);
        $visible = [];
        foreach ($this->graphs->roomCandidates($roomId) as $candidate) {
            try {
                $this->ideaRooms->room($candidate['id']);
                $visible[] = $candidate;
            } catch (AuthorizationException|NotFoundException) {
                // Do not reveal rooms or project names outside current permissions.
            }
        }

        return $visible;
    }

    /** Replace the caller-visible graph, preserving references now inaccessible to them.
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $links
     * @return array{graph: array<string, mixed>}
     */
    public function save(int $roomId, int $expectedVersion, array $nodes, array $links, bool $recordHistory = true): array
    {
        if (! array_is_list($nodes) || ! array_is_list($links)
            || count($nodes) > GraphInput::MAX_NODES || count($links) > GraphInput::MAX_LINKS) {
            throw new InvalidArgumentException('The canvas exceeds its size limit.');
        }
        $nodes = array_map(static fn ($node): array => is_array($node) ? GraphInput::node($node) : throw new InvalidArgumentException('Invalid canvas node.'), $nodes);
        $links = array_map(static fn ($link): array => is_array($link) ? GraphInput::link($link) : throw new InvalidArgumentException('Invalid canvas link.'), $links);

        return $this->graphs->transaction(function () use ($roomId, $expectedVersion, $nodes, $links, $recordHistory): array {
            $room = $this->editableRoom($roomId, $expectedVersion);
            if ($recordHistory) {
                $this->ensureHistoryBaseline($roomId, $room);
            }
            $existingNodes = $this->graphs->nodes($roomId);
            $existingLinks = $this->graphs->links($roomId);
            $visibleExisting = [];
            $hiddenExisting = [];
            foreach ($existingNodes as $node) {
                if ($this->canViewNode($node)) {
                    $visibleExisting[$node['id']] = $node;
                } else {
                    $hiddenExisting[$node['id']] = $node;
                }
            }
            if (count($nodes) + count($hiddenExisting) > GraphInput::MAX_NODES) {
                throw new InvalidArgumentException('The canvas exceeds its node limit.');
            }
            $nodeIds = [];
            $clientNodeIds = [];
            foreach ($nodes as $node) {
                $id = $node['id'];
                if ($id !== null) {
                    if (isset($hiddenExisting[$id])) {
                        throw new AuthorizationException;
                    }
                    if (! isset($visibleExisting[$id]) || isset($nodeIds[$id])) {
                        throw new InvalidArgumentException('The canvas contains a duplicate or missing node.');
                    }
                } elseif (isset($clientNodeIds[$node['clientId']])) {
                    throw new InvalidArgumentException('The canvas contains a duplicate client ID.');
                }
                $this->authorizeNodeReference($roomId, $node);
                $savedId = $this->graphs->putNode($roomId, $node, $this->userId());
                $nodeIds[$savedId] = true;
                if ($id === null) {
                    $clientNodeIds[$node['clientId']] = $savedId;
                }
            }

            $visibleLinks = [];
            $hiddenLinks = [];
            foreach ($existingLinks as $link) {
                if (isset($visibleExisting[$link['sourceId']], $visibleExisting[$link['targetId']])) {
                    $visibleLinks[$link['id']] = $link;
                } else {
                    $hiddenLinks[$link['id']] = $link;
                }
            }
            if (count($links) + count($hiddenLinks) > GraphInput::MAX_LINKS) {
                throw new InvalidArgumentException('The canvas exceeds its link limit.');
            }
            $linkIds = [];
            $clientLinkIds = [];
            $edgeKeys = [];
            foreach ($links as $link) {
                $id = $link['id'];
                if ($id !== null && (! isset($visibleLinks[$id]) || isset($linkIds[$id]))) {
                    throw new InvalidArgumentException('The canvas contains a duplicate or inaccessible link.');
                }
                if ($id === null && isset($clientLinkIds[$link['clientId']])) {
                    throw new InvalidArgumentException('The canvas contains a duplicate client ID.');
                }
                $link['sourceId'] = $this->resolveEndpoint($link['sourceId'], $nodeIds, $clientNodeIds);
                $link['targetId'] = $this->resolveEndpoint($link['targetId'], $nodeIds, $clientNodeIds);
                if ($link['sourceId'] === $link['targetId']) {
                    throw new InvalidArgumentException('A canvas link cannot point to itself.');
                }
                $edgeKey = $link['sourceId'].':'.$link['targetId'].':'.$link['type'];
                if (isset($edgeKeys[$edgeKey])) {
                    throw new InvalidArgumentException('The canvas contains a duplicate link.');
                }
                $edgeKeys[$edgeKey] = true;
                $savedId = $this->graphs->putLink($roomId, $link);
                $linkIds[$savedId] = true;
                if ($id === null) {
                    $clientLinkIds[$link['clientId']] = true;
                }
            }
            $this->graphs->deleteNodes($roomId, array_values(array_diff(array_keys($visibleExisting), array_keys($nodeIds))));
            $this->graphs->deleteLinks($roomId, array_values(array_diff(array_keys($visibleLinks), array_keys($linkIds))));
            $this->graphs->bumpVersion($roomId);
            $version = (int) $room['graph_version'] + 1;
            $this->rooms->addEvent($roomId, 'graph.updated', ['version' => $version]);

            if ($recordHistory) {
                $this->recordHistory($roomId, 'manual', 'Canvas edited');
            }

            return $this->graph($roomId);
        });
    }

    /** @return list<array<string, mixed>> */
    public function sources(int $roomId): array
    {
        $this->ideaRooms->room($roomId);

        return $this->graphs->sources($roomId);
    }

    /** @param list<array<string, mixed>> $citations
     * @return list<array<string, mixed>>
     */
    public function storeSources(int $roomId, array $citations): array
    {
        $this->ideaRooms->room($roomId);
        if (! array_is_list($citations) || count($citations) > 10) {
            throw new InvalidArgumentException('Too many research results.');
        }
        $validated = [];
        foreach ($citations as $citation) {
            if (! is_array($citation)) {
                throw new InvalidArgumentException('Invalid research result.');
            }
            $url = $citation['url'] ?? null;
            $title = $citation['title'] ?? null;
            $snippet = $citation['snippet'] ?? '';
            $provider = $citation['provider'] ?? 'google';
            $query = $citation['query'] ?? '';
            if (! is_string($url) || strlen($url) > 2048 || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)
                || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 255
                || ! is_string($snippet) || mb_strlen($snippet) > 2000
                || ! is_string($provider) || mb_strlen($provider) > 64
                || ! is_string($query) || mb_strlen($query) > 500) {
                throw new InvalidArgumentException('Invalid research result.');
            }
            $domain = parse_url($url, PHP_URL_HOST);
            if (! is_string($domain) || $domain === '' || mb_strlen($domain) > 255) {
                throw new InvalidArgumentException('Invalid research result URL.');
            }
            $validated[] = [
                'url' => $url,
                'title' => trim($title),
                'snippet' => $snippet,
                'domain' => strtolower($domain),
                'provider' => $provider,
                'query' => $query,
            ];
        }

        return $this->graphs->transaction(function () use ($roomId, $validated): array {
            $saved = [];
            foreach ($validated as $citation) {
                $saved[] = $this->graphs->addSource($roomId, $citation);
            }

            return $saved;
        });
    }

    /** @return array{graph: array<string, mixed>} */
    public function keepSource(int $roomId, int $sourceId, int $expectedVersion): array
    {
        $room = $this->editableRoom($roomId, $expectedVersion);
        $source = $this->graphs->source($roomId, $sourceId) ?? throw new NotFoundException;
        $graph = $this->graph($roomId)['graph'];
        foreach ($graph['nodes'] as $node) {
            if (($node['metadata']['sourceId'] ?? null) === $sourceId) {
                return ['graph' => $graph];
            }
        }
        $node = [
            'clientId' => 'source-'.$sourceId,
            'type' => 'source',
            'title' => (string) $source['title'],
            'content' => (string) $source['snippet'],
            'x' => 80.0 + (count($graph['nodes']) % 8) * 240.0,
            'y' => 80.0 + intdiv(count($graph['nodes']), 8) * 180.0,
            'metadata' => ['sourceId' => $sourceId, 'url' => $source['url'], 'domain' => $source['domain']],
        ];
        $graph['nodes'][] = $node;

        return $this->save($roomId, (int) $room['graph_version'], $graph['nodes'], $graph['links']);
    }

    /** @return array{graph: array<string, mixed>} */
    public function mode(int $roomId, string $mode, ?int $expectedVersion = null): array
    {
        if (! in_array($mode, ['explore', 'execute'], true)) {
            throw new InvalidArgumentException('Invalid Idea Room mode.');
        }

        return $this->graphs->transaction(function () use ($roomId, $mode, $expectedVersion): array {
            $room = $this->editableRoom($roomId, $expectedVersion);
            if ($room['mode'] !== $mode) {
                $this->ensureHistoryBaseline($roomId, $room);
                $this->graphs->setMode($roomId, $mode);
                $this->graphs->bumpVersion($roomId);
                $this->rooms->addEvent($roomId, 'graph.updated', ['version' => (int) $room['graph_version'] + 1, 'mode' => $mode]);
                $this->recordHistory($roomId, 'manual', 'Canvas mode changed');
            }

            return $this->graph($roomId);
        });
    }

    /** @return list<array<string, mixed>> */
    public function proposals(int $roomId): array
    {
        $this->ideaRooms->room($roomId);

        return array_values(array_filter($this->graphs->proposals($roomId), fn (array $proposal): bool => $this->proposalReferencesVisible($roomId, $proposal['patch'])));
    }

    /** @return array{proposals: list<array<string, mixed>>, nextCursor: int|null} */
    public function proposalsPage(int $roomId, int $beforeId = 0, int $limit = 20): array
    {
        $this->ideaRooms->room($roomId);
        if ($beforeId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Invalid proposal page.');
        }

        $scanned = $this->graphs->proposals($roomId, $beforeId, $limit);

        return [
            'proposals' => array_values(array_filter($scanned, fn (array $proposal): bool => $this->proposalReferencesVisible($roomId, $proposal['patch']))),
            'nextCursor' => count($scanned) === $limit ? (int) $scanned[count($scanned) - 1]['id'] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recoverableNodes(int $roomId): array
    {
        $this->ideaRooms->room($roomId);

        return array_values(array_filter($this->graphs->deletedNodes($roomId), fn (array $node): bool => $this->canViewNode($node)));
    }

    /** Restore a user's deleted nodes without changing their original IDs. @param list<int> $nodeIds
     * @return array{graph: array<string, mixed>}
     */
    public function restoreNodes(int $roomId, array $nodeIds, int $expectedVersion): array
    {
        if (! $this->validIdList($nodeIds, GraphInput::MAX_NODES) || $nodeIds === []) {
            throw new InvalidArgumentException('Invalid nodes to restore.');
        }

        return $this->graphs->transaction(function () use ($roomId, $nodeIds, $expectedVersion): array {
            $room = $this->editableRoom($roomId, $expectedVersion);
            $this->assertRestorableNodes($roomId, $nodeIds);
            $this->ensureHistoryBaseline($roomId, $room);
            $this->graphs->restoreNodes($roomId, $nodeIds);
            $graph = $this->graph($roomId)['graph'];
            if (count($graph['nodes']) > GraphInput::MAX_NODES || count($graph['links']) > GraphInput::MAX_LINKS) {
                throw new InvalidArgumentException('The restored canvas exceeds its size limit.');
            }
            $this->graphs->bumpVersion($roomId);
            $this->rooms->addEvent($roomId, 'graph.updated', ['version' => $expectedVersion + 1]);
            $this->recordHistory($roomId, 'manual', 'Canvas nodes restored');

            return $this->graph($roomId);
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(int $roomId): array
    {
        $this->ideaRooms->room($roomId);
        $entries = $this->graphs->history($roomId);
        $currentId = $entries[0]['id'] ?? null;

        return array_map(static fn (array $entry): array => $entry + ['isCurrent' => $entry['id'] === $currentId], $entries);
    }

    /**
     * Historic snapshots contain the complete graph, including references that
     * may have become inaccessible. Apply the same node visibility rule as the
     * live graph before a snapshot is rendered in the read-only archive.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function filterSnapshotForCurrentUser(int $roomId, array $entry): array
    {
        $this->ideaRooms->room($roomId);
        $snapshot = is_array($entry['graph'] ?? null) ? $entry['graph'] : [];
        $nodes = array_values(array_filter(
            is_array($snapshot['nodes'] ?? null) ? $snapshot['nodes'] : [],
            fn ($node): bool => is_array($node) && $this->canViewNode($node),
        ));
        $visible = array_fill_keys(array_column($nodes, 'id'), true);
        $links = array_values(array_filter(
            is_array($snapshot['links'] ?? null) ? $snapshot['links'] : [],
            static fn ($link): bool => is_array($link)
                && isset($visible[$link['sourceId'] ?? null], $visible[$link['targetId'] ?? null]),
        ));
        $entry['graph'] = ['mode' => $snapshot['mode'] ?? 'explore', 'nodes' => $nodes, 'links' => $links];

        return $entry;
    }

    /** @param array<string, mixed> $plan
     * @return array{plan: array<string, mixed>, status: string, planVersion: int, historyEntry: array<string, mixed>}
     */
    public function replacePlan(int $roomId, array $plan): array
    {
        $plan = Plan::replace($plan);

        return $this->graphs->transaction(function () use ($roomId, $plan): array {
            $room = $this->editableRoom($roomId, null);
            $this->ensureHistoryBaseline($roomId, $room);
            $this->rooms->updatePlan($roomId, $plan, 'ready_for_review');
            $entry = $this->recordHistory($roomId, 'manual', 'Plan edited');

            return ['plan' => $plan, 'status' => 'ready_for_review', 'planVersion' => $entry['planVersion'], 'historyEntry' => $entry];
        });
    }

    /** Apply an AI or MCP canvas/plan patch immediately, without creating workspace records.
     * @param array<string, mixed> $patch
     * @param list<array<string, mixed>> $inspirationCards
     * @return array{graph: array<string, mixed>, plan: array<string, mixed>, planVersion: int, historyEntry: array<string, mixed>}
     */
    public function applyPatch(int $roomId, array $patch, array $inspirationCards = [], string $origin = 'chat', string $summary = '', ?int $expectedGraphVersion = null, ?int $expectedPlanVersion = null): array
    {
        if (! in_array($origin, ['chat', 'mcp'], true) || mb_strlen($summary) > 255) {
            throw new InvalidArgumentException('Invalid canvas change metadata.');
        }

        return $this->graphs->transaction(function () use ($roomId, $patch, $inspirationCards, $origin, $summary, $expectedGraphVersion, $expectedPlanVersion): array {
            $room = $this->editableRoom($roomId, $expectedGraphVersion);
            if ($expectedPlanVersion !== null && (int) $room['plan_version'] !== $expectedPlanVersion) {
                throw new GraphConflictException;
            }
            $patch = $this->addInspirationNodes($roomId, $patch, $inspirationCards);
            $validated = $this->validateProposalPatch($roomId, $patch);
            if ($validated['nodes'] === [] && $validated['links'] === [] && $validated['removeNodeIds'] === []
                && $validated['removeLinkIds'] === [] && $validated['restoreNodeIds'] === []
                && $validated['plan'] === [] && ($validated['mode'] === null || $validated['mode'] === $room['mode'])) {
                throw new InvalidArgumentException('The canvas change is empty.');
            }
            $plan = $validated['plan'] === [] ? $room['plan'] : Plan::merge($room['plan'], $validated['plan']);
            $this->ensureHistoryBaseline($roomId, $room);

            if ($validated['restoreNodeIds'] !== []) {
                $this->assertRestorableNodes($roomId, $validated['restoreNodeIds']);
                $this->graphs->restoreNodes($roomId, $validated['restoreNodeIds']);
            }
            $current = $this->graph($roomId)['graph'];
            $nodes = $this->mergeItems($current['nodes'], $validated['nodes'], $validated['removeNodeIds']);
            $remainingLinks = array_values(array_filter($current['links'], static fn (array $link): bool => ! in_array($link['sourceId'], $validated['removeNodeIds'], true)
                && ! in_array($link['targetId'], $validated['removeNodeIds'], true)));
            $links = $this->mergeItems($remainingLinks, $validated['links'], $validated['removeLinkIds']);
            $graphChanged = $validated['nodes'] !== [] || $validated['links'] !== [] || $validated['removeNodeIds'] !== []
                || $validated['removeLinkIds'] !== [] || $validated['restoreNodeIds'] !== [];
            if ($graphChanged) {
                $this->save($roomId, (int) $room['graph_version'], $nodes, $links, false);
            }
            if ($validated['mode'] !== null && $validated['mode'] !== $current['mode']) {
                $this->graphs->setMode($roomId, $validated['mode']);
                if (! $graphChanged) {
                    $this->graphs->bumpVersion($roomId);
                    $this->rooms->addEvent($roomId, 'graph.updated', ['version' => (int) $room['graph_version'] + 1, 'mode' => $validated['mode']]);
                }
            }
            if ($validated['plan'] !== []) {
                $this->rooms->updatePlan($roomId, $plan, 'ready_for_review');
            }
            $entry = $this->recordHistory($roomId, $origin, $summary !== '' ? $summary : 'AI canvas change');

            return ['graph' => $this->graph($roomId)['graph'], 'plan' => $plan, 'planVersion' => $entry['planVersion'], 'historyEntry' => $entry];
        });
    }

    /** @return array{graph: array<string, mixed>, plan: array<string, mixed>, planVersion: int, historyEntry: array<string, mixed>} */
    public function restoreHistory(int $roomId, int $entryId, int $expectedGraphVersion, int $expectedPlanVersion): array
    {
        return $this->graphs->transaction(function () use ($roomId, $entryId, $expectedGraphVersion, $expectedPlanVersion): array {
            $room = $this->editableRoom($roomId, $expectedGraphVersion);
            if ((int) $room['plan_version'] !== $expectedPlanVersion) {
                throw new GraphConflictException;
            }
            $entry = $this->graphs->historyEntry($roomId, $entryId) ?? throw new NotFoundException;
            $latest = $this->graphs->latestHistory($roomId);
            if (($latest['id'] ?? null) === $entryId) {
                unset($entry['graph'], $entry['plan']);

                return ['graph' => $this->graph($roomId)['graph'], 'plan' => $room['plan'], 'planVersion' => (int) $room['plan_version'], 'historyEntry' => $entry + ['isCurrent' => true]];
            }
            $snapshot = $entry['graph'];
            if (! is_array($snapshot) || ! is_array($snapshot['nodes'] ?? null) || ! array_is_list($snapshot['nodes'])
                || ! is_array($snapshot['links'] ?? null) || ! array_is_list($snapshot['links'])
                || count($snapshot['nodes']) > GraphInput::MAX_NODES || count($snapshot['links']) > GraphInput::MAX_LINKS
                || ! in_array($snapshot['mode'] ?? null, ['explore', 'execute'], true)
                || ! is_array($entry['plan'])) {
                throw new InvalidArgumentException('Invalid canvas history snapshot.');
            }
            // A snapshot is room-scoped but references may become inaccessible later.
            // Refuse the whole restore rather than exposing or silently mutating them.
            foreach ($this->graphs->nodes($roomId) as $node) {
                if (! $this->canViewNode($node)) {
                    throw new AuthorizationException;
                }
            }
            $nodes = [];
            $nodeIds = [];
            foreach ($snapshot['nodes'] as $node) {
                $validated = is_array($node) ? GraphInput::node($node) : throw new InvalidArgumentException('Invalid canvas history node.');
                if ($validated['id'] === null || isset($nodeIds[$validated['id']])) {
                    throw new InvalidArgumentException('Invalid canvas history node ID.');
                }
                if (! $this->canViewNode($node)) {
                    throw new AuthorizationException;
                }
                $this->authorizeNodeReference($roomId, $validated);
                $nodeIds[$validated['id']] = true;
                $nodes[] = $validated;
            }
            $links = [];
            $linkIds = [];
            foreach ($snapshot['links'] as $link) {
                $validated = is_array($link) ? GraphInput::link($link) : throw new InvalidArgumentException('Invalid canvas history link.');
                if ($validated['id'] === null || isset($linkIds[$validated['id']])
                    || ! is_int($validated['sourceId']) || ! is_int($validated['targetId'])
                    || ! isset($nodeIds[$validated['sourceId']], $nodeIds[$validated['targetId']])) {
                    throw new InvalidArgumentException('Invalid canvas history link endpoint.');
                }
                $linkIds[$validated['id']] = true;
                $links[] = $validated;
            }
            $plan = Plan::replace($entry['plan']);
            $this->graphs->restoreGraphSnapshot($roomId, $nodes, $links, $this->userId());
            $this->graphs->setMode($roomId, $snapshot['mode']);
            $this->graphs->bumpVersion($roomId);
            // MySQL JSON objects may come back with keys in a different order.
            // A graph-only restore must not advance the plan version for that.
            if ($plan != $room['plan']) {
                $this->rooms->updatePlan($roomId, $plan, 'ready_for_review');
            }
            $this->rooms->addEvent($roomId, 'graph.updated', ['version' => $expectedGraphVersion + 1, 'mode' => $snapshot['mode']]);
            $newEntry = $this->recordHistory($roomId, 'restore', 'Restored history #'.$entryId);

            return ['graph' => $this->graph($roomId)['graph'], 'plan' => $plan, 'planVersion' => $newEntry['planVersion'], 'historyEntry' => $newEntry];
        });
    }

    /** @param array<string, mixed> $patch
     * @param  list<array<string, mixed>>  $inspirationCards
     * @return array<string, mixed>
     */
    public function createProposal(int $roomId, array $patch, array $inspirationCards = []): array
    {
        return $this->createReviewProposal($roomId, null, null, $patch, $inspirationCards, 'chat', '');
    }

    /** @param array<string, mixed> $patch
     * @param  list<array<string, mixed>>  $inspirationCards
     * @return array<string, mixed>
     */
    public function createMcpProposal(int $roomId, int $expectedVersion, array $patch, array $inspirationCards = [], string $summary = ''): array
    {
        return $this->createReviewProposal($roomId, $expectedVersion, null, $patch, $inspirationCards, 'mcp', $summary);
    }

    /** @param array<string, mixed> $planPatch
     * @return array<string, mixed>
     */
    public function proposePlanPatch(int $roomId, int $expectedGraphVersion, int $expectedPlanVersion, array $planPatch): array
    {
        if ($planPatch === []) {
            throw new InvalidArgumentException('The plan patch is empty.');
        }

        return $this->createReviewProposal($roomId, $expectedGraphVersion, $expectedPlanVersion, ['plan' => $planPatch], [], 'mcp', 'Update Idea Room plan');
    }

    /** @param array<string, mixed> $patch
     * @param  list<array<string, mixed>>  $inspirationCards
     * @return array<string, mixed>
     */
    private function createReviewProposal(int $roomId, ?int $expectedGraphVersion, ?int $expectedPlanVersion, array $patch, array $inspirationCards, string $origin, string $summary): array
    {
        if (mb_strlen($summary) > 255) {
            throw new InvalidArgumentException('The proposal summary is too long.');
        }
        if (! array_is_list($inspirationCards) || count($inspirationCards) > 12) {
            throw new InvalidArgumentException('Too many inspiration cards.');
        }
        $cards = [];
        foreach ($inspirationCards as $card) {
            if (! is_array($card) || array_diff(array_keys($card), ['title', 'text']) !== []
                || ! is_string($card['title'] ?? null) || trim($card['title']) === '' || mb_strlen($card['title']) > 255
                || ! is_string($card['text'] ?? null) || mb_strlen($card['text']) > 2000) {
                throw new InvalidArgumentException('Invalid inspiration card.');
            }
            $cards[] = ['title' => trim($card['title']), 'text' => $card['text']];
        }

        return $this->graphs->transaction(function () use ($roomId, $expectedGraphVersion, $expectedPlanVersion, $patch, $cards, $origin, $summary): array {
            $room = $this->editableRoom($roomId, $expectedGraphVersion);
            if ($expectedPlanVersion !== null && (int) $room['plan_version'] !== $expectedPlanVersion) {
                throw new GraphConflictException;
            }
            $validated = $this->validateProposalPatch($roomId, $patch);
            if ($validated['plan'] !== []) {
                Plan::merge($room['plan'], $validated['plan']);
            }
            if ($origin === 'mcp' && $validated['nodes'] === [] && $validated['links'] === [] && $validated['removeNodeIds'] === []
                && $validated['removeLinkIds'] === [] && $validated['restoreNodeIds'] === [] && $validated['plan'] === []
                && $validated['mode'] === null && $cards === []) {
                throw new InvalidArgumentException('The canvas proposal is empty.');
            }
            $proposal = $this->graphs->addProposal($roomId, $this->userId(), (int) $room['graph_version'], $validated, $cards, $origin, (int) $room['plan_version'], trim($summary));
            // Event replay can outlive access to a linked room/project; emit only an ID.
            $this->rooms->addEvent($roomId, 'graph.proposal', ['proposalId' => $proposal['id']]);

            return $proposal;
        });
    }

    /** @param array<string, mixed>|null $editedPatch
     * @return array{proposal: array<string, mixed>, graph: array<string, mixed>}
     */
    public function acceptProposal(int $roomId, int $proposalId, int $expectedVersion, ?array $editedPatch = null): array
    {
        return $this->graphs->transaction(function () use ($roomId, $proposalId, $expectedVersion, $editedPatch): array {
            $room = $this->editableRoom($roomId, $expectedVersion);
            $proposal = $this->graphs->proposal($roomId, $proposalId, true) ?? throw new NotFoundException;
            if ($proposal['status'] !== 'pending' || $proposal['graph_version'] !== $expectedVersion) {
                throw new GraphConflictException;
            }
            $patch = $this->validateProposalPatch($roomId, $editedPatch ?? $proposal['patch']);
            if ($patch['plan'] !== [] && $proposal['plan_version'] !== (int) $room['plan_version']) {
                throw new GraphConflictException;
            }
            if ($patch['restoreNodeIds'] !== []) {
                $this->assertRestorableNodes($roomId, $patch['restoreNodeIds']);
                $this->graphs->restoreNodes($roomId, $patch['restoreNodeIds']);
            }
            $current = $this->graph($roomId)['graph'];
            $nodes = $this->mergeItems($current['nodes'], $patch['nodes'], $patch['removeNodeIds']);
            $remainingLinks = array_values(array_filter($current['links'], static fn (array $link): bool => ! in_array($link['sourceId'], $patch['removeNodeIds'], true)
                && ! in_array($link['targetId'], $patch['removeNodeIds'], true)
            ));
            $links = $this->mergeItems($remainingLinks, $patch['links'], $patch['removeLinkIds']);
            $graphChanged = $patch['nodes'] !== [] || $patch['links'] !== [] || $patch['removeNodeIds'] !== []
                || $patch['removeLinkIds'] !== [] || $patch['restoreNodeIds'] !== [];
            $saved = $graphChanged ? $this->save($roomId, $expectedVersion, $nodes, $links) : ['graph' => $current];
            if ($patch['mode'] !== null && $patch['mode'] !== $current['mode']) {
                $this->graphs->setMode($roomId, $patch['mode']);
                if (! $graphChanged) {
                    $this->graphs->bumpVersion($roomId);
                    $this->rooms->addEvent($roomId, 'graph.updated', ['version' => $expectedVersion + 1, 'mode' => $patch['mode']]);
                }
                $saved = $this->graph($roomId);
            }
            if ($patch['plan'] !== []) {
                $this->rooms->updatePlan($roomId, Plan::merge($room['plan'], $patch['plan']), 'ready_for_review');
            }
            $this->graphs->setProposalStatus($roomId, $proposalId, 'accepted');
            $proposal['status'] = 'accepted';
            $this->rooms->addEvent($roomId, 'proposal.accepted', ['proposalId' => $proposalId, 'version' => $saved['graph']['version']]);

            return ['proposal' => $proposal, 'graph' => $saved['graph']];
        });
    }

    /** @return array<string, mixed> */
    public function discardProposal(int $roomId, int $proposalId): array
    {
        return $this->graphs->transaction(function () use ($roomId, $proposalId): array {
            $this->editableRoom($roomId, null);
            $proposal = $this->graphs->proposal($roomId, $proposalId, true) ?? throw new NotFoundException;
            if ($proposal['status'] === 'pending') {
                $this->graphs->setProposalStatus($roomId, $proposalId, 'discarded');
                $proposal['status'] = 'discarded';
                $this->rooms->addEvent($roomId, 'proposal.discarded', ['proposalId' => $proposalId]);
            }

            return $proposal;
        });
    }

    /** @return array<string, mixed> */
    private function editableRoom(int $roomId, ?int $expectedVersion): array
    {
        $locked = $this->graphs->lockRoom($roomId) ?? throw new NotFoundException;
        $room = $this->ideaRooms->room($roomId);
        if (! $this->ideaRooms->canEdit($room)) {
            throw new AuthorizationException;
        }
        if ($expectedVersion !== null && (int) $locked['graph_version'] !== $expectedVersion) {
            throw new GraphConflictException;
        }

        return $room;
    }

    /** @param array<string, mixed> $node */
    private function canViewNode(array $node): bool
    {
        $reference = $node['metadata']['reference'] ?? null;
        if (! is_array($reference)) {
            return true;
        }
        if (($reference['type'] ?? '') === 'project') {
            $projectId = (int) ($reference['id'] ?? 0);

            return $this->permissions->currentUserCan(ProjectsPermissions::VIEW, $projectId)
                && $this->ideaRooms->canReferenceProject($projectId);
        }
        if (($reference['type'] ?? '') === 'room') {
            try {
                $this->ideaRooms->room((int) ($reference['id'] ?? 0));

                return true;
            } catch (AuthorizationException|NotFoundException) {
                return false;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function authorizeNodeReference(int $roomId, array $node): void
    {
        $reference = $node['metadata']['reference'] ?? null;
        if (is_array($reference) && ! $this->canViewNode($node)) {
            throw new AuthorizationException;
        }
        $sourceId = $node['metadata']['sourceId'] ?? null;
        if ($sourceId !== null) {
            $source = $this->graphs->source($roomId, $sourceId);
            if ($source === null
                || (isset($node['metadata']['url']) && $node['metadata']['url'] !== $source['url'])
                || (isset($node['metadata']['domain']) && $node['metadata']['domain'] !== $source['domain'])) {
                throw new InvalidArgumentException('The source is not part of this room.');
            }
        }
    }

    /** @param array<int, true> $nodeIds
     * @param  array<string, int>  $clientNodeIds
     */
    private function resolveEndpoint(int|string $endpoint, array $nodeIds, array $clientNodeIds): int
    {
        $id = is_int($endpoint) ? $endpoint : ($clientNodeIds[$endpoint] ?? null);
        if ($id === null || ! isset($nodeIds[$id])) {
            throw new InvalidArgumentException('A canvas link refers to a missing node.');
        }

        return $id;
    }

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function validateProposalPatch(int $roomId, array $patch): array
    {
        if (array_diff(array_keys($patch), ['nodes', 'links', 'removeNodeIds', 'removeLinkIds', 'restoreNodeIds', 'plan', 'mode']) !== []) {
            throw new InvalidArgumentException('Unknown canvas proposal field.');
        }
        $nodes = $patch['nodes'] ?? [];
        $links = $patch['links'] ?? [];
        $removeNodeIds = $patch['removeNodeIds'] ?? [];
        $removeLinkIds = $patch['removeLinkIds'] ?? [];
        $restoreNodeIds = $patch['restoreNodeIds'] ?? [];
        $plan = $patch['plan'] ?? [];
        $mode = $patch['mode'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > GraphInput::MAX_NODES
            || ! is_array($links) || ! array_is_list($links) || count($links) > GraphInput::MAX_LINKS
            || ! $this->validIdList($removeNodeIds, GraphInput::MAX_NODES)
            || ! $this->validIdList($removeLinkIds, GraphInput::MAX_LINKS)
            || ! $this->validIdList($restoreNodeIds, GraphInput::MAX_NODES)
            || ! is_array($plan) || ($mode !== null && ! in_array($mode, ['explore', 'execute'], true))) {
            throw new InvalidArgumentException('Invalid canvas proposal.');
        }
        $nodes = array_map(static fn ($node): array => is_array($node) ? GraphInput::node($node) : throw new InvalidArgumentException('Invalid proposed node.'), $nodes);
        $links = array_map(static fn ($link): array => is_array($link) ? GraphInput::link($link) : throw new InvalidArgumentException('Invalid proposed link.'), $links);
        $current = $this->graph($roomId)['graph'];
        $nodeIds = array_fill_keys(array_column($current['nodes'], 'id'), true);
        $linkIds = array_fill_keys(array_column($current['links'], 'id'), true);
        if ($restoreNodeIds !== []) {
            $this->assertRestorableNodes($roomId, $restoreNodeIds);
            foreach ($restoreNodeIds as $restoredId) {
                $nodeIds[$restoredId] = true;
            }
        }
        $newNodes = [];
        $newLinks = [];
        $updatedNodes = [];
        $updatedLinks = [];
        foreach ($nodes as $node) {
            if ($node['id'] !== null && ! isset($nodeIds[$node['id']])) {
                throw new InvalidArgumentException('A proposed node is not accessible.');
            }
            if ($node['id'] !== null) {
                if (isset($updatedNodes[$node['id']]) || in_array($node['id'], $removeNodeIds, true)) {
                    throw new InvalidArgumentException('A proposed node is duplicated or removed.');
                }
                $updatedNodes[$node['id']] = true;
            }
            if ($node['id'] === null) {
                if (isset($newNodes[$node['clientId']])) {
                    throw new InvalidArgumentException('Duplicate proposed node.');
                }
                $newNodes[$node['clientId']] = true;
            }
            $this->authorizeNodeReference($roomId, $node);
        }
        foreach ($links as $link) {
            if ($link['id'] !== null && ! isset($linkIds[$link['id']])) {
                throw new InvalidArgumentException('A proposed link is not accessible.');
            }
            if ($link['id'] !== null) {
                if (isset($updatedLinks[$link['id']]) || in_array($link['id'], $removeLinkIds, true)) {
                    throw new InvalidArgumentException('A proposed link is duplicated or removed.');
                }
                $updatedLinks[$link['id']] = true;
            }
            if ($link['id'] === null) {
                if (isset($newLinks[$link['clientId']])) {
                    throw new InvalidArgumentException('Duplicate proposed link.');
                }
                $newLinks[$link['clientId']] = true;
            }
            foreach ([$link['sourceId'], $link['targetId']] as $endpoint) {
                if (is_int($endpoint) ? ! isset($nodeIds[$endpoint]) : ! isset($newNodes[$endpoint])) {
                    throw new InvalidArgumentException('A proposed link has an inaccessible endpoint.');
                }
                if (is_int($endpoint) && in_array($endpoint, $removeNodeIds, true)) {
                    throw new InvalidArgumentException('A proposed link targets a node being removed.');
                }
            }
        }
        foreach ($removeNodeIds as $id) {
            if (! isset($nodeIds[$id]) || in_array($id, $restoreNodeIds, true)) {
                throw new InvalidArgumentException('A proposed node removal is not accessible.');
            }
        }
        foreach ($removeLinkIds as $id) {
            if (! isset($linkIds[$id])) {
                throw new InvalidArgumentException('A proposed link removal is not accessible.');
            }
        }
        $resultingLinks = [];
        foreach ($current['links'] as $link) {
            if (! in_array($link['id'], $removeLinkIds, true)
                && ! in_array($link['sourceId'], $removeNodeIds, true)
                && ! in_array($link['targetId'], $removeNodeIds, true)) {
                $resultingLinks[$link['id']] = $link;
            }
        }
        foreach ($links as $link) {
            if ($link['id'] === null) {
                $resultingLinks[] = $link;
            } else {
                $resultingLinks[$link['id']] = $link;
            }
        }
        $edges = [];
        foreach ($resultingLinks as $link) {
            $edge = $link['sourceId'].':'.$link['targetId'].':'.$link['type'];
            if (isset($edges[$edge])) {
                throw new InvalidArgumentException('A proposed canvas link is duplicated.');
            }
            $edges[$edge] = true;
        }

        return [
            'nodes' => $nodes, 'links' => $links, 'removeNodeIds' => $removeNodeIds,
            'removeLinkIds' => $removeLinkIds, 'restoreNodeIds' => $restoreNodeIds,
            'plan' => PlanPatch::validate($plan), 'mode' => $mode,
        ];
    }

    /** @param list<array<string, mixed>> $current
     * @param  list<array<string, mixed>>  $updates
     * @param  list<int>  $removeIds
     * @return list<array<string, mixed>>
     */
    private function mergeItems(array $current, array $updates, array $removeIds): array
    {
        $items = [];
        foreach ($current as $item) {
            if (! in_array($item['id'], $removeIds, true)) {
                $items[$item['id']] = $item;
            }
        }
        foreach ($updates as $item) {
            if ($item['id'] === null) {
                $items[] = $item;
            } elseif (isset($items[$item['id']])) {
                $items[$item['id']] = array_replace($items[$item['id']], $item);
            } else {
                throw new InvalidArgumentException('A canvas proposal refers to a missing element.');
            }
        }

        return array_values($items);
    }

    /** @param array<string, mixed> $patch */
    private function proposalReferencesVisible(int $roomId, array $patch): bool
    {
        $visible = $this->graph($roomId)['graph'];
        $nodeIds = array_fill_keys(array_column($visible['nodes'], 'id'), true);
        $linkIds = array_fill_keys(array_column($visible['links'], 'id'), true);
        foreach (($patch['restoreNodeIds'] ?? []) as $id) {
            $node = $this->graphs->deletedNode($roomId, $id);
            if ($node === null || ! $this->canViewNode($node)) {
                return false;
            }
            $nodeIds[$id] = true;
        }
        foreach (($patch['nodes'] ?? []) as $node) {
            if (! $this->canViewNode($node) || ($node['id'] !== null && ! isset($nodeIds[$node['id']]))) {
                return false;
            }
        }
        foreach (($patch['links'] ?? []) as $link) {
            if ($link['id'] !== null && ! isset($linkIds[$link['id']])) {
                return false;
            }
            foreach ([$link['sourceId'], $link['targetId']] as $endpoint) {
                if (is_int($endpoint) && ! isset($nodeIds[$endpoint])) {
                    return false;
                }
            }
        }
        foreach (($patch['removeNodeIds'] ?? []) as $id) {
            if (! isset($nodeIds[$id])) {
                return false;
            }
        }
        foreach (($patch['removeLinkIds'] ?? []) as $id) {
            if (! isset($linkIds[$id])) {
                return false;
            }
        }

        return true;
    }

    private function validIdList(mixed $ids, int $limit): bool
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > $limit) {
            return false;
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                return false;
            }
        }

        return count($ids) === count(array_unique($ids));
    }

    /** @param list<int> $ids */
    private function assertRestorableNodes(int $roomId, array $ids): void
    {
        foreach ($ids as $id) {
            $node = $this->graphs->deletedNode($roomId, $id) ?? throw new InvalidArgumentException('A deleted canvas node was not found.');
            if (! $this->canViewNode($node)) {
                throw new AuthorizationException;
            }
        }
    }

    /** @param array<string, mixed> $patch @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     */
    private function addInspirationNodes(int $roomId, array $patch, array $cards): array
    {
        if (! array_is_list($cards) || count($cards) > 12) {
            throw new InvalidArgumentException('Too many inspiration cards.');
        }
        if ($cards === []) {
            return $patch;
        }
        if (! is_array($patch['nodes'] ?? []) || ! array_is_list($patch['nodes'] ?? [])) {
            throw new InvalidArgumentException('Invalid canvas nodes.');
        }
        $patch['nodes'] = $patch['nodes'] ?? [];
        $existingCount = count($this->graphs->nodes($roomId)) + count($patch['nodes']);
        $usedIds = array_fill_keys(array_filter(array_column($patch['nodes'], 'clientId'), 'is_string'), true);
        foreach ($cards as $index => $card) {
            if (! is_array($card) || array_diff(array_keys($card), ['title', 'text']) !== []
                || ! is_string($card['title'] ?? null) || trim($card['title']) === '' || mb_strlen($card['title']) > 255
                || ! is_string($card['text'] ?? null) || mb_strlen($card['text']) > 2000) {
                throw new InvalidArgumentException('Invalid inspiration card.');
            }
            do {
                $clientId = 'inspiration-'.bin2hex(random_bytes(8));
            } while (isset($usedIds[$clientId]));
            $usedIds[$clientId] = true;
            $position = $existingCount + $index;
            $patch['nodes'][] = [
                'clientId' => $clientId,
                'type' => 'insight',
                'title' => trim($card['title']),
                'content' => $card['text'],
                'x' => 80.0 + ($position % 8) * 240.0,
                'y' => 80.0 + intdiv($position, 8) * 180.0,
                'metadata' => ['inspiration' => true],
            ];
        }

        return $patch;
    }

    /** @param array<string, mixed> $room */
    private function ensureHistoryBaseline(int $roomId, array $room): void
    {
        if ($this->graphs->latestHistory($roomId) !== null) {
            return;
        }
        $this->graphs->addHistory($roomId, $this->userId(), 'initial', 'Initial state', (int) $room['graph_version'],
            (int) $room['plan_version'], $this->snapshot($roomId, $room), $room['plan']);
    }

    /** @return array<string, mixed> */
    private function recordHistory(int $roomId, string $origin, string $summary): array
    {
        $room = $this->rooms->find($roomId) ?? throw new NotFoundException;
        $entry = $this->graphs->addHistory($roomId, $this->userId(), $origin === 'chat' ? 'ai' : $origin,
            $summary, (int) $room['graph_version'], (int) $room['plan_version'], $this->snapshot($roomId, $room), $room['plan']);
        $entry['isCurrent'] = true;
        $this->rooms->addEvent($roomId, 'history.updated', [
            'historyEntryId' => $entry['id'], 'graphVersion' => $entry['graphVersion'], 'planVersion' => $entry['planVersion'],
        ]);

        return $entry;
    }

    /** @param array<string, mixed> $room @return array<string, mixed> */
    private function snapshot(int $roomId, array $room): array
    {
        // Capture the whole room, not the permission-filtered view. History metadata
        // does not expose this payload, and restores fail closed on hidden references.
        return ['mode' => $room['mode'], 'nodes' => $this->graphs->nodes($roomId), 'links' => $this->graphs->links($roomId)];
    }

    private function userId(): int
    {
        $id = (int) session('userdata.id');
        if ($id < 1) {
            throw new AuthorizationException;
        }

        return $id;
    }
}
