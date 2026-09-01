<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Membership uživatel ↔ supplier (tabulka user_suppliers, migrace 0148).
 *
 * Prázdné přiřazení zachovává ve standalone režimu zpětně kompatibilní přístup
 * bez omezení. Managed resolver je interpretuje fail-closed.
 * `role` je volitelný per-supplier override; NULL dědí globální users.role.
 *
 * Základ schématu vychází z MyÚčto migrace 1000; managed overlay v migraci
 * 0151 lokálně přidává tenantovou roli supplier_owner.
 */
final class UserSupplierRepository
{
    /** Cache existence tabulky v rámci requestu (DI = 1 instance / request). */
    private ?bool $tableExists = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * Ochrana proti nasazení kódu PŘED migrací 0148 (deploy okno): resolver volá
     * membership na každém autentizovaném requestu — bez tabulky by to bylo 500 na
     * celou instanci. Chybějící tabulka → chová se jako „bez membershipu" (BC).
     *
     * BEZPEČNOST: fail-open (→ neomezený přístup) povolíme JEN pro „table doesn't
     * exist" (SQLSTATE 42S02). Jakákoliv jiná PDO chyba (lock timeout, too many
     * connections, server gone away) se přehodí → 500, ať tenant izolace raději
     * spadne, než by ji transientní výpadek tiše vypnul. Transientní chybu
     * necachujeme (další request to zkusí znovu).
     */
    private function tableExists(): bool
    {
        if ($this->tableExists !== null) return $this->tableExists;
        try {
            $this->db->pdo()->query('SELECT 1 FROM user_suppliers LIMIT 1');
            return $this->tableExists = true;
        } catch (\PDOException $e) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();
            if ($sqlState === '42S02') {
                return $this->tableExists = false; // tabulka fakticky chybí → BC režim
            }
            throw $e; // transientní/jiná chyba → fail-closed (necachovat)
        }
    }

    /**
     * Mapa supplier_id => role override (NULL = zdědit globální users.role).
     * Jediný indexovaný dotaz — pokrývá membership check i role override.
     *
     * @return array<int, ?string>
     */
    public function assignmentsForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->tableExists()) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, role FROM user_suppliers WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['supplier_id']] = $r['role'] !== null ? (string) $r['role'] : null;
        }
        return $out;
    }

    /**
     * Seznam povolených supplier id. Prázdné pole = bez omezení (ne „nic").
     *
     * @return list<int>
     */
    public function allowedSupplierIds(int $userId): array
    {
        $ids = array_keys($this->assignmentsForUser($userId));
        sort($ids);
        return $ids;
    }

    /**
     * Přiřazení uživatele vč. jména firmy (pro admin UI).
     *
     * @return list<array{supplier_id:int, name:string, ic:?string, role:?string}>
     */
    public function listForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->tableExists()) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.supplier_id,
                    COALESCE(NULLIF(s.display_name, \'\'), s.company_name) AS name,
                    s.ic,
                    us.role
               FROM user_suppliers us
               JOIN supplier s ON s.id = us.supplier_id
              WHERE us.user_id = ?
           ORDER BY us.supplier_id'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'supplier_id' => (int) $r['supplier_id'],
                'name'        => (string) $r['name'],
                'ic'          => $r['ic'] !== null ? (string) $r['ic'] : null,
                'role'        => $r['role'] !== null ? (string) $r['role'] : null,
            ];
        }
        return $out;
    }

    /**
     * Nahradí kompletní sadu přiřazení uživatele (delete + insert v transakci).
     * Prázdné pole = zrušit omezení (uživatel zase vidí všechny firmy).
     *
     * @param list<array{supplier_id:int, role:?string}> $assignments
     */
    public function replaceForUser(int $userId, array $assignments): void
    {
        if ($userId <= 0 || !$this->tableExists()) return;
        $pdo = $this->db->pdo();
        // Volající (admin akce) může běžet ve vlastní transakci — vnořenou nezakládáme.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ?')->execute([$userId]);
            if ($assignments !== []) {
                $ins = $pdo->prepare(
                    'INSERT INTO user_suppliers (user_id, supplier_id, role) VALUES (?, ?, ?)'
                );
                foreach ($assignments as $a) {
                    $ins->execute([$userId, (int) $a['supplier_id'], $a['role'] ?? null]);
                }
            }
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Úklid přiřazení smazané firmy.
     *
     * DELETE FROM supplier běží kvůli cyklickému FK supplier ↔ currencies
     * s FOREIGN_KEY_CHECKS = 0, takže se ON DELETE CASCADE NEPROVEDE a řádky
     * je nutné smazat ručně. Bez toho by omezenému uživateli zůstal v setu
     * neexistující dodavatel.
     */
    public function deleteForSupplier(int $supplierId): void
    {
        if ($supplierId <= 0 || !$this->tableExists()) return;
        $this->db->pdo()->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
    }
}
