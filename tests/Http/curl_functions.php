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


	public static function reset(): void
	{
		self::$response = null;
		self::$error = null;
		self::$errno = 0;
		self::$headerSize = 0;
		self::$httpCode = 200;
		self::$contentType = null;
	}
}


function curl_init()
{
	return \curl_init();
}


function curl_setopt($ch, $option, $value)
{
	return true;
}


function curl_exec($ch)
{
	return CurlMocker::$response;
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
