<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
	private const ALLOWED_TYPES = ["boolean", "integer", "double", "string", "NULL"];
	private const ALLOWED_SPEC = ["?", "?a", "?#", "?d", "?f"];
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
	    if ($this->checkSpecifiersArgs($query, $args)) {
		    throw new Exception('invalid comparison of specifiers and args in query' . $query
			    . ' arg count:' . count($args));
	    }

	    return $this->collect($query, $args);
    }

    public function skip(): string
    {
		return '#!@skip@!#';
    }

	private function collect(string $string, array $args): string
	{
		$parts = preg_split('/\s+/', $string);

		$partsWithSubs = [];
		for ($i = 0; $i < count($parts); $i++) {
			$part = $parts[$i];
			if (!preg_match('/\?(?:[dfa#]|(?=\s|$))|(\{)|(\})/', $part)) {
				$partsWithSubs[] = $part . ' ';
				continue;
			}

			if (!in_array($part, self::ALLOWED_SPEC)) {
				if($subParts = $this->processSubstring($part)){
					array_push($partsWithSubs, ...$subParts);
				}
				$partsWithSubs[] = ' ';
				continue;
			}

			$partsWithSubs[] = $part;
			$partsWithSubs[] = ' ';
		}

		return rtrim($this->assemblyParts($partsWithSubs, $args));
	}

	private function assemblyParts(array $partsWithSubs, array $args): string
	{
		$result = '';
		$keyCount = 0;
		$belongsToBlock = false;
		$tempBlock = '';
		$showBlock = true;

		for ($i = 0; $i < count($partsWithSubs); $i++) {
			$part = $partsWithSubs[$i];
			if (!preg_match('/\?(?:[dfa#]|(?=\s|$))|(\{)|(\})/', $part)) {
				$tempBlock .= $part;
			}

			if ($part === '{') {
				$belongsToBlock = true;
				$showBlock = true;
				continue;
			}

			if ($part === '}') {
				$belongsToBlock = false;
				if ($showBlock){
					$result .= $tempBlock;
				}
				$tempBlock = '';
				continue;
			}

			if (in_array($part, self::ALLOWED_SPEC)) {
				if (isset($args[$keyCount]) && $args[$keyCount] === $this->skip()) {
					$showBlock = false;
					continue;
				}

				if ($part === '?#') {
					$tempBlock .= $this->processIds($args[$keyCount]);
					$keyCount++;
				}

				if ($part === '?d') {
					$tempBlock .= $this->processNums($args[$keyCount]);
					$keyCount++;
				}

				if ($part === '?f') {
					$tempBlock .= $this->processFloats($args[$keyCount]);
					$keyCount++;
				}

				if ($part === '?') {
					$tempBlock .= $this->processFree($args[$keyCount]);
					$keyCount++;
				}

				if ($part === '?a') {
					$tempBlock .= $this->processArray($args[$keyCount]);
					$keyCount++;
				}
			}

			if (!$belongsToBlock) {
				$result .= $tempBlock;
				$tempBlock = '';
			}
		}

		return $result;
	}

	private function processSubstring(string $string): array
	{
		preg_match_all('/\?(?:[dfa#]|(?=\s|$))|(\{)|(\})|(\))|(\()/', $string, $substring);
		return $substring[0];
	}

	private function processIds(array|string $arg): string
	{
		if (is_array($arg)) {
			$withBackticks = array_map(function($item) {
				return $this->addBackticks($item);
			}, $arg);

			return implode(', ', $withBackticks);
		}

		return $this->addBackticks($arg);
	}

	private function processNums($arg): string
	{
		if (is_null($arg)) {
			return 'NULL';
		}

		return strval(intval($arg));
	}

	private function processFloats($arg): string
	{
		if (is_null($arg)) {
			return 'NULL';
		}

		return strval(floatval($arg));
	}

	private function processFree($arg): string
	{
		$this->properType($arg);

		if (is_null($arg)) {
			return 'NULL';
		}

		if (is_string($arg)) {
			return $this->addBackslashes($arg);
		}

		if (is_bool($arg)) {
			return strval(intval($arg));
		}

		return strval($arg);
	}

	private function processArray($arg): string
	{
		if (!is_array($arg)) {
			throw new Exception('only an array is allowed');
		}

		$tempAr = [];

		if ($this->is_assoc($arg)) {
			$keys = array_keys($arg);
			for ($i = 0; $i < count($keys); $i++) {
				$key = $keys[$i];
				$value = $arg[$key];
				$tempAr[] = $this->addBackticks($key) . ' = ' . $this->processFree($value);
			}
			return implode(', ', $tempAr);
		}

		for ($i = 0; $i < count($arg); $i++) {
			$tempAr[] = $this->processFree($arg[$i]);
		}

		return implode(', ', $tempAr);
	}

	private function properType($arg): void
	{
		if (!in_array(gettype($arg), self::ALLOWED_TYPES)) {
			throw new Exception('not allowed type used');
		}
	}

	private function addBackticks(string $string): string
	{
		return "`" . $string . "`";
	}

	private function addBackslashes(string $string): string
	{
		return '\'' . $string . '\'';
	}

	private function is_assoc(array $array)
	{
		// Keys of the array
		$keys = array_keys($array);

		// If the array keys of the keys match the keys, then the array must
		// not be associative (e.g. the keys array looked like {0:0, 1:1...}).
		return array_keys($keys) !== $keys;
	}

	private function checkSpecifiersArgs(string $query, array $args): bool
	{
		//get all specifiers
		preg_match_all('/\?(?:[dfa#]|(?=\s|$))/', $query, $specifiers);

		return count($specifiers[0]) !== count($args);
	}
}
