<?php
/**
 * Lightweight Google Ads REST client (no Composer, cURL only)
 * ---------------------------------------------------------------------------
 * What it does:
 *  - Exchanges the refresh token for an access token (valid 1 hour)
 *  - Caches the access token to avoid repeated token requests
 *  - Runs GAQL queries (search / searchStream)
 *  - Lists accessible customers
 */
class GoogleAdsClient
{
    private array $cfg;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;
    private string $cacheFile;

    public function __construct(array $config)
    {
        $this->cfg       = $config;
        // Separate cache per Google account (refresh token)
        $this->cacheFile = sys_get_temp_dir() . '/gads_token_' . md5($config['client_id'] . '|' . $config['refresh_token']) . '.json';
    }

    /** Manager/account the call is made through (login-customer-id header) */
    public function setLoginCustomerId(?string $id): self
    {
        $this->cfg['login_customer_id'] = $id ? preg_replace('/\D/', '', $id) : '';
        return $this;
    }

    /** Get an access token (cached, or a fresh one from the refresh token) */
    public function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }
        if (is_file($this->cacheFile)) {
            $c = json_decode(file_get_contents($this->cacheFile), true);
            if (!empty($c['token']) && time() < ($c['exp'] ?? 0) - 60) {
                $this->accessToken = $c['token'];
                $this->tokenExpiry = $c['exp'];
                return $this->accessToken;
            }
        }

        $res = $this->http('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'refresh_token' => $this->cfg['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]));

        if (empty($res['access_token'])) {
            throw new RuntimeException('Could not get an access token: ' . json_encode($res));
        }
        $this->accessToken = $res['access_token'];
        $this->tokenExpiry = time() + (int)($res['expires_in'] ?? 3600);
        @file_put_contents($this->cacheFile, json_encode([
            'token' => $this->accessToken, 'exp' => $this->tokenExpiry,
        ]));
        return $this->accessToken;
    }

    /** Customer IDs this login can access */
    public function listAccessibleCustomers(): array
    {
        $res = $this->api('GET', '/customers:listAccessibleCustomers');
        return array_map(fn($r) => str_replace('customers/', '', $r), $res['resourceNames'] ?? []);
    }

    /** Run a GAQL query - all rows in one array */
    public function query(string $customerId, string $gaql): array
    {
        $customerId = str_replace('-', '', $customerId);
        $res  = $this->api('POST', "/customers/$customerId/googleAds:searchStream", ['query' => $gaql]);
        $rows = [];
        foreach ($res as $batch) {
            foreach ($batch['results'] ?? [] as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Generic mutate: $service = 'campaigns', 'adGroups', 'ads', 'adGroupAds',
     * 'adGroupCriteria', 'campaignCriteria', 'campaignBudgets', 'assetGroups' ...
     */
    public function mutate(string $customerId, string $service, array $operations, bool $partialFailure = false): array
    {
        $customerId = str_replace('-', '', $customerId);
        $body = ['operations' => $operations];
        if ($partialFailure) {
            $body['partialFailure'] = true;
        }
        return $this->api('POST', "/customers/$customerId/$service:mutate", $body);
    }

    /**
     * Several resources in one request (budget + campaign + ad group + ads ...) - atomic:
     * if one fails, nothing is created. $validateOnly = validate only, create nothing.
     */
    public function mutateAll(string $customerId, array $mutateOperations, bool $validateOnly = false): array
    {
        $customerId = str_replace('-', '', $customerId);
        $body = ['mutateOperations' => $mutateOperations];
        if ($validateOnly) {
            $body['validateOnly'] = true;
        }
        return $this->api('POST', "/customers/$customerId/googleAds:mutate", $body);
    }

    /** Account (customer) level update - tracking template, final URL suffix */
    public function mutateCustomer(string $customerId, array $fields): array
    {
        $customerId = str_replace('-', '', $customerId);
        return $this->api('POST', "/customers/$customerId:mutate", [
            'operation' => [
                'update'     => ['resourceName' => "customers/$customerId"] + $fields,
                'updateMask' => implode(',', array_keys($fields)),
            ],
        ]);
    }

    /** Set campaign status: ENABLED / PAUSED */
    public function setCampaignStatus(string $customerId, string $campaignId, string $status): array
    {
        $customerId = str_replace('-', '', $customerId);
        return $this->api('POST', "/customers/$customerId/campaigns:mutate", [
            'operations' => [[
                'update'     => ['resourceName' => "customers/$customerId/campaigns/$campaignId", 'status' => $status],
                'updateMask' => 'status',
            ]],
        ]);
    }

    /** Google Ads API call */
    public function api(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Content-Type: application/json',
        ];
        if (!empty($this->cfg['login_customer_id'])) {
            $headers[] = 'login-customer-id: ' . str_replace('-', '', $this->cfg['login_customer_id']);
        }
        if (!empty($this->cfg['developer_token'])) {
            $headers[] = 'developer-token: ' . $this->cfg['developer_token']; // optional, ignored by Google
        }
        $url = rtrim($this->cfg['api_base'] ?? 'https://googleads.googleapis.com', '/') . '/' . $this->cfg['api_version'] . $path;
        return $this->http($method, $url, $headers, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
    }

    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("cURL error: $err");
        }
        $data = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            $e   = isset($data[0]['error']) ? $data[0]['error'] : ($data['error'] ?? $data);
            $msg = is_array($e) ? ($e['message'] ?? json_encode($e))
                                : $e . (isset($data['error_description']) ? ' - ' . $data['error_description'] : '');
            // GoogleAdsFailure details -> readable "CUSTOMER_NOT_ENABLED - message" error
            $parts = [];
            foreach ((is_array($e) ? ($e['details'] ?? []) : []) as $d) {
                foreach ($d['errors'] ?? [] as $x) {
                    $codeName = is_array($x['errorCode'] ?? null) ? (string)reset($x['errorCode']) : '';
                    $p = trim($codeName . ' - ' . ($x['message'] ?? ''), ' -');
                    // Which text triggered the policy error
                    $trig = $x['trigger']['stringValue'] ?? '';
                    if (!empty($x['details']['policyFindingDetails']['policyTopicEntries'])) {
                        $topics = array_column($x['details']['policyFindingDetails']['policyTopicEntries'], 'topic');
                        $p .= ' [policy: ' . implode(', ', $topics) . ']';
                    }
                    if ($trig !== '') {
                        $p .= " (\"$trig\")";
                    }
                    $parts[] = $p;
                }
            }
            if ($parts) {
                $msg = implode(' | ', array_unique($parts));
            }
            throw new RuntimeException("API error ($code): $msg");
        }
        return $data;
    }
}
