<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Backup\Lib;

use Manticoresearch\Backup\Exception\InvalidPathException;

/**
 * Helper config parser for use in backup and client components
 */
class ManticoreConfig {
	public string $path;
	public string $host;
	public int $port;

	public string $data_dir;
	public string $sphinxql_state;
	public string $lemmatizer_base;
	public string $plugin_dir;

	public string $schema_path;

  /**
   * Initialization instance and parse the config
   *
   * @param string $config_path
   *  Path to manticore daemon searchd config
   */
	public function __construct(string $config_path) {
		$config = file_get_contents($config_path);
		if (false === $config) {
			throw new \InvalidArgumentException('Failed to read config file: ' . $config_path);
		}

		$this->path = $config_path;
		$this->parse($config);
	}

  /**
   * Parse the Manticore searchd configuration file data and get required parameters from there
   *
   * @param string $config
   *  The content of the config file
   * @return void
   */
	protected function parse(string $config): void {
	  // Set defaults first
		$this->host = '127.0.0.1';
		$this->port = 9308;

	  // Try to parse and replace defaults
		preg_match_all('/^\s*(listen|data_dir|lemmatizer_base|sphinxql_state|plugin_dir)\s*=\s*(.*)$/ium', $config, $m);
		if ($m) {
			foreach ($m[1] as $n => $key) {
				$value = $m[2][$n];
				if ($n === 'listen') { // in case of we need to parse
					$this->parseHostPort($value);
				} else { // In this case we have path/file directive
					$this->$key = $value;
				}
			}
		}

		if (!isset($this->data_dir)) {
			throw new InvalidPathException('Failed to detect data_dir from config file');
		}

		if (!static::isDataDirValid($this->data_dir)) {
			throw new InvalidPathException('The data_dir parameter in searchd config should contain absolute path');
		}

		$this->schema_path = $this->data_dir . '/manticore.json';

		echo PHP_EOL . 'Manticore config' . PHP_EOL
		. '  endpoint =  ' . $this->host . ':' . $this->port . PHP_EOL
		;
	}

	/**
	 * This is helper function that parses host and port from config directive
	 *
	 * @param string $value
	 * @return void
	 */
	protected function parseHostPort(string $value): void {
		$http_pos = strpos($value, ':http');
		if (false === $http_pos) {
			return;
		}
		$listen = substr($value, 0, $http_pos);
		if (false === strpos($listen, ':')) {
			$this->port = (int)$listen;
		} else {
			$this->host = strtok($listen, ':');
			$this->port = (int)strtok(':');
		}
	}

  /**
   * This functions returns global state files that we can backup
   *
   * @return array<string>
   *   List of absolute paths to each file/directory required to backup
   */
	public function getStatePaths(): array {
		$result = [];
		if (isset($this->sphinxql_state)) {
			$result[] = $this->sphinxql_state;
		}

		if (isset($this->lemmatizer_base)) {
			$result[] = $this->lemmatizer_base;
		}

		if (isset($this->plugin_dir) && is_dir($this->plugin_dir)) {
			$result[] = $this->plugin_dir;
		}

		return $result;
	}

  /**
   * This functions validates that data_dir is valid and it contains absolute path
   *
   * @param string $data_dir
   *  platform related data dir path absolute or relative
   * @return bool
   *  If the data dir is an absolute path true otherwise false
   */
	public static function isDataDirValid(string $data_dir): bool {
		return OS::isWindows()
		? !!preg_match('/^[a-z]\:\\/ius', $data_dir) // @phpstan-ignore-line
		: $data_dir[0] === '/'
		;
	}
}
