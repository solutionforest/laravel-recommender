<?php

namespace SolutionForest\Recommender\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

final class StateStore
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->connection()->table('rec_state')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    public function put(string $key, ?string $value): void
    {
        $this->connection()->table('rec_state')->upsert(
            [['key' => $key, 'value' => $value, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection(config('recommender.database_connection'));
    }
}
