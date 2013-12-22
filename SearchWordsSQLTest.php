<?php
require_once 'SearchWordsSQL.php';

function normalizeSQL(&$a) {
	$a['SQL'] = strtolower(preg_replace('/ +/', '', $a['SQL']));
	return $a;
}


/**
  * @backupGlobals disabled
  */
class SQLBuilderTest extends PHPUnit_Framework_TestCase {
	
	/**
	  *	@test
	  * @dataProvider providerReplicateDataType
	  */
	public function testReplicateDataType($a, $typename, $mul, $out) {
		$this->assertEquals($out, SearchWordsSQL\ReplicateDataType($a, $typename, $mul));
	}
	public function providerReplicateDataType() {
		return array(
			array( 
				array( 1, 2, 3 ),
				'integer',
				1,
				array( 'integer', 'integer', 'integer' )
			),

			array( 
				array( 1, 2, 3 ),
				'integer',
				2,
				array( 'integer', 'integer', 'integer', 'integer', 'integer', 'integer' )
			),

			array( 
				array(),
				'integer',
				1,
				array()
			),

		);
	}

	/**
	  *	@test
	  * @dataProvider providerSQLLikeValueCallback
	  */
	public function testSQLLikeValueCallback($value, $out) {
		$this->assertEquals($out, SearchWordsSQL\SQLLikeValueCallback($value));
	}
	public function providerSQLLikeValueCallback() {
		return array(
			array( 'test', 				'%test%'	),
			array( 'test%test', 		'%test\\%test%'	),
			array( 'test%%test',		'%test\\%\\%test%'	),
			array( 'test_test', 		'%test\\_test%'	),
			array( 'test*test', 		'%test%test%'	),
			array( 'test test', 		'%test test%'	),
			array( 'test\\test',		'%test\\\\test%'	),
			array( 'test\\\\test',		'%test\\\\\\\\test%'	),
			array( 'test%_%_\\\\test',	'%test\\%\\_\\%\\_\\\\\\\\test%'	),
			array( '', 					'%%'	),
		);
	}

	/**
	  *	@test
	  * @dataProvider providerBuild_1
	  */
	public function testBuild_1($wordsline, $out) {
		$o = new SearchWordsSQL\SQLBuilder("c = ?");
		$this->assertEquals(normalizeSQL($out), normalizeSQL($o->Build($wordsline)));
	}
	public function providerBuild_1() {
		return array(
			array( 'test', 	
				array(
					'SQL' => '(c = ?)',
					'value' => array('test'),
					'IBL' => 'test',
					'hit' => array('test'),
				)
			),

			array( '-test',
				array(
					'SQL' => '( not (c = ?))',
					'value' => array('test'),
					'IBL' => '-test',
					'hit' => array(),
				)
			),

			array( '--test',
				array(
					'SQL' => '( not (not (c = ?)))',
					'value' => array('test'),
					'IBL' => '-(-test)',
					'hit' => array(),
				)
			),

			array( '\\test',
				array(
					'SQL' => '(c = ?)',
					'value' => array('\\test'),
					'IBL' => 'test',
					'hit' => array('\\test'),
				)
			),

			array( '"test"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('test'),
					'IBL' => '"test"',
					'hit' => array('test'),
				)
			),

			array( '"test test"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('test test'),
					'IBL' => '"test test"',
					'hit' => array('test test'),
				)
			),

			array( '"-test"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('-test'),
					'IBL' => '"-test"',
					'hit' => array('-test'),
				)
			),

			array( '"test\\" test"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('test" test'),
					'IBL' => '"test test"',
					'hit' => array('test" test'),
				)
			),

			array( '"test\\"\\" test"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('test"" test'),
					'IBL' => '"test test"',
					'hit' => array('test"" test'),
				)
			),

			array( '\\\\"test\\"',
				array(
					'SQL' => '(c = ?)',
					'value' => array('\\"test"'),
					'IBL' => 'test',
					'hit' => array('\\"test"'),
				)
			),

			array( '\\"test\\"\\" test\\"',
				array(
					'SQL' => '((c = ?) and (c = ?))',
					'value' => array('"test""', 'test"'),
					'IBL' => '+test +test',
					'hit' => array('"test""', 'test"'),
				)
			),

			array( 'test1 test2',
				array(
					'SQL' => '((c = ?) and (c = ?))',
					'value' => array('test1', 'test2'),
					'IBL' => '+test1 +test2',
					'hit' => array('test1', 'test2'),
				)
			),

			array( ' test1  test2',
				array(
					'SQL' => '((c = ?) and (c = ?))',
					'value' => array('test1', 'test2'),
					'IBL' => '+test1 +test2',
					'hit' => array('test1', 'test2'),
				)
			),

			array( 'test1 OR test2',
				array(
					'SQL' => '((c = ?) or (c = ?))',
					'value' => array('test1', 'test2'),
					'IBL' => '(test1 test2)',
					'hit' => array('test1', 'test2'),
				)
			),

			array( 'test1 -test2',
				array(
					'SQL' => '((c = ?) and ( not (c = ?)))',
					'value' => array('test1', 'test2'),
					'IBL' => '+test1 -test2',
					'hit' => array('test1'),
				)
			),

			array( 'test1 OR -test2', 
				array(
					'SQL' => '((c = ?) or ( not (c = ?)))',
					'value' => array('test1', 'test2'),
					'IBL' => '(test1 -test2)',
					'hit' => array('test1'),
				)
			),

			array( 'test1 (test2 OR test3)', 
				array(
					'SQL' => '((c = ?) and (((c = ?) or (c = ?))))',
					'value' => array('test1', 'test2', 'test3'),
					'IBL' => '+test1 +(((test2 test3)))',
					'hit' => array('test1', 'test2', 'test3'),
				)
			),

			array( '(test1 test2) OR test3', 
				array(
					'SQL' => '((((c = ?) and (c = ?))) or (c = ?))',
					'value' => array('test1', 'test2', 'test3'),
					'IBL' => '((+test1 +test2) test3)',
					'hit' => array('test1', 'test2', 'test3'),
				)
			),

			array( '-(test1 test2) OR test3 test4', 
				array(
					'SQL' => '(((not(((c=?) and (c=?)))) or (c=?)) and (c=?))',
					'value' => array('test1', 'test2', 'test3', 'test4'),
					'IBL' => '+(-((+test1 +test2)) test3) +test4',
					'hit' => array('test3', 'test4'),
				)
			),

			array( '$test', 
				array(
					'SQL' => '(c = ?)',
					'value' => array('$test'),
					'IBL' => '$test',
					'hit' => array('$test'),
				)
			),

		);
	}


	/**
	  *	@test
	  * @dataProvider providerBuild_2
	  */
	public function testBuild_2($wordsline, $out) {
		$o = new SearchWordsSQL\SQLBuilder(
			"c = ?",
			function($v) { return strtoupper($v); }
		);
		$this->assertEquals(
			normalizeSQL($out),
			normalizeSQL( $o->Build($wordsline))
		);
	}
	public function providerBuild_2() {
		return array(
			array( 'test', 	
				array(
					'SQL' => '(c = ?)',
					'value' => array('TEST'),
					'IBL' => 'test',
					'hit' => array('test'),
				)
			),
		);
	}

	/**
	  *	@test
	  * @dataProvider providerBuild_3
	  */
	public function testBuild_3($wordsline, $out) {
		$o = new SearchWordsSQL\SQLBuilder(
			"c = ?",
			function($v) { return array($v, strtoupper($v)); }
		);
		$this->assertEquals(
			normalizeSQL($out),
			normalizeSQL( $o->Build($wordsline))
		);
	}
	public function providerBuild_3() {
		return array(
			array( 'test', 	
				array(
					'SQL' => '(c = ?)',
					'value' => array(array('test', 'TEST')),
					'IBL' => 'test',
					'hit' => array('test'),
				)
			),
		);
	}


	/**
	  *	@test
	  * @expectedException \InvalidArgumentException
	  */
	public function testBuild_stars() {
		$o = new SearchWordsSQL\SQLBuilder(
			"c = ?"
		);
		$o->Build("*****");
	}



	/**
	  *	@test
	  * @dataProvider providerIsComplement
	  */
	public function testIsComplement($wordsline, $out) {
		$o = new SearchWordsSQL\SQLBuilder("c = ?");
		$o->Build($wordsline);
		$this->assertEquals($out, $o->isComplement());
	}
	public function providerIsComplement() {
		return array(
			array('test',							false),
			array('test1 OR test2',					false),
			array('test1 test2',					false),
			array('-test',							true),
			array('-test1 test2',					false),
			array('-test1 -test2',					true),
			array('-test1 OR -test2',				true),
			array('-test1 OR -test2 OR -test3',		true),
			array('-test1 OR (test2 test3)',		true),
			array('-test1 (test2 OR -test3)',		true),
		);
	}

}
?>