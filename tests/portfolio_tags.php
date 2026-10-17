<?php
declare(strict_types=1);

namespace XF\Mvc\Entity {
    class Repository
    {
        private $fakeDb;
        public function __construct($db) { $this->fakeDb = $db; }
        protected function db() { return $this->fakeDb; }
    }
}
namespace Warext\Portfolio\Entity {
    class Portfolio
    {
        public int $portfolio_id;
        public string $tags_cache = '';
        public int $tag_count = 0;
        public int $saved = 0;
        public function __construct(int $id) { $this->portfolio_id = $id; }
        public function saveIfChanged(): void { $this->saved++; }
    }
}
namespace {
    class XF { public static int $time = 1700000000; }

    final class FakeDb
    {
        public array $tags = [];
        public array $maps = [];
        public array $counts = ['lookup' => 0, 'aggregate' => 0, 'read' => 0];
        public int $committed = 0;
        public int $rolledBack = 0;
        private int $sequence = 1;

        public function beginTransaction(): void {}
        public function commit(): void { $this->committed++; }
        public function rollback(): void { $this->rolledBack++; }

        public function fetchAllColumn(string $sql, $userId): array
        {
            $this->counts['read']++;
            return array_keys($this->maps[(int)$userId] ?? []);
        }

        public function fetchAll(string $sql, array $ids): array
        {
            if (str_contains($sql, 'tag_normalized IN'))
            {
                $this->counts['lookup']++;
                $out = [];
                foreach ($this->tags as $id => $row)
                {
                    if (in_array($row['tag_normalized'], $ids, true))
                    {
                        $out[] = ['tag_id' => $id, 'tag_normalized' => $row['tag_normalized']];
                    }
                }
                return $out;
            }
            if (str_contains($sql, 'COUNT(*) AS total'))
            {
                $this->counts['aggregate']++;
                $out = [];
                foreach ($ids as $id)
                {
                    $total = 0;
                    foreach ($this->maps as $map)
                    {
                        if (isset($map[$id])) $total++;
                    }
                    if ($total) $out[] = ['tag_id' => $id, 'total' => $total];
                }
                return $out;
            }
            throw new \RuntimeException('Unexpected SELECT: ' . $sql);
        }

        public function query(string $sql, array $args): void
        {
            if (str_starts_with($sql, 'INSERT IGNORE INTO xf_wrxt_portfolio_tag '))
            {
                foreach ($this->tags as $row) if ($row['tag_normalized'] === $args[1]) return;
                $this->tags[$this->sequence++] = ['tag' => $args[0], 'tag_normalized' => $args[1], 'use_count' => 0];
                return;
            }
            if (str_starts_with($sql, 'INSERT INTO xf_wrxt_portfolio_tag_map '))
            {
                $this->maps[(int)$args[0]][(int)$args[1]] = (int)$args[2];
                return;
            }
            if (str_starts_with($sql, 'DELETE FROM xf_wrxt_portfolio_tag_map '))
            {
                foreach (array_slice($args, 1) as $id) unset($this->maps[(int)$args[0]][(int)$id]);
                return;
            }
            if (str_starts_with($sql, 'UPDATE xf_wrxt_portfolio_tag SET use_count'))
            {
                $this->tags[(int)$args[1]]['use_count'] = (int)$args[0];
                return;
            }
            if (str_starts_with($sql, 'DELETE FROM xf_wrxt_portfolio_tag WHERE'))
            {
                foreach ($this->maps as $map) if (isset($map[(int)$args[0]])) return;
                unset($this->tags[(int)$args[0]]);
                return;
            }
            throw new \RuntimeException('Unexpected UPDATE: ' . $sql);
        }
    }

    require __DIR__ . '/../upload/src/addons/Warext/Portfolio/Repository/Portfolio.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    $db = new FakeDb();
    $repo = new \Warext\Portfolio\Repository\Portfolio($db);
    $p1 = new \Warext\Portfolio\Entity\Portfolio(10);
    $p2 = new \Warext\Portfolio\Entity\Portfolio(11);

    $repo->syncTags($p1, ' PHP, php, Minecraft, JavaScript ');
    check($p1->tag_count === 3, 'case-insensitive deduplication failed');
    check($p1->tags_cache === 'PHP, Minecraft, JavaScript', 'display-order normalization failed');
    check(count($db->tags) === 3, 'initial tags not inserted');
    check($db->counts['lookup'] === 2 && $db->counts['aggregate'] === 1, 'per-tag SELECT regression detected');

    $repo->syncTags($p2, 'PHP, Laravel');
    check(count($db->tags) === 4, 'shared tag was not reused');
    $repo->syncTags($p1, 'PHP, Laravel');
    check(count($db->tags) === 2, 'orphan tags not removed');
    $byName = [];
    foreach ($db->tags as $row) $byName[$row['tag_normalized']] = $row['use_count'];
    check(($byName['php'] ?? 0) === 2, 'shared use_count inaccurate');
    check(($byName['laravel'] ?? 0) === 2, 'new tag use_count inaccurate');

    $repo->syncTags($p2, '');
    check($p2->tag_count === 0 && count($db->tags) === 2, 'empty tag edit removed shared tags');
    $repo->syncTags($p1, '');
    check(!$db->tags, 'last tag reference was not collected');
    check($db->rolledBack === 0 && $db->committed === 5, 'transaction boundaries mismatch');
    check(count($repo->normalizeTags(implode(',', range(1, 15)))) === 10, 'max tag cap was bypassed');
    echo "Portfolio tag transaction, batching and shared-count regression: OK\n";
}
