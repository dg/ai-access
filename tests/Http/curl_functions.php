<?php declare(strict_types=1);

namespace AIAccess\Http;


class CurlMocker
{
	public static $response;
	public static $error;
	public static $errno = 0;
	public static $headerSize = 0;
	public static $httpCode = 200;
	public static $contentType;
	public static array $options = [];

	/** streaming: raw header lines and body chunks fed into the curl callbacks */
	public static array $headerLines = [];
	public static array $streamChunks = [];


	public static function reset(): void
	{
		self::$response = null;
		self::$error = null;
		self::$errno = 0;
		self::$headerSize = 0;
		self::$httpCode = 200;
		self::$contentType = null;
		self::$options = [];
		self::$headerLines = [];
		self::$streamChunks = [];
	}
}


function curl_init()
{
	return \curl_init();
}


function curl_setopt($ch, $option, $value)
{
	CurlMocker::$options[$option] = $value;
	return true;
}


function curl_exec($ch)
{
	// with a write callback set, behave like a real transfer: headers first, then the body
	// in pieces, aborting when the callback does not consume the whole chunk
	$write = CurlMocker::$options[CURLOPT_WRITEFUNCTION] ?? null;
	if ($write === null) {
		return CurlMocker::$response;
	}

	if ($header = CurlMocker::$options[CURLOPT_HEADERFUNCTION] ?? null) {
		foreach (CurlMocker::$headerLines as $line) {
			$header($ch, $line);
		}
	}
	foreach (CurlMocker::$streamChunks as $chunk) {
		if ($write($ch, $chunk) !== strlen($chunk)) {
			CurlMocker::$errno = CURLE_WRITE_ERROR;
			CurlMocker::$error = 'Failure writing output to destination';
			return false;
		}
	}
	// $response === false simulates a transport failure after the delivered chunks
	return CurlMocker::$response ?? true;
}


function curl_errno($ch)
{
	return CurlMocker::$errno;
}


function curl_error($ch)
{
	return CurlMocker::$error;
}


function curl_getinfo($ch, $option = null)
{
	if ($option === CURLINFO_HTTP_CODE) {
		return CurlMocker::$httpCode;
	}
	if ($option === CURLINFO_HEADER_SIZE) {
		return CurlMocker::$headerSize;
	}
	if ($option === CURLINFO_CONTENT_TYPE) {
		return CurlMocker::$contentType;
	}
	return null;
}
