#!/usr/bin/php
<?php
declare(strict_types=1);
cli_set_process_title('bmw-token-manager.php');

/**
 * BMW OAuth token refresher
 * - Reads token.json
 * - Immediately refreshes the token; aborts on failure
 * - Keeps the token alive by refreshing before expiry
 * - Always writes an up-to-date token.json atomically
 */

const TOKEN_URL = 'https://customer.bmwgroup.com/gcdm/oauth/token';
const DEFAULT_TOKEN_FILE = 'token.json';
const MIN_REFRESH_MARGIN = 120; // seconds before expiry to refresh
const RETRY_MAX = 5;
const RETRY_BASE_DELAY = 3; // seconds
const HTTP_TIMEOUT = 20;

function parseArgs() : array
{
	$opts = getopt('t:m:', ['token-file:', 'refresh-margin:']);
	$token_file = $opts['t'] ?? $opts['token-file'] ?? DEFAULT_TOKEN_FILE;
	$refresh_margin = (int)($opts['m'] ?? $opts['refresh-margin'] ?? MIN_REFRESH_MARGIN);

	if ($token_file === '')
	{
		throw new InvalidArgumentException('Missing argument: t:token-file');
	}

	return [
		'token_file' => $token_file,
		'refresh_margin' => max(0, $refresh_margin),
	];
}

function readTokenFile(string $path) : array
{
	if (!is_file($path))
	{
		throw new RuntimeException("Token file not found: {$path}");
	}
	$json = file_get_contents($path);
	if ($json === false)
	{
		throw new RuntimeException("Failed to read token file: {$path}");
	}
	$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	if (!isset($data['refresh_token']))
	{
		throw new RuntimeException('token.json missing "refresh_token"');
	}
	return $data;
}

function writeTokenFile(string $path, array $data) : void
{
	$tmp = $path . '.tmp';
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false)
	{
		throw new RuntimeException('Failed to encode token JSON');
	}
	if (file_put_contents($tmp, $json, LOCK_EX) === false)
	{
		throw new RuntimeException("Failed to write temp token file: {$tmp}");
	}
	@chmod($tmp, 0600);
	if (!@rename($tmp, $path))
	{
		@unlink($tmp);
		throw new RuntimeException("Failed to atomically replace token file: {$path}");
	}
}

function postForm(string $url, array $fields) : array
{
	$ch = curl_init($url);
	if ($ch === false)
	{
		throw new RuntimeException('Failed to init curl');
	}

	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
		CURLOPT_HTTPHEADER => [
			'Accept: application/json',
			'Content-Type: application/x-www-form-urlencoded',
		],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_TIMEOUT => HTTP_TIMEOUT,
	]);

	$response = curl_exec($ch);
	if ($response === false)
	{
		$err = curl_error($ch);
		curl_close($ch);
		throw new RuntimeException("curl error: {$err}");
	}

	$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);

	$headers = substr($response, 0, $header_size);
	$body = substr($response, $header_size);

	return [$status, $headers, $body];
}

function refreshToken(array $old_token) : array
{
	[$status, $_h, $body] = postForm(TOKEN_URL, [
		'grant_type' => 'refresh_token',
		'refresh_token' => $old_token['refresh_token'],
		'client_id' => $old_token['client_id'],
	]);

	if ($status !== 200)
	{
		$err = @json_decode($body, true);
		$code = $err['error'] ?? 'http_error';
		$desc = $err['error_description'] ?? trim($body);
		throw new RuntimeException("Token refresh failed ({$status}): {$code}: {$desc}");
	}

	$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

	if (!isset($data['id_token'], $data['refresh_token']))
	{
		throw new RuntimeException('Token refresh response missing id_token or refresh_token');
	}

	$now = time();
	$expires_in = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
	$expires_at = $now + $expires_in;

	$data['fetched_at'] = $now;
	$data['expires_at'] = $expires_at;

	return $data;
}

function computeSecondsUntilRefresh(array $token, int $refresh_margin) : int
{
	$now = time();
	$expires_at = isset($token['expires_at']) ? (int)$token['expires_at'] : ($now + 3600);
	$delay = $expires_at - $now - $refresh_margin;
	return max(30, $delay);
}

function mergeTokenData(array $old, array $new) : array
{
	// Preserve fields the API might omit, like gcid
	$merged = $old;
	foreach ($new as $k => $v)
	{
		$merged[$k] = $v;
	}
	return $merged;
}

function loopKeepAlive(string $token_file, int $refresh_margin, array $token) : void
{
	// Immediate refresh already done by caller. Enter loop.
	while (true)
	{
		$sleep_seconds = computeSecondsUntilRefresh($token, $refresh_margin);
		fwrite(STDERR, sprintf("[info] sleeping %ds; refresh at %s\n",
			$sleep_seconds,
			date('c', time() + $sleep_seconds)
		));
		sleep($sleep_seconds);

		$attempt = 0;
		while (true)
		{
			try
			{
				$new_token = refreshToken($token);
				$token = mergeTokenData($token, $new_token);
				writeTokenFile($token_file, $token);
				fwrite(STDERR, sprintf("[info] refreshed; expires_at=%s\n", date('c', (int)$token['expires_at'])));
				break;
			}
			catch (Throwable $e)
			{
				$attempt++;
				if ($attempt > RETRY_MAX)
				{
					throw new RuntimeException("Permanent failure refreshing token after " . RETRY_MAX . " attempts: " . $e->getMessage(), 0, $e);
				}
				$backoff = (int)round(RETRY_BASE_DELAY * (2 ** ($attempt - 1)));
				fwrite(STDERR, "[warn] refresh failed (attempt {$attempt}/" . RETRY_MAX . "): {$e->getMessage()} — retrying in {$backoff}s\n");
				sleep($backoff);
			}
		}
	}
}

function main() : void
{
	$args = parseArgs();
	$token_file = $args['token_file'];
	$refresh_margin = $args['refresh_margin'];
	$token = readTokenFile($token_file);

	// Immediate refresh; abort on failure
	$new_token = refreshToken($token);
	$token = mergeTokenData($token, $new_token);
	writeTokenFile($token_file, $token);
	fwrite(STDERR, sprintf("[info] initial refresh ok; expires_at=%s\n", date('c', (int)$token['expires_at'])));

	loopKeepAlive($token_file, $refresh_margin, $token);
}

try
{
	main();
}
catch (Throwable $e)
{
	fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
	exit(1);
}
