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
 *     php /dev/stdin <assetName> <action> <groupId> <1|0|unset> < lib/acl.php
 *
 * Sets one cell of Joomla's permissions matrix — what the Permissions tab of Global Configuration
 * (asset "root.1") or of a component's Options (asset "com_content", …) or of a category
 * ("com_content.category.<id>") writes — by editing that asset's rules JSON directly.
 * 1 = Allowed, 0 = Denied, unset = Inherited.
 */

declare(strict_types=1);

require 'configuration.php';

$config = new JConfig();
[, $asset, $action, $groupId, $value] = $argv;
$pdo    = new PDO("mysql:host={$config->host};dbname={$config->db}", $config->user, $config->password);
$prefix = $config->dbprefix;

$stmt = $pdo->prepare("SELECT rules FROM {$prefix}assets WHERE name = ?");
$stmt->execute([$asset]);
$raw = $stmt->fetchColumn();

if ($raw === false) {
    fwrite(STDERR, "No such asset: {$asset}\n");
    exit(1);
}

$rules = json_decode((string) $raw, true) ?: [];

if ($value === 'unset') {
    unset($rules[$action][$groupId]);

    if (empty($rules[$action])) {
        unset($rules[$action]);
    }
} else {
    $rules[$action][$groupId] = (int) $value;
}

$pdo->prepare("UPDATE {$prefix}assets SET rules = ? WHERE name = ?")
    ->execute([json_encode($rules ?: new stdClass()), $asset]);
