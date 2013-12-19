<?php

/**
 * SearchWordsSQL
 *
 * A library to convert Google-style seach words
 * into an SQL boolean expression and a MySQL IBL
 * (a.k.a. 'implied Boolean logic' or 'IN BOOLEAN MODE') one.
 * @copyright 2013 yonaka

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Lesser General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Lesser General Public License for more details.

    You should have received a copy of the GNU Lesser General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

 */

/*
 * namespace for SearchWordsSQL
 */
namespace SearchWordsSQL {

	/**
	 * A sample SQL-value callback function suitable for SQL LIKE operator
	 *
	 * Escapes meta characters for LIKE operator and replaces '*' to '%'.
	 * Also prepends and appends '%'.
	 * @api
	 * @see \SearchWordsSQL\SQLBuilder::__construct()
     * @param string $value An original word.
     * @return string A converted value.
	 */
	function SQLLikeValueCallback($value) {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace('%', '\\%', $value);
		$value = str_replace('_', '\\_', $value);
		$value = str_replace('*', '%', $value);
		return "%$value%";
	}
	/**
	 * The standard IBL callback function
	 *
	 * Removes meta characters of IBL.
	 * @api
     * @param string $value An original word.
     * @return string A converted value.
	 */
	function IBLCallback($value) {
		return preg_replace('/[><~"+]+|@[0-9]+|\\\\+/u', '', $value);
	}
	/**
	 * Duplicates string (the number of values X multiplier) times into an array.
	 *
	 * A utility function suitable for MDB2 prepare(). This might be useful
	 * when the parameter $wordSQL of SQLBuilder::__construct() has plural placeholders.
	 * @api
	 * @param array $values Values for a prepared query, returned by Build().
	 * @param string $typename Typename for a word.
	 * @param int $multiplier The number of parameters per word.
	 * @return string[] Replicated typenames.
	 */
	function ReplicateDataType(&$values, $typename, $multiplier = 1) {
		$c = count($values) * $multiplier;
		$a = array();
		for ($i = 0; $i < $c; $i++) {
			$a[] = $typename;
		}
		return $a;
	}
	
	/** @internal */
	function AddPlusSign($op, $ibl) {
		if ($op == 'exclude' || $op == 'and') return "$ibl";
		else if ($op == 'word' || $op == 'or') return "+$ibl";
		else return "+($ibl)";
	}

	/** Builds SQL and IBL from search words. */
	class SQLBuilder {

		/** @internal */
		public $wordSQL;
		/** @internal */
		public $valueCallback, $IBLCallback;
		/** @internal */
		private $tree;
		/** @internal */
		private $opFuncs;
		
		/**
		 * A constructor.
		 *
		 * A constructor.
		 * @api
		 * @param string $wordSQL A parameterized SQL boolean expression using placeholder(s) for each search word.
		 * @param callable $valueCallback A user function with a parameter to convert a search word. If omitted, no conversion will be processed. It would be given an original search word as the first parameter and must return a converted value, which if $thisobj were an instance of SQLBuilder, $thisobj->Build()['value'] would contain. The return value can be an array.
		 * @param callable $IBLCallback A user function with a parameter to convert a search word.  If omitted, \SearchWordsSQL\IBLCallback() is used. It would be given an original search word as the first parameter and must return a converted value, which if $thisobj were an instance of SQLBuilder, $thisobj->Build()['IBL'] would contain. The return value can be an array.
		 */
		public function __construct($wordSQL, $valueCallback = null, $IBLCallback = null) {
			$this->wordSQL = "($wordSQL)";
			$this->valueCallback = 
				isset($valueCallback) ?
				$this->valueCallback = $valueCallback :
				$this->valueCallback = function($value) { return $value; };
			$this->IBLCallback = 
				isset($IBLCallback) ?
				$this->IBLCallback = $IBLCallback :
				$this->IBLCallback = "SearchWordsSQL\IBLCallback";


			$o = $this;
			$this->opFuncs = array(
				'word'	=>	function($node) use ($o) {
					$lhs = $node[0]['word'];
					$lhsval = call_user_func($o->valueCallback, $lhs);
					$lhsibl = call_user_func($o->IBLCallback, $lhs);
					$wordtype = $node[0]['wordtype'];
					return array(
						'SQL'	=>	$o->wordSQL,
						'value'	=>	array( $lhsval ),
						'IBL'	=>	$wordtype == "single" ? $lhsibl : "\"$lhsibl\"",
						'hit'	=>	preg_split('/\\*+/u', $lhs),
					);
				},
				'and'	=>	function($node) use ($o) {
					$lhs = call_user_func(array($o, "BuildSQL"), $node[0]);
					$lhsop = $node[0]['op'];
					$rhs = call_user_func(array($o, "BuildSQL"), $node[2]);
					$rhsop = $node[2]['op'];
					return array(
						'SQL'	=>	"( ${lhs['SQL']} AND ${rhs['SQL']} )",
						'value'	=>	array_merge(array(), $lhs['value'], $rhs['value']),
						'IBL'	=>	AddPlusSign($lhsop, $lhs['IBL']) .
									" " .
									AddPlusSign($rhsop, $rhs['IBL']),
						'hit'	=>	array_merge(array(), $lhs['hit'], $rhs['hit']),
					);
				},
				'or'	=>	function($node) use ($o) {
					$lhs = call_user_func(array($o, "BuildSQL"), $node[0]);
					$rhs = call_user_func(array($o, "BuildSQL"), $node[2]);
					return array(
						'SQL'	=>	"( ${lhs['SQL']} OR ${rhs['SQL']} )",
						'value'	=>	array_merge(array(), $lhs['value'], $rhs['value']),
						'IBL'	=>	"(${lhs['IBL']} ${rhs['IBL']})",
						'hit'	=>	array_merge(array(), $lhs['hit'], $rhs['hit']),
					);
				},
				'paren'	=>	function($node) use ($o) {
					$lhs = call_user_func(array($o, "BuildSQL"), $node[1]);
					return array(
						'SQL'	=>	"( ${lhs['SQL']} )",
						'value'	=>	$lhs['value'],
						'IBL'	=>	"(${lhs['IBL']})",
						'hit'	=>	$lhs['hit'],
					);
				},
				'exclude'	=>	function($node) use ($o) {
					$lhs = call_user_func(array($o, "BuildSQL"), $node[1]);
					$lhsop = $node[1]['op'];
					return array(
						'SQL'	=>	"( not ${lhs['SQL']} )",
						'value'	=>	$lhs['value'],
						'IBL'	=>	$lhsop == 'word' ? "-${lhs['IBL']}" : "-(${lhs['IBL']})",
						'hit'	=>	array(),
					);
				},
			);

		}
		
		/**
		 * Converts search words into SQL and IBL.
		 *
		 * Converts search words into SQL and IBL.
		 * @api
		 * @param string $wordsline
		 * 	Search words in the syntax described on readme.md.
		 * @throws \InvalidArgumentException if the search words has syntax error(s).
		 * @return array
		 *	Key 'SQL': an SQL parameterized expression.
		 *	Key 'IBL': an IBL expression.
		 *	Key 'value': Values for paramaters for the SQL expression.
		 *	Key 'hit': an array consisted of possible hit words.
		 */
		public function Build($wordsline) {
			$t = \SearchWordsSQL\Parser\ParseSearchWords($wordsline);
			$this->tree = $t[1];
			$r = $this->BuildSQL();
			return $r;
		}

		/**
		 * Returns whether built result is complement.
		 *
		 * Estimates whether built result is complement.
		 * For example, a search word '-word1' is complement, whereas '-word1 word2' is not complement.
		 * In most cases, search words which are complement is inappropriate for search.
		 * @api
		 * @param array $tree This must be omitted.
		 */
		public function isComplement($tree = null) {
			if (!isset($tree)) $tree = $this->tree;
//			echo "top: " . $tree['op'] . "<br>\n";
			return $tree['op'] == 'exclude'
				|| (
					$tree['op'] == 'paren' && (
						$this->isComplement($tree['node'][1])
					)
				) || (
					$tree['op'] == 'or' && (
						$this->isComplement($tree['node'][0])
						|| $this->isComplement($tree['node'][2])
					)
				) || (
					$tree['op'] == 'and' && (
						$this->isComplement($tree['node'][0])
						&& $this->isComplement($tree['node'][2])
					)
				);
		}
		
		/** @internal */
		public function BuildSQL($tree = null) {
			if (!isset($tree)) $tree = $this->tree;
			
			return $this->opFuncs[$tree['op']]($tree['node']);
		}
	}
}

/** @internal */
namespace SearchWordsSQL\Parser {
	// operator-precedence grammar
	// See: Alfred V. Aho, Jeffrey D. Ullman [1977]. "Principles of Compiler Design," section 5.3.

	$rank_l = array(
		'$'			=> 0,
		'LPAREN'	=> 0,
		'RPAREN'	=> 6,
		'WORD'		=> 6,
		'OR'		=> 4,
		'AND'		=> 2,
		'EXCLUDE'	=> 5,
	);
	$rank_r = array(
		'$'			=> 0,
		'LPAREN'	=> 6,
		'RPAREN'	=> 0,
		'WORD'		=> 6,
		'OR'		=> 3,
		'AND'		=> 1,
		'EXCLUDE'	=> 5,
	);
	$syntax = array(
		array(
			'form' => array(
				'WORD'
			),
			'handler' => function (&$stack) {
				return ReduceSome($stack, 1, 'word');
			}
		),
		array(
			'form' => array(
				null, 'AND', null
			),
			'handler' => function (&$stack) {
				return ReduceSome($stack, 3, 'and');
			}
		),
		array(
			'form' => array(
				null, 'OR', null
			),
			'handler' => function (&$stack) {
				return ReduceSome($stack, 3, 'or');
			}
		),
		array(
			'form' => array(
				'LPAREN', null, 'RPAREN'
			),
			'handler' => function (&$stack) {
				return ReduceSome($stack, 3, 'paren');
			}
		),
		array(
			'form' => array(
				'EXCLUDE', null
			),
			'handler' => function (&$stack) {
				return ReduceSome($stack, 2, 'exclude');
			}
		),
	);
	/** @internal */
	function ReduceSome(&$stack, $count, $op) {
		$a = array_splice($stack, -$count, $count);
		array_push($stack, array('node' => $a, 'op' => $op));
		return FindLeftTerminal($a);
	}
	/** @internal */
	function FindLeftTerminal(&$tokens, $start = 0) {
		for ($i = $start; $i < count($tokens); $i++) {
			if (array_key_exists('type', $tokens[$i]))	return $tokens[$i];
		}
	}
	/** @internal */
	function FindRightTerminal(&$tokens) {
		for ($i = count($tokens) - 1; $i >= 0; $i--) {
			if (array_key_exists('type', $tokens[$i]))	return $tokens[$i];
		}
	}
	
	/** @internal */
	function GetRankL($type) {
		global $rank_l;
		if (!array_key_exists($type, $rank_l)) throw new \InvalidArgumentException();
		return $rank_l[$type];
	}
	/** @internal */
	function GetRankR($type) {
		global $rank_r;
		if (!array_key_exists($type, $rank_r)) throw new \InvalidArgumentException();
		return $rank_r[$type];
	}
	/** @internal */
	function ParseSearchWords($wordsline) {
		
		$tokens = TokenizeSearchWords($wordsline);

		array_push($tokens, array('type'=>'$'));
		$stack = array(array('type' => '$'));

		$token_pos = 0;
		for ($i = 0; $i < 100; $i++) {
			$a = FindRightTerminal($stack);
			$b = FindLeftTerminal($tokens, $token_pos);
			if ($a['type'] == '$' && $b['type'] == '$') return $stack;
			
			$a_rank = GetRankL($a['type']);
			$b_rank = GetRankR($b['type']);
			if ($a_rank <= $b_rank) {
				array_push($stack, $b);
				$token_pos++;
			} elseif ($a_rank > $b_rank) {
				do {
					$lastToken = Reduce($stack);
					$lastToken_rank = GetRankR($lastToken['type']);
					$endStack = FindRightTerminal($stack);
					$endStack_rank = GetRankL($endStack['type']);
				} while($endStack_rank >= $lastToken_rank);
			} else {
				throw new \LogicException("this could not happen");
			}
		}
		throw new \InvalidArgumentException("too complex to parse");
	}
	/** @internal */
	function Reduce(&$stack) {
		global $syntax;

		for ($i = 0; $i < count($syntax); $i++) {
			if (LastMatch($stack, $syntax[$i]['form'])) {
				return $syntax[$i]['handler']($stack);
			}
		}
		throw new \InvalidArgumentException("syntax error");
	}
	/** @internal */
	function LastMatch($haystack, $needle) {
		$hpos = count($haystack) - 1;
		$npos = count($needle) - 1;
		if ($hpos < $npos) return false;
		
		for (;$npos >= 0; $npos--, $hpos--) {
			if (array_key_exists('node', $haystack[$hpos])) {
				if (!is_null($needle[$npos] ))	return false;
			} elseif ($haystack[$hpos]['type'] != $needle[$npos]) {
				return false;
			}
		}
		return true;
	}
	/** @internal */
	function TokenizeSearchWords($wordsline) {
		preg_match_all('/(?:(OR)|(-)|(?<!\\\\)"(?U)(.*)(?<!\\\\)"(?-U)|([^\s\(\)]+)|(\()|(\)))\s*/', $wordsline, $matches, PREG_SET_ORDER);
		
		$r = array();
		for ($i = 0; $i < count($matches); ++$i) {
			if ($matches[$i][1] != '') {
				$r[$i] = array('type' => 'OR');
			} elseif ($matches[$i][2] != '') {
				$r[$i] = array('type' => 'EXCLUDE');
			} elseif ($matches[$i][3] != '') {
				$w = $matches[$i][3];
				$w = preg_replace('/\\\\"/', '"', $w);
				$w = preg_replace('/\\\\\\\\/', '\\', $w);
				$r[$i] = array('type' => 'WORD', 'word' => $w, 'wordtype' => 'multi');
			} elseif ($matches[$i][4] != '') {
				$w = $matches[$i][4];
				$w = preg_replace('/\\\\"/', '"', $w);
				$w = preg_replace('/\\\\\\\\/', '\\', $w);
				$r[$i] = array('type' => 'WORD', 'word' => $w, 'wordtype' => 'single');
			} elseif ($matches[$i][5] != '') {
				$r[$i] = array('type' => 'LPAREN');
			} elseif ($matches[$i][6] != '') {
				$r[$i] = array('type' => 'RPAREN');
			}
		}
		for ($j = 0; $j < count($r); ++$j) {
			if ($j < count($r) - 1
				&& ($r[$j]['type'] == 'WORD' || $r[$j]['type'] == 'RPAREN')
				&& $r[$j+1]['type'] != 'OR' && $r[$j+1]['type'] != 'RPAREN' ) {
				array_splice($r, $j + 1, 0, array(array('type' => 'AND')));
			}
		}
//		echo '$r: ' . join(' ', array_map(function($a){return $a['type'];}, $r));
		
		return $r;
	}
}

?>