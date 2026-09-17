<?php

// Router for `php -S`: a Horizon that misbehaves on the wire, for what an
// Http::fake cannot express (body size, stalls, concurrency). The first
// path segment names the scenario, which decides how the stats call
// answers; masters and workload answer validly, the job calls 404, and the
// metrics calls count how many of them run at once in HOSTILE_COUNTER_FILE.

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
[, $scenario, $rest] = array_pad(explode('/', $path, 3), 3, '');

$json = function (string $body): void {
    header('Content-Type: application/json');
    echo $body;
};

$stats = '{"status":"running","jobsPerMinute":1,"failedJobs":0,"processes":1,"pausedMasters":0,"wait":[],"padding":"';

if ($rest === 'horizon/api/masters') {
    $json('[]');

    return true;
}

if ($rest === 'horizon/api/workload') {
    $queues = $scenario === 'many-queues' ? range(1, 150) : [1];

    $json(json_encode(array_map(fn (int $i) => ['name' => "q{$i}", 'length' => $i, 'wait' => 0, 'processes' => 1], $queues)));

    return true;
}

if (str_starts_with($rest, 'horizon/api/metrics/queues/')) {
    $counter = (string) getenv('HOSTILE_COUNTER_FILE');

    $change = function (int $delta) use ($counter): void {
        $handle = fopen($counter, 'c+');
        flock($handle, LOCK_EX);
        [$running, $peak, $total] = array_map('intval', explode(' ', trim((string) stream_get_contents($handle)) ?: '0 0 0'));
        $running += $delta;
        $peak = max($peak, $running);
        $total += $delta > 0 ? 1 : 0;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, "{$running} {$peak} {$total}");
        flock($handle, LOCK_UN);
        fclose($handle);
    };

    $change(1);
    usleep(100_000);
    $change(-1);

    $json('[{"runtime":0.5}]');

    return true;
}

if ($rest !== 'horizon/api/stats') {
    http_response_code(404);

    return true;
}

switch ($scenario) {
    case 'valid':
    case 'many-queues':
        $json($stats.'"}');
        break;

    case 'just-under-the-cap':
        $json($stats.str_repeat('x', 2 * 1024 * 1024 - strlen($stats) - 100).'"}');
        break;

    case 'announced-too-large':
        // Promises more than the cap and then trickles, so only the header
        // can end the call quickly.
        header('Content-Type: application/json');
        header('Content-Length: '.(3 * 1024 * 1024));
        echo $stats;
        flush();
        sleep(30);
        break;

    case 'endless':
        header('Content-Type: application/json');
        echo $stats;
        flush();

        while (! connection_aborted()) {
            echo str_repeat('x', 1024 * 1024);
            flush();
        }
        break;

    case 'stalls-mid-body':
        header('Content-Type: application/json');
        header('Content-Length: '.(strlen($stats) + 1000));
        echo $stats;
        flush();
        sleep(30);
        break;

    default:
        http_response_code(404);
}

return true;
