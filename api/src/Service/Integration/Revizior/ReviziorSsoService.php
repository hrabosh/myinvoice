<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Revizior\Security\ReviziorReplayGuard;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use MyInvoice\Service\Revizior\Security\ReviziorSsoTicketVerifier;
use PDO;

/**
 * Spotřebování SSO ticketu → nová session u poskytovatele (R4, §2.14).
 *
 * Pořadí kroků není libovolné:
 *
 * 1. **podpis a čas** — nejlevnější kontrola, zahodí většinu nesmyslů;
 * 2. **jednorázové `jti`** hned potom — druhé použití téhož ticketu selže,
 *    i když první redirect proběhl a session vznikla;
 * 3. **organizace a členství z DB**, ne z ticketu — odebrání přístupu
 *    v ReviziORu platí okamžitě, ne až vyprší poslední vydaný ticket;
 * 4. **cíl** proti allowlistu a vlastnictví;
 * 5. **návratová URL** proti allowlistu originů;
 * 6. teprve pak vzniká session.
 *
 * Session se vždycky zakládá nová (žádné převzetí existující), takže případná
 * cizí session v prohlížeči nepřežije přechod — obrana proti fixaci.
 */
final class ReviziorSsoService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ReviziorSsoTicketVerifier $verifier,
        private readonly ReviziorReplayGuard $replayGuard,
        private readonly ReviziorSsoTargetPolicy $targetPolicy,
        private readonly ReviziorReturnUrlPolicy $returnUrlPolicy,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $activity,
        private readonly Config $config,
    ) {}

    /**
     * @return array{redirect:string, session:array{token:string,csrf_token:string,expires_at:int}, user_id:int}
     */
    public function consume(string $ticket, string $ip, string $userAgent): array
    {
        $claims = $this->verifier->verify($ticket);
        $this->replayGuard->consumeNonce(
            $claims->jti,
            'sso',
            $claims->issuer,
            $claims->userUuid,
            $claims->expiresAt,
        );

        $pdo = $this->db->pdo();
        $organization = $this->organization($pdo, $claims->organizationUuid);
        if ($organization === null) {
            throw ReviziorSsoException::forbidden('sso_organization_unknown');
        }
        if ((string) $organization['status'] === 'suspended') {
            throw ReviziorSsoException::forbidden('sso_organization_suspended');
        }
        $supplierId = (int) $organization['supplier_id'];

        $member = $this->activeMember($pdo, (int) $organization['id'], $claims->userUuid, $supplierId);
        if ($member === null) {
            throw ReviziorSsoException::forbidden('sso_membership_inactive');
        }

        $redirect = $this->targetPolicy->resolve($pdo, $claims->target, $supplierId);
        $returnTo = $this->returnUrlPolicy->assertAllowed($claims->returnTo);

        $session = $this->sessions->create(
            (int) $member['user_id'],
            $ip,
            $userAgent,
            new SessionAuthContext('revizior_sso', 'basic'),
        );
        // Návratová adresa žije na session, ne v query stringu: „Zpět do
        // ReviziORu" se pak nedá přesměrovat úpravou URL v prohlížeči.
        $pdo->prepare('UPDATE sessions SET revizior_return_url = ? WHERE id = ?')
            ->execute([$returnTo, $session['token']]);

        $this->activity->log(
            'revizior.sso.consumed',
            (int) $member['user_id'],
            'user',
            (int) $member['user_id'],
            [
                'organization_uuid' => $claims->organizationUuid,
                'user_uuid' => $claims->userUuid,
                'target' => $redirect,
            ],
            $ip,
            $userAgent,
            supplierId: $supplierId,
        );

        return ['redirect' => $redirect, 'session' => $session, 'user_id' => (int) $member['user_id']];
    }

    public function cookieHeader(string $token, int $expiresAt): string
    {
        $name = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Neplatný název auth cookie.');
        }
        $sameSite = (string) $this->config->get('session.cookie_samesite', 'Lax');
        if (!in_array($sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new \InvalidArgumentException('Neplatná SameSite politika auth cookie.');
        }
        // `Strict` by cookie po přechodu z ReviziORu neposlala na první request,
        // takže by uživatel skončil na přihlášení. `Lax` je nejužší, co funguje.
        if ($sameSite === 'Strict') {
            $sameSite = 'Lax';
        }
        $secure = (bool) $this->config->get('session.cookie_secure', true);

        return sprintf(
            '%s=%s; HttpOnly; Path=/; Max-Age=%d; SameSite=%s%s',
            $name,
            $token,
            max(0, $expiresAt - time()),
            $sameSite,
            $secure ? '; Secure' : '',
        );
    }

    /** @return array<string,mixed>|null */
    private function organization(PDO $pdo, string $organizationUuid): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, supplier_id, status FROM revizior_organization_links WHERE organization_uuid = ?'
        );
        $statement->execute([$organizationUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Aktivní členství **a** aktivní účet **a** řádek v `user_suppliers` —
     * všechny tři podmínky, protože každá se ruší jinou cestou.
     *
     * @return array<string,mixed>|null
     */
    private function activeMember(PDO $pdo, int $organizationLinkId, string $userUuid, int $supplierId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT rul.user_id, rul.supplier_role
               FROM revizior_user_links rul
               JOIN users u ON u.id = rul.user_id
               JOIN user_suppliers us ON us.user_id = rul.user_id AND us.supplier_id = ?
              WHERE rul.organization_link_id = ? AND rul.user_uuid = ?
                AND rul.active = 1 AND rul.revoked_at IS NULL AND u.is_active = 1'
        );
        $statement->execute([$supplierId, $organizationLinkId, $userUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
