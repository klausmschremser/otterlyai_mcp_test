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
 * On first use the citations CSV is imported into citations_<u_id>.db (SQLite).
 * All subsequent citation queries hit the indexed SQLite file — millisecond responses.
 *
 * Connect Claude via:
 *   https://yoursite.com/?u_id=1234
 *
 * Debug:
 *   https://yoursite.com/?u_id=1234&debug=1
 *
 * Force re-import of citations DB:
 *   https://yoursite.com/?u_id=1234&rebuild_db=1
 */

ini_set('memory_limit',       '256M');
ini_set('max_execution_time', '300');

// On Railway (FrankenPHP/Railpack), index.php lives at /app/index.php
// so __DIR__ = /app  ->  data folder = /app/data
// Adjust DATA_DIR here if your layout differs.
define('DATA_DIR', __DIR__ . '/data');

define('ENGINE_FILES', [
    'chatgpt'      => 'ChatGPT',
    'googleaio'    => 'Google AI Overviews',
    'perplexity'   => 'Perplexity',
    'mscopilot'    => 'MS Copilot',
    'googleaimode' => 'Google AI Mode',
    'gemini'       => 'Google Gemini',
]);

define('LOG_DB_PATH', DATA_DIR . '/mcp_log.db');

// ── Logging ───────────────────────────────────────────────────────────────────

/**
 * Session ID: derived from IP + User-Agent + hour-bucket.
 * Same client within the same clock-hour gets the same session_id,
 * so you can count how many tool calls one Claude conversation made.
 */
function get_session_id(): string {
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
    // Take only the first IP if comma-separated (proxy chain)
    $ip  = trim(explode(',', $ip)[0]);
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $hour = date('YmdH');
    return substr(md5("$ip|$ua|$hour"), 0, 16);
}

function get_log_db(): PDO {
    $db = new PDO('sqlite:' . LOG_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode = WAL");  // WAL allows concurrent reads+writes
    $db->exec("PRAGMA synchronous  = NORMAL");
    $db->exec("CREATE TABLE IF NOT EXISTS requests (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        ts           TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
        session_id   TEXT    NOT NULL,
        u_id         TEXT    NOT NULL,
        method       TEXT    NOT NULL,
        tool_name    TEXT,
        duration_ms  INTEGER,
        status       TEXT    DEFAULT 'ok',
        ip           TEXT,
        user_agent   TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_session  ON requests(session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ts       ON requests(ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_u_id     ON requests(u_id)");
    return $db;
}

function log_request(string $u_id, string $method, ?string $tool_name, int $duration_ms, string $status = 'ok'): void {
    try {
        $db = get_log_db();
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')[0]);
        $db->prepare("INSERT INTO requests (session_id, u_id, method, tool_name, duration_ms, status, ip, user_agent)
                      VALUES (:sid, :uid, :method, :tool, :dur, :status, :ip, :ua)")
           ->execute([
               ':sid'    => get_session_id(),
               ':uid'    => $u_id,
               ':method' => $method,
               ':tool'   => $tool_name,
               ':dur'    => $duration_ms,
               ':status' => $status,
               ':ip'     => $ip,
               ':ua'     => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200),
           ]);
    } catch (\Throwable $e) {
        // Logging must never break the MCP response — silently swallow errors
    }
}

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
    return DATA_DIR . '/' . $u_id . '/' . $engine_key . '.csv';
}

function citations_csv_path(string $u_id): string {
    return DATA_DIR . '/' . $u_id . '/citations.csv';
}

function citations_db_path(string $u_id): string {
    return DATA_DIR . '/' . $u_id . '/citations.db';
}

function normalise_headers(array $headers): array {
    return array_map(function (string $h): string {
        return preg_match('/^.+\byour brand rank$/i', $h) ? 'Your brand rank' : $h;
    }, $headers);
}

// ── CSV streaming helpers ─────────────────────────────────────────────────────

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

function each_prompt_row(string $u_id, callable $cb): void {
    foreach (ENGINE_FILES as $key => $label) {
        each_engine_row($u_id, $key, $label, $cb);
    }
}

function count_csv_rows(string $path): int {
    if (!file_exists($path)) return 0;
    $fh = fopen($path, 'r');
    $count = 0;
    fgets($fh); // skip header
    while (fgets($fh) !== false) $count++;
    fclose($fh);
    return $count;
}

// ── SQLite citations DB ───────────────────────────────────────────────────────

/**
 * Build (or rebuild) the SQLite DB from the citations CSV.
 * Streams the CSV in chunks — never loads the whole file into memory.
 * Returns ['rows_imported' => int, 'duration_s' => float].
 */
function build_citations_db(string $u_id): array {
    $csv_path = citations_csv_path($u_id);
    $db_path  = citations_db_path($u_id);

    if (!file_exists($csv_path)) {
        return ['error' => 'Citations CSV not found: ' . $csv_path];
    }

    // Remove existing DB so we start fresh
    if (file_exists($db_path)) unlink($db_path);

    $t0 = microtime(true);

    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Performance pragmas — safe for a write-once DB
    $db->exec("PRAGMA journal_mode = OFF");
    $db->exec("PRAGMA synchronous  = OFF");
    $db->exec("PRAGMA cache_size   = -32000"); // 32 MB cache

    $db->exec("CREATE TABLE citations (
        id                  INTEGER PRIMARY KEY,
        title               TEXT,
        url                 TEXT,
        domain              TEXT,
        domain_category     TEXT,
        my_brand_mentioned  TEXT,
        competitors_mentioned TEXT,
        cited               INTEGER DEFAULT 0
    )");

    $fh = fopen($csv_path, 'r');
    $raw_headers = fgetcsv($fh);
    // Map CSV column names → DB columns
    $col_map = [
        'Title'                 => 'title',
        'Url'                   => 'url',
        'Domain'                => 'domain',
        'Domain Category'       => 'domain_category',
        'My Brand Mentioned'    => 'my_brand_mentioned',
        'Competitors Mentioned' => 'competitors_mentioned',
        'Cited'                 => 'cited',
    ];

    $insert = $db->prepare("INSERT INTO citations
        (title, url, domain, domain_category, my_brand_mentioned, competitors_mentioned, cited)
        VALUES (:title, :url, :domain, :domain_category, :my_brand_mentioned, :competitors_mentioned, :cited)
    ");

    $count      = 0;
    $batch_size = 500;

    $db->beginTransaction();
    while (($raw = fgetcsv($fh)) !== false) {
        $row = array_combine($raw_headers, array_pad($raw, count($raw_headers), ''));
        $insert->execute([
            ':title'                  => $row['Title']                 ?? '',
            ':url'                    => $row['Url']                   ?? '',
            ':domain'                 => $row['Domain']                ?? '',
            ':domain_category'        => $row['Domain Category']       ?? '',
            ':my_brand_mentioned'     => $row['My Brand Mentioned']    ?? '',
            ':competitors_mentioned'  => $row['Competitors Mentioned'] ?? '',
            ':cited'                  => (int)($row['Cited']           ?? 0),
        ]);
        $count++;
        if ($count % $batch_size === 0) {
            $db->commit();
            $db->beginTransaction();
        }
    }
    $db->commit();
    fclose($fh);

    // Indexes for every column we filter/sort on
    $db->exec("CREATE INDEX idx_domain              ON citations(domain)");
    $db->exec("CREATE INDEX idx_brand_mentioned     ON citations(my_brand_mentioned)");
    $db->exec("CREATE INDEX idx_cited               ON citations(cited DESC)");
    $db->exec("CREATE INDEX idx_domain_cited        ON citations(domain, cited DESC)");

    $db->exec("ANALYZE");

    return [
        'rows_imported' => $count,
        'duration_s'    => round(microtime(true) - $t0, 2),
        'db_path'       => $db_path,
        'db_size_bytes' => filesize($db_path),
    ];
}

/**
 * Get (or lazily create) the SQLite PDO connection for citations.
 * If the DB doesn't exist yet, build it first (one-time cost).
 */
function get_citations_db(string $u_id): PDO {
    $db_path = citations_db_path($u_id);

    if (!file_exists($db_path)) {
        build_citations_db($u_id);
    }

    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA cache_size = -32000");
    $db->exec("PRAGMA temp_store = MEMORY");
    return $db;
}

// ── Tools list ────────────────────────────────────────────────────────────────

function get_tools(): array {
    // NOTE: the trigger description below tells Claude when to use this MCP
    return [
        [
            'name'        => '_about',
            'description' => 'IMPORTANT: Only use this MCP connector and its tools when the user explicitly asks about OtterlyAI data, mentions "OtterlyAI", asks about "my AI visibility", "my brand mentions in AI", "AI engine stats", "prompt tracking", or says "from OtterlyAI". Do NOT use these tools for general questions. This server provides access to OtterlyAI prompt tracking and citation data.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
        ],
        [
            'name'        => 'get_dataset_info',
            'description' => 'Returns info about this OtterlyAI dataset: which engine files exist, row counts per engine, and citations row count. Also shows whether the citations SQLite DB has been built.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
        ],
        [
            'name'        => 'build_citations_index',
            'description' => 'One-time tool: imports the citations CSV into a fast SQLite index. Only needed once per u_id — subsequent calls skip this step automatically. Call this if citations queries are slow or the DB is missing.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
        ],
        [
            'name'        => 'list_prompts',
            'description' => 'List and filter prompts across all AI engines. Filter by engine name, country code, keyword in prompt text, or tag. Returns paginated rows.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'engine'  => ['type' => 'string', 'description' => 'Filter by AI engine: "ChatGPT", "Perplexity", "Google AI Overviews", "MS Copilot", "Google AI Mode", "Google Gemini"'],
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
            'description' => 'Summary of your brand mentions and rankings per AI engine: total prompts, prompts with brand mention, total mention count, rank-1 appearances, and mention rate %.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
        ],
        [
            'name'        => 'compare_engines',
            'description' => 'Compare brand mention rates across all AI engines, optionally filtered to prompts containing a specific keyword.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => 'Optional: only include prompts containing this keyword'],
                ],
            ],
        ],
        [
            'name'        => 'top_cited_domains',
            'description' => 'Top cited domains from the citations index, ranked by total citation count. Fast — uses SQLite.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'n'               => ['type' => 'integer', 'description' => 'How many top domains to return (default 20, max 100)'],
                    'brand_mentioned' => ['type' => 'boolean', 'description' => 'If true, only rows where your brand was mentioned'],
                ],
            ],
        ],
        [
            'name'        => 'search_citations',
            'description' => 'Search the citations index by domain, URL title keyword, or brand-mention flag. Fast — uses SQLite with indexes.',
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
            'description' => 'Total mention and citation counts for every tracked competitor across all engines (or one engine).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'engine' => ['type' => 'string', 'description' => 'Optional: limit to one AI engine'],
                    'top_n'  => ['type' => 'integer', 'description' => 'Return only top N competitors by mention count (default: all)'],
                ],
            ],
        ],
    ];
}

// ── Tool execution ────────────────────────────────────────────────────────────

function execute_tool(string $name, array $args, string $u_id): string {
    switch ($name) {

        // ── get_dataset_info ──────────────────────────────────────────────────
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
            $cp = citations_csv_path($u_id);
            $dp = citations_db_path($u_id);
            $info['citations'] = [
                'csv_file'    => basename($cp),
                'csv_exists'  => file_exists($cp),
                'csv_rows'    => file_exists($cp) ? count_csv_rows($cp) : 0,
                'db_file'     => basename($dp),
                'db_exists'   => file_exists($dp),
                'db_size_mb'  => file_exists($dp) ? round(filesize($dp) / 1048576, 2) : 0,
                'db_rows'     => file_exists($dp) ? (function() use ($u_id) {
                    $db = get_citations_db($u_id);
                    return (int)$db->query("SELECT COUNT(*) FROM citations")->fetchColumn();
                })() : 0,
            ];
            return json_encode($info, JSON_PRETTY_PRINT);
        }

        // ── build_citations_index ─────────────────────────────────────────────
        case 'build_citations_index': {
            $result = build_citations_db($u_id);
            return json_encode($result, JSON_PRETTY_PRINT);
        }

        // ── list_prompts ──────────────────────────────────────────────────────
        case 'list_prompts': {
            $engine_f  = trim($args['engine']  ?? '');
            $country_f = strtolower(trim($args['country'] ?? ''));
            $keyword_f = strtolower(trim($args['keyword'] ?? ''));
            $tag_f     = strtolower(trim($args['tag']     ?? ''));
            $limit     = min((int)($args['limit']  ?? 50), 500);
            $offset    = max((int)($args['offset'] ?? 0),  0);
            $matched   = 0;
            $rows      = [];

            each_prompt_row($u_id, function (array $row) use (
                $engine_f, $country_f, $keyword_f, $tag_f, $limit, $offset, &$matched, &$rows
            ) {
                if ($engine_f  && stripos($row['AI Engine'] ?? '', $engine_f)  === false) return;
                if ($country_f && stripos($row['Country']   ?? '', $country_f) === false) return;
                if ($keyword_f && stripos($row['Prompt']    ?? '', $keyword_f) === false) return;
                if ($tag_f     && stripos($row['Tags']      ?? '', $tag_f)     === false) return;
                if ($matched >= $offset && count($rows) < $limit) $rows[] = $row;
                $matched++;
            });

            return json_encode(['total_matched' => $matched, 'offset' => $offset, 'limit' => $limit, 'rows' => $rows], JSON_PRETTY_PRINT);
        }

        // ── brand_performance_summary ─────────────────────────────────────────
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

        // ── compare_engines ───────────────────────────────────────────────────
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
                if (($row['Your brand rank'] ?? '') === '1') $by_engine[$e]['rank1']++;
            });
            foreach ($by_engine as &$e) {
                $e['mention_rate'] = $e['prompts'] > 0
                    ? round($e['with_mention'] / $e['prompts'] * 100, 1) . '%' : '0%';
            }
            return json_encode(['keyword_filter' => $keyword ?: 'none', 'engines' => array_values($by_engine)], JSON_PRETTY_PRINT);
        }

        // ── top_cited_domains — SQLite ─────────────────────────────────────────
        case 'top_cited_domains': {
            $n          = min((int)($args['n'] ?? 20), 100);
            $brand_only = !empty($args['brand_mentioned']);

            $db  = get_citations_db($u_id);
            $sql = "SELECT domain,
                           SUM(cited)   AS total_cited,
                           COUNT(*)     AS appearances
                    FROM   citations";
            $params = [];
            if ($brand_only) {
                $sql .= " WHERE LOWER(my_brand_mentioned) = 'yes'";
            }
            $sql .= " GROUP BY domain ORDER BY total_cited DESC LIMIT :n";
            $params[':n'] = $n;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
        }

        // ── search_citations — SQLite ─────────────────────────────────────────
        case 'search_citations': {
            $domain_f   = trim($args['domain']        ?? '');
            $title_f    = trim($args['title_keyword'] ?? '');
            $brand_only = !empty($args['brand_mentioned']);
            $limit      = min((int)($args['limit']  ?? 50), 200);
            $offset     = max((int)($args['offset'] ?? 0),  0);

            $db     = get_citations_db($u_id);
            $where  = [];
            $params = [];

            if ($domain_f !== '') {
                $where[]          = "domain LIKE :domain";
                $params[':domain'] = '%' . $domain_f . '%';
            }
            if ($title_f !== '') {
                $where[]         = "title LIKE :title";
                $params[':title'] = '%' . $title_f . '%';
            }
            if ($brand_only) {
                $where[] = "LOWER(my_brand_mentioned) = 'yes'";
            }

            $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // Total count
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM citations $where_sql");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();

            // Rows
            $params[':limit']  = $limit;
            $params[':offset'] = $offset;
            $stmt = $db->prepare("SELECT title, url, domain, domain_category,
                                         my_brand_mentioned, competitors_mentioned, cited
                                  FROM   citations $where_sql
                                  ORDER  BY cited DESC
                                  LIMIT  :limit OFFSET :offset");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode(['total_matched' => $total, 'offset' => $offset, 'limit' => $limit, 'rows' => $rows], JSON_PRETTY_PRINT);
        }

        // ── competitor_mentions ───────────────────────────────────────────────
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

            return json_encode(['engine_filter' => $engine_f ?: 'all', 'competitors' => $list], JSON_PRETTY_PRINT);
        }

        case '_about':
            return json_encode([
                'description' => 'OtterlyAI MCP server. Use only when the user asks about OtterlyAI data, brand mentions in AI engines, prompt tracking, or citations.',
                'trigger_phrases' => ['OtterlyAI', 'AI visibility', 'brand mentions in AI', 'prompt tracking', 'citations data', 'AI engine stats'],
            ], JSON_PRETTY_PRINT);

        default:
            return json_encode(['error' => "Unknown tool: $name"]);
    }
}

// ── Handle one JSON-RPC request ───────────────────────────────────────────────

function handle_jsonrpc(array $req, string $u_id): ?array {
    $t0        = microtime(true);
    $id        = $req['id']     ?? null;
    $method    = $req['method'] ?? '';
    $tool_name = ($method === 'tools/call') ? ($req['params']['name'] ?? null) : null;
    $status    = 'ok';
    $result    = null;

    switch ($method) {
        case 'initialize':
            $result = [
                'jsonrpc' => '2.0', 'id' => $id,
                'result'  => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'otterly-mcp', 'version' => '1.0.0'],
                ],
            ];
            break;

        case 'notifications/initialized':
            // No log for silent notifications
            return null;

        case 'ping':
            $result = ['jsonrpc' => '2.0', 'id' => $id, 'result' => []];
            break;

        case 'tools/list':
            $result = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => get_tools()]];
            break;

        case 'tools/call':
            $content = execute_tool(
                $req['params']['name']      ?? '',
                $req['params']['arguments'] ?? [],
                $u_id
            );
            $result = [
                'jsonrpc' => '2.0', 'id' => $id,
                'result'  => ['content' => [['type' => 'text', 'text' => $content]]],
            ];
            break;

        default:
            $status = 'unknown_method';
            $result = [
                'jsonrpc' => '2.0', 'id' => $id,
                'error'   => ['code' => -32601, 'message' => "Method not found: $method"],
            ];
    }

    $duration_ms = (int)round((microtime(true) - $t0) * 1000);
    log_request($u_id, $method, $tool_name, $duration_ms, $status);

    return $result;
}

// ── Validate u_id ─────────────────────────────────────────────────────────────

$u_id = safe_id($_GET['u_id'] ?? '');
if ($u_id === '') {
    err_exit(400, 'Missing ?u_id= parameter. Usage: /?u_id=1234');
}
if (!is_dir(DATA_DIR . '/' . $u_id)) {
    err_exit(404, "No data directory found for u_id=$u_id (expected: " . DATA_DIR . "/$u_id/) [__DIR__=" . __DIR__ . "]");
}

// ── Debug endpoint ────────────────────────────────────────────────────────────

if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    $debug = [
        'u_id'     => $u_id,
        'data_dir' => DATA_DIR . '/' . $u_id,
        'php_ext'  => ['pdo' => extension_loaded('pdo'), 'pdo_sqlite' => extension_loaded('pdo_sqlite')],
        'files'    => [],
    ];
    foreach (ENGINE_FILES as $key => $label) {
        $p = data_path($u_id, $key);
        $debug['files'][$key] = ['path' => $p, 'exists' => file_exists($p), 'size' => file_exists($p) ? filesize($p) : 0];
    }
    $cp = citations_csv_path($u_id);
    $dp = citations_db_path($u_id);
    $debug['files']['citations_csv'] = ['path' => $cp, 'exists' => file_exists($cp), 'size' => file_exists($cp) ? filesize($cp) : 0];
    $debug['files']['citations_db']  = ['path' => $dp, 'exists' => file_exists($dp), 'size' => file_exists($dp) ? filesize($dp) : 0];
    $debug['tools_list'] = handle_jsonrpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $u_id);
    echo json_encode($debug, JSON_PRETTY_PRINT);
    exit;
}

// ── Rebuild DB endpoint ───────────────────────────────────────────────────────

if (isset($_GET['rebuild_db'])) {
    header('Content-Type: application/json');
    echo json_encode(build_citations_db($u_id), JSON_PRETTY_PRINT);
    exit;
}

// ── Logs viewer endpoint ──────────────────────────────────────────────────────
// ?u_id=1234&logs=1              → recent requests + stats + tool breakdown
// ?u_id=1234&logs=1&sessions=1   → one row per session (great for "how many calls per conversation")
// ?u_id=1234&logs=1&all=1        → across all u_ids (admin overview)
// ?u_id=1234&logs=1&limit=500    → return more rows (default 200, max 1000)

if (isset($_GET['logs'])) {
    header('Content-Type: application/json');
    try {
        $db         = get_log_db();
        $log_limit  = min((int)($_GET['limit'] ?? 200), 1000);
        $scope_all  = isset($_GET['all']);
        $uid_clause = $scope_all ? '' : "AND u_id = " . $db->quote($u_id);

        if (isset($_GET['sessions'])) {
            // ── Session summary ───────────────────────────────────────────────
            // Each row = one Claude conversation / MCP session
            $rows = $db->query("
                SELECT
                    session_id,
                    u_id,
                    COUNT(*)                                                    AS total_requests,
                    SUM(CASE WHEN method = 'tools/call' THEN 1 ELSE 0 END)     AS tool_calls,
                    MIN(ts)                                                     AS first_seen,
                    MAX(ts)                                                     AS last_seen,
                    ROUND(AVG(duration_ms))                                     AS avg_duration_ms,
                    GROUP_CONCAT(DISTINCT CASE WHEN tool_name IS NOT NULL THEN tool_name END) AS tools_used,
                    ip
                FROM  requests
                WHERE 1=1 $uid_clause
                GROUP BY session_id
                ORDER BY last_seen DESC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'report'        => 'session_summary',
                'scope'         => $scope_all ? 'all' : $u_id,
                'session_count' => count($rows),
                'note'          => 'session_id is stable per IP+UserAgent within the same clock-hour',
                'sessions'      => $rows,
            ], JSON_PRETTY_PRINT);

        } else {
            // ── Recent requests + stats ───────────────────────────────────────
            $rows = $db->query("
                SELECT ts, session_id, u_id, method, tool_name, duration_ms, status, ip
                FROM   requests
                WHERE  1=1 $uid_clause
                ORDER  BY ts DESC
                LIMIT  $log_limit
            ")->fetchAll(PDO::FETCH_ASSOC);

            $stats = $db->query("
                SELECT
                    COUNT(*)                                                    AS total_requests,
                    SUM(CASE WHEN method = 'tools/call' THEN 1 ELSE 0 END)     AS tool_calls,
                    COUNT(DISTINCT session_id)                                  AS unique_sessions,
                    ROUND(AVG(duration_ms))                                     AS avg_duration_ms,
                    MIN(ts)                                                     AS oldest,
                    MAX(ts)                                                     AS newest
                FROM requests
                WHERE 1=1 $uid_clause
            ")->fetch(PDO::FETCH_ASSOC);

            $tools_breakdown = $db->query("
                SELECT   tool_name,
                         COUNT(*)               AS calls,
                         ROUND(AVG(duration_ms)) AS avg_ms,
                         MIN(duration_ms)        AS min_ms,
                         MAX(duration_ms)        AS max_ms
                FROM     requests
                WHERE    method = 'tools/call' $uid_clause
                GROUP BY tool_name
                ORDER BY calls DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Requests-per-hour heatmap (last 48 h)
            $hourly = $db->query("
                SELECT   strftime('%Y-%m-%dT%H:00Z', ts) AS hour,
                         COUNT(*)                         AS requests
                FROM     requests
                WHERE    ts >= datetime('now','-48 hours') $uid_clause
                GROUP BY hour
                ORDER BY hour DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'report'          => 'recent_requests',
                'scope'           => $scope_all ? 'all' : $u_id,
                'stats'           => $stats,
                'tools_breakdown' => $tools_breakdown,
                'hourly_last_48h' => $hourly,
                'requests'        => $rows,
            ], JSON_PRETTY_PRINT);
        }
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── HTTP POST transport ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $req  = json_decode($body, true);
    if (!is_array($req)) err_exit(400, 'Invalid JSON body');

    if (isset($req[0])) {
        // Batch
        $responses = [];
        foreach ($req as $single) {
            $r = handle_jsonrpc($single, $u_id);
            if ($r !== null) $responses[] = $r;
        }
        header('Content-Type: application/json');
        echo json_encode($responses);
    } else {
        $response = handle_jsonrpc($req, $u_id);
        if ($response === null) {
            http_response_code(204);
        } else {
            mcp_response($response);
        }
    }
    exit;
}

// ── SSE transport (GET) ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');

    $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip query string and any trailing /index.php from path
    // On Railway the app is served at / so the endpoint URL must end in /
    $path     = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $path     = preg_replace('#/index\.php$#', '/', $path);
    $path     = rtrim($path, '/') . '/';
    $endpoint = "$proto://$host{$path}?u_id=$u_id";

    echo "event: endpoint\n";
    echo "data: $endpoint\n\n";
    flush();

    $start = time();
    while (!connection_aborted() && (time() - $start) < 50) {
        echo ": ping\n\n";
        flush();
        sleep(10);
    }
    exit;
}

// ── OPTIONS preflight ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

err_exit(405, 'Method not allowed');
