<?php

namespace App\Support;

/**
 * Folds a classified file list into the directory tree the risk map draws.
 * Depth is capped at three levels and each node keeps its twelve largest
 * children, so the payload stays small for large repositories. Files whose
 * area, floor or owner rule could not be evaluated are counted apart from
 * covered files and from gaps, since neither claim is known to be true.
 *
 * Node shape: id, name, kind, files, paths, children, plus segments on leaves.
 * kind is "directory", "files" (loose files of a directory, or of the root
 * with paths [""]) or "other" (siblings past the twelfth, which also carry
 * pathCount; paths holds at most 50). Synthetic ids end in a NUL byte plus the
 * kind, so no repository path can collide with them. Consumers read the
 * directory a node stands for from paths, never from name or id.
 * Coverage rows split every file into covered, unchecked, floor or gap
 * (files = coveredFiles + uncheckedFiles + floorFiles + gap files). Gap rows
 * with isAggregate true stand for folded directories and have no single path.
 */
final class RiskMapTree
{
    private const MAX_DEPTH = 3;

    private const MAX_CHILDREN = 12;

    private const MAX_GAPS = 5;

    private const MAX_OTHER_PATHS = 50;

    private const ROOT_FILES = 'root files';

    /**
     * @param  array<string, array{areas: list<int>, area: ?int, isFloor: bool, owners: list<string>, isUnchecked?: bool, globHits?: list<array{0: int, 1: int}>}>  $files
     * @param  list<int>  $globCounts  globs per area
     * @return array{totalFiles: int, coveredFiles: int, uncheckedFiles: int, tree: list<array<string, mixed>>, coverage: list<array{name: string, files: int, coveredFiles: int, uncheckedFiles: int, floorFiles: int}>, gaps: list<array{path: string, files: int, isAggregate: bool}>, areaFiles: list<int>, areaPathFiles: list<list<int>>, areaOwners: list<list<string>>}
     */
    public static function build(array $files, array $globCounts): array
    {
        $areaCount = count($globCounts);
        $areaFiles = array_fill(0, $areaCount, 0);
        $areaPathFiles = array_fill(0, $areaCount, []);
        $ownerCounts = array_fill(0, $areaCount, []);
        $coveredFiles = 0;
        $uncheckedFiles = 0;
        $root = self::emptyDirectory();

        foreach ($files as $path => $file) {
            $file['isUnchecked'] ??= false;

            if ($file['isUnchecked']) {
                $uncheckedFiles++;
            } elseif ($file['area'] !== null) {
                $coveredFiles++;
                $areaFiles[$file['area']]++;
                foreach ($file['owners'] as $owner) {
                    $ownerCounts[$file['area']][$owner] = ($ownerCounts[$file['area']][$owner] ?? 0) + 1;
                }
            }
            foreach ($file['globHits'] ?? [] as [$area, $glob]) {
                $areaPathFiles[$area][$glob] = ($areaPathFiles[$area][$glob] ?? 0) + 1;
            }

            $segments = explode('/', (string) $path);
            array_pop($segments);
            $node = &$root;
            foreach (array_slice($segments, 0, self::MAX_DEPTH) as $segment) {
                $node['dirs'][$segment] ??= self::emptyDirectory();
                $node = &$node['dirs'][$segment];
            }
            $node['files'][] = $file;
            unset($node);
        }

        $tree = self::topLevel($root);

        return [
            'totalFiles' => count($files),
            'coveredFiles' => $coveredFiles,
            'uncheckedFiles' => $uncheckedFiles,
            'tree' => $tree,
            'coverage' => self::coverage($tree),
            'gaps' => self::gaps($tree),
            'areaFiles' => array_values($areaFiles),
            'areaPathFiles' => self::pathCounts($areaPathFiles, $globCounts),
            'areaOwners' => array_map(fn (array $counts): array => array_map(strval(...), array_keys(self::sortDesc($counts))), $ownerCounts),
        ];
    }

    /**
     * @return array{dirs: array<string, mixed>, files: list<mixed>}
     */
    private static function emptyDirectory(): array
    {
        return ['dirs' => [], 'files' => []];
    }

    /**
     * @param  array{dirs: array<string, mixed>, files: list<mixed>}  $root
     * @return list<array<string, mixed>>
     */
    private static function topLevel(array $root): array
    {
        $nodes = [];
        foreach ($root['dirs'] as $name => $dir) {
            $nodes[] = self::node((string) $name, (string) $name, $dir);
        }
        if ($root['files'] !== []) {
            $nodes[] = self::leaf("\0files", self::ROOT_FILES, 'files', [''], $root['files']);
        }

        return self::fold($nodes, '');
    }

    /**
     * @param  array{dirs: array<string, mixed>, files: list<mixed>}  $dir
     * @return array<string, mixed>
     */
    private static function node(string $id, string $name, array $dir): array
    {
        if ($dir['dirs'] === []) {
            return self::leaf($id, $name, 'directory', [$id], $dir['files']);
        }

        $children = [];
        foreach ($dir['dirs'] as $childName => $child) {
            $children[] = self::node("{$id}/{$childName}", (string) $childName, $child);
        }
        if ($dir['files'] !== []) {
            $children[] = self::leaf("{$id}\0files", 'files', 'files', [$id], $dir['files']);
        }
        $children = self::fold($children, $id);

        return [
            'id' => $id,
            'name' => $name,
            'kind' => 'directory',
            'files' => array_sum(array_column($children, 'files')),
            'paths' => [$id],
            'children' => $children,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private static function leaf(string $id, string $name, string $kind, array $paths, array $files): array
    {
        $counts = [];
        foreach ($files as $file) {
            $key = json_encode([$file['area'], $file['isFloor'], $file['isUnchecked']]);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return [
            'id' => $id,
            'name' => $name,
            'kind' => $kind,
            'files' => count($files),
            'paths' => $paths,
            'children' => [],
            'segments' => self::segments($counts),
        ];
    }

    /**
     * @param  array<int|string, int>  $counts  keyed by json [area, isFloor, isUnchecked]
     * @return list<array{area: ?int, isFloor: bool, isUnchecked: bool, files: int}>
     */
    private static function segments(array $counts): array
    {
        $segments = [];
        foreach ($counts as $key => $files) {
            [$area, $isFloor, $isUnchecked] = json_decode((string) $key);
            $segments[] = ['area' => $area, 'isFloor' => $isFloor, 'isUnchecked' => $isUnchecked, 'files' => $files];
        }
        usort($segments, fn (array $a, array $b): int => [$b['files'], $a['area'] ?? -1, $a['isFloor'], $a['isUnchecked']] <=> [$a['files'], $b['area'] ?? -1, $b['isFloor'], $b['isUnchecked']]);

        return $segments;
    }

    /**
     * Sorts siblings largest first, keeps the largest twelve and folds the
     * rest into one leaf named "other" that lists every folded path.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function fold(array $nodes, string $parentId): array
    {
        usort($nodes, fn (array $a, array $b): int => [$b['files'], $a['id']] <=> [$a['files'], $b['id']]);

        if (count($nodes) <= self::MAX_CHILDREN) {
            return $nodes;
        }

        $folded = array_slice($nodes, self::MAX_CHILDREN);
        $counts = [];
        foreach ($folded as $node) {
            foreach (self::leafSegments($node) as $segment) {
                $key = json_encode([$segment['area'], $segment['isFloor'], $segment['isUnchecked']]);
                $counts[$key] = ($counts[$key] ?? 0) + $segment['files'];
            }
        }

        $allPaths = array_merge(...array_column($folded, 'paths'));

        $other = [
            'id' => "{$parentId}\0other",
            'name' => 'other',
            'kind' => 'other',
            'files' => array_sum(array_column($folded, 'files')),
            'paths' => array_slice($allPaths, 0, self::MAX_OTHER_PATHS),
            'pathCount' => count($allPaths),
            'children' => [],
            'segments' => self::segments($counts),
        ];

        return [...array_slice($nodes, 0, self::MAX_CHILDREN), $other];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array{area: ?int, isFloor: bool, isUnchecked: bool, files: int}>
     */
    private static function leafSegments(array $node): array
    {
        if (isset($node['segments'])) {
            return $node['segments'];
        }

        return array_merge(...array_map(self::leafSegments(...), $node['children']));
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array{name: string, files: int, coveredFiles: int, uncheckedFiles: int, floorFiles: int}>
     */
    private static function coverage(array $tree): array
    {
        return array_map(function (array $node): array {
            $segments = self::leafSegments($node);
            $sum = fn (callable $isIncluded): int => array_sum(array_column(array_filter($segments, $isIncluded), 'files'));

            return [
                'name' => $node['name'],
                'files' => $node['files'],
                'coveredFiles' => $sum(fn (array $s): bool => $s['area'] !== null && ! $s['isUnchecked']),
                'uncheckedFiles' => $sum(fn (array $s): bool => $s['isUnchecked']),
                'floorFiles' => $sum(fn (array $s): bool => $s['area'] === null && $s['isFloor'] && ! $s['isUnchecked']),
            ];
        }, $tree);
    }

    /**
     * Directories with the most files in no area. Floor and unchecked files
     * are not gaps. A "files" leaf reports as its parent directory, and a folded "other" leaf is an aggregate.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array{path: string, files: int, isAggregate: bool}>
     */
    private static function gaps(array $tree): array
    {
        $gaps = [];
        $walk = function (array $node) use (&$walk, &$gaps): void {
            if (! isset($node['segments'])) {
                array_map($walk, $node['children']);

                return;
            }
            $uncovered = array_sum(array_column(array_filter($node['segments'], fn (array $s): bool => $s['area'] === null && ! $s['isFloor'] && ! $s['isUnchecked']), 'files'));
            if ($uncovered > 0) {
                $isAggregate = $node['kind'] === 'other';
                $path = $isAggregate
                    ? ltrim(str_replace("\0other", ' (other)', $node['id']))
                    : ($node['paths'][0] === '' ? self::ROOT_FILES : $node['paths'][0]);
                $gaps[] = ['path' => $path, 'files' => $uncovered, 'isAggregate' => $isAggregate];
            }
        };
        array_map($walk, $tree);
        usort($gaps, fn (array $a, array $b): int => [$b['files'], $a['path']] <=> [$a['files'], $b['path']]);

        return array_slice($gaps, 0, self::MAX_GAPS);
    }

    /**
     * @param  list<array<int, int>>  $areaPathFiles
     * @param  list<int>  $globCounts
     * @return list<list<int>>
     */
    private static function pathCounts(array $areaPathFiles, array $globCounts): array
    {
        return array_map(
            fn (int $globs, int $area): array => array_map(fn (int $glob): int => $areaPathFiles[$area][$glob] ?? 0, $globs > 0 ? range(0, $globs - 1) : []),
            $globCounts,
            array_keys($globCounts),
        );
    }

    /**
     * @param  array<int|string, int>  $counts
     * @return array<int|string, int>
     */
    private static function sortDesc(array $counts): array
    {
        uksort($counts, fn (string|int $a, string|int $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return $counts;
    }
}
