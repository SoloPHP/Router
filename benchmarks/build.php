<?php

declare(strict_types=1);

/*
 * Cost of preparing a router on every request: registering the routes, building the table and
 * one lookup; for solo-cached, loading a warm RouteCache file and one lookup. Milliseconds,
 * median, in a warm process. solo-cached needs OPcache (-d opcache.enable_cli=1).
 *
 * php build.php [--libraries=solo,solo-cached,leaf,symfony,slim] [--layouts=...] [--counts=100,1000] [--json=file]
 */

require __DIR__ . '/bootstrap.php';

$options = getopt('', ['libraries:', 'layouts:', 'counts:', 'json:']);
$libraries = listOption($options, 'libraries', ['solo', 'solo-cached', ...array_slice(LIBRARIES, 1)]);

$results = [];
foreach (listOption($options, 'layouts', ['separate', 'tenant', 'locale']) as $layout) {
    foreach (array_map('intval', listOption($options, 'counts', ['100', '1000'])) as $count) {
        $routes = routes($layout, $count);
        [[$method, $uri, $expected]] = requests($layout, $count, 'last', 1);
        $row = ['layout' => $layout, 'routes' => $count];

        foreach ($libraries as $library) {
            $row[$library] = null;
            if (!supports($library, $layout, 'dynamic')) {
                continue;
            }
            $prepare = static function () use ($library, $routes, $method, $uri) {
                [$lookup, $normalize] = library($library, $routes);
                return $normalize($lookup($method, $uri));
            };
            if ($prepare() !== $expected) {
                throw new RuntimeException("$library did not resolve $method $uri");
            }

            $samples = [];
            for ($i = $count >= 1000 ? 30 : 200; $i > 0; $i--) {
                $start = hrtime(true);
                $prepare();
                $samples[] = (hrtime(true) - $start) / 1e6;
            }
            $row[$library] = round(median($samples), 4);
        }
        $results[] = $row;
    }
}

printf("| Layout | Routes | %s |\n", implode(' | ', $libraries));
printf("|---|---:|%s\n", str_repeat('---:|', count($libraries)));
foreach ($results as $row) {
    $cells = array_map(static fn(string $l) => $row[$l] === null ? '—' : number_format($row[$l], 3), $libraries);
    printf("| %s | %d | %s |\n", $row['layout'], $row['routes'], implode(' | ', $cells));
}

if (isset($options['json'])) {
    $report = [
        'recorded_at_utc' => gmdate('c'),
        'unit' => 'milliseconds per registration, build and one lookup, median',
        'solo_source' => realpath($soloSource),
        'environment' => environment(),
        'results' => $results,
    ];
    file_put_contents($options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}
