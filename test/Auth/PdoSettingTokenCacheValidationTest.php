<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\PdoSettingTokenCache;

/** No database needed: the name is validated before any query. */
final class PdoSettingTokenCacheValidationTest extends TestCase
{
    public function testRejectsUnsafeDatabaseNames(): void
    {
        $pdo = $this->createStub(\PDO::class);
        foreach (['', 'a`b', 'a.b', 'sando_1; DROP TABLE x', str_repeat('a', 65), 'a b'] as $bad) {
            try {
                new PdoSettingTokenCache($pdo, $bad);
                $this->fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(PdoSettingTokenCache::class, new PdoSettingTokenCache($pdo, 'sando_65dcfd6bd9644'));
    }
}
