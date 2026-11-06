<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

/*
 * Lab helper, piped into the php container and run from the Joomla root:
 *
 *     php /dev/stdin <userId> < lib/token.php
 *
 * Gives the user a Joomla API token seed (if they have none), enables it, and prints the token
 * exactly as plg_api-authentication_token expects it: base64("sha256:<id>:<hmac(seed, secret)>").
 * Doing it in SQL saves driving the user-profile form through a browser.
 */

declare(strict_types=1);

require 'configuration.php';

$config = new JConfig();
$userId = (int) ($argv[1] ?? 0);
$pdo    = new PDO("mysql:host={$config->host};dbname={$config->db}", $config->user, $config->password);
$prefix = $config->dbprefix;

$stmt = $pdo->prepare("SELECT profile_value FROM {$prefix}user_profiles WHERE user_id = ? AND profile_key = 'joomlatoken.token'");
$stmt->execute([$userId]);
$seed = $stmt->fetchColumn();

if (!is_string($seed) || $seed === '') {
    $seed = base64_encode(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO {$prefix}user_profiles (user_id, profile_key, profile_value, ordering)
         VALUES (?, 'joomlatoken.token', ?, 1), (?, 'joomlatoken.enabled', '1', 2)"
    )->execute([$userId, $seed, $userId]);
}

echo base64_encode('sha256:' . $userId . ':' . hash_hmac('sha256', (string) base64_decode($seed), $config->secret)), "\n";
