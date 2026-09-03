<?php

declare(strict_types=1);

/**
 * Jednorázově založí break-glass platform admina řízené instalace.
 *
 * Heslo se záměrně nikdy nepřijímá v argv. Interaktivně (nebo ze STDIN při
 * automatizaci s --confirm) se načte dvakrát a v DB se uloží jen jeho hash.
 *
 * Použití:
 *   php api/bin/revizior-bootstrap-platform-admin.php \
 *     --email=ops@example.invalid --name="Operations" [--confirm]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Deployment\DeploymentCapabilities;

/** @return array{email:string,name:string,confirm:bool,help:bool} */
function parseArguments(array $arguments): array
{
    $options = ['email' => '', 'name' => '', 'confirm' => false, 'help' => false];
    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = (string) $arguments[$index];
        if ($argument === '--confirm') {
            $options['confirm'] = true;
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        foreach (['email', 'name'] as $key) {
            if ($argument === "--$key") {
                if (!isset($arguments[$index + 1])) throw new \InvalidArgumentException("Chybí hodnota --$key.");
                $options[$key] = (string) $arguments[++$index];
                continue 2;
            }
            if (str_starts_with($argument, "--$key=")) {
                $options[$key] = substr($argument, strlen($key) + 3);
                continue 2;
            }
        }
        if (str_starts_with($argument, '--password')) {
            throw new \InvalidArgumentException('Heslo nesmí být předáno v argumentu; načítá se bezpečně ze STDIN.');
        }
        throw new \InvalidArgumentException("Neznámý argument: $argument");
    }
    return $options;
}

function usage(): void
{
    fwrite(STDERR, "Použití: php api/bin/revizior-bootstrap-platform-admin.php --email=<email> --name=<jméno> [--confirm]\n");
    fwrite(STDERR, "  Bez --confirm příkaz vyžaduje interaktivní potvrzení. Heslo se nikdy nepředává v argv.\n");
}

function stdinIsInteractive(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDIN);
}

function readPasswordSilent(string $label): string
{
    fwrite(STDERR, $label);
    if (PHP_OS_FAMILY === 'Windows' && stdinIsInteractive()) {
        $command = 'powershell.exe -NoProfile -Command "$p = Read-Host -AsSecureString; ' .
            '[System.Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
            '[System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $password = rtrim((string) shell_exec($command), "\r\n");
        fwrite(STDERR, "\n");
        return $password;
    }
    if (stdinIsInteractive()) @shell_exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (stdinIsInteractive()) {
        @shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return $password;
}

function cliOperator(): ?string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $user = @posix_getpwuid(posix_geteuid());
        if (is_array($user) && ($user['name'] ?? '') !== '') return (string) $user['name'];
    }
    foreach (['USER', 'LOGNAME', 'USERNAME'] as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return null;
}

try {
    $options = parseArguments($argv);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    usage();
    exit(2);
}
if ($options['help']) {
    usage();
    exit(0);
}

$email = mb_strtolower(trim($options['email']));
$name = trim($options['name']);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
    fwrite(STDERR, "ERROR: --email musí být platná e-mailová adresa do 190 znaků.\n");
    exit(2);
}
if ($name === '' || mb_strlen($name) > 120) {
    fwrite(STDERR, "ERROR: --name musí obsahovat 1 až 120 znaků.\n");
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

try {
    $app = Bootstrap::buildApp();
    $container = $app->getContainer();
    $capabilities = $container->get(DeploymentCapabilities::class);
    if (!$capabilities->isReviziorManaged()) {
        throw new \RuntimeException('Příkaz je povolen pouze v režimu revizior_managed.');
    }
    $mfaPolicy = $container->get(MfaPolicyService::class);
    if (!$mfaPolicy->isRequired()) {
        throw new \RuntimeException('Nejdřív nastav auth.require_mfa=true; platform admin musí dokončit MFA.');
    }
    $connection = $container->get(Connection::class);
    $pdo = $connection->pdo();
    $pdo->query('SELECT 1 FROM users LIMIT 1');
    $logger = $container->get(ActivityLogger::class);
    $hasher = $container->get(PasswordHasher::class);
} catch (\Throwable $exception) {
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(3);
}

$existing = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE email = ? LIMIT 1');
$existing->execute([$email]);
$existingUser = $existing->fetch(\PDO::FETCH_ASSOC) ?: null;
$adminStatement = $pdo->query("SELECT id, email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
if ($adminStatement === false) throw new \RuntimeException('Platform admina se nepodařilo načíst.');
$admin = $adminStatement->fetch(\PDO::FETCH_ASSOC) ?: null;

if (is_array($existingUser) && ($existingUser['role'] !== 'admin' || !(bool) $existingUser['is_active'])) {
    fwrite(STDERR, "ERROR: Účet $email už existuje, ale není aktivním platform adminem; bootstrap jej bezpečnostně nepovýší.\n");
    exit(4);
}
if (is_array($admin) && (!is_array($existingUser) || (int) $admin['id'] !== (int) $existingUser['id'])) {
    fwrite(STDERR, "ERROR: Platform admin už existuje ({$admin['email']}); bootstrap nezakládá druhého.\n");
    exit(4);
}

$alreadyExists = is_array($existingUser);
fwrite(STDERR, "\nReviziOR managed platform bootstrap\n");
fwrite(STDERR, "  Operace: " . ($alreadyExists ? 'ověřit existující účet' : 'založit účet') . "\n");
fwrite(STDERR, "  Admin:   $name <$email>\n");
fwrite(STDERR, "  Supplier membership: žádný\n");
fwrite(STDERR, "  MFA: povinné při prvním bezpečném přístupu\n\n");

if (!$options['confirm']) {
    if (!stdinIsInteractive()) {
        fwrite(STDERR, "ERROR: Neinteraktivní běh vyžaduje explicitní --confirm.\n");
        exit(2);
    }
    fwrite(STDERR, "Pro pokračování napiš BOOTSTRAP: ");
    if (trim((string) fgets(STDIN)) !== 'BOOTSTRAP') {
        fwrite(STDERR, "Zrušeno.\n");
        exit(1);
    }
}

$password = '';
$confirmation = '';
if (!$alreadyExists) {
    $password = readPasswordSilent('Nové heslo (min. 12 znaků): ');
    $confirmation = readPasswordSilent('Heslo znovu: ');
    if (!hash_equals($password, $confirmation)) {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($password);
            sodium_memzero($confirmation);
        }
        fwrite(STDERR, "ERROR: Hesla se neshodují.\n");
        exit(2);
    }
    try {
        $hasher->validate($password);
    } catch (\InvalidArgumentException $exception) {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($password);
            sodium_memzero($confirmation);
        }
        fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
        exit(2);
    }
}

$operator = cliOperator();
$host = gethostname() ?: null;
$pdo->beginTransaction();
try {
    $lockStatement = $pdo->query('SELECT id, email, role, is_active FROM users ORDER BY id FOR UPDATE');
    if ($lockStatement === false) throw new \RuntimeException('Uživatele se nepodařilo uzamknout.');
    $rows = $lockStatement->fetchAll(\PDO::FETCH_ASSOC);
    $matching = null;
    $platformAdmin = null;
    foreach ($rows as $row) {
        if (mb_strtolower((string) $row['email']) === $email) $matching = $row;
        if ((string) $row['role'] === 'admin' && $platformAdmin === null) $platformAdmin = $row;
    }

    if (is_array($matching)) {
        if ((string) $matching['role'] !== 'admin' || !(bool) $matching['is_active']) {
            throw new \DomainException('Účet během bootstrapu vznikl, ale není aktivním platform adminem.');
        }
        $userId = (int) $matching['id'];
        $logger->log('platform_admin.bootstrap_reused', $userId, 'user', $userId, [
            'via' => 'cli:revizior-bootstrap-platform-admin',
            'operator_os_user' => $operator,
            'operator_host' => $host,
        ]);
        $pdo->commit();
        fwrite(STDOUT, "OK: Platform admin $email už existuje (id=$userId); stav byl auditován.\n");
        exit(0);
    }
    if (is_array($platformAdmin)) {
        throw new \DomainException("Platform admin už během bootstrapu vznikl ({$platformAdmin['email']}).");
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, role, locale, is_active)
         VALUES (?, ?, ?, \'admin\', \'cs\', 1)'
    );
    $insert->execute([$email, $hasher->hash($password), $name]);
    $userId = (int) $pdo->lastInsertId();
    $logger->log('platform_admin.bootstrap_created', $userId, 'user', $userId, [
        'via' => 'cli:revizior-bootstrap-platform-admin',
        'operator_os_user' => $operator,
        'operator_host' => $host,
        'mfa_required' => true,
        'supplier_memberships' => 0,
    ]);
    $pdo->commit();
} catch (\Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('sodium_memzero')) {
        if ($password !== '') sodium_memzero($password);
        if ($confirmation !== '') sodium_memzero($confirmation);
    }
    fwrite(STDERR, "ERROR: {$exception->getMessage()}\n");
    exit(5);
}

if (function_exists('sodium_memzero')) {
    if ($password !== '') sodium_memzero($password);
    if ($confirmation !== '') sodium_memzero($confirmation);
}
fwrite(STDOUT, "OK: Platform admin $email vytvořen (id=$userId), bez supplier membershipu.\n");
fwrite(STDOUT, "Při prvním povoleném break-glass přístupu musí dokončit MFA.\n");
