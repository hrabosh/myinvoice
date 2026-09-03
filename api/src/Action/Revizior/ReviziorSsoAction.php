<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Integration\Revizior\ReviziorSsoService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `GET /api/auth/revizior/sso?ticket=…` — jednorázový přechod z ReviziORu.
 *
 * Odpověď je vždy `303` (úspěch) nebo malá HTML stránka s chybou; nikdy JSON,
 * protože sem chodí prohlížeč, ne klient API. Ticket se nikdy neloguje ani
 * neopakuje v odpovědi a redirect po úspěchu ho v URL nemá.
 */
final class ReviziorSsoAction
{
    public function __construct(
        private readonly ReviziorSsoService $sso,
        private readonly DeploymentCapabilities $capabilities,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->capabilities->isReviziorManaged()) {
            // Ve standalone instalaci endpoint neexistuje — 404, ne 403:
            // jeho existence sama o sobě prozrazuje režim nasazení.
            return $this->page($response->withStatus(404), 'not_found');
        }

        $ticket = (string) ($request->getQueryParams()['ticket'] ?? '');
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');

        try {
            $result = $this->sso->consume($ticket, $ip, $userAgent);
        } catch (ReviziorSsoException $e) {
            $this->logger->warning('ReviziOR SSO ticket rejected', [
                'error_code' => $e->errorCode,
                'ip' => $ip,
            ]);

            return $this->page($response->withStatus($e->httpStatus), $e->errorCode);
        } catch (Throwable $e) {
            $this->logger->error('ReviziOR SSO failed', ['exception' => $e::class]);

            return $this->page($response->withStatus(503), 'sso_unavailable');
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', $result['redirect'])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader(
                'Set-Cookie',
                $this->sso->cookieHeader($result['session']['token'], (int) $result['session']['expires_at']),
            );
    }

    /**
     * Chybová stránka bez detailu a bez ticketu; kód je jen pro podporu.
     * Odkaz zpět vede na nakonfigurovanou adresu ReviziORu, nikdy na hodnotu
     * z ticketu — ta právě neprošla ověřením.
     */
    private function page(Response $response, string $code): Response
    {
        $appUrl = trim((string) $this->config->get('deployment.revizior.app_url', ''));
        $back = $appUrl !== ''
            ? sprintf('<p><a href="%s">Zpět do ReviziORu</a></p>', htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8'))
            : '';
        $html = sprintf(
            '<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Přihlášení se nezdařilo</title></head>'
            . '<body><h1>Přihlášení se nezdařilo</h1>'
            . '<p>Odkaz do fakturace není platný nebo už byl použitý. Zkuste to prosím z ReviziORu znovu.</p>'
            . '%s<p><small>%s</small></p></body></html>',
            $back,
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
        );
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
