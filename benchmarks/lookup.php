<?php

declare(strict_types=1);

/*
 * Lookup speed in a prebuilt route table: microseconds per lookup, median of several runs.
 *
 * php lookup.php [--libraries=solo,leaf,symfony,slim] [--layouts=...] [--counts=100,1000]
 *                [--profiles=...] [--runs=7] [--lookups=10000] [--json=file]
 */

require __DIR__ . '/bootstrap.php';

$options = getopt('', ['libraries:', 'layouts:', 'counts:', 'profiles:', 'runs:', 'lookups:', 'json:']);
$libraries = listOption($options, 'libraries', LIBRARIES);
$runs = (int) ($options['runs'] ?? 7);
$lookups = (int) ($options['lookups'] ?? 10_000);

$results = [];
foreach (listOption($options, 'layouts', LAYOUTS) as $layout) {
    foreach (array_map('intval', listOption($options, 'counts', ['100', '1000'])) as $count) {
        $routes = routes($layout, $count);
        $adapters = [];
        foreach ($libraries as $library) {
            if (supports($library, $layout, 'dynamic')) {
                $adapters[$library] = library($library, $routes);
            }
        }

        foreach (listOption($options, 'profiles', PROFILES) as $profile) {
            $requests = requests($layout, $count, $profile, $lookups);
            $measured = array_values(array_filter(
                $libraries,
                static fn(string $library): bool => supports($library, $layout, $profile)
            ));

            // Checks every result and warms every route and pattern before timing.
            foreach ($measured as $library) {
                [$lookup, $normalize] = $adapters[$library];
                foreach ($requests as [$method, $uri, $expected]) {
                    $actual = $normalize($lookup($method, $uri));
                    if ($actual !== $expected) {
                        throw new RuntimeException(sprintf(
                            '%s returned %s for %s %s, expected %s',
                            $library,
                            json_encode($actual),
                            $method,
                            $uri,
                            json_encode($expected)
                        ));
                    }
                }
            }

            $samples = array_fill_keys($measured, []);
            for ($run = 0; $run < $runs; $run++) {
                // Rotate the order to avoid a systematic first or last position.
                $shift = $run % count($measured);
                foreach ([...array_slice($measured, $shift), ...array_slice($measured, 0, $shift)] as $library) {
                    $lookup = $adapters[$library][0];
                    $start = hrtime(true);
                    foreach ($requests as [$method, $uri]) {
                        $lookup($method, $uri);
                    }
                    $samples[$library][] = (hrtime(true) - $start) / 1000 / $lookups;
                }
            }

            $row = ['layout' => $layout, 'routes' => $count, 'profile' => $profile];
            foreach ($libraries as $library) {
                $row[$library] = isset($samples[$library]) ? round(median($samples[$library]), 3) : null;
            }
            $results[] = $row;
            fwrite(STDERR, '.');
        }
    }
}
fwrite(STDERR, PHP_EOL);

printf("| Layout | Routes | Profile | %s |\n", implode(' | ', $libraries));
printf("|---|---:|---|%s\n", str_repeat('---:|', count($libraries)));
foreach ($results as $row) {
    $cells = array_map(static fn(string $l) => $row[$l] === null ? '—' : number_format($row[$l], 2), $libraries);
    printf("| %s | %d | %s | %s |\n", $row['layout'], $row['routes'], $row['profile'], implode(' | ', $cells));
}

if (isset($options['json'])) {
    $report = [
        'recorded_at_utc' => gmdate('c'),
        'unit' => 'microseconds per lookup, median',
        'runs' => $runs,
        'lookups_per_run' => $lookups,
        'solo_source' => realpath($soloSource),
        'environment' => environment(),
        'results' => $results,
    ];
    file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
