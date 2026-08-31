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
    public function testClientActionsKeepValidationContactsAuditAndSupplierGuard(): void
    {
        $create = self::source('Action/Client/CreateClientAction.php');
        self::assertOrderedSubstrings($create, [
            'SupplierScopeMiddleware::ATTR_CURRENT_ID',
            'Validation::client($body)',
            '$this->repo->create($body, $supplierId)',
            '$this->emailContacts->replaceForClient(',
            '$this->logger->log(\'client.created\'',
        ]);

        $update = self::source('Action/Client/UpdateClientAction.php');
        self::assertOrderedSubstrings($update, [
            'SupplierGuard::owns(',
            'Validation::client($body)',
            '$this->repo->update($id, $body)',
            '$this->emailContacts->replaceForClient(',
            '$this->logger->log(\'client.updated\'',
        ]);
    }

    public function testInvoiceDraftActionKeepsSharedBusinessFlowInOrder(): void
    {
        $source = self::source('Action/Invoice/CreateInvoiceAction.php');
        self::assertOrderedSubstrings($source, [
            '$this->defaults->resolve($body)',
            'InvoiceValidation::invoice(',
            'SupplierGuard::owns(',
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
