<?php
/**
 * Test vectors: values as this package writes them, with fixed keys.
 *
 * They must stay readable by every later version. Never change or remove a
 * vector - add new ones for new formats. If a test with one of these fails,
 * a change of the package makes existing data of websites unreadable.
 *
 * Generated with version 4.1.0. Each vector holds the method, the stored
 * key (as it would be in wp-config.php), the configuration of the method,
 * the context, the encrypted value and the plain text. "requires" names a
 * function (or "aes256gcm") the server needs to decrypt it.
 *
 * Not included: Sodium with AEGIS-256 (algorithm 1), which can only be
 * generated with libsodium 1.0.19 or newer (PHP 8.4+). Add a vector once
 * this package is tested on such a server.
 *
 * @package crypt-for-wordpress
 */

return array(
	'openssl-aes-256-gcm' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array(),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'OHZvNms5SFl2S01TaHZiaTprSkpJK25nc2RlWkRsMVk3LytDcnNnPT06ZlFTNzZyZTJPb2NENjRBS3JqZEFoY2VSZWN5bHFhV3ZXR3loSVZsTlUvQ1JObHpzZXl0blk0RXNwZVVBMk16Z3BOS0ZiQTA9',
	),
	'openssl-aes-256-gcm-context' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array(),
		'context'  => 'api_secret',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'QUdTLzdUanZyajZIT0JULzptZnFudnpUamFjRlNnN1BtWFVYRk5nPT06YTFQa01YMUszcHdtb0dyWnBUSTNPa3YzbG9aM3d5SmdremowSlA3dk05QkRxbXprMTlyc2xjUU1WS0pYd253dXB2Nms0dzA9',
	),
	'openssl-aes-256-gcm-pbkdf2' => array(
		'method'   => 'openssl',
		'key'      => '30kr83ojXNpXtpiVo8+Cfw5GQl4s6D6t2XAffLn1cFM=',
		'config'   => array( 'hash_type' => 'hash_pbkdf2' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'VEdHVll3Rm1PZkEzSUwwWDpKOWI4S0luNzVFcHhxU3ZyeDh0UkRnPT06eDNIdmFYbGxHRHI0UVhaT1U3MnNvNVR6UE9ydjNhYWtVOEM5M0lLVXhTQ1A3NktIVDFVZGN4cDB2dWlCRHFFOHJNQWJxZ0k9',
	),
	'openssl-aes-256-gcm-pbkdf2-context' => array(
		'method'   => 'openssl',
		'key'      => '30kr83ojXNpXtpiVo8+Cfw5GQl4s6D6t2XAffLn1cFM=',
		'config'   => array( 'hash_type' => 'hash_pbkdf2' ),
		'context'  => 'api_secret',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'QWVoZzhuTnVQSzNScmIxVDpQNlBqRkRnUXhRNEUvVkovaG9WOU13PT06L20rVEtvWXlUaWNSblZBNG9GQjVhcmRoUzJ2N0pKWmNtdjVVZSt0MHJLYnNuS1J2WnBOK25SZ2JySUxaOUpSU2xGd0pNSW89',
	),
	'openssl-chacha20-poly1305' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'chacha20-poly1305' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'Unpod1NjM1RINmdEbXBDWTo2TDFVSnY5b1lzd1UrRFBrSnpRclV3PT06YUgwZWZQYm9ZcGZUNCt2Q1pkVTdpbzZOZTFPbDJQYnV3SURCNlRyZTRUdERyTktzYW54ajhKeUtOQTYyTWlVWnkxVFBoR1k9',
	),
	'openssl-chacha20-poly1305-context' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'chacha20-poly1305' ),
		'context'  => 'api_secret',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'ZFY5TXRMNjZxbGRYT1p3dzppWURDSjdSVEIzOHdJelpTTXA2aFhBPT06dER4a0hsdFhydm5tTVhNOFdNcnJ4Z0ZiZmtZRmIwRmd2SnRuYXFMTnFxNUJHeWJHOHB1T3QrWm82SWIyQS8zY2htNFB2QnM9',
	),
	'openssl-aes-256-cbc-v2' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'aes-256-cbc' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'djI6Z3g2MEhvMmxmUXkzcEZ3YmxheDYxUT09OjVzRVR5N3dyS1o1cFhsY1NLb3ZmbWlEalpHd3BySE5Gd3VWeTkzbTB6VGUwTzdiSUZwS3J6bnZkUEw4ZXgxMndBdjR3Zm05cU1rY1pmOVFyVjVxOTg4cG9JNlNmbGlpQngyN2JXSzdaOWtPMXprK1BENUErZ09teFIwd2M3Rlo3',
	),
	'openssl-aes-256-cbc-v2-context' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'aes-256-cbc' ),
		'context'  => 'api_secret',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'djI6T3ZabFNMTElyV2Z5QldHRzRNeFdKQT09OlFTeVJDVFpKbnBIL0RhcDNYMU9HVEQrOVZlekMrS2s2Tnpnbk52cWgxOXA4QkhFbFlzU09DK1IwTk9sZUU1TkY0N2JjSU1DK3czL3lOdC9lUThYRlhuc3JwcmQ0U3J5RHlCYjQ3aEhMa2pqbElHbDdDdlEybDIyblN1eGJYeU1O',
	),
	'openssl-aes-256-cbc-legacy-hkdf' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'aes-256-cbc' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'Y0FoZ2FtQWlWVi90UUFIZ0VBVWVpdz09OlcrcUR4NGp1RHBMbVhPbHltRmFhdHdlbXN5M0pOR2pOL01Za1dVd3QxeWpOWUFKaC91US9hSnRMU3NTdTR5WStvOGJyZlk2NVV3TzhXWjBYWmZGeTJLWUpGbUplbUxIanNJajBKRy8zQnkzcWJTZGp4cks5SXRPUWgwaThqRVdh',
	),
	'openssl-aes-256-cbc-legacy-decoded-key' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'aes-256-cbc' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'S1lJcmJuSGpLV3BnOTlQREF5SnhGdz09OjNqOEx3VG9pZlBnY1RWbVkvVWZLdjB4RitaNmZDNzcwY0twZWQreWl4d3hLcEkvTUtKYXFuZXFsQXNGQWpqTUFuL2RKUzdhTnRCN0gxV05VSllrbzRpb05RNzFybktXRGhLNGRIclBseStNb0lwN01DK043NkdURkpFMHJsNFlr',
	),
	'openssl-aes-256-cbc-legacy-raw-key' => array(
		'method'   => 'openssl',
		'key'      => 'afb7e4875f355e8e74e021abd9cde65b7086b5a36eacbfcd1a9228903352fce6',
		'config'   => array( 'cipher_algorithm' => 'aes-256-cbc' ),
		'context'  => '',
		'requires' => '',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'a1ZtNUVtL3JYYUVxQmIzTmNxUHZUQT09Ok81NHZ6bEdOcXppNWR5QnVWVjJxYXBBdEorQ1laWTZ4MCtMQ3NOZm9LRDdESy9RMnpUZ0JNcmhMb0VIUGJsbTNkVkxVSXBsbTYzRWR4S29nVUhiNUJaMktRUlA5bVNnai9XSUN0MkR3aUFrZk5yeit6dDBHOS9sN0RyQ0VoTlVw',
	),
	'sodium-aes256gcm' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => '',
		'requires' => 'aes256gcm',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'Aoha61HL2ZdeUglvxaxGCJQNM6KuZ9fN0bC4rw//IHgFtj/LBH8IHGXExJnA2QJZD58spsmj+ezxNHDpBB3kS8S0X3Uf1rn8fhC6koVxbA+xmQ==',
	),
	'sodium-aes256gcm-context' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => 'api_secret',
		'requires' => 'aes256gcm',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'ArzivkN89S9+xB01rA5y6/zg1SmByAHtA24SZUDAs3e+RVnT15boZvMZCR9K/N9kNdZ6aLxLyV0L6qomRefP/IyQJUzXVvpnUimV1pJocCLFSw==',
	),
	'sodium-xchacha20poly1305' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => '',
		'requires' => 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'A0dpghS31soNhAuEF/ilzgC1kotQjTDOOl7uWRM/pOUw5nY4JXlbmwE239okOxc0LAQsqYIf8jYsx8DW2Lm/nR9NNmc1Yv0SJgIFai9M/k4jnijFVrI6XUYKWj+Osw==',
	),
	'sodium-xchacha20poly1305-context' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => 'api_secret',
		'requires' => 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'A7u0X7LgUWUI9X6ipJhhMXeAD5I5YYStAoJdSwe+0Xf9roCXNpG0m1nL+gHrYve+LNXXx9G/5ZILaZlNSjwv1PMvhuL+vULpeSdPGCztZAm/iFp5W2FDc5vZ+SyLBA==',
	),
	'sodium-chacha20poly1305' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => '',
		'requires' => 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'BHESzWD0MKjb9rhJEqWYhuAK3FGgsNqP9BcZ9mO8b8zzU8xvVSXttWGCTyle9CDc/8ZTxRrneGdyD4jKNDpWNqhOcXJCwpnsgCwr0iE/gR+UVg==',
	),
	'sodium-chacha20poly1305-context' => array(
		'method'   => 'sodium',
		'key'      => 'pSUuDLNl1QH7DfvLCDs7JVEpa/2c8WbQ4siQppkQ/+U=',
		'config'   => array(),
		'context'  => 'api_secret',
		'requires' => 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt',
		'plain'    => "Hällo Wörld – 🔐 test vector\nwith a second line",
		'value'    => 'BM2swiIazilsX0lAzmpIXKsJpR8wVQMjOSRFcF47fApg6GEliPH52bOhq0iNqaxeT5V7cF2ZEjLxMNSNkZaB+3KuYYYwew+/HX80hj9m08FEVQ==',
	),
);
