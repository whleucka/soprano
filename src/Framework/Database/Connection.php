<?php

namespace Echo\Framework\Database;

use Echo\Framework\Session\Flash;
use Echo\Framework\Support\SingletonTrait;
use PDO;
use PDOException;
use PDOStatement;

final class Connection implements ConnectionInterface
{
  use SingletonTrait;

  private bool $connected = false;
  private ?PDO $link = null;
  private DriverInterface $driver;
  private PDOStatement $stmt;
  private array $debug = [];
  private bool $debugEnabled;
  private bool $profilerAvailable;

  public function __construct(DriverInterface $driver)
  {
    $this->driver = $driver;
    $this->debugEnabled = config('app.debug') ?? false;
    $this->profilerAvailable = class_exists('Echo\Framework\Debug\Profiler');
    $this->connect();
  }

  public static function getInstance(DriverInterface $driver): Connection
  {
    if (self::$instance === null) {
      self::$instance = new self($driver);
    }
    return self::$instance;
  }

  public static function newInstance(DriverInterface $driver): Connection
  {
    self::$instance = new self($driver);
    return self::$instance;
  }

  public function isConnected(): bool
  {
    return $this->connected;
  }

  public function tryConnection(): bool
  {
    $this->connect();
    return $this->connected;
  }

  private function connect(): void
  {
    if ($this->link === null) {
      try {
        $this->connected = true;
        $this->link = new PDO(
          $this->driver->getDsn(),
          $this->driver->getUsername(),
          $this->driver->getPassword(),
          $this->driver->getOptions()
        );
        $this->syncTimeZone();
      } catch (PDOException $e) {
        error_log("Please refer to setup guide: https://github.com/whleucka/echo");

        $debug = config("app.debug");
        if ($debug) {
          Flash::add("danger", "Database connection failed.");
        }

        $this->connected = false;

        if (preg_match('/unknown database/i', $e->getMessage())) {
          error_log('Unknown database. ' . $e->getMessage());
        } else if (preg_match('/Name or service not known/', $e->getMessage())) {
          error_log('Unknown database host. ' . $e->getMessage());
        } else {
          error_log('Unknown database error. ' . $e->getMessage());
        }
      }
    }
  }

  /**
   * Make the session's clock agree with PHP's.
   *
   * PHP and the database swap wall-clock strings constantly: NOW() and
   * CURRENT_TIMESTAMP come back for PHP to diff against its own time(), and
   * PHP-built cutoffs ('- 1 WEEK') go into WHERE clauses. That only works
   * while both sides map the zone to the same UTC offset. Each side resolves
   * a named zone with its own tz database, and the database container's
   * copy can lag. When Alberta moved to permanent UTC-6 in November 2026,
   * PHP's bundled tzdb had the change but the MariaDB image's tzdata didn't,
   * and every "played 1 minute ago" read as an hour.
   *
   * So the session gets PHP's current numeric offset instead of a zone
   * name, and PHP's tzdb is the only one that matters. It's set on every
   * connect, not once: the link is persistent, and a fresh offset per
   * request also carries it across a DST change.
   *
   * The zone is read from config rather than date('P'): the HTTP kernel,
   * and with it this connection, is resolved before Application calls
   * date_default_timezone_set(), so PHP's default is still UTC here.
   */
  private function syncTimeZone(): void
  {
    if ($this->link->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
      return;
    }

    $this->link->exec("SET time_zone = " . $this->link->quote(self::utcOffset(config('app.timezone'))));
  }

  /**
   * "+HH:MM" for the zone right now, per PHP's tz database. An empty or
   * unknown zone falls back to PHP's default.
   */
  public static function utcOffset(?string $zone, string $at = 'now'): string
  {
    try {
      $tz = new \DateTimeZone($zone ?: date_default_timezone_get());
    } catch (\Exception) {
      $tz = new \DateTimeZone(date_default_timezone_get());
    }

    return (new \DateTimeImmutable($at, $tz))->format('P');
  }

  public function execute(string $sql, array $params = []): mixed
  {
    if (!$this->connected) return null;

    $startTime = microtime(true);

    $this->debug = [
      'sql' => $sql,
      'params' => $params,
    ];
    $stmt = $this->link->prepare($sql);
    $stmt->execute($params);
    $this->stmt = $stmt;

    // Add profiling hook (using cached flags for performance)
    if ($this->debugEnabled && $this->profilerAvailable) {
      \Echo\Framework\Debug\Profiler::getInstance()->queries()?->log($sql, $params, $startTime);
    }

    return $stmt;
  }

  public function fetch(string $sql, array $params = []): array
  {
    return $this->execute($sql, $params)?->fetch() ?: [];
  }

  public function fetchAll(string $sql, array $params = []): array
  {
    $stmt = $this->execute($sql, $params);
    if ($stmt === null) {
      throw new \RuntimeException('Database connection not established');
    }
    return $stmt->fetchAll();
  }

  public function lastInsertId(): string
  {
    if ($this->link === null) {
      throw new \RuntimeException('Database connection not established');
    }
    return $this->link->lastInsertId();
  }

  public function errorInfo(): array
  {
    if ($this->link === null) {
      return ['', null, 'Database connection not established'];
    }
    return $this->link->errorInfo();
  }

  public function errorCode(): ?string
  {
    if ($this->link === null) {
      return null;
    }
    return $this->link->errorCode();
  }

  public function debug(): array
  {
    return $this->debug;
  }

  public function beginTransaction(): bool
  {
    if ($this->link === null) {
      throw new \RuntimeException('Database connection not established');
    }
    return $this->link->beginTransaction();
  }

  public function commit(): bool
  {
    if ($this->link === null) {
      throw new \RuntimeException('Database connection not established');
    }
    if ($this->inTransaction()) {
      return $this->link->commit();
    }
    return true;
  }

  public function rollback(): bool
  {
    if ($this->link === null) {
      return false;
    }
    if ($this->inTransaction()) {
      return $this->link->rollBack();
    }
    return false;
  }

  public function inTransaction(): bool
  {
    if ($this->link === null) {
      return false;
    }
    return $this->link->inTransaction();
  }

  public function getLink(): ?PDO
  {
    return $this->link;
  }
}
