<?php
namespace mbaynton\GithubCommitAlert;

class Persistence {
  /**
   * @var \PDO $pdo
   */
  protected $pdo;

  /**
   * @var \PDOStatement $setLastTimeStmt
   */
  private $setLastTimeStmt;

  /**
   * @var \PDOStatement $getLastTimeStmt
   */
  private $getLastTimeStmt;

  public function __construct($db_path) {
    $dir = dirname($db_path);
    if (! is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $this->pdo = new \PDO('sqlite:' . $db_path);
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->ensureSchema();

    $this->setLastTimeStmt = $this->pdo->prepare('REPLACE INTO repos (name, last_seen_commit_time) VALUES(:repo, :ts)');
    $this->getLastTimeStmt = $this->pdo->prepare('SELECT last_seen_commit_time FROM repos WHERE name = :repo');
  }

  /**
   * Ensures the database schema is as expected.
   */
  protected function ensureSchema() {
    $s = <<<SQL
    CREATE TABLE IF NOT EXISTS repos (
      name VARCHAR NOT NULL PRIMARY KEY,
      last_seen_commit_time VARCHAR NOT NULL
    )
SQL;
    $this->pdo->exec($s);
  }

  /**
   * Set time of last commit in the repository that we know about.
   *
   * @param string $repo
   *   username/repo-name
   * @param string $ts
   *   In format 2016-08-01T00:00:00Z
   */
  public function setRepoLastSeenCommitTime($repo, $ts) {
    $this->setLastTimeStmt->execute([':repo' => $repo, ':ts' => $ts]);
  }

  /**
   * Get time of last commit in repository that we know about.
   *
   * @param string $repo
   *   username/repo-name
   * @return string|false
   *   In format 2016-08-01T00:00:00Z.
   *   False if the repo has not been seen before.
   */
  public function getRepoLastSeenCommitTime($repo) {
    $this->getLastTimeStmt->execute([':repo' => $repo]);
    $result = $this->getLastTimeStmt->fetchColumn(0);
    $this->getLastTimeStmt->closeCursor();

    return $result;
  }

  public function getRepoList() {
    return $this->pdo->query('SELECT name FROM repos ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN);
  }

  public function removeRepo($repo) {
    $rm_stmt = $this->pdo->prepare('DELETE from repos WHERE name = :name');
    $rm_stmt->execute([':name' => $repo]);
    return $rm_stmt->rowCount();
  }
}
