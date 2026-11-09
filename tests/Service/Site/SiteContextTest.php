<?php

namespace App\Tests\Service\Site;

use App\Entity\Site;
use App\Repository\SiteRepository;
use App\Service\Site\SiteContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SiteContextTest extends TestCase
{
    /** @var array<int, Site> */
    private array $sites;

    protected function setUp(): void
    {
        $this->sites = [1 => new Site(), 2 => new Site()];
    }

    private function context(Request $request, bool $granted = true, ?int $sessionSite = null): SiteContext
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $sessionSite) {
            $session->set('admin_current_site_id', $sessionSite);
        }
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        $repository = $this->createStub(SiteRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?Site => $this->sites[(int) $id] ?? null);

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return new SiteContext($stack, $repository, $checker);
    }

    private function refererRequest(string $referer): Request
    {
        return Request::create('https://bo.test/admin/invoice', 'GET', [], [], [], ['HTTP_REFERER' => $referer]);
    }

    public function testUrlWinsOverTheSession(): void
    {
        $context = $this->context(Request::create('https://bo.test/admin/registration?_site=2'), sessionSite: 1);

        self::assertSame($this->sites[2], $context->getCurrentSite());
    }

    public function testRefererKeepsTheSiteOfTheOriginTab(): void
    {
        $context = $this->context($this->refererRequest('https://bo.test/admin/registration?_site=2'), sessionSite: 1);

        self::assertSame($this->sites[2], $context->getCurrentSite());
    }

    public function testRefererFromAnotherHostIsIgnored(): void
    {
        $context = $this->context($this->refererRequest('https://autre.test/admin?_site=2'), sessionSite: 1);

        self::assertSame($this->sites[1], $context->getCurrentSite());
    }

    public function testSessionIsTheFallback(): void
    {
        self::assertSame($this->sites[1], $this->context(Request::create('https://bo.test/admin/registration'), sessionSite: 1)->getCurrentSite());
    }

    public function testForbiddenSiteInTheUrlGivesNoSite(): void
    {
        $context = $this->context(Request::create('https://bo.test/admin/registration?_site=2'), false, 1);

        self::assertNull($context->getCurrentSite());
    }

    public function testInvalidValueFallsBackToTheSession(): void
    {
        self::assertSame($this->sites[1], $this->context(Request::create('https://bo.test/admin/registration?_site=abc'), sessionSite: 1)->getCurrentSite());
    }
}
