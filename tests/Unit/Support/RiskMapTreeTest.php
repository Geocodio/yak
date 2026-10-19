<?php

use App\Support\RiskMapTree;

function riskMapFile(?int $area, bool $isFloor = false, array $owners = [], array $globHits = [], bool $isUnchecked = false): array
{
    return ['areas' => $area === null ? [] : [$area], 'area' => $area, 'isFloor' => $isFloor, 'owners' => $owners, 'isUnchecked' => $isUnchecked, 'globHits' => $globHits];
}

it('counts coverage and folds files into depth-3 directories', function () {
    $map = RiskMapTree::build([
        'app/Http/Middleware/Api/SecurityHeaders.php' => riskMapFile(1, owners: ['@mhansen'], globHits: [[1, 0]]),
        'app/Http/Kernel.php' => riskMapFile(1, owners: ['@mhansen'], globHits: [[1, 1]]),
        'app/Support/Slug.php' => riskMapFile(null),
        'README.md' => riskMapFile(null),
        '.yak/config.yml' => riskMapFile(null, isFloor: true),
    ], [1, 2]);

    expect($map['totalFiles'])->toBe(5)
        ->and($map['coveredFiles'])->toBe(2)
        ->and($map['areaFiles'])->toBe([0, 2])
        ->and($map['areaPathFiles'][1])->toBe([1, 1])
        ->and($map['areaOwners'][1])->toBe(['@mhansen'])
        ->and(collect($map['tree'])->pluck('id')->all())->toContain('app', 'root files', '.yak');

    $app = collect($map['tree'])->firstWhere('id', 'app');
    $http = collect($app['children'])->firstWhere('id', 'app/Http');
    expect(collect($http['children'])->pluck('id')->all())->toContain('app/Http/Middleware', 'app/Http/files');
    $files = collect($http['children'])->firstWhere('id', 'app/Http/files');
    expect($files['paths'])->toBe(['app/Http']);
});

it('folds small siblings into one other node that lists their paths', function () {
    $files = [];
    foreach (range(1, 15) as $i) {
        foreach (range(1, $i) as $j) {
            $files["src/dir{$i}/f{$j}.php"] = riskMapFile(null);
        }
    }
    $src = collect(RiskMapTree::build($files, [])['tree'])->firstWhere('id', 'src');
    $other = collect($src['children'])->last();

    expect($src['children'])->toHaveCount(13)
        ->and($other['name'])->toBe('other')
        ->and($other['id'])->toBe('src/(other)')
        ->and($other['files'])->toBe(6)
        ->and($other['paths'])->toBe(['src/dir3', 'src/dir2', 'src/dir1'])
        ->and($other['segments'])->toBe([['area' => null, 'isFloor' => false, 'isUnchecked' => false, 'files' => 6]]);
});

it('lists the biggest gaps and reports no gaps when everything is covered', function () {
    $covered = RiskMapTree::build(['a/x.php' => riskMapFile(0, globHits: [[0, 0]])], [1]);
    $gappy = RiskMapTree::build(['a/x.php' => riskMapFile(null), 'a/y.php' => riskMapFile(null), 'b/z.php' => riskMapFile(null)], []);

    expect($covered['gaps'])->toBe([])
        ->and($gappy['gaps'][0])->toBe(['path' => 'a', 'files' => 2]);
});

it('caps gaps at five, names files leaves by their directory and skips floor files', function () {
    $files = ['x/a.php' => riskMapFile(null), 'x/y/b.php' => riskMapFile(null), 'x/y/c.php' => riskMapFile(null), '.yak/c.yml' => riskMapFile(null, isFloor: true)];
    foreach (range(1, 6) as $i) {
        $files["d{$i}/f.php"] = riskMapFile(null);
    }
    $gaps = RiskMapTree::build($files, [])['gaps'];

    expect($gaps)->toHaveCount(5)
        ->and($gaps[0])->toBe(['path' => 'x/y', 'files' => 2])
        ->and(collect($gaps)->pluck('path')->all())->not->toContain('.yak');
    expect(collect(RiskMapTree::build($files, [])['gaps'])->pluck('path')->all())->not->toContain('x/files');
    expect(RiskMapTree::build(['x/a.php' => riskMapFile(null), 'x/y/b.php' => riskMapFile(null), 'x/y/c.php' => riskMapFile(null), 'x/z/d.php' => riskMapFile(0)], [1])['gaps'])
        ->toBe([['path' => 'x/y', 'files' => 2], ['path' => 'x', 'files' => 1]]);
});

it('splits a leaf into segments by area and floor', function () {
    $map = RiskMapTree::build(['a/x.php' => riskMapFile(0, globHits: [[0, 0]]), 'a/y.php' => riskMapFile(null), 'a/z.php' => riskMapFile(null)], [1]);
    $leaf = collect($map['tree'])->firstWhere('id', 'a');

    expect($leaf['segments'])->toBe([
        ['area' => null, 'isFloor' => false, 'isUnchecked' => false, 'files' => 2],
        ['area' => 0, 'isFloor' => false, 'isUnchecked' => false, 'files' => 1],
    ]);
});

it('keeps unchecked files out of covered files and gaps and counts them apart', function () {
    $map = RiskMapTree::build([
        'a/x.php' => riskMapFile(0, isUnchecked: true),
        'a/y.php' => riskMapFile(null, isUnchecked: true),
        'a/z.php' => riskMapFile(0),
        'b/w.php' => riskMapFile(null, isUnchecked: true),
    ], [1]);

    expect($map['totalFiles'])->toBe(4)
        ->and($map['coveredFiles'])->toBe(1)
        ->and($map['uncheckedFiles'])->toBe(3)
        ->and($map['areaFiles'])->toBe([1])
        ->and($map['gaps'])->toBe([])
        ->and($map['coverage'])->toBe([
            ['name' => 'a', 'files' => 3, 'coveredFiles' => 1, 'uncheckedFiles' => 2],
            ['name' => 'b', 'files' => 1, 'coveredFiles' => 0, 'uncheckedFiles' => 1],
        ]);
    expect(collect($map['tree'])->firstWhere('id', 'a')['segments'])->toContain(['area' => null, 'isFloor' => false, 'isUnchecked' => true, 'files' => 1]);
});

it('reports coverage per top-level node, largest first, with root files named', function () {
    $map = RiskMapTree::build([
        'app/a.php' => riskMapFile(0), 'app/b.php' => riskMapFile(null), 'app/c.php' => riskMapFile(null),
        'docs/a.md' => riskMapFile(null), 'README.md' => riskMapFile(null),
    ], [1]);

    expect($map['coverage'])->toBe([
        ['name' => 'app', 'files' => 3, 'coveredFiles' => 1, 'uncheckedFiles' => 0],
        ['name' => 'docs', 'files' => 1, 'coveredFiles' => 0, 'uncheckedFiles' => 0],
        ['name' => 'root files', 'files' => 1, 'coveredFiles' => 0, 'uncheckedFiles' => 0],
    ]);
});

it('keeps an area whose globs match nothing, with zero counts', function () {
    $map = RiskMapTree::build(['a/x.php' => riskMapFile(0, owners: ['@a'], globHits: [[0, 0]]), 'a/y.php' => riskMapFile(null)], [1, 2]);

    expect($map['areaFiles'])->toBe([1, 0])
        ->and($map['areaPathFiles'])->toBe([[1], [0, 0]])
        ->and($map['areaOwners'])->toBe([['@a'], []])
        ->and($map['gaps'])->toBe([['path' => 'a', 'files' => 1]]);
});

it('orders owners by how many files they own', function () {
    $map = RiskMapTree::build([
        'a/1.php' => riskMapFile(0, owners: ['@b']), 'a/2.php' => riskMapFile(0, owners: ['@b', '@a']), 'a/3.php' => riskMapFile(0, owners: ['@c', '@a']),
    ], [1]);

    expect($map['areaOwners'][0])->toBe(['@a', '@b', '@c']);
});

it('folds top-level directories past twelve into an other node', function () {
    $files = [];
    foreach (range(1, 14) as $i) {
        $files["top{$i}/f.php"] = riskMapFile(null);
    }
    $map = RiskMapTree::build($files, []);

    expect($map['tree'])->toHaveCount(13)
        ->and(collect($map['tree'])->last()['id'])->toBe('(other)')
        ->and($map['coverage'])->toHaveCount(13);
});
