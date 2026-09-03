<?php

declare(strict_types=1);

namespace MyInvoice\Service\Client;

use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Validation;
use MyInvoice\Service\WriteActor;

/**
 * Jediná cesta, kterou vzniká a mění se klient.
 *
 * Vytaženo z `CreateClientAction`/`UpdateClientAction` (R3 integrace ReviziOR),
 * aby integrační endpoint nekopíroval validaci, kontakty ani audit a aby UI
 * i integrace zapisovaly stejně. Pořadí kroků je záměrně shodné s původními
 * actions: validace → zápis → e-mailové kontakty → activity log → načtení.
 *
 * Chyba kontaktů přichází až po zápisu klienta a klienta nevrací zpět — tak
 * se to chovalo i dřív (action neměla transakci). Volající, který transakci
 * potřebuje, si ji otevře sám; repository sdílí jedno PDO, takže se zápisy
 * do ní zapojí.
 *
 * `allowIncompleteAddress`: ReviziOR zná adresu klienta jako jeden řádek a
 * město/PSČ posílá jako `null`. Bez nich se klient uloží (prázdné hodnoty ve
 * sloupcích, které je vyžadují) a uživatel je doplní ve fakturaci před
 * vystavením dokladu. UI tuhle volbu nikdy nezapíná — formulář město i PSČ
 * vyžaduje dál.
 */
final class ClientWriter
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ClientEmailContactRepository $emailContacts,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> klient včetně `email_contacts`
     * @throws ClientWriteException
     */
    public function create(
        int $supplierId,
        array $body,
        WriteActor $actor,
        bool $allowIncompleteAddress = false,
    ): array {
        $this->validate($body, $allowIncompleteAddress);
        try {
            $id = $this->repo->create($body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            throw ClientWriteException::integrity($e->getMessage());
        }

        $this->replaceContacts($id, $supplierId, $body);
        $client = $this->load($id, $supplierId);

        $this->logger->log('client.created', $actor->userId, 'client', $id, [
            'company_name' => $body['company_name'],
            'ic' => $body['ic'] ?? null,
        ], $actor->ip, $actor->userAgent);

        return $client;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{client: array<string,mixed>, backfilled: array{expense:int, revenue:int}}
     * @throws ClientWriteException
     */
    public function update(
        int $clientId,
        int $supplierId,
        array $body,
        WriteActor $actor,
        bool $allowIncompleteAddress = false,
    ): array {
        $current = $this->repo->find($clientId);
        if ($current === null || (int) ($current['supplier_id'] ?? 0) !== $supplierId) {
            throw ClientWriteException::notFound();
        }

        $this->validate($body, $allowIncompleteAddress);
        try {
            $backfilled = $this->repo->update($clientId, $body);
        } catch (\InvalidArgumentException $e) {
            throw ClientWriteException::integrity($e->getMessage());
        }

        $contactsChanged = $this->replaceContacts($clientId, $supplierId, $body);

        $this->logger->log(
            'client.updated',
            $actor->userId,
            'client',
            $clientId,
            $contactsChanged ? ['email_contacts' => $body['email_contacts']] : null,
            $actor->ip,
            $actor->userAgent,
        );

        return [
            'client' => $this->load($clientId, $supplierId),
            'backfilled' => ['expense' => (int) $backfilled['expense'], 'revenue' => (int) $backfilled['revenue']],
        ];
    }

    /** @param array<string,mixed> $body */
    private function validate(array $body, bool $allowIncompleteAddress): void
    {
        $errors = Validation::client($body);
        if ($allowIncompleteAddress) {
            unset($errors['city'], $errors['zip']);
        }
        if ($errors !== []) {
            throw ClientWriteException::validation($errors);
        }
    }

    /**
     * Replace-all e-mailových kontaktů, jen když klíč v payloadu je — partial
     * update bez klíče kontakty nemění (#86).
     *
     * @param array<string,mixed> $body
     */
    private function replaceContacts(int $clientId, int $supplierId, array $body): bool
    {
        if (!isset($body['email_contacts']) || !is_array($body['email_contacts'])) {
            return false;
        }
        try {
            $this->emailContacts->replaceForClient($clientId, $supplierId, $body['email_contacts']);
        } catch (\DomainException $e) {
            throw ClientWriteException::contacts($e->getMessage());
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function load(int $clientId, int $supplierId): array
    {
        $client = $this->repo->find($clientId) ?? [];
        $client['email_contacts'] = $this->emailContacts->listForClient($clientId, $supplierId);
        return $client;
    }
}
