<?php

function renderTrialStatsSummaryCards(array $summary): void
{
    $items = [
        ['label' => 'Total', 'value' => (int)($summary['total'] ?? 0), 'icon' => 'bi-collection'],
        ['label' => 'Today', 'value' => (int)($summary['today'] ?? 0), 'icon' => 'bi-calendar-day'],
        ['label' => 'This Week', 'value' => (int)($summary['week'] ?? 0), 'icon' => 'bi-calendar-week'],
        ['label' => 'This Month', 'value' => (int)($summary['month'] ?? 0), 'icon' => 'bi-calendar3'],
    ];
    ?>
    <div class="columns is-multiline is-variable is-4 trial-stats-grid">
        <?php foreach ($items as $item): ?>
        <div class="column is-12-mobile is-6-tablet is-3-desktop">
            <div class="card enhanced-card stat-card trial-stat-card">
                <div class="stat-icon">
                    <i class="bi <?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                </div>
                <div class="stat-value"><?php echo number_format((int)$item['value']); ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderTrialStatsTable(array $records): void
{
    if (empty($records)) {
        ?>
        <div class="app-empty-state">
            <span class="icon"><i class="bi bi-database-exclamation" aria-hidden="true"></i></span>
            <p>No trial records are available yet.</p>
        </div>
        <?php
        return;
    }
    ?>
    <div class="app-table-shell">
        <div class="app-table-wrapper">
            <table class="table is-fullwidth is-hoverable app-table">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>User</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Remote IP</th>
                        <th>VPN Hostname</th>
                        <th>Public Host</th>
                        <th>Created</th>
                        <th>Expires</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string)($record['request_code'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="trial-stats-meta">
                                <div><?php echo htmlspecialchars((string)($record['username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div><?php echo htmlspecialchars((string)($record['email'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars((string)($record['full_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="trial-stats-meta"><?php echo htmlspecialchars((string)($record['client_ip'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                        </td>
                        <td>
                            <span class="tag is-light"><?php echo htmlspecialchars((string)($record['service'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <div class="trial-stats-meta"><?php echo count($record['fixed_ports'] ?? []); ?> fixed endpoints</div>
                        </td>
                        <td>
                            <?php $status = strtolower((string)($record['status'] ?? 'unknown')); ?>
                            <span class="status-badge <?php echo htmlspecialchars(mapTrialStatsStatusClass($status), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(mapTrialStatsStatusLabel($status), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars((string)($record['remote_address'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($record['service_host'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($record['public_host'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(formatTrialStatsDate((string)($record['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(formatTrialStatsDate((string)($record['expires_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function mapTrialStatsStatusLabel(string $status): string
{
    $labels = [
        'active' => 'Active',
        'cleaned' => 'Cleaned',
        'cleanup_failed' => 'Cleanup Failed',
    ];

    return $labels[$status] ?? 'Unknown';
}

function mapTrialStatsStatusClass(string $status): string
{
    $classes = [
        'active' => 'status-enabled',
        'cleaned' => 'status-up',
        'cleanup_failed' => 'status-disabled',
    ];

    return $classes[$status] ?? 'status-unknown';
}

function formatTrialStatsDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (Exception $e) {
        return $value;
    }
}
