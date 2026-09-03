<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * R0 baseline for orchestration that R3/R5 will extract into shared services.
 *
 * These assertions intentionally describe today's action-owned flow. They make
 * an incomplete extraction fail loudly while the functional integration tests
 * continue to protect calculations, price overrides and tenant scoping.
 */
final class ReviziorSharedFlowCharacterizationTest extends TestCase
{
    /**
     * R3: klientský tok je vytažený do sdíleného `ClientWriter`. Actions jsou
     * jen mapování HTTP → služba → JSON; pořadí kroků hlídá služba.
     */
    public function testClientActionsDelegateToSharedWriterAndKeepGuards(): void
    {
        $create = self::source('Action/Client/CreateClientAction.php');
        self::assertOrderedSubstrings($create, [
            'SupplierScopeMiddleware::ATTR_CURRENT_ID',
            '$this->writer->create($supplierId, $body, $actor)',
        ]);
        self::assertStringNotContainsString('Validation::client(', $create);

        $update = self::source('Action/Client/UpdateClientAction.php');
        self::assertOrderedSubstrings($update, [
            'SupplierGuard::owns(',
            '$this->writer->update($id, $supplierId, $body, $actor)',
        ]);
        self::assertStringNotContainsString('Validation::client(', $update);

        $writer = self::source('Service/Client/ClientWriter.php');
        self::assertStringContainsString('Validation::client($body)', $writer);
        self::assertStringContainsString('$this->emailContacts->replaceForClient(', $writer);
        self::assertOrderedSubstrings($writer, [
            '$this->validate($body, $allowIncompleteAddress)',
            '$this->repo->create($body, $supplierId)',
            '$this->replaceContacts($id, $supplierId, $body)',
            '\'client.created\',',
            '$this->validate($body, $allowIncompleteAddress)',
            '$this->repo->update($clientId, $body)',
            '$this->replaceContacts($clientId, $supplierId, $body)',
            '\'client.updated\',',
        ]);
    }

    /**
     * R3: tok konceptu je vytažený do sdíleného `InvoiceDraftCreator`. Action je
     * jen mapování HTTP → služba → JSON; pořadí kroků hlídá služba.
     */
    public function testInvoiceDraftActionDelegatesToSharedCreatorWhichKeepsBusinessFlowInOrder(): void
    {
        $action = self::source('Action/Invoice/CreateInvoiceAction.php');
        self::assertStringContainsString('$this->creator->create(SupplierGuard::currentId($request), $body, $actor)', $action);
        self::assertStringNotContainsString('InvoiceValidation::invoice(', $action);
        self::assertStringNotContainsString('createDraft(', $action);

        $source = self::source('Service/Invoice/InvoiceDraftCreator.php');
        self::assertOrderedSubstrings($source, [
            '$this->defaults->resolve($body)',
            'InvoiceValidation::invoice(',
            '$this->clients->find((int) $body[\'client_id\'])',
            '$this->applyVatClassificationDefaults(',
            '$this->repo->createDraft($body, $userId)',
            '$this->repo->replaceItems(',
            '$this->calc->recompute($id)',
            '$this->rateApplier->applyToInvoice($id)',
            '$this->logger->log(\'invoice.created\'',
            '$this->repo->find($id)',
        ]);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invoiceLifecyclePoints(): iterable
    {
        yield 'issue' => ['Action/Invoice/IssueInvoiceAction.php', 'logger->log(\'invoice.issued\''];
        yield 'send' => ['Action/Invoice/SendEmailAction.php', 'logger->log(\'invoice.sent\''];
        yield 'payment' => ['Action/Invoice/CreatePaymentAction.php', 'logger->log(\'invoice.payment_added\''];
        yield 'cancel' => ['Action/Invoice/CancelInvoiceAction.php', 'logger->log(\'invoice.cancelled\''];
        yield 'credit note' => ['Action/Invoice/CancelInvoiceAction.php', 'logger->log(\'invoice.credit_note_created\''];
    }

    #[DataProvider('invoiceLifecyclePoints')]
    public function testFutureOutboxHookPointIsCharacterized(string $file, string $marker): void
    {
        self::assertStringContainsString($marker, self::source($file));
    }

    private static function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/src/' . $relative;
        $source = file_get_contents($path);
        self::assertNotFalse($source, "Nelze načíst $path");
        return $source;
    }

    /** @param list<string> $needles */
    private static function assertOrderedSubstrings(string $source, array $needles): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = strpos($source, $needle, $offset);
            self::assertNotFalse($position, "Chybí nebo je mimo pořadí: $needle");
            $offset = $position + strlen($needle);
        }
    }
}
