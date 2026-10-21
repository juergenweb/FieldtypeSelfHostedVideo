<?php namespace ProcessWire\SelfHostedVideoTests;

/**
 * Calls protected methods of the module's classes, for the few small helpers that are not public
 */
class Reach {
	public static function call($objectOrClass, $method, ...$args) {
		$m = new \ReflectionMethod($objectOrClass, $method);
		return $m->invokeArgs(is_object($objectOrClass) ? $objectOrClass : null, $args);
	}
	public static function module() {
		return \ProcessWire\wire('modules')->get('FieldtypeSelfHostedVideo');
	}
}
