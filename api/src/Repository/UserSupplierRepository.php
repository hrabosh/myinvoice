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
     * Členové jediné firmy. Nevrací globální roli ani uživatele mimo supplier.
     *
     * @return list<array{user_id:int,email:string,name:string,role:string,is_active:bool}>
     */
    public function listForSupplier(int $supplierId): array
    {
        if ($supplierId <= 0 || !$this->tableExists()) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id AS user_id, u.email, u.name, COALESCE(us.role, u.role) AS role, u.is_active
              FROM user_suppliers us
               JOIN users u ON u.id = us.user_id
              WHERE us.supplier_id = ?
                AND u.role <> \'admin\'
           ORDER BY u.is_active DESC, u.name, u.id'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
            'is_active' => (bool) $row['is_active'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Změní výhradně tenantovou roli existujícího člena. Lock celé membership
     * sady brání souběžnému odebrání dvou posledních ownerů.
     */
    public function updateRoleForSupplier(int $supplierId, int $userId, string $role): bool
    {
        if ($supplierId <= 0 || $userId <= 0 || !$this->tableExists()) return false;
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $members = $this->lockMembersForSupplier($supplierId);
            $target = $this->memberByUserId($members, $userId);
            if ($target === null) {
                if ($ownTransaction) $pdo->commit();
                return false;
            }
            $this->guardLastActiveOwner($members, $target, $role);
            $stmt = $pdo->prepare(
                'UPDATE user_suppliers SET role = ? WHERE supplier_id = ? AND user_id = ?'
            );
            $stmt->execute([$role, $supplierId, $userId]);
            if ($ownTransaction) $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Odebere existující membership; posledního aktivního ownera odebrat nelze. */
    public function removeForSupplier(int $supplierId, int $userId): bool
    {
        if ($supplierId <= 0 || $userId <= 0 || !$this->tableExists()) return false;
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $members = $this->lockMembersForSupplier($supplierId);
            $target = $this->memberByUserId($members, $userId);
            if ($target === null) {
                if ($ownTransaction) $pdo->commit();
                return false;
            }
            $this->guardLastActiveOwner($members, $target, null);
            $stmt = $pdo->prepare(
                'DELETE FROM user_suppliers WHERE supplier_id = ? AND user_id = ?'
            );
            $stmt->execute([$supplierId, $userId]);
            if ($ownTransaction) $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
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

    /** @return list<array{user_id:int,role:?string,is_active:bool}> */
    private function lockMembersForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT us.user_id, us.role, u.is_active
               FROM user_suppliers us
               JOIN users u ON u.id = us.user_id
              WHERE us.supplier_id = ?
                AND u.role <> \'admin\'
              FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['user_id'],
            'role' => $row['role'] !== null ? (string) $row['role'] : null,
            'is_active' => (bool) $row['is_active'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<array{user_id:int,role:?string,is_active:bool}> $members
     * @return array{user_id:int,role:?string,is_active:bool}|null
     */
    private function memberByUserId(array $members, int $userId): ?array
    {
        foreach ($members as $member) {
            if ($member['user_id'] === $userId) return $member;
        }
        return null;
    }

    /**
     * @param list<array{user_id:int,role:?string,is_active:bool}> $members
     * @param array{user_id:int,role:?string,is_active:bool} $target
     */
    private function guardLastActiveOwner(array $members, array $target, ?string $newRole): void
    {
        if (!$target['is_active'] || $target['role'] !== 'supplier_owner' || $newRole === 'supplier_owner') {
            return;
        }
        $activeOwners = count(array_filter(
            $members,
            static fn (array $member): bool => $member['is_active'] && $member['role'] === 'supplier_owner',
        ));
        if ($activeOwners <= 1) {
            throw new \DomainException('last_supplier_owner');
        }
    }
}
