<?php

/**
 * Nette Framework
 *
 * @copyright  Copyright (c) 2004, 2010 David Grudl
 * @license    http://nettephp.com/license  Nette license
 * @link       http://nettephp.com
 * @category   Nette
 * @package    Nette
 */

/*namespace Nette;*/



/**
 * Simple parser for Nette Object Notation.
 *
 * @copyright  Copyright (c) 2004, 2010 David Grudl
 * @package    Nette
 */
class NeonParser extends Object
{
	/** @var array */
	private static $patterns = array(
		'(\'[^\'\n]*\'|"(?:\\\\.|[^"\\\\\n])*")', // string
		'(@[a-zA-Z_0-9\\\\]+)', // object
		'([:-](?=\s|$)|[,=[\]{}()])', // symbol
		'#.*', // comment
		'(\n *)', // indent
		'literal' => '([^#"\',:=@[\]{}()<>\s](?:[^#,:=\]})>\n]+|:(?!\s)|(?<!\s)#)*)(?<!\s)', // literal / boolean / integer / float
		' +', // whitespace
	);

	/** @var string */
	private static $regexp;

	private static $brackets = array(
		'[' => ']',
		'{' => '}',
		'(' => ')',
	);

	/** @var array */
	private $tokens;

	/** @var int */
	private $n;



	/**
	 * Parser.
	 * @param  string
	 * @return array
	 */
	public function parse($s)
	{
		$this->tokenize($s);
		$this->n = 0;
		return $this->_parse();
	}



	/**
	 * Tokenizer & parser.
	 * @param  int  indentation (for block-parser)
	 * @param  string  end char (for inline-hash/array parser)
	 * @return array
	 */
	private function _parse($indent = NULL, $endBracket = NULL)
	{
		$inlineParser = $endBracket !== NULL; // block or inline parser?

		$result = $inlineParser || $indent ? array() : NULL;
		$value = $key = $object = NULL;
		$hasValue = $hasKey = FALSE;
		$tokens = $this->tokens;
		$n = & $this->n;
		$count = count($tokens);

		for (; $n < $count; $n++) {
			$t = $tokens[$n];

			if ($t === ',') { // ArrayEntry separator
				if (!$hasValue || !$inlineParser) {
					$this->error();
				}
				if ($hasKey) $result[$key] = $value; else $result[] = $value;
				$hasKey = $hasValue = FALSE;

			} elseif ($t === ':' || $t === '=') { // KeyValuePair separator
				if ($hasKey || !$hasValue) {
					$this->error();
				}
				$key = (string) $value;
				$hasKey = TRUE;
				$hasValue = FALSE;

			} elseif ($t === '-') { // BlockArray bullet
				if ($hasKey || $hasValue || $inlineParser) {
					$this->error();
				}
				$key = NULL;
				$hasKey = TRUE;

			} elseif (isset(self::$brackets[$t])) { // Opening bracket [ ( {
				if ($hasValue) {
					$this->error();
				}
				$hasValue = TRUE;
				$value = $this->_parse(NULL, self::$brackets[$tokens[$n++]]);

			} elseif ($t === ']' || $t === '}' || $t === ')') { // Closing bracket ] ) }
				if ($t !== $endBracket) { // unexpected type of bracket or block-parser
					$this->error();
				}
				if ($hasValue) {
					if ($hasKey) $result[$key] = $value; else $result[] = $value;
				} elseif ($hasKey) {
					$this->error();
				}
				return $result; // inline parser exit point

			} elseif ($t[0] === '@') { // Object
				$object = $t; // TODO

			} elseif ($t[0] === "\n") { // Indent
				if ($inlineParser) {
					if ($hasValue) {
						if ($hasKey) $result[$key] = $value; else $result[] = $value;
						$hasKey = $hasValue = FALSE;
					}

				} else {
					while (isset($tokens[$n+1]) && $tokens[$n+1][0] === "\n") $n++; // skip to last indent

					$newIndent = strlen($tokens[$n]) - 1;
					if ($indent === NULL) { // first iteration
						$indent = $newIndent;
					}

					if ($newIndent > $indent) { // open new block-array or hash
						if ($hasValue || !$hasKey) {
							$this->error();
						} elseif ($key === NULL) {
							$result[] = $this->_parse($newIndent);
						} else {
							$result[$key] = $this->_parse($newIndent);
						}
						$newIndent = strlen($tokens[$n]) - 1;
						$hasKey = FALSE;

					} else {
						if ($hasValue && !$hasKey) { // block items must have "key"; NULL key means list item
							if ($result === NULL) return $value;  // simple value parser exit point
							$this->error();

						} elseif ($hasKey) {
							$value = $hasValue ? $value : NULL;
							if ($key === NULL) $result[] = $value; else $result[$key] = $value;
							$hasKey = $hasValue = FALSE;
						}
					}

					if ($newIndent < $indent || !isset($tokens[$n+1])) { // close block
						return $result; // block parser exit point
					}
				}

			} else { // Value
				if ($hasValue) {
					$this->error();
				}
				if ($t[0] === '"') {
					$value = json_decode($t);
					if ($value === NULL) {
						$this->error();
					}
				} elseif ($t[0] === "'") {
					$value = substr($t, 1, -1);
				} elseif ($t === 'true' || $t === 'yes' || $t === 'TRUE' || $t === 'YES') {
					$value = TRUE;
				} elseif ($t === 'false' || $t === 'no' || $t === 'FALSE' || $t === 'NO') {
					$value = FALSE;
				} elseif ($t === 'null' || $t === 'NULL') {
					$value = NULL;
				} elseif (is_numeric($t)) {
					$value = $t * 1;
				} else { // literal
					$value = $t;
				}
				$hasValue = TRUE;
			}
		}

		throw new /*\*/Exception('NEON parse error: unexpected end of file.');
	}



	/**
	 * Lexical scanner.
	 * @param  string
	 * @return void
	 */
	private function tokenize($s)
	{
		if (!self::$regexp) {
			self::$regexp = '~' . implode('|', self::$patterns) . '~mA';
		}

		$s = str_replace("\r", '', $s);
		$s = strtr($s, "\t", ' ');
		$s = "\n" . $s . "\n"; // first is required by block-array & block-hash; last by tokenize()

		$this->tokens = preg_split(self::$regexp, $s, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

		if (end($this->tokens) !== "\n") { // parse error
			preg_match_all(self::$regexp, $s, $matches, PREG_SET_ORDER);
			$len = 0;
			foreach ($matches as $m) $len += strlen($m[0]);
			$line = substr_count($s, "\n", 0, $len);
			$col = $len - strrpos(substr($s, 0, $len), "\n");
			throw new /*\*/Exception("NEON parse error on line $line, column $col");
		}
	}



	private function error()
	{
		$s = '';
		$n = $this->n;
		while ($n && strlen($s) < 20) $s = strtr($this->tokens[$n--], "\n", ' ') . $s;
		throw new /*\*/Exception("NEON parse error: unexpected {$this->tokens[$this->n]} near $s");
	}

}