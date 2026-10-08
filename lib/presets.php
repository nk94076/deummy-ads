<?php
/**
 * Campaign presets: reusable defaults a user applies when building a campaign.
 * Each preset stores excluded locations (geo) + negative keywords, so an affiliate
 * campaign (Impact / AWIN etc.) can auto-exclude the same locations and negatives
 * without re-entering them every time.
 *
 * Stored per owner as a small JSON blob: {excluded:[{id,name,type}], negatives:"line\nline"}.
 */

/** All presets for an owner (newest first). */
function presets_list(string $owner): array
{
    $out = [];
    foreach (q('SELECT id, name, data, created_at FROM campaign_presets WHERE owner = ? ORDER BY name', [$owner]) as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        $out[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'excluded' => array_values($d['excluded'] ?? []),
            'negatives' => (string)($d['negatives'] ?? ''),
        ];
    }
    return $out;
}

/** Create or update a preset. $excluded = list of {id,name,type}. Returns the id. */
function preset_save(string $owner, string $name, array $excluded, string $negatives, ?int $id = null): int
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Preset name is required.');
    }
    // Keep only the fields we need, and only valid geo ids.
    $clean = [];
    foreach ($excluded as $g) {
        $gid = preg_replace('/\D/', '', (string)($g['id'] ?? ''));
        if ($gid === '') {
            continue;
        }
        $clean[] = ['id' => $gid, 'name' => mb_substr((string)($g['name'] ?? $gid), 0, 120), 'type' => mb_substr((string)($g['type'] ?? ''), 0, 40)];
    }
    $data = json_encode(['excluded' => $clean, 'negatives' => mb_substr(trim($negatives), 0, 4000)]);
    if ($id) {
        q('UPDATE campaign_presets SET name = ?, data = ? WHERE id = ? AND owner = ?',
          [mb_substr($name, 0, 120), $data, $id, $owner]);
        return $id;
    }
    q('INSERT INTO campaign_presets (owner, name, data, created_at) VALUES (?,?,?,?)',
      [$owner, mb_substr($name, 0, 120), $data, now()]);
    return (int)db()->lastInsertId();
}

/** Delete a preset (owner-scoped). */
function preset_remove(string $owner, int $id): bool
{
    return q('DELETE FROM campaign_presets WHERE id = ? AND owner = ?', [$id, $owner])->rowCount() > 0;
}
