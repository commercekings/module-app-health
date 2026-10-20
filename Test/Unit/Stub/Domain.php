<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Model\SiteDomain;

/** SiteDomain as the shop App health was set up on, or as a copy of shop.example.com on staging.example.com. */
trait Domain
{
    private function siteDomain(bool $copy = false): SiteDomain
    {
        $domain = $this->createStub(SiteDomain::class);
        $domain->method('matches')->willReturn(!$copy);
        $domain->method('stored')->willReturn($copy ? 'shop.example.com' : '');
        $domain->method('current')->willReturn($copy ? 'staging.example.com' : 'shop.example.com');
        return $domain;
    }
}
