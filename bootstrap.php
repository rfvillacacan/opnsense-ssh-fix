<?php
/** OPNsense 25.7 first-access helper. No credentials are embedded. */
declare(strict_types=1);

function buildPlan(array $current, string $operator): array
{
    if (!filter_var($operator, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('Supply one valid operator IPv4 address, without /32.');
    }
    if (empty($current['interfaces']['wan']['if'])) {
        throw new RuntimeException('Assign WAN before using this helper.');
    }
    $next = $current;
    $next['system']['ssh']['enabled'] = '1';
    $next['system']['ssh']['permitrootlogin'] = '1';
    $next['system']['ssh']['passwordauth'] = '1';
    $next['system']['ssh']['port'] = '22';
    $next['system']['ssh']['interfaces'] = 'wan';

    // Only replace rules owned by this helper. Preserve every other rule.
    $rules = $next['filter']['rule'] ?? [];
    if (!is_array($rules)) {
        throw new RuntimeException('Unexpected firewall rule structure.');
    }
    $rules = array_values(array_filter($rules, static function ($r): bool {
        return !in_array($r['descr'] ?? '', ['bootstrap operator SSH', 'bootstrap operator HTTPS'], true);
    }));
    $managed = [];
    foreach (['22' => 'SSH', '443' => 'HTTPS'] as $port => $service) {
        $managed[] = [
            'type' => 'pass', 'interface' => 'wan', 'ipprotocol' => 'inet',
            'protocol' => 'tcp', 'log' => '1',
            'descr' => 'bootstrap operator ' . $service,
            'source' => ['address' => $operator . '/32'],
            'destination' => ['network' => 'wanip', 'port' => (string)$port],
        ];
    }
    $next['filter']['rule'] = array_merge($managed, $rules);
    return $next;
}

function runCommand(string $command): void
{
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException('Command failed: ' . $command . '. Configuration may already be saved; keep the console open.');
    }
}

function main(array $args): int
{
    if (in_array('--help', $args, true)) {
        echo "Usage: php bootstrap.php OPERATOR_IPV4 [--apply]\n";
        echo "Default: read configuration and show plan only. --apply requires interactive confirmation.\n";
        return 0;
    }
    $apply = false;
    $operator = null;
    foreach (array_slice($args, 1) as $arg) {
        if ($arg === '--apply') {
            $apply = true;
        } elseif ($operator === null && filter_var($arg, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $operator = $arg;
        } else {
            throw new RuntimeException('Invalid argument. Use --help.');
        }
    }
    if ($operator === null) {
        throw new RuntimeException('Operator IPv4 is required. Use --help.');
    }
    if (PHP_OS_FAMILY !== 'BSD' || !is_file('/usr/local/etc/inc/config.inc') || !is_file('/conf/config.xml')) {
        throw new RuntimeException('Run only on an installed OPNsense router.');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('Run as root from the provider console.');
    }
    global $config;
    $originalHash = hash_file('sha256', '/conf/config.xml');
    require_once('/usr/local/etc/inc/util.inc');
    require_once('/usr/local/etc/inc/config.inc');
    $before = $config;
    $planned = buildPlan($before, $operator);
    echo "PLAN: enable root SSH/password authentication on WAN TCP22.\n";
    echo "PLAN: add WAN TCP22 and TCP443 pass rules from {$operator}/32 to WAN address.\n";
    echo "Existing root password is reused. Other rules, interfaces, routes and certificates are preserved.\n";
    echo "No Hetzner firewall, DNS, package, password or reboot changes.\n";
    echo "Existing broader rules remain broader; this is a first-access helper, not a security audit.\n";
    if (!$apply) {
        echo "DRY RUN: no configuration saved and no services restarted.\n";
        return 0;
    }
    echo "Keep this console open. Confirm operator-only TCP22/443 in Hetzner first.\n";
    echo "A native OPNsense configuration revision will be retained locally; it is not an external backup.\n";
    echo "Preserve a protected external configuration backup if required by your operating policy.\n";
    echo "Type APPLY to save and activate these changes: ";
    if (trim((string)fgets(STDIN)) !== 'APPLY') {
        echo "Cancelled; no changes.\n";
        return 0;
    }
    // Abort if another administrator changed config while confirmation was pending.
    // Hash covers bytes only and is never printed.
    if ($originalHash === false || hash_file('sha256', '/conf/config.xml') !== $originalHash) {
        throw new RuntimeException('Configuration changed during review. Run a fresh dry run.');
    }
    $config = $planned;
    $saved = write_config('Operator-authorized SSH/HTTPS bootstrap', true);
    if (!is_array($saved)) {
        throw new RuntimeException('Configuration save failed; services not restarted.');
    }
    echo "Configuration saved with native revision backup.\n";
    runCommand('/usr/local/sbin/configctl filter reload');
    runCommand('/usr/local/sbin/configctl openssh restart');
    runCommand('pfctl -si');
    runCommand('sockstat -4 -l');
    runCommand('ssh-keygen -lf /conf/sshd/ssh_host_ed25519_key.pub');
    echo "Activation commands completed. Verify a TCP22 listener, PF Status: Enabled, and the fingerprint above.\n";
    echo "Then test a NEW SSH connection and HTTPS from your computer; remote access is not proven by this message.\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(main($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
