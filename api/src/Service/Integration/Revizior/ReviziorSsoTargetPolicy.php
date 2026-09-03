<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use PDO;

/**
 * Kam smí SSO ticket poslat prohlížeč (R4, §2.14).
 *
 * ## Regex nestačí
 *
 * Tvar cesty je jen první síto. Cíl s ID se navíc **načte z DB** a ověří proti
 * supplieru organizace — jinak by podepsaný ticket pro tenanta A otevřel
 * fakturu tenanta B jen tím, že v ReviziORu vznikne cíl s cizím ID.
 *
 * ## Kontraktní cesta ≠ cesta v aplikaci
 *
 * Kontrakt v1 zmrazil `/price-list`, ale obrazovka ceníku žije na
 * `/admin/price-list`. Překlad je tady: kontrakt zůstává stabilní a redirect
 * míří na skutečnou obrazovku. Cíl, pro který obrazovka v managed režimu
 * neexistuje, se odmítá — poslat uživatele na stránku, kam ho router stejně
 * nepustí, vypadá jako rozbitá aplikace.
 */
final class ReviziorSsoTargetPolicy
{
    /**
     * @return string cesta v aplikaci, na kterou se přesměruje
     */
    public function resolve(PDO $pdo, string $target, int $supplierId): string
    {
        if ($target === '' || strlen($target) > 512) {
            throw ReviziorSsoException::forbidden('sso_target_forbidden');
        }
        // Query, fragment, absolutní URL, `//host`, traversal ani řídicí znaky
        // se nepřevádějí na „skoro platnou" cestu — rovnou konec.
        if ($target[0] !== '/'
            || str_starts_with($target, '//')
            || str_starts_with($target, '/\\')
            || str_contains($target, '..')
            || str_contains($target, '?')
            || str_contains($target, '#')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || rawurldecode($target) !== $target
        ) {
            throw ReviziorSsoException::forbidden('sso_target_forbidden');
        }

        if (in_array($target, ['/invoices', '/clients', '/projects'], true)) {
            return $target;
        }
        if ($target === '/price-list') {
            return '/admin/price-list';
        }
        if ($target === '/bank') {
            return '/bank';
        }
        if ($target === '/settings/supplier') {
            return '/settings/supplier';
        }

        if (preg_match('#^/invoices/([1-9][0-9]{0,17})(/edit)?$#D', $target, $m) === 1) {
            $this->assertOwned($pdo, 'invoices', (int) $m[1], $supplierId);

            return $target;
        }
        if (preg_match('#^/clients/([1-9][0-9]{0,17})$#D', $target, $m) === 1) {
            $this->assertOwned($pdo, 'clients', (int) $m[1], $supplierId);

            return $target;
        }
        if (preg_match('#^/projects/([1-9][0-9]{0,17})$#D', $target, $m) === 1) {
            $this->assertProjectOwned($pdo, (int) $m[1], $supplierId);

            return $target;
        }

        throw ReviziorSsoException::forbidden('sso_target_forbidden');
    }

    private function assertOwned(PDO $pdo, string $table, int $id, int $supplierId): void
    {
        $statement = $pdo->prepare(sprintf('SELECT 1 FROM %s WHERE id = ? AND supplier_id = ?', $table));
        $statement->execute([$id, $supplierId]);
        if ($statement->fetchColumn() === false) {
            // Cizí i neexistující cíl dávají tutéž odpověď: rozdíl by prozradil,
            // které doklady u poskytovatele existují.
            throw ReviziorSsoException::forbidden('sso_target_forbidden');
        }
    }

    private function assertProjectOwned(PDO $pdo, int $id, int $supplierId): void
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?'
        );
        $statement->execute([$id, $supplierId]);
        if ($statement->fetchColumn() === false) {
            throw ReviziorSsoException::forbidden('sso_target_forbidden');
        }
    }
}
