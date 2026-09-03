<?php

declare(strict_types=1);

/**
 * ReviziOR outbox — doručení událostí o dokladech (R5).
 *
 *   php api/bin/cron-revizior-outbox.php [--limit=50] [--status] [--retry=<eventId>]
 *
 * Bez argumentů odešle jednu dávku. `--status` jen vypíše frontu (pro alerting),
 * `--retry` vrátí dead-letter event zpět do fronty. Ve standalone instalaci
 * a bez nakonfigurovaného callbacku skončí bez práce.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Integration\Revizior\ReviziorOutboxDispatcher;

$options = getopt('', ['limit::', 'status', 'retry::']);
$container = Bootstrap::buildApp()->getContainer();
$capabilities = $container->get(DeploymentCapabilities::class);
if (!$capabilities->isReviziorManaged()) {
    echo "Instalace neběží v režimu ReviziOR Fakturace — nic k odesílání.\n";
    exit(0);
}

$dispatcher = $container->get(ReviziorOutboxDispatcher::class);

if (array_key_exists('status', $options)) {
    $status = $dispatcher->status();
    printf(
        "pending=%d failed=%d delivered=%d oldest_pending=%s\n",
        $status['pending'],
        $status['failed'],
        $status['delivered'],
        $status['oldest_pending_age_seconds'] === null ? '-' : $status['oldest_pending_age_seconds'] . 's',
    );
    // Nenulový exit kód dává monitoringu důvod k alertu bez parsování výstupu.
    exit($status['failed'] > 0 ? 2 : 0);
}

if (isset($options['retry']) && $options['retry'] !== '') {
    $eventId = (string) $options['retry'];
    echo $dispatcher->requeue($eventId)
        ? "Event {$eventId} vrácen do fronty.\n"
        : "Event {$eventId} nebyl ve stavu failed.\n";
    exit(0);
}

if (!$dispatcher->isConfigured()) {
    fwrite(STDERR, "Callback ReviziORu není nakonfigurovaný (URL nebo podpisový klíč).\n");
    exit(1);
}

$limit = isset($options['limit']) && $options['limit'] !== '' ? (int) $options['limit'] : ReviziorOutboxDispatcher::DEFAULT_BATCH;
$result = $dispatcher->dispatch($limit);
printf(
    "claimed=%d delivered=%d retried=%d failed=%d\n",
    $result['claimed'],
    $result['delivered'],
    $result['retried'],
    $result['failed'],
);
exit($result['failed'] > 0 ? 2 : 0);
