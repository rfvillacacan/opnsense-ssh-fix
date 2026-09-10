<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
function check(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    echo "PASS: {$name}\n";
}
$original = [
    'interfaces' => ['wan' => ['if' => 'vtnet0', 'ipaddr' => 'dhcp'], 'lan' => ['if' => 'vtnet1', 'ipaddr' => 'dhcp']],
    'system' => ['hostname' => 'synthetic-router', 'ssh' => ['port' => '2222']],
    'staticroutes' => ['route' => [['network' => '10.20.0.0/24']]],
    'cert' => [['descr' => 'synthetic certificate placeholder']],
    'filter' => ['rule' => [['descr' => 'Existing LAN rule', 'type' => 'pass', 'interface' => 'lan']]],
];
$next = buildPlan($original, '192.0.2.10');
foreach (['interfaces', 'staticroutes', 'cert'] as $key) {
    check($next[$key] === $original[$key], "Preserve {$key}");
}
check($next['system']['hostname'] === $original['system']['hostname'], 'Preserve hostname');
check($next['filter']['rule'][2] === $original['filter']['rule'][0], 'Preserve existing rule');
check($next['system']['ssh']['enabled'] === '1' && $next['system']['ssh']['passwordauth'] === '1', 'Enable SSH/password auth');
check($next['system']['ssh']['permitrootlogin'] === '1' && $next['system']['ssh']['interfaces'] === 'wan', 'Root and WAN SSH');
foreach (array_slice($next['filter']['rule'], 0, 2) as $rule) {
    check($rule['source']['address'] === '192.0.2.10/32' && $rule['destination']['network'] === 'wanip', 'Restrict source and destination');
}
check(array_column(array_column(array_slice($next['filter']['rule'], 0, 2), 'destination'), 'port') === ['22','443'], 'Only SSH/HTTPS ports');
check(buildPlan($next, '192.0.2.10') === $next, 'Idempotent repeat');
$changed = buildPlan($next, '198.51.100.9');
check(count($changed['filter']['rule']) === 3 && $changed['filter']['rule'][0]['source']['address'] === '198.51.100.9/32', 'Replace operator without duplicate rules');
foreach (['', '0.0.0.0/0', '192.0.2.10/32', '192.0.2.10;id', '::1', '999.1.1.1'] as $bad) {
    $rejected = false;
    try { buildPlan($original, $bad); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Reject invalid operator: ' . $bad);
}
$rejected = false;
try { buildPlan([], '192.0.2.10'); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Reject missing WAN');
check($original['system']['ssh']['port'] === '2222', 'Plan does not mutate input');

requireRoot();
check(true, 'Root check works with PHP POSIX unavailable');
foreach ([[0, ['1000']], [1, []], [0, ['not-a-uid']], [0, []]] as $result) {
    $rejected = false;
    try { requireRoot(static fn() => $result); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Reject nonroot or invalid UID probe');
}
