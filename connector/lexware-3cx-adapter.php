<?php
declare(strict_types=1);

/**
 * Lexware Office (formerly lexoffice) -> 3CX Adapter
 * ==================================================
 *
 * The Lexware contacts API cannot filter by phone number (only by email, name,
 * customer number, customer/vendor). A 3CX CRM template therefore cannot look
 * up a caller directly in Lexware. This adapter bridges the gap: it pulls all
 * contacts from Lexware, builds an index searchable by phone number and email,
 * and answers the lookup requests of the 3CX template.
 *
 * Two cleanly separated roles:
 *
 *   php lexware-3cx-adapter.php refresh    Rebuild the index from Lexware
 *                                          (run on a timer, e.g. every 10 min).
 *                                          Calls the Lexware API.
 *
 *   php -S 0.0.0.0:8710 lexware-3cx-adapter.php
 *                                          HTTP service. Reads only the cache
 *                                          file, never calls Lexware during a
 *                                          lookup, so it stays fast and never
 *                                          blocks an incoming call.
 *
 *   php lexware-3cx-adapter.php selftest   Internal tests, no network.
 *
 * HTTP endpoints (all except /health require ?token=<shared_token>):
 *   GET /lookup?number=+49...        Caller by phone number -> {"contacts":[...]}
 *   GET /lookupByEmail?email=a@b.de  Contact by email       -> {"contacts":[...]}
 *   GET /search?q=text               Free-text search       -> {"contacts":[...]}
 *   GET /health                      Cache status
 *
 * Configuration: environment variables take precedence (Docker); otherwise a
 * PHP config file (config.php) next to this script is used (bare-metal). See
 * config.example.php and the README.
 *
 * Exit codes (CLI): 0 = ok, 1 = config/usage error, 2 = runtime error.
 *
 * License: MIT.
 */

// ===========================================================================
// Configuration and logging
// ===========================================================================

/** @return array<string,mixed> */
function loadConfig(): array
{
    // Environment variables take precedence (Docker: config directly under
    // `environment:` in compose). Falls back to the PHP config file for
    // bare-metal / systemd operation.
    if (getenv('ADAPTER_TOKEN') !== false || getenv('LEXWARE_API_KEY') !== false) {
        return configFromEnv();
    }

    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        logLine('No environment variables and no config.php found. Copy config.example.php to config.php.');
        exit(1);
    }
    /** @var array<string,mixed> $cfg */
    $cfg = require $file;
    return $cfg;
}

/** @return array<string,mixed> */
function configFromEnv(): array
{
    $str = static fn (string $k, string $d): string
        => (($v = getenv($k)) !== false && $v !== '') ? $v : $d;
    $int = static fn (string $k, int $d): int
        => (($v = getenv($k)) !== false && $v !== '') ? (int) $v : $d;
    $bool = static function (string $k, bool $d): bool {
        $v = getenv($k);
        if ($v === false || $v === '') {
            return $d;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    };

    return [
        'lexware' => [
            'base_url'       => rtrim($str('LEXWARE_BASE_URL', 'https://api.lexware.io'), '/'),
            'api_key'        => $str('LEXWARE_API_KEY', ''),
            'app_base_url'   => $str('LEXWARE_APP_BASE_URL', 'https://app.lexware.de'),
            'only_customers' => $bool('LEXWARE_ONLY_CUSTOMERS', false),
            'page_size'      => $int('LEXWARE_PAGE_SIZE', 250),
            'page_delay_ms'  => $int('LEXWARE_PAGE_DELAY_MS', 600),
        ],
        'adapter' => [
            'host'                  => '0.0.0.0',
            'port'                  => $int('ADAPTER_PORT', 8710),
            'shared_token'          => $str('ADAPTER_TOKEN', ''),
            'cache_file'            => $str('ADAPTER_CACHE_FILE', '/data/cache.json'),
            'max_cache_age_seconds' => $int('ADAPTER_MAX_CACHE_AGE', 3600),
        ],
        'sync' => [
            'country_code' => $str('COUNTRY_CODE', '+49'),
        ],
    ];
}

function logLine(string $message): void
{
    // Via the php://stderr stream, which exists in both SAPIs (CLI and the
    // built-in web server) - unlike the STDERR constant. The token and the
    // API key are deliberately never logged.
    static $err = null;
    if ($err === null) {
        $err = fopen('php://stderr', 'w');
    }
    if ($err !== false) {
        fwrite($err, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
    }
}

/** Lowercase, with correct handling of non-ASCII letters when mbstring exists. */
function lc(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

// ===========================================================================
// Phone number normalization
// ===========================================================================

final class Phone
{
    /** Normalizes a phone number to E.164, or null. */
    public static function normalize(?string $raw, string $countryCode): ?string
    {
        if ($raw === null) {
            return null;
        }

        $s = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($s === '') {
            return null;
        }

        // Allow "+" only in the first position.
        $s = (str_starts_with($s, '+') ? '+' : '') . str_replace('+', '', $s);

        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        } elseif (str_starts_with($s, '0')) {
            $s = $countryCode . substr($s, 1);
        } elseif (!str_starts_with($s, '+')) {
            $s = $countryCode . $s;
        }

        // Notation "+49 (0) 6145 ..." - the zero after the country code drops.
        $cc = ltrim($countryCode, '+');
        if (str_starts_with($s, '+' . $cc . '0')) {
            $s = '+' . $cc . ltrim(substr($s, 1 + strlen($cc)), '0');
        }

        return strlen($s) >= 8 ? $s : null;
    }

    /** Last eight digits - fallback key against formatting differences. */
    public static function last8(string $e164): ?string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        return strlen($digits) >= 8 ? substr($digits, -8) : null;
    }
}

// ===========================================================================
// Lexware contact -> flat address-book record(s)
// ===========================================================================

final class ContactMapper
{
    /**
     * Maps one Lexware contact into the flat records the adapter serves and the
     * 3CX template reads. A company with contact persons yields several records:
     * one per contact person (their own number, display "Last, First (Company)")
     * plus one for the company's own number(s). Returns an empty array for
     * archived or nameless contacts.
     *
     * @param  array<string,mixed> $c   Lexware contact
     * @return array<int,array<string,mixed>>  0..n address-book records
     */
    public static function map(array $c, string $appBaseUrl): array
    {
        if (!empty($c['archived'])) {
            return [];
        }

        $id = (string) ($c['id'] ?? '');
        if ($id === '') {
            return [];
        }

        $url     = rtrim($appBaseUrl, '/') . '/permalink/contacts/view/' . $id;
        $records = [];

        if (isset($c['company']) && is_array($c['company'])) {
            $company = self::clean((string) ($c['company']['name'] ?? ''));
            $persons = $c['company']['contactPersons'] ?? [];
            $persons = is_array($persons) ? $persons : [];

            // One record per contact person with their own number,
            // display "Last, First (Company)".
            $pIdx = 0;
            foreach ($persons as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $first = self::clean((string) ($p['firstName'] ?? ''));
                $last  = self::clean((string) ($p['lastName'] ?? ''));
                $phone = self::clean((string) ($p['phoneNumber'] ?? ''));
                $mail  = self::clean((string) ($p['emailAddress'] ?? ''));

                if ($first === '' && $last === '' && $phone === '' && $mail === '') {
                    continue;
                }

                $phones = $phone !== '' ? [self::slotForNumber($phone) => $phone] : [];
                $records[] = self::record(
                    $id, $id . '#p' . $pIdx, $url,
                    self::displayName($first, $last, $company),
                    $first, $last, $company, $mail, $phones
                );
                $pIdx++;
            }

            // Plus the company's own number(s). The company name goes into the
            // display name (so it is visible on desk phones and in emails); the
            // company field stays empty here, otherwise clients show the
            // company twice.
            $companyPhones = self::collectTopLevelPhones($c);
            if ($companyPhones !== [] || $persons === []) {
                $records[] = self::record(
                    $id, $id . '#c', $url,
                    $company,
                    '', '', '', self::firstEmail($c), $companyPhones
                );
            }

            return $records;
        }

        // Person contact (no company).
        $person = isset($c['person']) && is_array($c['person']) ? $c['person'] : [];
        $first  = self::clean((string) ($person['firstName'] ?? ''));
        $last   = self::clean((string) ($person['lastName'] ?? ''));

        if ($first === '' && $last === '') {
            return [];
        }

        $records[] = self::record(
            $id, $id, $url,
            self::displayName($first, $last, ''),
            $first, $last, '', self::firstEmail($c),
            self::collectTopLevelPhones($c)
        );

        return $records;
    }

    /**
     * Builds one flat address-book record.
     *
     * @param  array<string,string> $phones  slot (mobile|business|home|other) => raw number
     * @return array<string,mixed>
     */
    private static function record(
        string $id,
        string $entityId,
        string $url,
        string $display,
        string $first,
        string $last,
        string $company,
        string $email,
        array $phones
    ): array {
        return [
            'id'          => $id,
            'entityId'    => $entityId,
            'contactUrl'  => $url,
            'displayName' => $display !== '' ? $display : trim($first . ' ' . $last),
            'firstName'   => $first,
            'lastName'    => $last,
            'company'     => $company,
            'email'       => $email,
            'mobile'      => $phones['mobile']   ?? '',
            'business'    => $phones['business'] ?? '',
            'home'        => $phones['home']     ?? '',
            'other'       => $phones['other']    ?? '',
        ];
    }

    /**
     * Assigns a (not yet normalized) phone number to a 3CX slot. German mobile
     * prefixes (15x/16x/17x) go to "mobile", everything else to "business".
     * Purely cosmetic (which Phone* field 3CX fills); matching is unaffected.
     */
    private static function slotForNumber(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($d, '0049')) {
            $d = substr($d, 4);
        } elseif (str_starts_with($d, '49')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        return preg_match('/^1[567]/', $d) === 1 ? 'mobile' : 'business';
    }

    /**
     * Removes invisible bidi / zero-width control characters that Lexware
     * sometimes wraps around values (mainly phone numbers), and trims. They are
     * irrelevant for matching (only digits count there) but clutter the display.
     */
    public static function clean(string $s): string
    {
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? $s;
        return trim($s);
    }

    /**
     * Builds the display name in the format "Last, First (Company)". A missing
     * name part is dropped; a pure company contact without a contact person
     * yields just the company name.
     */
    public static function displayName(string $first, string $last, string $company): string
    {
        if ($last !== '' && $first !== '') {
            $person = $last . ', ' . $first;
        } elseif ($last !== '') {
            $person = $last;
        } elseif ($first !== '') {
            $person = $first;
        } else {
            $person = '';
        }

        if ($person !== '') {
            return $company !== '' ? $person . ' (' . $company . ')' : $person;
        }

        return $company;
    }

    /**
     * Collects the top-level phone numbers (the company's or person's own
     * numbers), grouped into the four 3CX categories, first number per
     * category. Contact-person numbers are NOT taken here - they get their own
     * records in map().
     *
     * @param  array<string,mixed> $c
     * @return array<string,string> raw numbers (not yet normalized)
     */
    public static function collectTopLevelPhones(array $c): array
    {
        $out = [];
        $pn  = (isset($c['phoneNumbers']) && is_array($c['phoneNumbers'])) ? $c['phoneNumbers'] : [];

        $take = static function (string $target, array $sources) use (&$out, $pn): void {
            if (isset($out[$target])) {
                return;
            }
            foreach ($sources as $key) {
                $vals = $pn[$key] ?? null;
                if (is_array($vals)) {
                    foreach ($vals as $v) {
                        $v = self::clean((string) $v);
                        if ($v !== '') {
                            $out[$target] = $v;
                            return;
                        }
                    }
                }
            }
        };

        $take('mobile',   ['mobile']);
        $take('business', ['business', 'office']);
        $take('home',     ['private']);
        $take('other',    ['other', 'fax']);

        return $out;
    }

    /**
     * First top-level email address (of the company or person). No fallback to
     * contact persons - they have their own records.
     *
     * @param array<string,mixed> $c
     */
    private static function firstEmail(array $c): string
    {
        $ea = (isset($c['emailAddresses']) && is_array($c['emailAddresses'])) ? $c['emailAddresses'] : [];
        foreach (['business', 'office', 'other', 'private'] as $key) {
            $vals = $ea[$key] ?? null;
            if (is_array($vals)) {
                foreach ($vals as $v) {
                    $v = self::clean((string) $v);
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        }

        return '';
    }
}

// ===========================================================================
// Index building (from mapped records)
// ===========================================================================

final class IndexBuilder
{
    /**
     * Builds the cache structure from a list of flat records: exact-match index
     * by E.164, fallback index by the last eight digits, email index, and the
     * full list for free-text search.
     *
     * @param  array<int,array<string,mixed>> $contacts
     * @return array<string,mixed>
     */
    public static function build(array $contacts, string $countryCode): array
    {
        $list      = array_values($contacts);
        $byNumber  = [];
        $byLast8   = [];
        $byEmail   = [];

        foreach ($list as $idx => $contact) {
            foreach (['mobile', 'business', 'home', 'other'] as $field) {
                $e164 = Phone::normalize((string) ($contact[$field] ?? ''), $countryCode);
                if ($e164 === null) {
                    continue;
                }
                if (!isset($byNumber[$e164])) {
                    $byNumber[$e164] = $idx;
                }
                $last8 = Phone::last8($e164);
                if ($last8 !== null) {
                    $byLast8[$last8][$idx] = true;
                }
            }

            $email = strtolower(trim((string) ($contact['email'] ?? '')));
            if ($email !== '' && !isset($byEmail[$email])) {
                $byEmail[$email] = $idx;
            }
        }

        // Turn the set of indices per last8 into a list.
        $last8Flat = [];
        foreach ($byLast8 as $key => $set) {
            $last8Flat[$key] = array_keys($set);
        }

        return [
            'generatedAt' => time(),
            'count'       => count($list),
            'countryCode' => $countryCode,
            'byNumber'    => $byNumber,
            'byLast8'     => $last8Flat,
            'byEmail'     => $byEmail,
            'list'        => $list,
        ];
    }
}

// ===========================================================================
// Lexware API client
// ===========================================================================

final class LexwareClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $pageSize,
        private readonly int $pageDelayMs
    ) {
    }

    /**
     * Loads all contacts page by page. Respects the rate limit (pause per page)
     * and retries on HTTP 429 with increasing backoff.
     *
     * @param  array<string,string> $filter  query filter, e.g. ['customer'=>'true']
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllContacts(array $filter = []): array
    {
        $all   = [];
        $page  = 0;
        $pages = 1;

        do {
            $query = array_merge($filter, ['page' => $page, 'size' => $this->pageSize]);
            $url   = $this->baseUrl . '/v1/contacts?' . http_build_query($query);

            $res = $this->get($url);

            if ($res['status'] === 429) {
                // Rate limit: retry with exponential backoff.
                static $retry = 0;
                $retry++;
                if ($retry > 5) {
                    throw new RuntimeException('Lexware: persistent rate limit (HTTP 429).');
                }
                $wait = (int) (500 * (2 ** $retry));
                logLine("Rate limit hit, waiting {$wait} ms and retrying.");
                usleep($wait * 1000);
                continue;
            }

            if ($res['status'] !== 200 || !is_array($res['json'])) {
                throw new RuntimeException(sprintf(
                    'Lexware /v1/contacts page %d: HTTP %d - %s',
                    $page,
                    $res['status'],
                    substr($res['raw'], 0, 300)
                ));
            }

            $content = $res['json']['content'] ?? [];
            if (is_array($content)) {
                foreach ($content as $c) {
                    if (is_array($c)) {
                        $all[] = $c;
                    }
                }
            }

            $pages = (int) ($res['json']['totalPages'] ?? 1);
            $page++;

            if ($page < $pages) {
                usleep($this->pageDelayMs * 1000);
            }
        } while ($page < $pages);

        return $all;
    }

    /** @return array{status:int, json:mixed, raw:string} */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Lexware connection failed: ' . $error);
        }

        $raw     = is_string($raw) ? $raw : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return ['status' => $status, 'json' => $decoded, 'raw' => $raw];
    }
}

// ===========================================================================
// Lookup in the cache
// ===========================================================================

final class ContactLookup
{
    /** @param array<string,mixed> $cache */
    public function __construct(private readonly array $cache)
    {
    }

    /** @return array<string,mixed>|null */
    public function byNumber(string $rawNumber): ?array
    {
        $cc   = (string) ($this->cache['countryCode'] ?? '+49');
        $e164 = Phone::normalize($rawNumber, $cc);
        if ($e164 === null) {
            return null;
        }

        $byNumber = $this->cache['byNumber'] ?? [];
        if (isset($byNumber[$e164])) {
            return $this->cache['list'][$byNumber[$e164]] ?? null;
        }

        // Fallback: last eight digits, but only on an unambiguous match, to
        // avoid wrong name assignments.
        $last8 = Phone::last8($e164);
        $byLast8 = $this->cache['byLast8'] ?? [];
        if ($last8 !== null && isset($byLast8[$last8]) && count($byLast8[$last8]) === 1) {
            return $this->cache['list'][$byLast8[$last8][0]] ?? null;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function byEmail(string $email): ?array
    {
        $key = strtolower(trim($email));
        $byEmail = $this->cache['byEmail'] ?? [];
        if ($key !== '' && isset($byEmail[$key])) {
            return $this->cache['list'][$byEmail[$key]] ?? null;
        }
        return null;
    }

    /**
     * Free-text search over display name, name, company, email and numbers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 25): array
    {
        $needle = lc(trim($query));
        if ($needle === '') {
            return [];
        }

        $digits  = preg_replace('/\D/', '', $query) ?? '';
        $results = [];

        foreach (($this->cache['list'] ?? []) as $contact) {
            $haystack = lc(implode(' ', [
                (string) ($contact['displayName'] ?? ''),
                (string) ($contact['firstName'] ?? ''),
                (string) ($contact['lastName'] ?? ''),
                (string) ($contact['company'] ?? ''),
                (string) ($contact['email'] ?? ''),
            ]));

            $match = str_contains($haystack, $needle);

            if (!$match && $digits !== '' && strlen($digits) >= 3) {
                foreach (['mobile', 'business', 'home', 'other'] as $f) {
                    $num = preg_replace('/\D/', '', (string) ($contact[$f] ?? '')) ?? '';
                    if ($num !== '' && str_contains($num, $digits)) {
                        $match = true;
                        break;
                    }
                }
            }

            if ($match) {
                $results[] = $contact;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }
}

// ===========================================================================
// Cache read / write
// ===========================================================================

/** @param array<string,mixed> $cache */
function writeCache(string $path, array $cache): void
{
    $tmp = $path . '.tmp';
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not serialize cache.');
    }
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Cache file not writable: ' . $tmp);
    }
    rename($tmp, $path);
}

/** @return array<string,mixed>|null */
function readCache(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

// ===========================================================================
// CLI: refresh
// ===========================================================================

function commandRefresh(array $cfg): int
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "PHP extension 'curl' is missing - use an image with the "
            . "curl extension enabled (the official php:8.3-cli images ship it)."
            . PHP_EOL);
        return 1;
    }

    $lw = $cfg['lexware'];

    if (($lw['api_key'] ?? '') === '' || str_starts_with((string) $lw['api_key'], 'REPLACE_ME')) {
        fwrite(STDERR, 'Lexware API key is not set (env LEXWARE_API_KEY or config.php).' . PHP_EOL);
        return 1;
    }

    $client = new LexwareClient(
        rtrim((string) $lw['base_url'], '/'),
        (string) $lw['api_key'],
        (int) $lw['page_size'],
        (int) $lw['page_delay_ms']
    );

    $filter = !empty($lw['only_customers']) ? ['customer' => 'true'] : [];

    logLine('Loading contacts from Lexware ...');
    $raw = $client->fetchAllContacts($filter);
    logLine(sprintf('%d contacts received. Building index ...', count($raw)));

    $mapped = [];
    foreach ($raw as $c) {
        foreach (ContactMapper::map($c, (string) $lw['app_base_url']) as $rec) {
            $mapped[] = $rec;
        }
    }

    $cache = IndexBuilder::build($mapped, (string) $cfg['sync']['country_code']);
    writeCache((string) $cfg['adapter']['cache_file'], $cache);

    logLine(sprintf(
        'Index written: %d records, %d phone numbers, %d email addresses.',
        $cache['count'],
        count($cache['byNumber']),
        count($cache['byEmail'])
    ));

    return 0;
}

// ===========================================================================
// HTTP: serve (under `php -S`)
// ===========================================================================

/** @param array<string,mixed>|object $payload */
function sendJson(array|object $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function serveHttp(array $cfg): void
{
    $adapter = $cfg['adapter'];
    $path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Check the token (except /health, which reveals no contact data).
    $token = (string) ($_GET['token'] ?? '');
    if ($path !== '/health' && !hash_equals((string) $adapter['shared_token'], $token)) {
        sendJson(['error' => 'forbidden'], 403);
        return;
    }

    $cache = readCache((string) $adapter['cache_file']);

    if ($path === '/health') {
        if ($cache === null) {
            sendJson(['status' => 'empty', 'message' => 'Cache missing - run refresh.'], 503);
            return;
        }
        $age = time() - (int) ($cache['generatedAt'] ?? 0);
        sendJson([
            'status'      => 'ok',
            'contacts'    => (int) ($cache['count'] ?? 0),
            'ageSeconds'  => $age,
            'generatedAt' => date('c', (int) ($cache['generatedAt'] ?? 0)),
        ]);
        return;
    }

    if ($cache === null) {
        // No cache: return an empty result instead of an error, so 3CX puts
        // the call through undisturbed.
        sendJson(['contacts' => []]);
        return;
    }

    $maxAge = (int) ($adapter['max_cache_age_seconds'] ?? 0);
    if ($maxAge > 0 && (time() - (int) ($cache['generatedAt'] ?? 0)) > $maxAge) {
        logLine('Warning: cache is stale; skipping lookup.');
        sendJson(['contacts' => []]);
        return;
    }

    $lookup = new ContactLookup($cache);

    switch ($path) {
        case '/lookup':
            $hit = $lookup->byNumber((string) ($_GET['number'] ?? ''));
            sendJson(['contacts' => $hit !== null ? [$hit] : []]);
            return;

        case '/lookupByEmail':
            $hit = $lookup->byEmail((string) ($_GET['email'] ?? ''));
            sendJson(['contacts' => $hit !== null ? [$hit] : []]);
            return;

        case '/search':
            sendJson(['contacts' => $lookup->search((string) ($_GET['q'] ?? ''))]);
            return;

        default:
            sendJson(['error' => 'not_found'], 404);
            return;
    }
}

// ===========================================================================
// CLI: selftest (no network)
// ===========================================================================

function commandSelftest(): int
{
    $fail = 0;
    $check = static function (string $name, bool $ok) use (&$fail): void {
        printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $name);
        if (!$ok) {
            $fail++;
        }
    };

    // Phone number normalization
    $cases = [
        ['+49 6145 1234567',    '+4961451234567'],
        ['06145 / 12 34 567',   '+4961451234567'],
        ['+49 (0) 6145 1234567','+4961451234567'],
        ['0049 6145 1234567',   '+4961451234567'],
        ['+1 415 555 0123',     '+14155550123'],
        ['0176 12345678',       '+4917612345678'],
        ['123',                 null],
        ['no number here',      null],
    ];
    foreach ($cases as [$in, $want]) {
        $check("normalize \"$in\"", Phone::normalize($in, '+49') === $want);
    }

    // Example contacts in Lexware format
    $person = [
        'id'    => 'aaaaaaaa-0000-0000-0000-000000000001',
        'person'=> ['salutation' => 'Mr', 'firstName' => 'Max', 'lastName' => 'Sample'],
        'phoneNumbers' => [
            'mobile'   => ['0176 12345678'],
            'business' => ['+49 6145 1234567'],
            'private'  => ['069 111222'],
        ],
        'emailAddresses' => ['business' => ['max@sample.com']],
    ];
    // Company with a contact person, both with their own number.
    $company = [
        'id'     => 'bbbbbbbb-0000-0000-0000-000000000002',
        'company'=> [
            'name' => 'Sample Ltd',
            'contactPersons' => [
                ['firstName' => 'Erika', 'lastName' => 'Sample',
                 'emailAddress' => 'erika@sample.com', 'phoneNumber' => '+49 151 5550100'],
            ],
        ],
        'phoneNumbers' => ['business' => ['06151 5550111'], 'fax' => ['089 4443300']],
    ];
    $archived = ['id' => 'cccc', 'archived' => true, 'person' => ['lastName' => 'Gone']];
    $nameless = ['id' => 'dddd', 'phoneNumbers' => ['mobile' => ['0170 0000000']]];

    $recPerson  = ContactMapper::map($person, 'https://app.lexware.de');
    $recCompany = ContactMapper::map($company, 'https://app.lexware.de');
    $mPerson    = $recPerson[0] ?? [];

    $erika = null;
    $comp  = null;
    foreach ($recCompany as $r) {
        if (($r['firstName'] ?? '') === 'Erika') {
            $erika = $r;
        } elseif (($r['firstName'] ?? '') === '') {
            $comp = $r;
        }
    }

    $check('person mapping name',   ($mPerson['firstName'] ?? '') === 'Max' && ($mPerson['lastName'] ?? '') === 'Sample');
    $check('person mapping mobile', ($mPerson['mobile'] ?? '') === '0176 12345678');
    $check('person mapping email',  ($mPerson['email'] ?? '') === 'max@sample.com');
    $check('contactUrl correct',    ($mPerson['contactUrl'] ?? '') === 'https://app.lexware.de/permalink/contacts/view/' . $person['id']);

    // Company yields two records: contact person + company number
    $check('company: two records',      count($recCompany) === 2);
    $check('person record display',     $erika !== null && $erika['displayName'] === 'Sample, Erika (Sample Ltd)');
    $check('person record mobile',      $erika !== null && $erika['mobile'] === '+49 151 5550100');
    $check('person record email',       $erika !== null && $erika['email'] === 'erika@sample.com');
    $check('company record display',    $comp !== null && $comp['displayName'] === 'Sample Ltd');
    $check('company record number',     $comp !== null && $comp['business'] === '06151 5550111');
    $check('company record no dup name',$comp !== null && $comp['company'] === '');
    $check('entityId unique per record',$erika !== null && $comp !== null && $erika['entityId'] !== $comp['entityId']);
    $check('entityId scheme person',    $erika !== null && $erika['entityId'] === $company['id'] . '#p0');
    $check('entityId scheme company',   $comp !== null && $comp['entityId'] === $company['id'] . '#c');

    // Bidi / zero-width control characters are stripped (LRE + PDF around a number)
    $marked = ContactMapper::map([
        'id'    => 'eeee',
        'person'=> ['firstName' => 'Test', 'lastName' => 'Marks'],
        'phoneNumbers' => ['mobile' => ["\u{202A}+49 151 5550100\u{202C}"]],
    ], 'x');
    $check('control characters stripped', ($marked[0]['mobile'] ?? '') === '+49 151 5550100');

    // Display name variants
    $check('displayName person only',   ($mPerson['displayName'] ?? '') === 'Sample, Max');
    $check('displayName company only',  ContactMapper::displayName('', '', 'Only Ltd') === 'Only Ltd');
    $check('displayName last name only',ContactMapper::displayName('', 'Meier', 'X AG') === 'Meier (X AG)');
    $check('archived skipped',          ContactMapper::map($archived, 'x') === []);
    $check('nameless skipped',          ContactMapper::map($nameless, 'x') === []);

    // Index over all records (1 person + 2 from the company)
    $all    = array_merge($recPerson, $recCompany);
    $cache  = IndexBuilder::build($all, '+49');
    $lookup = new ContactLookup($cache);

    $check('index count', ($cache['count'] ?? 0) === 3);

    $h1 = $lookup->byNumber('+49 6145 1234567');
    $check('lookup person (business)', $h1 !== null && $h1['id'] === $person['id']);

    $h2 = $lookup->byNumber('0176 12345678');
    $check('lookup person (mobile)', $h2 !== null && $h2['id'] === $person['id']);

    $h3 = $lookup->byNumber('06145-1234567');
    $check('lookup with different format', $h3 !== null && $h3['id'] === $person['id']);

    $h4 = $lookup->byNumber('+49 30 0000000');
    $check('unknown number -> no match', $h4 === null);

    $h5 = $lookup->byEmail('MAX@sample.com');
    $check('lookup by email', $h5 !== null && $h5['id'] === $person['id']);

    // Core case: contact person calls from their mobile -> name + company
    $h6 = $lookup->byNumber('+49 151 5550100');
    $check('lookup person mobile -> name + company', $h6 !== null && $h6['displayName'] === 'Sample, Erika (Sample Ltd)');

    // Company number -> company display
    $h7 = $lookup->byNumber('06151 5550111');
    $check('lookup company number -> company', $h7 !== null && $h7['displayName'] === 'Sample Ltd');

    $s2 = $lookup->search('sample');
    $check('search finds records', count($s2) >= 1);

    $sE = $lookup->search('erika');
    $check('search contact person', count($sE) === 1 && $sE[0]['displayName'] === 'Sample, Erika (Sample Ltd)');

    $s3 = $lookup->search('1234567');
    $check('search by number fragment', count($s3) === 1 && $s3[0]['id'] === $person['id']);

    echo $fail === 0
        ? "\nAll tests passed.\n"
        : "\n$fail test(s) failed.\n";

    return $fail === 0 ? 0 : 2;
}

// ===========================================================================
// Entry point
// ===========================================================================

if (php_sapi_name() === 'cli-server') {
    // Under `php -S` every request runs through this file.
    try {
        serveHttp(loadConfig());
    } catch (Throwable $e) {
        logLine('HTTP error: ' . $e->getMessage());
        sendJson(['error' => 'internal'], 500);
    }
    return;
}

$command = $argv[1] ?? '';

try {
    switch ($command) {
        case 'refresh':
            exit(commandRefresh(loadConfig()));
        case 'selftest':
            exit(commandSelftest());
        default:
            fwrite(STDERR, sprintf(
                "Usage:\n" .
                "  php -S 0.0.0.0:<port> %1\$s      start the HTTP service\n" .
                "  php %1\$s refresh                 rebuild the index from Lexware\n" .
                "  php %1\$s selftest                run internal tests\n",
                basename(__FILE__)
            ));
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Aborted: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
