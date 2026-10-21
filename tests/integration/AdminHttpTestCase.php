<?php namespace ProcessWire\SelfHostedVideoTests;

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Base of the tests that meet the admin as a signed-in user.
 *
 * They make users of their own with a password made for the test (a superuser and a user without rights), sign in as
 * them over HTTP and remove them at the end.
 */
abstract class AdminHttpTestCase extends HttpTestCase {

	const userName = 'shvtest-admin';
	const plainName = 'shvtest-plain';

	protected static $password = '';
	protected static $cookies = array(self::userName => array(), self::plainName => array());

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$wire = \ProcessWire\wire();
		self::removeUsers();
		self::$cookies = array(self::userName => array(), self::plainName => array());
		self::$password = bin2hex(random_bytes(10)) . 'Aa1!';
		$super = $wire->users->add(self::userName);
		$super->pass = self::$password;
		$super->addRole('superuser');
		$super->save();
		$plain = $wire->users->add(self::plainName);
		$plain->pass = self::$password;
		$plain->save();
	}

	public static function tearDownAfterClass(): void {
		self::removeUsers();
		parent::tearDownAfterClass();
	}

	protected static function removeUsers() {
		$users = \ProcessWire\wire('users');
		foreach(array(self::userName, self::plainName) as $name) {
			$user = $users->get('name=' . $name);
			if($user && $user->id) $users->delete($user, true);
		}
	}

	protected function adminPath($path = '') {
		// (by the name of the page of the admin: a script from the command line may not know the folder of the website)
		$admin = \ProcessWire\wire('pages')->get(\ProcessWire\wire('config')->adminRootPageID);
		return '/' . $admin->name . '/' . ltrim($path, '/');
	}

	/** Header line for the cookies of a user */
	protected function cookieHeader($who) {
		$parts = array();
		foreach(self::$cookies[$who] as $name => $value) $parts[] = "$name=$value";
		return implode('; ', $parts);
	}

	protected function remember($who, array $cookies) {
		foreach($cookies as $line) {
			if(preg_match('~^([^=;\s]+)=([^;]*)~', $line, $m)) self::$cookies[$who][$m[1]] = $m[2];
		}
	}

	/** Ask the website as a user who has signed in (signs in first time) */
	protected function asUser($who, $path, $method = 'GET', $body = null) {
		if(!isset(self::$cookies[$who]['signedin'])) $this->signIn($who);
		$headers = array('Cookie' => $this->cookieHeader($who));
		if($body !== null) $headers['Content-Type'] = 'application/x-www-form-urlencoded';
		$r = $this->http($method, $path, $headers, $body);
		$this->remember($who, $r['cookies']);
		return $r;
	}

	protected function signIn($who) {
		self::$cookies[$who] = array();
		$login = $this->http('GET', $this->adminPath());
		$this->remember($who, $login['cookies']);
		if(!preg_match('~name=[\'"]login_name[\'"]~', $login['body'])) {
			$this->markTestSkipped('The sign-in page of the admin is not where ProcessWire puts it (' . $login['url'] . '): ' . $login['status']);
		}
		$fields = array('login_name' => $who, 'login_pass' => self::$password, 'login_submit' => '1');
		// the token against forged forms
		if(preg_match('~<input[^>]*type=[\'"]hidden[\'"][^>]*id=[\'"]_post_token[\'"][^>]*name=[\'"]([^\'"]+)[\'"][^>]*value=[\'"]([^\'"]+)[\'"]~', $login['body'], $m)) $fields[$m[1]] = $m[2];
		else if(preg_match('~<input[^>]*name=[\'"](TOKEN[^\'"]+)[\'"][^>]*value=[\'"]([^\'"]+)[\'"]~', $login['body'], $m)) $fields[$m[1]] = $m[2];
		$r = $this->http('POST', $this->adminPath(), array('Cookie' => $this->cookieHeader($who), 'Content-Type' => 'application/x-www-form-urlencoded'), http_build_query($fields));
		$this->remember($who, $r['cookies']);
		self::$cookies[$who]['signedin'] = '1';
		$check = $this->http('GET', $this->adminPath('page/'), array('Cookie' => $this->cookieHeader($who)));
		if(!preg_match('~id=[\'"]pw-masthead|pw-logout|/logout/~i', $check['body'])) {
			$this->markTestSkipped('Could not sign in as ' . $who . ' over HTTP (status ' . $r['status'] . ', then ' . $check['status'] . '): the sign-in may be changed by the website.');
		}
	}

	protected function assertMatchesPattern($pattern, $text, $message = '') {
		$this->assertTrue(preg_match($pattern, $text) === 1, $message . ' (' . $pattern . ')');
	}

	protected function inDefault($fn) {
		$languages = \ProcessWire\wire('languages');
		if(!$languages) return $fn();
		$user = \ProcessWire\wire('user');
		$was = $user->language;
		$user->language = $languages->getDefault();
		try {
			return $fn();
		} finally {
			$user->language = $was;
		}
	}
}
