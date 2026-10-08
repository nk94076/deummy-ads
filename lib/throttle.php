<?php
/**
 * IP-based login throttle (file-backed, survives new sessions / cleared cookies).
 * 10 failures within 15 min from one IP -> locked for 15 min.
 *
 * All read-modify-write on the counter file is done under an exclusive flock so parallel
 * failed attempts can't both read the same count and overwrite each other (undercounting
 * fails would weaken the throttle). Reads take a shared lock so they never see a torn file.
 */

function login_throttle_file(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return sys_get_temp_dir() . '/adhook_login_' . md5($ip);
}

/** Read the throttle state under a shared lock, so we never read a half-written file. */
function login_throttle_read(): array
{
    $fh = @fopen(login_throttle_file(), 'r');
    if (!$fh) {
        return ['fails' => 0, 'last' => 0];
    }
    $d = ['fails' => 0, 'last' => 0];
    if (flock($fh, LOCK_SH)) {
        $raw = stream_get_contents($fh);
        $d = json_decode((string)$raw, true) ?: $d;
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $d;
}

function login_locked(): int
{
    $d = login_throttle_read();
    if (($d['fails'] ?? 0) >= 10 && (int)($d['last'] ?? 0) > time() - 900) {
        return 900 - (time() - (int)$d['last']); // seconds left
    }
    return 0;
}

function login_note_fail(): void
{
    // Hold an EXCLUSIVE lock across the whole read-modify-write so parallel failed attempts
    // can't both read the same count and overwrite each other. fopen 'c+' creates if missing
    // and does NOT truncate on open.
    $fh = @fopen(login_throttle_file(), 'c+');
    if (!$fh) {
        return;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }
    $raw = stream_get_contents($fh);
    $d = json_decode((string)$raw, true) ?: ['fails' => 0, 'last' => 0];
    if ((int)($d['last'] ?? 0) < time() - 900) {
        $d['fails'] = 0; // window expired, reset
    }
    $d['fails'] = (int)($d['fails'] ?? 0) + 1;
    $d['last'] = time();
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($d));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Clear the throttle for the current IP (called after a successful login).
 * Zeroes the counter under the SAME exclusive lock the increment uses, instead of unlink().
 * With unlink(), a failed attempt already holding the lock would write its increment to the
 * now-orphaned inode while a fresh attempt creates a new file - leaving the count inconsistent.
 * Locking + rewriting the same file serializes reset against increment cleanly.
 */
function login_throttle_clear(): void
{
    $fh = @fopen(login_throttle_file(), 'c+');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode(['fails' => 0, 'last' => 0]));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}
