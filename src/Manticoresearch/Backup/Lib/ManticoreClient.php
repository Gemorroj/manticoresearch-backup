<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\SearchdException;

/**
 * This class is used for communication with manticore searchd HTTP protocol by using SQL endpoint
 */
class ManticoreClient {
	const API_PATH = '/sql?mode=raw';

	protected ManticoreConfig $Config;

	public function __construct(ManticoreConfig $Config) {
		$this->Config = $Config;

		$versions = $this->getVersions();
		$ver_num = strtok($versions['manticore'], ' ');

		if ($ver_num === false || version_compare($ver_num, Searchd::MIN_VERSION) <= 0) {
			$ver_sfx = strtok(' ');
			if (false === $ver_sfx) {
				throw new \RuntimeException('Failed to find the version of the manticore searchd');
			}

			$is_old = $ver_num < Searchd::MIN_VERSION;
			if (!$is_old) {
				[, $ver_date] = explode('@', $ver_sfx);
				$is_old = $ver_date < Searchd::MIN_DATE;
			}

			if ($is_old) {
				throw new \RuntimeException(
					'You are running old version of manticore searchd, minimum required: ' . Searchd::MIN_VERSION
				);
			}
		}
		echo PHP_EOL . 'Manticore versions:' . PHP_EOL
			. '  manticore: ' . $versions['manticore'] . PHP_EOL
			. '  columnar: ' . $versions['columnar'] . PHP_EOL
			. '  secondary: ' . $versions['secondary'] . PHP_EOL
		;

	  // Validate config path or fail
		$config_path = $this->getConfigPath();
		if ($config_path !== $this->Config->path) {
			throw new \RuntimeException(
				"Configs mismatched: '{$this->Config->path} <> {$config_path}"
				. ', make sure the instance you are backing up is using the provided config'
			);
		}
	}

  /**
   * Little helper to get current config that is used in Client
   *
   * @return ManticoreConfig
   *  Structure with initialized config
   */
	public function getConfig(): ManticoreConfig {
		return $this->Config;
	}

  /**
   * Helper function that we will use for first init of client and config
   *
   * @param string $config_path
   * @return self
   */
	public static function init(string $config_path): self {
		$Config = new ManticoreConfig($config_path);
		$Client = new ManticoreClient($Config);
		return $Client;
	}

  /**
   * This method freezes the index to perform safe copy of the data
   *
   * @param array<string>|string $tables
   *  Name of manticore index or list of tables
   * @return array<string>
   *  Return list of files for frozen index required to backup
   * @throws SearchdException
   */
	public function freeze(array|string $tables): array {
		if (is_string($tables)) {
			$tables = [$tables];
		}
		$tables_string = implode(', ', $tables);
		$result = $this->execute('FREEZE ' . $tables_string);
		if ($result[0]['error']) {
			throw new SearchdException('Failed to get lock for tables - ' . $tables_string);
		}
		return array_column($result[0]['data'], 'file');
	}

  /**
   * This method unfreezes the index we fronzen before
   *
   * @param array<string>|string $tables
   *  Name of index to unfreeze or list of tables
   * @return bool
   *  Return the result of operation
   */
	public function unfreeze(array|string $tables): bool {
		if (is_string($tables)) {
			$tables = [$tables];
		}
		$result = $this->execute('UNFREEZE ' . implode(', ', $tables));
		return !$result[0]['error'];
	}

  /**
   * This is helper function run unfreeze all available tables
   *
   * @return bool
   *  The result of unfreezing
   */
	public function unfreezeAll(): bool {
		println(LogLevel::Info, PHP_EOL . 'Unfreezing all tables...');
		return array_reduce(
			array_keys($this->getTables()), function (bool $carry, string $index): bool {
				println(LogLevel::Info, '  ' . $index . '...');
				$is_ok = $this->unfreeze($index);
				println(LogLevel::Info, '   ' . get_op_result($is_ok));
				$carry = $carry && $is_ok;
				return $carry;
			}, true
		);
	}

  /**
   * Query all tables that we have on instance
   *
   * @return array<string,string>
   *  array with index as a key and type as a value [ index => type ]
   */
	public function getTables(): array {
		$result = $this->execute('SHOW TABLES');
		return array_combine(
			array_column($result[0]['data'], 'Index'),
			array_column($result[0]['data'], 'Type')
		);
	}

  /**
   * Get manticore, protocol and columnar versions
   *
   * @return array{manticore:string,columnar:string,secondary:string}
   *  Parsed list of versions available with keys of [manticore, columnar, secondary]
   */
	public function getVersions(): array {
		$result = $this->execute('SHOW STATUS LIKE \'version\'');
		$version = $result[0]['data'][0]['Value'] ?? '';
		$ver_pattern = '(\d+\.\d+\.\d+[^\(\)]+)';
		$match_expr = "/^{$ver_pattern}(\(columnar\s{$ver_pattern}\))?"
			. "([^\(]*\(secondary\s{$ver_pattern}\))?$/ius"
		;
		preg_match($match_expr, $version, $m);

		return [
			'manticore' => $m[1] ?? '0.0.0',
			'columnar' => $m[3] ?? '0.0.0',
			'secondary' => $m[5] ?? '0.0.0',
		];
	}

	public function flushAttributes(): void {
		$this->execute('FLUSH ATTRIBUTES');
	}

  /**
   * This function is used to validate the config path of running daemon
   *
   * @return string
   *  Path to config from SHOW SETTINGS query
   */
	public function getConfigPath(): string {
		$result = $this->execute('SHOW SETTINGS');
		$config_path = realpath($result[0]['data'][0]['Value']);

		if (false === $config_path) {
			throw new \RuntimeException(
        'Unable to get config path from SHOW SETTINGS'
      );
		}

	  // Fix issue with //manticore.conf path
		if ($config_path[0] === '/') {
			$config_path = '/' . ltrim($config_path, '/');
		}

		return $config_path;
	}

  /**
   * Run SQL query via HTTP endpoint and return result set
   *
   * @param string $query
   *  SQL query to execute
   * @return array{0:array{data:array<array{Value:string}>,error:string}}
   *  The result of the query passed to be executed
   */
	public function execute(string $query): array {
		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => 'Content-type: application/json',
				'content' => http_build_query(compact('query')),
				'ignore_errors' => false,
				'timeout' => 3,
			],
		];
		$context = stream_context_create($opts);
		try {
			$result = file_get_contents(
				'http://' . $this->Config->host . ':' . $this->Config->port . static::API_PATH,
				false,
				$context
			);
		} catch (\ErrorException) {
			throw new SearchdException('Failed to connect to the manticoresearch daemon. Is it running?');
		}

		if (!$result) { // can be null or false in failed cases so we check non strict here
			throw new SearchdException(__METHOD__ . ': failed to execute query: "' . $query . '"');
		}

	  // @phpstan-ignore-next-line
		return json_decode($result, true);
	}

  /**
   * Get signal handler for received signals on interruption
   *
   * @return \Closure
   */
	public function getSignalHandlerFn(FileStorage $Storage): \Closure {
		return function (int $signal) use ($Storage): void {
			println(LogLevel::Warn, 'Caught signal ' . $signal);
			$Storage->cleanUp();
			$this->unfreezeAll();
		};
	}
}