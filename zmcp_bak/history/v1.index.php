<?php
/**
 * OtterlyAI MCP Server
 *
 * Pre-place files at:
 *   ./data/<u_id>/chatgpt_<u_id>.csv
 *   ./data/<u_id>/googleaio_<u_id>.csv
 *   ./data/<u_id>/perplexity_<u_id>.csv
 *   ./data/<u_id>/mscopilot_<u_id>.csv
 *   ./data/<u_id>/googleaimode_<u_id>.csv
 *   ./data/<u_id>/gemini_<u_id>.csv
 *   ./data/<u_id>/citations_<u_id>.csv
 *
 * Connect Claude via:
 *   https://yoursite.com/mcp.php?u_id=1234
 */

ini_set('memory_limit',       '256M');
ini_set('max_execution_time', '120');

define('DATA_DIR', __DIR__ . '/../../data');

define('ENGINE_FILES', [
    'chatgpt'      => 'ChatGPT',
    'googleaio'    => 'Google AI Overviews',
    'perplexity'   => 'Perplexity',
    'mscopilot'    => 'MS Copilot',
    'googleaimode' => 'Google AI Mode',
    'gemini'       => 'Google Gemini',
]);

// ── Helpers ───────────────────────────────────────────────────────────────────

function err_exit(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

function mcp_response(array $payload): void {
    header('Content-Type: application/json');
    echo json_encode($payload);
}

function safe_id(string $id): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
}

function data_path(string $u_id, string $engine_key): string {
    return DATA_DIR . '/' . $u_id . '/' . $engine_key . '_' . $u_id . '.csv';
}

function citations_path(string $u_id): string {
    return DATA_DIR . '/' . $u_id . '/citations_' . $u_id . '.csv';
}

/**
 * Normalise the engine-specific rank column name to a generic "Your brand rank".
 * e.g. "ChatGPT your brand rank" → "Your brand rank"
 */
function normalise_headers(array $headers): array {
    return array_map(function (string $h): string {
        return preg_match('/^.+\byour brand rank$/i', $h) ? 'Your brand rank' : $h;
    }, $headers);
}

/**
 * Iterate every row of a single engine CSV, calling $cb(array $row) per row.
 * Rows include an "AI Engine" key prepended.
 */
function each_engine_row(string $u_id, string $engine_key, string $engine_label, callable $cb): void {
    $path = data_path($u_id, $engine_key);
    if (!file_exists($path)) return;

    $fh = fopen($path, 'r');
    if (!$fh) return;

    $raw_headers = fgetcsv($fh);
    if (!$raw_headers) { fclose($fh); return; }

    $headers = normalise_headers($raw_headers);

    while (($raw = fgetcsv($fh)) !== false) {
        $row = array_combine($headers, array_pad($raw, count($headers), ''));
        $row = array_merge(['AI Engine' => $engine_label], $row);
        $cb($row);
    }
    fclose($fh);
}

/** Iterate all 6 engine CSVs combined. */
function each_prompt_row(string $u_id, callable $cb): void {
    foreach (ENGINE_FILES as $key => $label) {
        each_engine_row($u_id, $key, $label, $cb);
    }
}

/** Iterate citations CSV row by row. */
function each_citation_row(string $u_id, callable $cb): void {
    $path = citations_path($u_id);
    if (!file_exists($path)) return;

    $fh = fopen($path, 'r');
    if (!$fh) return;

    $headers = fgetcsv($fh);
    if (!$headers) { fclose($fh); return; }

    while (($raw = fgetcsv($fh)) !== false) {
        $cb(array_combine($headers, array_pad($raw, count($headers), '')));
    }
    fclose($fh);
}

/** Count data rows (excludes header) without loading the file. */
function count_csv_rows(string $path): int {
    if (!file_exists($path)) return 0;
    $fh    = fopen($path, 'r');
    $count = 0;
    fgets($fh); // skip header
    while (fgets($fh) !== false) $count++;
    fclose($fh);
    return $count;
}

// ── Tool definitions ──────────────────────────────────────────────────────────

$tools = [
    [
        'name'        => 'get_dataset_info',
        'description' => 'Returns info about this OtterlyAI dataset: which engine files exist, row counts per engine, and citations row count.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
    ],
    [
        'name'        => 'list_prompts',
        'description' => 'List and filter prompts across all AI engines. Filter by engine name, country code, keyword in prompt text, or tag. Returns paginated rows.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'engine'  => ['type' => 'string', 'description' => 'Filter by AI engine, e.g. "ChatGPT", "Perplexity", "Google AI Overviews", "MS Copilot", "Google AI Mode", "Google Gemini"'],
                'country' => ['type' => 'string', 'description' => 'Filter by country code, e.g. "us", "de"'],
                'keyword' => ['type' => 'string', 'description' => 'Substring match against the Prompt column'],
                'tag'     => ['type' => 'string', 'description' => 'Substring match against the Tags column'],
                'limit'   => ['type' => 'integer', 'description' => 'Max rows to return (default 50, max 500)'],
                'offset'  => ['type' => 'integer', 'description' => 'Pagination offset (default 0)'],
            ],
        ],
    ],
    [
        'name'        => 'brand_performance_summary',
        'description' => 'Summary of your brand mentions and rankings per AI engine: total prompts tracked, prompts where brand was mentioned, total mention count, and rank-1 appearances.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
    ],
    [
        'name'        => 'compare_engines',
        'description' => 'Compare brand mention rates across all AI engines, optionally filtered to prompts containing a specific keyword.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => 'Optional prompt keyword to filter on'],
            ],
        ],
    ],
    [
        'name'        => 'top_cited_domains',
        'description' => 'Top cited domains from the citations export, ranked by total citation count.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'n'               => ['type' => 'integer', 'description' => 'How many top domains to return (default 20, max 100)'],
                'brand_mentioned' => ['type' => 'boolean', 'description' => 'If true, only include rows where your brand was mentioned'],
            ],
        ],
    ],
    [
        'name'        => 'search_citations',
        'description' => 'Search the citations CSV by domain, URL title keyword, or brand-mention flag. Returns paginated results.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'domain'          => ['type' => 'string', 'description' => 'Filter by domain (substring)'],
                'title_keyword'   => ['type' => 'string', 'description' => 'Filter by title keyword (substring)'],
                'brand_mentioned' => ['type' => 'boolean', 'description' => 'If true, only rows where My Brand Mentioned = Yes'],
                'limit'           => ['type' => 'integer', 'description' => 'Max rows (default 50, max 200)'],
                'offset'          => ['type' => 'integer', 'description' => 'Pagination offset (default 0)'],
            ],
        ],
    ],
    [
        'name'        => 'competitor_mentions',
        'description' => 'Total mention and citation counts for every tracked competitor across all engines (or a single engine).',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'engine' => ['type' => 'string', 'description' => 'Optional: limit to one AI engine'],
                'top_n'  => ['type' => 'integer', 'description' => 'Return only top N competitors by mention count (default: all)'],
            ],
        ],
    ],
];

// ── Tool execution ────────────────────────────────────────────────────────────

function execute_tool(string $name, array $args, string $u_id): string {

    switch ($name) {

        case 'get_dataset_info': {
            $info = ['u_id' => $u_id, 'engines' => []];
            foreach (ENGINE_FILES as $key => $label) {
                $path = data_path($u_id, $key);
                $info['engines'][$label] = [
                    'file'   => basename($path),
                    'exists' => file_exists($path),
                    'rows'   => file_exists($path) ? count_csv_rows($path) : 0,
                ];
            }
            $cp = citations_path($u_id);
            $info['citations'] = [
                'file'   => basename($cp),
                'exists' => file_exists($cp),
                'rows'   => file_exists($cp) ? count_csv_rows($cp) : 0,
            ];
            return json_encode($info, JSON_PRETTY_PRINT);
        }

        case 'list_prompts': {
            $engine_f  = trim($args['engine']  ?? '');
            $country_f = strtolower(trim($args['country'] ?? ''));
            $keyword_f = strtolower(trim($args['keyword'] ?? ''));
            $tag_f     = strtolower(trim($args['tag']     ?? ''));
            $limit     = min((int)($args['limit']  ?? 50), 500);
            $offset    = max((int)($args['offset'] ?? 0),  0);

            $matched = 0;
            $rows    = [];

            each_prompt_row($u_id, function (array $row) use (
                $engine_f, $country_f, $keyword_f, $tag_f,
                $limit, $offset, &$matched, &$rows
            ) {
                if ($engine_f  && stripos($row['AI Engine'] ?? '', $engine_f)  === false) return;
                if ($country_f && stripos($row['Country']   ?? '', $country_f) === false) return;
                if ($keyword_f && stripos($row['Prompt']    ?? '', $keyword_f) === false) return;
                if ($tag_f     && stripos($row['Tags']      ?? '', $tag_f)     === false) return;

                if ($matched >= $offset && count($rows) < $limit) $rows[] = $row;
                $matched++;
            });

            return json_encode([
                'total_matched' => $matched,
                'offset'        => $offset,
                'limit'         => $limit,
                'rows'          => $rows,
            ], JSON_PRETTY_PRINT);
        }

        case 'brand_performance_summary': {
            $stats = [];

            each_prompt_row($u_id, function (array $row) use (&$stats) {
                $e = $row['AI Engine'] ?? 'unknown';
                if (!isset($stats[$e])) {
                    $stats[$e] = ['engine' => $e, 'total_prompts' => 0,
                                  'prompts_with_mention' => 0, 'total_mentions' => 0, 'rank_1_count' => 0];
                }
                $m = (int)($row['Your brand mentioned'] ?? 0);
                $stats[$e]['total_prompts']++;
                if ($m > 0) $stats[$e]['prompts_with_mention']++;
                $stats[$e]['total_mentions'] += $m;
                if (($row['Your brand rank'] ?? '') === '1') $stats[$e]['rank_1_count']++;
            });

            foreach ($stats as &$s) {
                $s['mention_rate'] = $s['total_prompts'] > 0
                    ? round($s['prompts_with_mention'] / $s['total_prompts'] * 100, 1) . '%' : '0%';
            }
            return json_encode(array_values($stats), JSON_PRETTY_PRINT);
        }

        case 'compare_engines': {
            $keyword   = strtolower(trim($args['keyword'] ?? ''));
            $by_engine = [];

            each_prompt_row($u_id, function (array $row) use ($keyword, &$by_engine) {
                if ($keyword && stripos($row['Prompt'] ?? '', $keyword) === false) return;
                $e = $row['AI Engine'] ?? 'unknown';
                if (!isset($by_engine[$e])) {
                    $by_engine[$e] = ['engine' => $e, 'prompts' => 0, 'with_mention' => 0, 'rank1' => 0];
                }
                $by_engine[$e]['prompts']++;
                if ((int)($row['Your brand mentioned'] ?? 0) > 0) $by_engine[$e]['with_mention']++;
                if (($row['Your brand rank'] ?? '') === '1')       $by_engine[$e]['rank1']++;
            });

            foreach ($by_engine as &$e) {
                $e['mention_rate'] = $e['prompts'] > 0
                    ? round($e['with_mention'] / $e['prompts'] * 100, 1) . '%' : '0%';
            }
            return json_encode([
                'keyword_filter' => $keyword ?: 'none',
                'engines'        => array_values($by_engine),
            ], JSON_PRETTY_PRINT);
        }

        case 'top_cited_domains': {
            $n          = min((int)($args['n'] ?? 20), 100);
            $brand_only = !empty($args['brand_mentioned']);
            $domains    = [];

            each_citation_row($u_id, function (array $row) use ($brand_only, &$domains) {
                if ($brand_only && stripos($row['My Brand Mentioned'] ?? '', 'yes') === false) return;
                $domain = $row['Domain'] ?? '';
                $cited  = (int)($row['Cited'] ?? 0);
                if (!isset($domains[$domain])) {
                    $domains[$domain] = ['domain' => $domain, 'total_cited' => 0, 'appearances' => 0];
                }
                $domains[$domain]['total_cited']  += $cited;
                $domains[$domain]['appearances']++;
            });

            usort($domains, fn($a, $b) => $b['total_cited'] <=> $a['total_cited']);
            return json_encode(array_slice($domains, 0, $n), JSON_PRETTY_PRINT);
        }

        case 'search_citations': {
            $domain_f = strtolower(trim($args['domain']        ?? ''));
            $title_f  = strtolower(trim($args['title_keyword'] ?? ''));
            $brand_only = !empty($args['brand_mentioned']);
            $limit    = min((int)($args['limit']  ?? 50), 200);
            $offset   = max((int)($args['offset'] ?? 0),  0);

            $matched = 0;
            $rows    = [];

            each_citation_row($u_id, function (array $row) use (
                $domain_f, $title_f, $brand_only, $limit, $offset, &$matched, &$rows
            ) {
                if ($domain_f   && stripos($row['Domain'] ?? '', $domain_f) === false) return;
                if ($title_f    && stripos($row['Title']  ?? '', $title_f)  === false) return;
                if ($brand_only && stripos($row['My Brand Mentioned'] ?? '', 'yes') === false) return;

                if ($matched >= $offset && count($rows) < $limit) $rows[] = $row;
                $matched++;
            });

            return json_encode([
                'total_matched' => $matched,
                'offset'        => $offset,
                'limit'         => $limit,
                'rows'          => $rows,
            ], JSON_PRETTY_PRINT);
        }

        case 'competitor_mentions': {
            $engine_f    = strtolower(trim($args['engine'] ?? ''));
            $top_n       = (int)($args['top_n'] ?? 0);
            $competitors = null;

            each_prompt_row($u_id, function (array $row) use ($engine_f, &$competitors) {
                if ($engine_f && stripos($row['AI Engine'] ?? '', $engine_f) === false) return;

                if ($competitors === null) {
                    $competitors = [];
                    foreach (array_keys($row) as $col) {
                        if (preg_match('/^(.+) mentioned$/', $col, $m)) {
                            $comp = $m[1];
                            if (strtolower($comp) === 'your brand') continue;
                            $competitors[$comp] = ['competitor' => $comp, 'total_mentioned' => 0, 'total_cited' => 0];
                        }
                    }
                }

                foreach ($competitors as $comp => &$data) {
                    $data['total_mentioned'] += (int)($row[$comp . ' mentioned'] ?? 0);
                    $data['total_cited']     += (int)($row[$comp . ' cited']     ?? 0);
                }
            });

            $list = array_values($competitors ?? []);
            usort($list, fn($a, $b) => $b['total_mentioned'] <=> $a['total_mentioned']);
            if ($top_n > 0) $list = array_slice($list, 0, $top_n);

            return json_encode([
                'engine_filter' => $engine_f ?: 'all',
                'competitors'   => $list,
            ], JSON_PRETTY_PRINT);
        }

        default:
            return json_encode(['error' => "Unknown tool: $name"]);
    }
}

// ── Validate u_id ─────────────────────────────────────────────────────────────

$u_id = safe_id($_GET['u_id'] ?? '');
if ($u_id === '') {
    err_exit(400, 'Missing ?u_id= parameter. Usage: mcp.php?u_id=1234');
}


if (!is_dir(DATA_DIR . '/' . $u_id)) {
    err_exit(404, "No data directory found for u_id=$u_id  (expected: data/$u_id/)");
}

// ── HTTP POST transport ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $req  = json_decode($body, true);
    if (!$req) err_exit(400, 'Invalid JSON body');
    handle_mcp_request($req, $u_id);
    exit;
}

// ── SSE transport (GET) ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path     = strtok($_SERVER['REQUEST_URI'] ?? '/mcp.php', '?');
    $post_url = "$proto://$host{$path}?u_id=$u_id";

    echo "event: endpoint\n";
    echo "data: " . json_encode(['uri' => $post_url]) . "\n\n";
    flush();

    $start = time();
    while (!connection_aborted() && (time() - $start) < 55) {
        echo ": heartbeat\n\n";
        flush();
        sleep(15);
    }
    exit;
}

err_exit(405, 'Method not allowed');

// ── MCP dispatcher (defined after globals are set) ────────────────────────────

function handle_mcp_request(array $req, string $u_id): void {
    global $tools;

    $id     = $req['id']     ?? null;
    $method = $req['method'] ?? '';

    switch ($method) {

        case 'initialize':
            mcp_response([
                'jsonrpc' => '2.0', 'id' => $id,
                'result'  => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'otterly-mcp', 'version' => '1.0.0'],
                ],
            ]);
            break;

        case 'notifications/initialized':
            http_response_code(204);
            break;

        case 'tools/list':
            mcp_response(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]]);
            break;

        case 'tools/call':
            $content = execute_tool($req['params']['name'] ?? '', $req['params']['arguments'] ?? [], $u_id);
            mcp_response([
                'jsonrpc' => '2.0', 'id' => $id,
                'result'  => ['content' => [['type' => 'text', 'text' => $content]]],
            ]);
            break;

        default:
            mcp_response([
                'jsonrpc' => '2.0', 'id' => $id,
                'error'   => ['code' => -32601, 'message' => "Method not found: $method"],
            ]);
    }
}
