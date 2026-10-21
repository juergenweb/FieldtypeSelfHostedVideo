<?php namespace ProcessWire\SelfHostedVideoTests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ProcessWire\SelfHostedVideoEmbed as E;

/**
 * Embedding of videos on other websites: lists of websites, and which request is let through
 */
class EmbedTest extends TestCase {

	public static function lists() {
		return array(
			'one per line' => array("example.com\nother.org", array('example.com', 'other.org')),
			'commas and semicolons' => array('a.com, b.com;c.com', array('a.com', 'b.com', 'c.com')),
			'addresses' => array('https://www.example.com/page?x=1#top', array('example.com')),
			'with a port' => array('http://example.com:8080/', array('example.com')),
			'wildcard and www' => array("*.example.com\nwww.other.org", array('example.com', 'other.org')),
			'capitals' => array('EXAMPLE.COM', array('example.com')),
			'repeated' => array('a.com a.com https://a.com', array('a.com')),
			'a dot at the end' => array('example.com.', array('example.com')),
			'rubbish is skipped' => array("good.com\n<script>\nbad_host.com\n-x.com\n.. \nfine.org", array('good.com', 'fine.org')),
			'blank' => array("  \n ", array()),
		);
	}

	#[DataProvider('lists')]
	public function testParseHosts($text, $expected) {
		$this->assertSame($expected, E::parseHosts($text));
	}

	public function testHostListedIncludesSubdomains() {
		$list = array('example.com');
		$this->assertTrue(E::hostListed('example.com', $list));
		$this->assertTrue(E::hostListed('blog.example.com', $list));
		$this->assertTrue(E::hostListed('a.b.example.com', $list));
		$this->assertFalse(E::hostListed('notexample.com', $list), 'only whole labels');
		$this->assertFalse(E::hostListed('example.com.evil.net', $list));
		$this->assertFalse(E::hostListed('example.org', $list));
		$this->assertFalse(E::hostListed('example.com', array()));
	}

	protected function decide(array $server, array $allowed = array()) {
		return E::decide($server, array('my.site'), $allowed);
	}

	public function testFetchMetadataSameOriginAndSameSiteAndTypedInPass() {
		foreach(array('same-origin', 'same-site', 'none') as $site) {
			$this->assertTrue($this->decide(array('HTTP_SEC_FETCH_SITE' => $site)), $site);
		}
	}

	public function testCrossSiteIsRefusedUnlessListed() {
		$cross = array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'video', 'HTTP_ORIGIN' => 'https://other.net');
		$this->assertFalse($this->decide($cross));
		$this->assertTrue($this->decide($cross, array('other.net')));
		$this->assertTrue($this->decide(array_merge($cross, array('HTTP_ORIGIN' => 'https://sub.other.net')), array('other.net')));
		$this->assertFalse($this->decide(array_merge($cross, array('HTTP_ORIGIN' => ''))), 'no origin: no way to tell');
	}

	public function testCrossSiteLinkToTheFileIsNoEmbedding() {
		$this->assertTrue($this->decide(array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'document')));
	}

	public function testCrossSiteFallsBackToTheRefererWhenThereIsNoOrigin() {
		$server = array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'video', 'HTTP_REFERER' => 'https://other.net/page');
		$this->assertTrue($this->decide($server, array('other.net')));
		$this->assertFalse($this->decide($server));
	}

	public function testOriginNullIsNoOrigin() {
		$this->assertFalse($this->decide(array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'video', 'HTTP_ORIGIN' => 'null')));
	}

	public function testOlderBrowsersAreJudgedByTheReferer() {
		$this->assertTrue($this->decide(array()), 'no referer: let through');
		$this->assertTrue($this->decide(array('HTTP_REFERER' => 'https://my.site/page')), 'the site itself');
		$this->assertTrue($this->decide(array('HTTP_REFERER' => 'https://sub.my.site/page')), 'a subdomain of the site');
		$this->assertFalse($this->decide(array('HTTP_REFERER' => 'https://other.net/page')));
		$this->assertTrue($this->decide(array('HTTP_REFERER' => 'https://other.net/page'), array('other.net')));
		$this->assertFalse($this->decide(array('HTTP_REFERER' => 'https://my.site.evil.net/')));
	}

	public function testFetchMetadataIsCaseInsensitive() {
		$this->assertTrue($this->decide(array('HTTP_SEC_FETCH_SITE' => ' Same-Origin ')));
	}

	public function testWithoutRulesTakesOurBlockOutAndKeepsTheRest() {
		$ours = E::markerStart . "\nRewriteRule ^x$ - [F]\n" . E::markerEnd;
		$content = "# before\nRewriteEngine On\n\n$ours\n\n# after\nOptions -Indexes\n";
		$out = E::withoutRules($content);
		$this->assertStringNotContainsString('RewriteRule ^x$', $out);
		$this->assertStringNotContainsString('BEGIN FieldtypeSelfHostedVideo', $out);
		$this->assertStringContainsString("# before\nRewriteEngine On", $out);
		$this->assertStringContainsString("# after\nOptions -Indexes", $out);
		$this->assertSame($out, E::withoutRules($out), 'nothing more to take out');
	}

	public function testWithoutRulesOfAFileThatHoldsOnlyOurBlockIsBlank() {
		$this->assertSame('', E::withoutRules(E::markerStart . "\nRewriteRule ^x$ - [F]\n" . E::markerEnd . "\n"));
	}

	public function testWithoutRulesLeavesOtherFilesAsTheyAre() {
		$content = "RewriteEngine On\n# one\n";
		$this->assertSame($content, E::withoutRules($content));
	}

	public function testWithoutRulesAlsoTakesOutTheBlockOfTheOldModuleName() {
		$old = "# BEGIN FieldtypeVideoFile: embedding on other websites\nRewriteRule ^x$ - [F]\n# END FieldtypeVideoFile\n";
		$this->assertSame("Options -Indexes\n", E::withoutRules("Options -Indexes\n" . $old));
	}
}
