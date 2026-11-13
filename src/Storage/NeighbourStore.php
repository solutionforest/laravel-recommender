<?php

namespace SolutionForest\Recommender\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use SolutionForest\Recommender\Support\Ids;

/**
 * rec_item_neighbours: versioned so a rebuild never exposes half-written data.
 * Readers use the version recorded in rec_state; the previous version is
 * deleted only after the new one is complete.
 */
final class NeighbourStore
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StateStore $state,
    ) {
    }

    public function activeVersion(string $feed): ?int
    {
        $value = $this->state->get($this->versionKey($feed));

        return $value === null ? null : (int) $value;
    }

    /**
     * A version strictly greater than the active one. Rows left over from an
     * interrupted build with the same number are removed first.
     */
    public function nextVersion(string $feed): int
    {
        $version = max((int) floor(microtime(true) * 1000), ($this->activeVersion($feed) ?? 0) + 1);

        $this->deleteVersion($feed, $version);

        return $version;
    }

    /**
     * @param  array<int|string, list<array{0: int|string, 1: float}>>  $rows  item id => neighbours
     */
    public function write(string $feed, int $version, array $rows, int $chunk = 500): void
    {
        foreach (array_chunk($rows, max(1, $chunk), true) as $part) {
            $insert = [];
            foreach ($part as $itemId => $neighbours) {
                $insert[] = [
                    'feed' => $feed,
                    'version' => $version,
                    'item_id' => (string) $itemId,
                    'neighbours' => json_encode($neighbours, JSON_UNESCAPED_UNICODE),
                ];
            }
            $this->connection()->table('rec_item_neighbours')->insert($insert);
        }
    }

    /**
     * Switch readers to $version and delete every other version.
     * $switched runs once readers use $version, before old rows go (so
     * caches holding the old version can be dropped while it still exists).
     *
     * @param  ?callable(): void  $switched
     */
    public function activate(string $feed, int $version, int $chunk = 5000, ?callable $switched = null): void
    {
        $this->state->put($this->versionKey($feed), (string) $version);
        if ($switched !== null) {
            $switched();
        }

        // Old versions are removed in primary-key chunks: short statements,
        // no long lock on a large table.
        $this->deleteWhere(fn ($q) => $q->where('feed', $feed)->where('version', '<>', $version), $chunk);
    }

    /** Remove one (unfinished) version. */
    public function deleteVersion(string $feed, int $version, int $chunk = 5000): void
    {
        $this->deleteWhere(fn ($q) => $q->where('feed', $feed)->where('version', $version), $chunk);
    }

    private function deleteWhere(callable $scope, int $chunk): void
    {
        do {
            $ids = $scope($this->connection()->table('rec_item_neighbours'))
                ->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids !== []) {
                $this->connection()->table('rec_item_neighbours')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunk);
    }

    /**
     * @param  list<int|string>  $itemIds
     * @param  ?int  $version  the active version when the caller already has it (saves a query)
     * @return array<int|string, list<array{0: int|string, 1: float}>>
     */
    public function neighboursOf(string $feed, array $itemIds, ?int $version = null): array
    {
        $version ??= $this->activeVersion($feed);
        if ($version === null || $itemIds === []) {
            return [];
        }

        $rows = $this->connection()->table('rec_item_neighbours')
            ->where('feed', $feed)
            ->where('version', $version)
            ->whereIn('item_id', array_map('strval', $itemIds))
            ->get(['item_id', 'neighbours']);

        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->neighbours, true) ?: [];
            $out[Ids::normalize($row->item_id)] = array_values(array_map(
                fn ($pair) => [Ids::normalize($pair[0]), (float) $pair[1]],
                $decoded,
            ));
        }

        return $out;
    }

    private function versionKey(string $feed): string
    {
        return "neighbours:{$feed}:version";
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection(config('recommender.database_connection'));
    }
}
