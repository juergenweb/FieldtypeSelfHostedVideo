<?php
/**
 * Runs the unit tests of the module with the stand-in of PHPUnit (or with PHPUnit, if it is installed: then use
 * vendor/bin/phpunit -c tests/phpunit.xml.dist instead).
 *
 *   php tests/run.php [filter]      filter: part of a class or test name
 *
 * ProcessWire is found as bootstrap.php says (PW_ROOT).
 */
require __DIR__ . '/bootstrap.php';

$filter = isset($argv[1]) ? $argv[1] : '';
$before = get_declared_classes();
$files = array_merge(glob(__DIR__ . '/unit/*Test.php'), glob(__DIR__ . '/integration/*Test.php'));
sort($files);
foreach($files as $file) require_once $file;
$classes = array_values(array_filter(array_diff(get_declared_classes(), $before), function($c) {
	return is_subclass_of($c, 'PHPUnit\Framework\TestCase') && !(new ReflectionClass($c))->isAbstract();
}));

$pass = 0; $fail = 0; $skipped = 0; $failures = array(); $started = microtime(true);

foreach($classes as $class) {
	$short = substr(strrchr('\\' . $class, '\\'), 1);
	$shown = false;
	$classSkip = null;
	try {
		$class::setUpBeforeClass();
	} catch(PHPUnit\Framework\SkippedTestError $e) {
		$classSkip = $e->getMessage();
	} catch(Throwable $e) {
		echo "\n== $short\n  FAIL setUpBeforeClass — " . $e->getMessage() . ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
		$fail++; $failures[] = "$short::setUpBeforeClass";
		try { $class::tearDownAfterClass(); } catch(Throwable $ignore) {}
		continue;
	}
	foreach((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if(strpos($method->name, 'test') !== 0 || $method->class !== $class && !is_subclass_of($class, $method->class)) continue;
		$runs = array(array('', array()));
		foreach($method->getAttributes('PHPUnit\Framework\Attributes\DataProvider') as $attribute) {
			$runs = array();
			$provider = $attribute->newInstance()->method;
			foreach((new $class($method->name))->$provider() as $key => $args) $runs[] = array(is_int($key) ? '#' . $key : $key, (array) $args);
		}
		foreach($runs as $run) {
			list($label, $args) = $run;
			$name = $method->name . ($label !== '' ? " [$label]" : '');
			if(strlen($filter) && stripos("$short $name", $filter) === false) continue;
			if(!$shown) { echo "\n== $short\n"; $shown = true; }
			if($classSkip !== null) { $skipped++; echo "  skip $name — $classSkip\n"; continue; }
			$test = new $class($method->name);
			try {
				$test->_setUp();
				try {
					$method->invokeArgs($test, $args);
					if($test->_expected()) throw new PHPUnit\Framework\AssertionFailedError('expected ' . $test->_expected() . ' to be thrown');
				} catch(PHPUnit\Framework\SkippedTestError $e) {
					$skipped++;
					echo "  skip $name — " . $e->getMessage() . "\n";
					$test->_tearDown();
					continue;
				} catch(PHPUnit\Framework\AssertionFailedError $e) {
					throw $e;
				} catch(Throwable $e) {
					$expected = $test->_expected();
					if(!$expected || !($e instanceof $expected)) throw $e;
				}
				$test->_tearDown();
				$pass++;
				echo "  ok   $name\n";
			} catch(Throwable $e) {
				try { $test->_tearDown(); } catch(Throwable $ignore) {}
				$fail++;
				$failures[] = "$short::$name";
				echo "  FAIL $name — " . $e->getMessage() . (($e instanceof PHPUnit\Framework\AssertionFailedError) ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')') . "\n";
			}
		}
	}
	try { $class::tearDownAfterClass(); } catch(Throwable $e) { echo "  FAIL tearDownAfterClass — " . $e->getMessage() . "\n"; $fail++; $failures[] = "$short::tearDownAfterClass"; }
}

printf("\n%d passed, %d failed%s (%.2f s)\n", $pass, $fail, $skipped ? ", $skipped skipped" : '', microtime(true) - $started);
foreach($failures as $f) echo "  FAILED: $f\n";
exit($fail ? 1 : 0);
