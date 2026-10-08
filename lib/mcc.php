<?php
/**
 * Create multiple Google Ads accounts under a manager account (MCC) in one go
 * API: CustomerService.CreateCustomerClient  ->  POST /customers/{mcc}:createCustomerClient
 *
 * Note: currency and time zone can never be changed after creation.
 * Billing (payment method) must be set up in the Google Ads UI.
 */

const MCC_CURRENCIES = ['INR', 'USD', 'GBP', 'EUR', 'AUD', 'CAD', 'AED', 'SGD', 'NZD', 'JPY', 'CHF', 'SAR', 'ZAR', 'MYR', 'HKD', 'BRL', 'MXN', 'IDR', 'PHP', 'THB'];
const MCC_TIMEZONES = ['Asia/Kolkata', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix',
    'America/Toronto', 'America/Vancouver', 'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Madrid', 'Asia/Dubai', 'Asia/Riyadh',
    'Asia/Singapore', 'Asia/Kuala_Lumpur', 'Asia/Hong_Kong', 'Asia/Tokyo', 'Asia/Jakarta', 'Asia/Manila', 'Asia/Bangkok',
    'Australia/Sydney', 'Australia/Melbourne', 'Australia/Perth', 'Pacific/Auckland', 'Africa/Johannesburg', 'America/Sao_Paulo', 'America/Mexico_City'];
const MCC_MAX_BATCH = 25;

/** All manager accounts of the user's own connected Google accounts */
function mcc_list(string $owner): array
{
    $out = [];
    foreach (conns_load($owner) as $c) {
        try {
            foreach (accounts_data($c)['roots'] as $r) {
                if ($r['type'] === 'MCC') {
                    $out[] = ['conn' => $c['id'], 'email' => $c['email'], 'id' => $r['id'], 'name' => $r['name'], 'count' => $r['count'] ?? 0];
                }
            }
        } catch (Throwable $e) {
            // errors for this Google account are shown on the accounts page
        }
    }
    return $out;
}

/** Validate and normalize one row */
function mcc_clean_row(array $r, array $def): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($r['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 255) {
        throw new RuntimeException('Account name is required (max 255 characters).');
    }
    $cur = strtoupper(trim((string)($r['currency'] ?? '') ?: ($def['currency'] ?? 'INR')));
    if (!in_array($cur, MCC_CURRENCIES, true)) {
        throw new RuntimeException("Currency \"$cur\" is not supported.");
    }
    $tz = trim((string)($r['timezone'] ?? '') ?: ($def['timezone'] ?? 'Asia/Kolkata'));
    if (!in_array($tz, MCC_TIMEZONES, true)) {
        throw new RuntimeException("Time zone \"$tz\" is not supported.");
    }
    $email = strtolower(trim((string)($r['email'] ?? '') ?: ($def['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Invalid invite email: $email");
    }
    $role = in_array($def['role'] ?? '', ['ADMIN', 'STANDARD', 'READ_ONLY'], true) ? $def['role'] : 'ADMIN';
    $tpl = trim((string)($def['tracking_url_template'] ?? ''));
    if ($tpl !== '' && !preg_match('#^(https?://|\{lpurl)#i', $tpl)) {
        throw new RuntimeException('Tracking template must start with {lpurl} or http(s)://');
    }
    return ['name' => $name, 'currency' => $cur, 'timezone' => $tz, 'email' => $email, 'role' => $role,
            'tracking_url_template' => $tpl, 'final_url_suffix' => ltrim(trim((string)($def['final_url_suffix'] ?? '')), '?&')];
}

function mcc_payload(array $v, bool $validateOnly): array
{
    $cc = ['descriptiveName' => $v['name'], 'currencyCode' => $v['currency'], 'timeZone' => $v['timezone']];
    if ($v['tracking_url_template'] !== '') {
        $cc['trackingUrlTemplate'] = $v['tracking_url_template'];
    }
    if ($v['final_url_suffix'] !== '') {
        $cc['finalUrlSuffix'] = $v['final_url_suffix'];
    }
    $body = ['customerClient' => $cc];
    if ($v['email'] !== '') {
        $body['emailAddress'] = $v['email'];
        $body['accessRole'] = $v['role'];
    }
    if ($validateOnly) {
        $body['validateOnly'] = true;
    }
    return $body;
}

/**
 * Create accounts one by one. Result per row: ok + customer_id, or error.
 * $demo = true -> nothing is created, simulation only.
 */
function mcc_create_clients(string $owner, string $connId, string $mccId, array $rows, array $def, bool $validateOnly, bool $demo): array
{
    $rows = array_values(array_filter($rows, fn($r) => trim((string)($r['name'] ?? '')) !== ''));
    if (!$rows) {
        throw new RuntimeException('Enter at least one account name.');
    }
    if (count($rows) > MCC_MAX_BATCH) {
        throw new RuntimeException('Maximum ' . MCC_MAX_BATCH . ' accounts per batch.');
    }
    $client = null;
    if (!$demo) {
        $conn = conns_load($owner)[$connId] ?? null;
        if (!$conn) {
            throw new RuntimeException('This Google connection is not yours (shared accounts cannot create new accounts).');
        }
        $ok = false;
        foreach (accounts_data($conn)['roots'] as $r) {
            $ok = $ok || ($r['type'] === 'MCC' && $r['id'] === $mccId);
        }
        if (!$ok) {
            throw new RuntimeException('Manager account not found under this Google account. Refresh accounts and try again.');
        }
        $client = client_for($conn)->setLoginCustomerId($mccId);
    }
    $out = [];
    $created = 0;
    $limitHit = false;
    foreach ($rows as $i => $r) {
        $res = ['row' => $i + 1, 'name' => (string)($r['name'] ?? '')];
        try {
            $v = mcc_clean_row($r, $def);
            $res += ['currency' => $v['currency'], 'timezone' => $v['timezone'], 'email' => $v['email']];
            if ($limitHit) {
                throw new RuntimeException('Skipped because of the Google error above.');
            }
            if ($demo) {
                if (stripos($v['name'], 'fail') !== false) {
                    throw new RuntimeException('API error (400): DEMO - name contains "fail", so this is a demo error.');
                }
                $id = $validateOnly ? '' : (string)mt_rand(1000000000, 9999999999);
            } else {
                $resp = $client->api('POST', "/customers/$mccId:createCustomerClient", mcc_payload($v, $validateOnly));
                $id = $validateOnly ? '' : (string)preg_replace('#^customers/#', '', (string)($resp['resourceName'] ?? ''));
                $res['invitation_link'] = $resp['invitationLink'] ?? '';
                usleep(300000); // avoid bursting requests to Google
            }
            $res += ['ok' => true, 'customer_id' => $id];
            if (!$validateOnly) {
                $created++;
            }
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'CREATION_DENIED_INELIGIBLE_MCC')) {
                // Google rule: the manager needs a linked account with $1,000+ spend - no point trying the other rows
                $m = 'CREATION_DENIED_INELIGIBLE_MCC - This manager account cannot create new accounts yet. Google requires a linked '
                   . 'Google Ads account with more than $1,000 lifetime spend and a good policy history.';
                $limitHit = true;
            } elseif (preg_match('/TOO_MANY|RESOURCE_EXHAUSTED|limit/i', $m) && !str_starts_with($m, 'Skip:')) {
                $limitHit = true;
            }
            $res += ['ok' => false, 'error' => mb_strimwidth($m, 0, 300, '…')];
        }
        $out[] = $res;
    }
    if ($created && !$demo) {
        q('DELETE FROM account_cache WHERE conn_id = ?', [$connId]); // force a fresh account list
    }
    return ['results' => $out, 'created' => $created, 'failed' => count(array_filter($out, fn($x) => !$x['ok'])), 'validated' => $validateOnly];
}

// ============================================================================
// Link EXISTING Google Ads accounts to a manager account (MCC), and unlink them.
// This is the workaround when CreateCustomerClient is denied (CREATION_DENIED_INELIGIBLE_MCC):
// instead of creating new accounts, link accounts that already exist.
// Google flow: the manager creates a PENDING client link; the client account then accepts it.
// If this tool also has admin access to the client account, it can accept automatically.
// ============================================================================

/** Accept a pending manager (MCC) link on the CLIENT side, if the user has access to the client. */
function mcc_accept_manager_link(string $owner, string $clientId, string $mccId): bool
{
    $mgrId = preg_replace('/\D/', '', $mccId);
    $clientId = preg_replace('/\D/', '', $clientId);
    foreach (conns_load($owner) as $c) {
        try {
            $has = false;
            foreach (accounts_data($c)['accounts'] as $a) {
                if ($a['id'] === $clientId) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                continue; // this Google login can't reach the client account - try the next
            }
            $cl = client_for($c)->setLoginCustomerId($clientId);
            foreach ($cl->query($clientId, "SELECT customer_manager_link.resource_name, customer_manager_link.manager_customer,
                                                   customer_manager_link.status
                                            FROM customer_manager_link
                                            WHERE customer_manager_link.status = 'PENDING'") as $row) {
                $l = $row['customerManagerLink'] ?? [];
                $mgrRn = (string)($l['managerCustomer'] ?? '');
                if ($mgrRn === "customers/$mgrId" || str_ends_with($mgrRn, "/$mgrId")) {
                    $cl->api('POST', "/customers/$clientId/customerManagerLinks:mutate", [
                        'operations' => [['update' => ['resourceName' => $l['resourceName'], 'status' => 'ACTIVE'], 'updateMask' => 'status']],
                    ]);
                    return true;
                }
            }
        } catch (Throwable $e) {
            // no access via this connection, or query failed - try the next connection
        }
    }
    return false;
}

/** Bulk-link existing Customer IDs to a manager account. Result per row: ACTIVE / PENDING / error. */
function mcc_link_clients(string $owner, string $connId, string $mccId, array $clientIds, bool $autoAccept, bool $demo): array
{
    $mgrId = preg_replace('/\D/', '', $mccId);
    $ids = [];
    foreach ($clientIds as $x) {
        $x = preg_replace('/\D/', '', (string)$x);
        if ($x !== '') {
            $ids[$x] = $x; // dedup
        }
    }
    $ids = array_values($ids);
    if (!$ids) {
        throw new RuntimeException('Enter at least one Customer ID to link.');
    }
    if (count($ids) > 50) {
        throw new RuntimeException('Link at most 50 accounts at a time.');
    }

    $mgr = null;
    if (!$demo) {
        $conn = conns_load($owner)[$connId] ?? null;
        if (!$conn) {
            throw new RuntimeException('This Google connection is not yours (shared accounts cannot manage links).');
        }
        $isMgr = false;
        foreach (accounts_data($conn)['roots'] as $r) {
            if ($r['type'] === 'MCC' && $r['id'] === $mgrId) {
                $isMgr = true;
                break;
            }
        }
        if (!$isMgr) {
            throw new RuntimeException('Manager account not found under this Google account. Refresh accounts and try again.');
        }
        $mgr = client_for($conn)->setLoginCustomerId($mgrId);
    }

    $out = [];
    $linked = 0;
    foreach ($ids as $cid) {
        $res = ['customer_id' => $cid];
        try {
            if (strlen($cid) < 8) {
                throw new RuntimeException('Invalid Customer ID (it should be a 10-digit number).');
            }
            if ($cid === $mgrId) {
                throw new RuntimeException('Cannot link the manager account to itself.');
            }
            if ($demo) {
                $res += ['ok' => true, 'status' => $autoAccept ? 'ACTIVE' : 'PENDING',
                         'note' => $autoAccept ? 'Linked and accepted (demo).' : 'Invitation sent - the account owner accepts it (demo).'];
                $linked++;
            } else {
                $r = $mgr->api('POST', "/customers/$mgrId/customerClientLinks:mutate", [
                    'operations' => [['create' => ['clientCustomer' => "customers/$cid", 'status' => 'PENDING']]],
                ]);
                $rn = (string)($r['results'][0]['resourceName'] ?? '');
                $mlId = str_contains($rn, '~') ? substr(strrchr($rn, '~'), 1) : '';
                $status = 'PENDING';
                $note = 'Invitation sent. The account owner accepts it in Google Ads (Admin → Account access → Managers).';
                if ($autoAccept) {
                    try {
                        if (mcc_accept_manager_link($owner, $cid, $mgrId)) {
                            $status = 'ACTIVE';
                            $note = 'Linked and accepted automatically (this tool has access to the account).';
                        } else {
                            $note = 'Invitation sent. Auto-accept skipped - this tool has no admin access to ' . fmt_cid($cid) . '. Accept it in that account.';
                        }
                    } catch (Throwable $e) {
                        $note = 'Invitation sent. Could not auto-accept: ' . mb_strimwidth($e->getMessage(), 0, 160, '…');
                    }
                }
                $res += ['ok' => true, 'status' => $status, 'manager_link_id' => $mlId, 'note' => $note];
                $linked++;
                usleep(250000); // avoid bursting requests to Google
            }
        } catch (Throwable $e) {
            $res += ['ok' => false, 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…')];
        }
        $out[] = $res;
    }
    if ($linked && !$demo) {
        q('DELETE FROM account_cache WHERE conn_id = ?', [$connId]);
    }
    return ['results' => $out, 'linked' => $linked, 'failed' => count(array_filter($out, fn($x) => !$x['ok']))];
}

/** Accounts currently linked to a manager account (ACTIVE + PENDING client links), with names. */
function mcc_linked_clients(string $owner, string $connId, string $mccId): array
{
    $conn = conns_load($owner)[$connId] ?? null;
    if (!$conn) {
        throw new RuntimeException('This Google connection is not yours.');
    }
    $mgrId = preg_replace('/\D/', '', $mccId);
    $cl = client_for($conn)->setLoginCustomerId($mgrId);

    $names = [];
    try {
        foreach ($cl->query($mgrId, "SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code
                                     FROM customer_client") as $r) {
            $c = $r['customerClient'] ?? [];
            if (!empty($c['id'])) {
                $names[(string)$c['id']] = ['name' => $c['descriptiveName'] ?? '', 'currency' => $c['currencyCode'] ?? ''];
            }
        }
    } catch (Throwable $e) {
        // names are a nice-to-have; carry on without them
    }

    $out = [];
    foreach ($cl->query($mgrId, "SELECT customer_client_link.client_customer, customer_client_link.status,
                                        customer_client_link.manager_link_id, customer_client_link.hidden
                                 FROM customer_client_link
                                 WHERE customer_client_link.status IN ('ACTIVE', 'PENDING')") as $r) {
        $l = $r['customerClientLink'] ?? [];
        $client = (string)($l['clientCustomer'] ?? '');
        $cid = $client ? substr(strrchr($client, '/'), 1) : '';
        if ($cid === '') {
            continue;
        }
        $out[] = ['customer_id' => $cid, 'status' => $l['status'] ?? '',
                  'manager_link_id' => (string)($l['managerLinkId'] ?? ''), 'hidden' => !empty($l['hidden']),
                  'name' => $names[$cid]['name'] ?? '', 'currency' => $names[$cid]['currency'] ?? ''];
    }
    usort($out, fn($a, $b) => [$a['status'] === 'PENDING' ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['status'] === 'PENDING' ? 0 : 1, mb_strtolower($b['name'])]);
    return $out;
}

/** Remove (deactivate) a client's link to the manager account. */
function mcc_unlink_client(string $owner, string $connId, string $mccId, string $clientId, string $managerLinkId, bool $demo): void
{
    if ($demo) {
        return;
    }
    $conn = conns_load($owner)[$connId] ?? null;
    if (!$conn) {
        throw new RuntimeException('This Google connection is not yours.');
    }
    $mgrId = preg_replace('/\D/', '', $mccId);
    $clientId = preg_replace('/\D/', '', $clientId);
    $managerLinkId = preg_replace('/\D/', '', $managerLinkId);
    $isMgr = false;
    foreach (accounts_data($conn)['roots'] as $r) {
        if ($r['type'] === 'MCC' && $r['id'] === $mgrId) {
            $isMgr = true;
            break;
        }
    }
    if (!$isMgr) {
        throw new RuntimeException('Manager account not found under this Google account.');
    }
    $cl = client_for($conn)->setLoginCustomerId($mgrId);
    if ($managerLinkId === '') {
        foreach (mcc_linked_clients($owner, $connId, $mccId) as $l) {
            if ($l['customer_id'] === $clientId) {
                $managerLinkId = $l['manager_link_id'];
                break;
            }
        }
    }
    if ($managerLinkId === '') {
        throw new RuntimeException('Link not found for this account (it may already be unlinked).');
    }
    $rn = "customers/$mgrId/customerClientLinks/{$clientId}~{$managerLinkId}";
    $cl->api('POST', "/customers/$mgrId/customerClientLinks:mutate", [
        'operations' => [['update' => ['resourceName' => $rn, 'status' => 'INACTIVE'], 'updateMask' => 'status']],
    ]);
    q('DELETE FROM account_cache WHERE conn_id = ?', [$connId]);
}
