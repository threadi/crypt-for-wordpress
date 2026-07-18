<?php
/**
 * Test Helper::sanitize_for_php_comment().
 *
 * Regression test for the comment-injection fix: dynamic values (plugin
 * name, author, slug, ...) embedded into generated PHP files (wp-config.php,
 * the MU-plugin header, custom key files) must never be able to break out
 * of the comment they are placed in.
 *
 * @package crypt-for-wordpress
 */

namespace CryptForWordPress\Tests\Unit;

use CryptForWordPress\Tests\CryptForWordPressTests;

/**
 * Object to test the comment-injection-safe sanitizing helper.
 */
class HelperSanitizeForPhpComment extends CryptForWordPressTests {

	/**
	 * A newline must not survive, so it can never terminate a "// ..." line
	 * comment early and let a following line be interpreted as PHP code.
	 *
	 * @return void
	 */
	public function test_strips_newlines(): void {
		$input = "Evil Plugin\r\n?php system(\$_GET['cmd']); ?";

		$this->assertStringNotContainsString( "\n", \CryptForWordPress\Helper::sanitize_for_php_comment( $input ) );
		$this->assertStringNotContainsString( "\r", \CryptForWordPress\Helper::sanitize_for_php_comment( $input ) );
	}

	/**
	 * Any other ASCII control character must be stripped too, not just
	 * \r and \n.
	 *
	 * @return void
	 */
	public function test_strips_other_control_characters(): void {
		$input    = "Evil\x00Plugin\x1FName\x7F";
		$sanitized = \CryptForWordPress\Helper::sanitize_for_php_comment( $input );

		$this->assertSame( 'EvilPluginName', $sanitized );
	}

	/**
	 * A multiline comment sequence must not survive as-is, so it can never close a
	 * "/** .. *​/" doc comment early and let the rest be interpreted as PHP code.
	 *
	 * @return void
	 */
	public function test_breaks_up_comment_terminator(): void {
		$input = 'Evil Plugin */ system($_GET[cmd]); /*';

		$this->assertStringNotContainsString( '*/', \CryptForWordPress\Helper::sanitize_for_php_comment( $input ) );
	}

	/**
	 * A combined attack string (newline AND comment terminator in one value)
	 * must be neutralized on both fronts at once.
	 *
	 * @return void
	 */
	public function test_neutralizes_combined_attack(): void {
		$input     = "Evil Plugin */\r\nsystem(\$_GET['cmd']);\r\n/*";
		$sanitized = \CryptForWordPress\Helper::sanitize_for_php_comment( $input );

		$this->assertStringNotContainsString( '*/', $sanitized );
		$this->assertStringNotContainsString( "\n", $sanitized );
		$this->assertStringNotContainsString( "\r", $sanitized );
	}

	/**
	 * A normal, harmless plugin name must survive completely unchanged, so
	 * this sanitizing does not degrade the normal, non-malicious case.
	 *
	 * @return void
	 */
	public function test_leaves_normal_values_untouched(): void {
		$this->assertSame( 'My Cool Plugin', \CryptForWordPress\Helper::sanitize_for_php_comment( 'My Cool Plugin' ) );
		$this->assertSame( 'Jane Doe (https://example.com)', \CryptForWordPress\Helper::sanitize_for_php_comment( 'Jane Doe (https://example.com)' ) );
	}

	/**
	 * An empty string must stay an empty string.
	 *
	 * @return void
	 */
	public function test_empty_string_stays_empty(): void {
		$this->assertSame( '', \CryptForWordPress\Helper::sanitize_for_php_comment( '' ) );
	}
}
