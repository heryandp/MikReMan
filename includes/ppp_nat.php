<?php

function normalizeInputBoolean($value) {
    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    return !empty($value);
}

function parseForwardPortsInput($ports_input) {
    if (!is_string($ports_input) || trim($ports_input) === '') {
        return [];
    }

    $ports = array_map('trim', explode(',', $ports_input));
    $ports = array_filter($ports, static function ($port) {
        return $port !== '';
    });

    $validated = [];
    foreach ($ports as $port) {
        if (!is_numeric($port)) {
            continue;
        }

        $port = (int)$port;
        if ($port < 1 || $port > 65535) {
            continue;
        }

        $validated[] = (string)$port;
    }

    return array_values(array_unique($validated));
}

function parseRequestedPortsFromInput(array $input) {
    if (isset($input['requested_ports']) && is_array($input['requested_ports'])) {
        return parseForwardPortsInput(implode(',', $input['requested_ports']));
    }

    if (!empty($input['requested_ports_json']) && is_string($input['requested_ports_json'])) {
        $decoded = json_decode($input['requested_ports_json'], true);
        if (is_array($decoded)) {
            return parseForwardPortsInput(implode(',', $decoded));
        }
    }

    return parseForwardPortsInput($input['ports'] ?? '');
}

function parseRequestedPortEntriesFromInput(array $input) {
    if (!empty($input['requested_port_entries_json']) && is_string($input['requested_port_entries_json'])) {
        $decoded = json_decode($input['requested_port_entries_json'], true);
        if (is_array($decoded)) {
            $entries = [];
            $seen_ports = [];

            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $port = trim((string)($entry['port'] ?? ''));
                $label = trim((string)($entry['label'] ?? ''));

                if (!is_numeric($port)) {
                    continue;
                }

                $port = (int)$port;
                if ($port < 1 || $port > 65535) {
                    continue;
                }

                $port = (string)$port;
                if (isset($seen_ports[$port])) {
                    continue;
                }

                $entries[] = [
                    'port' => $port,
                    'label' => substr($label, 0, 64)
                ];
                $seen_ports[$port] = true;
            }

            return $entries;
        }
    }

    return array_map(static function ($port) {
        return [
            'port' => $port,
            'label' => ''
        ];
    }, parseRequestedPortsFromInput($input));
}

function parseNatSnapshotInput($snapshot_input) {
    if (!is_string($snapshot_input) || trim($snapshot_input) === '') {
        return [];
    }

    $decoded = json_decode($snapshot_input, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static function ($rule) {
        return is_array($rule);
    }));
}

function buildPPPUserNatComment($username, $internal_port, $use_individual_comment = false, $label = '') {
    $label = trim((string)$label);

    if ($use_individual_comment || $label !== '') {
        $comment = $username . ' (Port ' . $internal_port;
        if ($label !== '') {
            $comment .= ' | ' . $label;
        }

        return $comment . ')';
    }

    return $username;
}

function isManagedPPPUserNatRule($rule, array $candidate_usernames) {
    $comment = trim((string)($rule['comment'] ?? ''));

    if ($comment === '') {
        return false;
    }

    $candidate_usernames = array_values(array_unique(array_filter(array_map('strval', $candidate_usernames))));
    foreach ($candidate_usernames as $username) {
        $escaped_username = preg_quote($username, '/');
        if (preg_match('/^' . $escaped_username . '(?: \\(Port \\d+(?: \\| .*?)?\\))?$/', $comment)) {
            return true;
        }
    }

    return false;
}

function parseManagedPPPUserNatComment($comment, array $candidate_usernames) {
    $comment = trim((string)$comment);
    $candidate_usernames = array_values(array_unique(array_filter(array_map('strval', $candidate_usernames))));

    foreach ($candidate_usernames as $username) {
        $escaped_username = preg_quote($username, '/');
        if (preg_match('/^' . $escaped_username . '(?: \\(Port (\\d+)(?: \\| (.*?))?\\))?$/', $comment, $matches)) {
            return [
                'username' => $username,
                'port' => trim((string)($matches[1] ?? '')),
                'label' => trim((string)($matches[2] ?? ''))
            ];
        }
    }

    return [
        'username' => '',
        'port' => '',
        'label' => ''
    ];
}

function filterManagedPPPUserNatRules(array $nat_rules, array $candidate_usernames) {
    $filtered = [];
    $seen = [];

    foreach ($nat_rules as $rule) {
        if (!isManagedPPPUserNatRule($rule, $candidate_usernames)) {
            continue;
        }

        $rule_id = $rule['.id'] ?? md5(json_encode($rule));
        if (isset($seen[$rule_id])) {
            continue;
        }

        $filtered[] = $rule;
        $seen[$rule_id] = true;
    }

    return $filtered;
}

function filterLegacyPPPUserNatRules(array $nat_rules, $remote_address) {
    $filtered = [];
    $seen = [];
    $remote_address = trim((string)$remote_address);

    if ($remote_address === '') {
        return [];
    }

    foreach ($nat_rules as $rule) {
        $rule_remote_address = trim((string)($rule['to-addresses'] ?? ''));
        $internal_port = trim((string)($rule['to-ports'] ?? ''));
        $external_port = trim((string)($rule['dst-port'] ?? ''));
        $protocol = strtolower(trim((string)($rule['protocol'] ?? 'tcp')));
        $chain = strtolower(trim((string)($rule['chain'] ?? 'dstnat')));
        $action = strtolower(trim((string)($rule['action'] ?? 'dst-nat')));

        if ($rule_remote_address !== $remote_address) {
            continue;
        }

        if ($chain !== 'dstnat' || $action !== 'dst-nat' || $protocol !== 'tcp') {
            continue;
        }

        if (!is_numeric($internal_port) || !is_numeric($external_port)) {
            continue;
        }

        $rule_id = $rule['.id'] ?? md5(json_encode($rule));
        if (isset($seen[$rule_id])) {
            continue;
        }

        $filtered[] = $rule;
        $seen[$rule_id] = true;
    }

    return $filtered;
}

function deletePPPUserNatRules($mikrotik, array $nat_rules, $qemu_hostfwd = null) {
    $unique_rules = [];
    $seen = [];

    foreach ($nat_rules as $rule) {
        $rule_id = $rule['.id'] ?? md5(json_encode($rule));
        if (isset($seen[$rule_id])) {
            continue;
        }

        $unique_rules[] = $rule;
        $seen[$rule_id] = true;
    }

    $nat_rules = $unique_rules;
    $hostfwd_remove_result = removeQemuHostFwdRules($nat_rules, $qemu_hostfwd);
    $deleted_count = 0;
    $errors = [];

    foreach ($nat_rules as $rule) {
        $rule_id = $rule['.id'] ?? null;

        if (!$rule_id) {
            $errors[] = 'NAT rule missing .id for dst-port ' . ($rule['dst-port'] ?? 'unknown');
            continue;
        }

        try {
            $mikrotik->deleteFirewallNAT($rule_id);
            $deleted_count++;
        } catch (Exception $e) {
            $errors[] = 'Port ' . ($rule['dst-port'] ?? 'unknown') . ': ' . $e->getMessage();
        }
    }

    return [
        'deleted_count' => $deleted_count,
        'hostfwd_removed_count' => $hostfwd_remove_result['removed_count'] ?? 0,
        'errors' => array_merge($hostfwd_remove_result['errors'] ?? [], $errors)
    ];
}

function syncPPPUserNatRules($mikrotik, $qemu_hostfwd, array $existing_nat_rules, $old_username, $new_username, $old_remote_address, $new_remote_address, array $requested_port_entries, $use_individual_comment = false) {
    $requested_port_entries = array_values(array_filter($requested_port_entries, static function ($entry) {
        return is_array($entry) && !empty($entry['port']);
    }));
    $requested_ports = array_map(static function ($entry) {
        return (string)$entry['port'];
    }, $requested_port_entries);
    $requested_labels = [];
    foreach ($requested_port_entries as $entry) {
        $requested_labels[(string)$entry['port']] = trim((string)($entry['label'] ?? ''));
    }

    $candidate_usernames = array_values(array_unique(array_filter([$old_username, $new_username])));
    $managed_rules = filterManagedPPPUserNatRules($existing_nat_rules, $candidate_usernames);
    $legacy_rules = filterLegacyPPPUserNatRules($existing_nat_rules, $old_remote_address);
    $managed_rule_map = [];

    foreach (array_merge($managed_rules, $legacy_rules) as $rule) {
        $rule_id = $rule['.id'] ?? md5(json_encode($rule));
        $managed_rule_map[$rule_id] = $rule;
    }

    $managed_rules = array_values($managed_rule_map);

    $existing_by_port = [];
    $duplicate_rules = [];

    foreach ($managed_rules as $rule) {
        $internal_port = trim((string)($rule['to-ports'] ?? ''));
        if ($internal_port === '') {
            continue;
        }

        if (!isset($existing_by_port[$internal_port])) {
            $existing_by_port[$internal_port] = $rule;
            continue;
        }

        $duplicate_rules[] = $rule;
    }

    $identity_changed = $old_username !== $new_username || $old_remote_address !== $new_remote_address;
    $rules_to_delete = $duplicate_rules;
    $create_plan = [];
    $preserved_count = 0;

    if ($identity_changed) {
        $rules_to_delete = array_merge($rules_to_delete, $managed_rules);

        foreach ($requested_port_entries as $entry) {
            $internal_port = (string)$entry['port'];
            $existing_rule = $existing_by_port[$internal_port] ?? null;
            $external_port = $existing_rule['dst-port'] ?? null;

            if (!is_numeric($external_port)) {
                $external_port = generateRandomPort($mikrotik);
            }

            $create_plan[] = [
                'internal_port' => $internal_port,
                'external_port' => (int)$external_port,
                'label' => $entry['label'] ?? ''
            ];
        }
    } else {
        foreach ($existing_by_port as $internal_port => $rule) {
            $internal_port = (string)$internal_port;
            $existing_comment = trim((string)($rule['comment'] ?? ''));
            $expected_comment = buildPPPUserNatComment(
                $new_username,
                $internal_port,
                $use_individual_comment,
                $requested_labels[$internal_port] ?? ''
            );

            if (!in_array($internal_port, $requested_ports, true)) {
                $rules_to_delete[] = $rule;
                continue;
            }

            if ($existing_comment !== $expected_comment) {
                $rules_to_delete[] = $rule;
                $create_plan[] = [
                    'internal_port' => $internal_port,
                    'external_port' => (int)($rule['dst-port'] ?? generateRandomPort($mikrotik)),
                    'label' => $requested_labels[$internal_port] ?? ''
                ];
                continue;
            }

            $preserved_count++;
        }

        foreach ($requested_port_entries as $entry) {
            $internal_port = (string)$entry['port'];
            if (isset($existing_by_port[$internal_port])) {
                continue;
            }

            $create_plan[] = [
                'internal_port' => $internal_port,
                'external_port' => generateRandomPort($mikrotik),
                'label' => $entry['label'] ?? ''
            ];
        }
    }

    $delete_result = deletePPPUserNatRules($mikrotik, $rules_to_delete, $qemu_hostfwd);
    $created_rules = [];
    $create_errors = [];

    foreach ($create_plan as $plan) {
        $nat_data = [
            'chain' => 'dstnat',
            'action' => 'dst-nat',
            'protocol' => 'tcp',
            'dst-port' => (string)$plan['external_port'],
            'to-addresses' => $new_remote_address,
            'to-ports' => (string)$plan['internal_port'],
            'comment' => buildPPPUserNatComment($new_username, $plan['internal_port'], $use_individual_comment, $plan['label'] ?? '')
        ];

        $create_result = createPPPUserNatRule(
            $mikrotik,
            $nat_data,
            $plan['external_port'],
            $plan['internal_port'],
            'edit',
            $qemu_hostfwd
        );

        $created_rules[] = $create_result;

        if (empty($create_result['success'])) {
            $create_errors[] = 'Port ' . $plan['internal_port'] . ': ' . ($create_result['error'] ?? 'Unknown error');
        }
    }

    return [
        'preserved_count' => $preserved_count,
        'deleted_count' => $delete_result['deleted_count'],
        'hostfwd_removed_count' => $delete_result['hostfwd_removed_count'],
        'created_count' => count(array_filter($created_rules, static function ($rule) {
            return !empty($rule['success']);
        })),
        'created_rules' => $created_rules,
        'errors' => array_merge($delete_result['errors'], $create_errors)
    ];
}
