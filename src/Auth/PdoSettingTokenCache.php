<?php

namespace Shopify\Auth;

/**
 * Caches the minted token in the store's own `settings` table, setting id
 * `shopify_access_token`.
 *
 * Every statement uses the fully qualified `<database>`.`settings`: connections are
 * shared per MySQL host and switched with USE, so an unqualified name could hit
 * another store's database.
 */
final class PdoSettingTokenCache implements TokenCacheInterface
{
    public const SETTING_ID = 'shopify_access_token';
    public const SETTING_GROUP = 'shopify';

    private string $table;
    private bool $loaded = false;
    private ?string $raw = null;

    /**
     * @param string|null $preloaded The setting's raw value when the caller has already loaded the
     *                               store's settings (saves a query). Null means "not loaded".
     */
    public function __construct(private readonly \PDO $pdo, string $database, ?string $preloaded = null)
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new \InvalidArgumentException('Invalid database name');
        }
        $this->table = '`' . $database . '`.`settings`';
        if ($preloaded !== null) {
            $this->raw = $preloaded;
            $this->loaded = true;
        }
    }

    public function get(): ?TokenSet
    {
        if (!$this->loaded) {
            $stmt = $this->pdo->prepare("SELECT `value` FROM {$this->table} WHERE `id` = :id");
            $this->check($stmt !== false && $stmt->execute(['id' => self::SETTING_ID]), 'read');
            $value = $stmt->fetchColumn();
            $this->raw = is_string($value) ? $value : null;
            $this->loaded = true;
        }
        return TokenSet::fromJson($this->raw);
    }

    public function put(TokenSet $token): void
    {
        $json = $token->toJson();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (`id`, `value`, `group`, `updated_at`) VALUES (:id, :v, :g, NOW())
             ON DUPLICATE KEY UPDATE `value` = :v2, `updated_at` = NOW()"
        );
        $this->check($stmt !== false && $stmt->execute(['id' => self::SETTING_ID, 'v' => $json, 'g' => self::SETTING_GROUP, 'v2' => $json]), 'write');
        $this->raw = $json;
        $this->loaded = true;
    }

    public function clear(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE `id` = :id");
        $this->check($stmt !== false && $stmt->execute(['id' => self::SETTING_ID]), 'clear');
        $this->raw = null;
        $this->loaded = true;
    }

    private function check(bool $ok, string $what): void
    {
        if (!$ok) {
            // Covers PDO in ERRMODE_SILENT; in ERRMODE_EXCEPTION PDO has already thrown.
            throw new \RuntimeException("Token cache $what failed");
        }
    }
}
