<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function function_exists;
use function strlen;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_RAW;

final class SnappyCompressor implements Compressor{
	use SingletonTrait;

	public const DEFAULT_THRESHOLD = 256;
	public const SNAPPY_MAX_SIZE = 1024;

	private static function make() : self{
		return new self(self::DEFAULT_THRESHOLD);
	}

	public function __construct(
		private ?int $minCompressionSize
	){}

	public function getCompressionThreshold() : ?int{
		return $this->minCompressionSize;
	}

	public function decompress(string $payload) : string{
		if(function_exists('snappy_uncompress')){
			$result = @snappy_uncompress($payload);
			if($result === false){
				throw new DecompressionException("Failed to decompress snappy data");
			}
			return $result;
		}
		throw new DecompressionException("Snappy extension not available");
	}

	public function compress(string $payload) : string{
		if(strlen($payload) < ($this->minCompressionSize ?? PHP_INT_MAX)){
			return $payload;
		}

		if(function_exists('snappy_compress')){
			$result = snappy_compress($payload);
			if($result === false){
				return Utils::assumeNotFalse(zlib_encode($payload, ZLIB_ENCODING_RAW, 1), "ZLIB fallback compression failed");
			}
			return $result;
		}

		return Utils::assumeNotFalse(zlib_encode($payload, ZLIB_ENCODING_RAW, 1), "ZLIB fallback compression failed");
	}

	public function getNetworkId() : int{
		return CompressionAlgorithm::ZLIB;
	}

	public static function isAvailable() : bool{
		return function_exists('snappy_compress') && function_exists('snappy_uncompress');
	}
}
