<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\PdoSettingTokenCache;
use Shopify\Auth\TokenSet;

/**
 * Runs against a real MySQL (never a shared one). Set
 *   SHOPIFY_PHP_TEST_MYSQL="mysql:host=127.0.0.1;port=3306" SHOPIFY_PHP_TEST_MYSQL_USER=root SHOPIFY_PHP_TEST_MYSQL_PASSWORD=root
 * Creates and drops two scratch databases. Skipped when SHOPIFY_PHP_TEST_MYSQL is unset.
 */
final class PdoSettingTokenCacheTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private const DB_A = 'shopify_php_test_a';
    private const DB_B = 'shopify_php_test_b';

    protected function setUp(): void
    {
        $dsn = getenv('SHOPIFY_PHP_TEST_MYSQL');
        if (!$dsn) {
            $this->markTestSkipped('SHOPIFY_PHP_TEST_MYSQL not set');
        }
        if (self::$pdo === null) {
            self::$pdo = new \PDO($dsn, getenv('SHOPIFY_PHP_TEST_MYSQL_USER') ?: null, getenv('SHOPIFY_PHP_TEST_MYSQL_PASSWORD') ?: null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        foreach ([self::DB_A, self::DB_B] as $db) {
            self::$pdo->exec("DROP DATABASE IF EXISTS `$db`");
            self::$pdo->exec("CREATE DATABASE `$db`");
            // Same definition as Admin's mysqlTableCreateStatements.sql
            self::$pdo->exec("CREATE TABLE `$db`.`settings` (
                `id` varchar(64) NOT NULL DEFAULT '', `value` text, `group` varchar(32) DEFAULT NULL,
                `updated_at` datetime NOT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`), KEY `idx_group` (`group`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            foreach ([self::DB_A, self::DB_B] as $db) {
                self::$pdo->exec("DROP DATABASE IF EXISTS `$db`");
            }
        }
    }

    private function row(string $db): ?array
    {
        $r = self::$pdo->query("SELECT `value`, `group`, `updated_at` FROM `$db`.`settings` WHERE `id` = 'shopify_access_token'")->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function testPutInsertsThenUpdatesAndGetReadsBack(): void
    {
        $cache = new PdoSettingTokenCache(self::$pdo, self::DB_A);
        $this->assertNull($cache->get());

        $cache->put(new TokenSet('shpat_one', 2000, 1500, 'read_products'));
        $row = $this->row(self::DB_A);
        $this->assertSame('shopify', $row['group']);
        $this->assertNotEmpty($row['updated_at']);

        $cache->put(new TokenSet('shpat_two', 3000, 2500));
        $this->assertSame('shpat_two', TokenSet::fromJson($this->row(self::DB_A)['value'])->token);
        $this->assertSame('shpat_two', (new PdoSettingTokenCache(self::$pdo, self::DB_A))->get()->token);
    }

    public function testWritesGoToTheNamedDatabaseRegardlessOfTheConnectionsCurrentDatabase(): void
    {
        self::$pdo->exec('USE `' . self::DB_B . '`');
        (new PdoSettingTokenCache(self::$pdo, self::DB_A))->put(new TokenSet('shpat_a', 2000, 1500));
        $this->assertNotNull($this->row(self::DB_A));
        $this->assertNull($this->row(self::DB_B));
    }

    public function testClearDeletesTheRow(): void
    {
        $cache = new PdoSettingTokenCache(self::$pdo, self::DB_A);
        $cache->put(new TokenSet('shpat_a', 2000, 1500));
        $cache->clear();
        $this->assertNull($this->row(self::DB_A));
        $this->assertNull($cache->get());
    }

    public function testPreloadedValueSavesTheQuery(): void
    {
        $json = (new TokenSet('shpat_pre', 2000, 1500))->toJson();
        $cache = new PdoSettingTokenCache(self::$pdo, self::DB_A, $json);
        $this->assertSame('shpat_pre', $cache->get()->token, 'served from the preloaded value, not the (empty) table');
    }
}
